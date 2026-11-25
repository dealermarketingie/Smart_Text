<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - SMS reservation tests.
 *
 * Isolated Plesk CLI only.
 * Synthetic identities only.
 * Every database change is rolled back.
 */

$expectedDirectory =
    '/var/www/vhosts/smarttext.com/try-now-check/tests';

if (
    PHP_SAPI !== 'cli' ||
    DIRECTORY_SEPARATOR !== '/' ||
    realpath(__DIR__) !== $expectedDirectory
) {
    fwrite(
        STDERR,
        "FAIL: Isolated Linux CLI required.\n"
    );
    exit(1);
}

$root = dirname(__DIR__);

require_once $root
    . '/lib/try-now-sms-attempt-ledger-reservation.php';

function reservationCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function reservationSyntheticRow(
    int $customerId,
    string $label
): array {
    return [
        'campaign_id' => 7907,
        'customer_id' => $customerId,
        'attempt_id' => bin2hex(random_bytes(16)),
        'journal_slot_id' => hash(
            'sha256',
            'test-slot:' . $label
        ),
        'recipient_digest' => hash_hmac(
            'sha256',
            'test-recipient:' . $label,
            str_repeat('K', 32)
        ),
    ];
}

function reservationIsBlocked(
    array $result
): bool {
    return
        $result[
            'reserved_in_current_transaction'
        ] === false &&
        $result['reservation_committed'] === false &&
        $result['safe_for_transport'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false;
}

$pdo = null;
$failure = null;

try {
    reservationCheck(
        'Production operations remain disabled',
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false
    );

    $configFile = $root . '/db-test.php';

    if (
        !is_file($configFile) ||
        is_link($configFile)
    ) {
        throw new RuntimeException(
            'Test configuration unavailable.'
        );
    }

    $config = require $configFile;

    reservationCheck(
        'Isolated database configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        (int) ($config['port'] ?? 0) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test' &&
        ($config['username'] ?? null) ===
            'try_now_test' &&
        is_string($config['password'] ?? null) &&
        $config['password'] !== ''
    );

    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=33080;' .
        'dbname=smarttext_try_now_test;charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    $identity = $pdo->query(
        'SELECT DATABASE() AS db,
                CURRENT_USER() AS db_user'
    )->fetch();

    reservationCheck(
        'Authenticated isolated database connection',
        is_array($identity) &&
        $identity['db'] ===
            'smarttext_try_now_test' &&
        strpos(
            $identity['db_user'],
            'try_now_test@'
        ) === 0
    );

    reservationCheck(
        'Ledger initially contains no reservations',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    $original = reservationSyntheticRow(
        900000201,
        'original'
    );

    $disabled = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $original
    );

    reservationCheck(
        'Reservation disabled by default',
        $disabled['status'] === 'disabled' &&
        reservationIsBlocked($disabled)
    );

    $withoutTransaction =
        stTryNowReserveSyntheticLedgerAttempt(
            $pdo,
            $original,
            true
        );

    reservationCheck(
        'Reservation requires existing transaction',
        $withoutTransaction['status'] ===
            'transaction_required' &&
        reservationIsBlocked($withoutTransaction)
    );

    $pdo->beginTransaction();

    $invalidCampaign = $original;
    $invalidCampaign['campaign_id'] = 999999;

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $invalidCampaign,
        true
    );

    reservationCheck(
        'Unsupported campaign rejected',
        $result['status'] === 'invalid_reservation' &&
        reservationIsBlocked($result)
    );

    $invalidCustomer = $original;
    $invalidCustomer['customer_id'] = 0;

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $invalidCustomer,
        true
    );

    reservationCheck(
        'Invalid customer ID rejected',
        $result['status'] === 'invalid_reservation' &&
        reservationIsBlocked($result)
    );

    $invalidAttempt = $original;
    $invalidAttempt['attempt_id'] =
        'g' . str_repeat('0', 31);

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $invalidAttempt,
        true
    );

    reservationCheck(
        'Malformed attempt identifier rejected',
        $result['status'] === 'invalid_reservation' &&
        reservationIsBlocked($result)
    );

    $invalidSlot = $original;
    $invalidSlot['journal_slot_id'] =
        'g' . str_repeat('0', 63);

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $invalidSlot,
        true
    );

    reservationCheck(
        'Malformed journal slot rejected',
        $result['status'] === 'invalid_reservation' &&
        reservationIsBlocked($result)
    );

    $invalidRecipient = $original;
    $invalidRecipient['recipient_digest'] =
        'g' . str_repeat('0', 63);

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $invalidRecipient,
        true
    );

    reservationCheck(
        'Malformed recipient digest rejected',
        $result['status'] === 'invalid_reservation' &&
        reservationIsBlocked($result)
    );

    $created = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $original,
        true
    );

    reservationCheck(
        'Synthetic attempt reserved in transaction',
        $created['status'] ===
            'reserved_uncommitted_test_only' &&
        $created[
            'reserved_in_current_transaction'
        ] === true &&
        $created['reservation_committed'] === false
    );

    $stored = $pdo->prepare(
        'SELECT attempt_id,
                journal_slot_id,
                recipient_digest,
                checkpoint_sequence,
                checkpoint_revision
         FROM try_now_sms_attempt_ledger
         WHERE campaign_id = ?
           AND customer_id = ?'
    );

    $stored->execute([
        $original['campaign_id'],
        $original['customer_id'],
    ]);

    $row = $stored->fetch();

    reservationCheck(
        'Reserved identity stored correctly',
        is_array($row) &&
        $row['attempt_id'] ===
            $original['attempt_id'] &&
        $row['journal_slot_id'] ===
            $original['journal_slot_id'] &&
        $row['recipient_digest'] ===
            $original['recipient_digest']
    );

    reservationCheck(
        'New reservation has no checkpoint',
        (int) $row['checkpoint_sequence'] === 0 &&
        (int) $row['checkpoint_revision'] === 0
    );

    $duplicateCustomer = reservationSyntheticRow(
        900000201,
        'different-attempt'
    );

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $duplicateCustomer,
        true
    );

    reservationCheck(
        'Duplicate customer reservation blocked',
        $result['status'] ===
            'reservation_exists_requires_review' &&
        reservationIsBlocked($result)
    );

    $duplicateAttempt = reservationSyntheticRow(
        900000202,
        'duplicate-attempt'
    );

    $duplicateAttempt['attempt_id'] =
        $original['attempt_id'];

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $duplicateAttempt,
        true
    );

    reservationCheck(
        'Duplicate attempt ID blocked',
        $result['status'] ===
            'reservation_exists_requires_review' &&
        reservationIsBlocked($result)
    );

    $duplicateSlot = reservationSyntheticRow(
        900000203,
        'duplicate-slot'
    );

    $duplicateSlot['journal_slot_id'] =
        $original['journal_slot_id'];

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $duplicateSlot,
        true
    );

    reservationCheck(
        'Duplicate journal slot blocked',
        $result['status'] ===
            'reservation_exists_requires_review' &&
        reservationIsBlocked($result)
    );

    $duplicateRecipient = reservationSyntheticRow(
        900000204,
        'duplicate-recipient'
    );

    $duplicateRecipient['recipient_digest'] =
        $original['recipient_digest'];

    $result = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $duplicateRecipient,
        true
    );

    reservationCheck(
        'Duplicate recipient blocked',
        $result['status'] ===
            'reservation_exists_requires_review' &&
        reservationIsBlocked($result)
    );

    reservationCheck(
        'Rejected reservations leave one original row',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 1
    );

    reservationCheck(
        'Successful reservation never authorises SMS',
        $created['safe_for_transport'] === false &&
        $created['automatic_send'] === false &&
        $created['automatic_retry'] === false &&
        $created['automatic_cleanup'] === false
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * No COMMIT exists anywhere in this test.
     * Always roll back synthetic reservations.
     */
    if (
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        try {
            $pdo->rollBack();
        } catch (Throwable $e) {
            if ($failure === null) {
                $failure = $e;
            }
        }
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . $failure->getMessage() . "\n"
    );
    exit(1);
}

try {
    reservationCheck(
        'All synthetic reservations rolled back',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    echo "PASS: All isolated SMS reservation tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'FAIL: ' . $e->getMessage() . "\n"
    );
    exit(1);
}
