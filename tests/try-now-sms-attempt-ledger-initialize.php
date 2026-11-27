<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Ledger initialization tests.
 *
 * Isolated Plesk CLI only.
 * Synthetic identities and keys only.
 * No COMMIT; always roll back.
 */

if (
    PHP_SAPI !== 'cli' ||
    DIRECTORY_SEPARATOR !== '/' ||
    realpath(__DIR__) !==
        '/var/www/vhosts/smarttext.com/try-now-check/tests'
) {
    fwrite(STDERR, "FAIL: Isolated CLI required.\n");
    exit(1);
}

$root = dirname(__DIR__);

require_once $root
    . '/lib/try-now-sms-attempt-ledger-initialize.php';

function ledgerInitCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function ledgerInitBlocked(
    array $result
): bool {
    return
        $result[
            'checkpoint_initialized_in_transaction'
        ] === false &&
        $result['checkpoint_committed'] === false &&
        $result['safe_for_transport'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false;
}

$pdo = null;
$failure = null;

try {
    ledgerInitCheck(
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
            'Private test configuration unavailable.'
        );
    }

    $config = require $configFile;

    ledgerInitCheck(
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

    ledgerInitCheck(
        'Authenticated isolated database connection',
        is_array($identity) &&
        $identity['db'] ===
            'smarttext_try_now_test' &&
        strpos(
            $identity['db_user'],
            'try_now_test@'
        ) === 0
    );

    ledgerInitCheck(
        'Ledger initially contains zero rows',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    $namespace = 'isolated-test';
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);
    $sealKey = str_repeat('S', 32);

    $campaignId = 7907;
    $customerId = 900000301;
    $attemptId = stTryNowNewJournalAttemptId();

    $reservation = [
        'campaign_id' => $campaignId,
        'customer_id' => $customerId,
        'attempt_id' => $attemptId,
        'journal_slot_id' => stTryNowJournalSlotId(
            $namespace,
            $campaignId,
            $customerId,
            $bindingKey
        ),
        'recipient_digest' => hash_hmac(
            'sha256',
            'synthetic-recipient:301',
            str_repeat('K', 32)
        ),
    ];

    $prepared = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        1,
        'prepared',
        '2026-11-25T10:00:00Z',
        str_repeat('0', 64),
        $recordKey
    );

    $history = [$prepared];

    $seal = stTryNowBuildJournalHeadSeal(
        $history,
        $recordKey,
        $sealKey
    );

    $run = static function (
        array $candidateReservation,
        array $candidateHistory,
        array $candidateSeal,
        bool $allow = true,
        ?string $overrideBindingKey = null,
        ?string $overrideRecordKey = null,
        ?string $overrideSealKey = null
    ) use (
        $pdo,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    ): array {
        return stTryNowInitializeSyntheticLedgerCheckpoint(
            $pdo,
            $candidateReservation,
            $candidateHistory,
            $candidateSeal,
            $namespace,
            $overrideBindingKey ?? $bindingKey,
            $overrideRecordKey ?? $recordKey,
            $overrideSealKey ?? $sealKey,
            $allow
        );
    };

    $disabled = $run(
        $reservation,
        $history,
        $seal,
        false
    );

    ledgerInitCheck(
        'Initialization disabled by default',
        $disabled['status'] === 'disabled' &&
        ledgerInitBlocked($disabled)
    );

    $noTransaction = $run(
        $reservation,
        $history,
        $seal
    );

    ledgerInitCheck(
        'Initialization requires a transaction',
        $noTransaction['status'] ===
            'transaction_required' &&
        ledgerInitBlocked($noTransaction)
    );

    $pdo->beginTransaction();

    $reserved = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $reservation,
        true
    );

    ledgerInitCheck(
        'Attempt reserved without committing',
        $reserved['status'] ===
            'reserved_uncommitted_test_only' &&
        $reserved[
            'reserved_in_current_transaction'
        ] === true &&
        $reserved['reservation_committed'] === false
    );

    $wrongBindingKey = $run(
        $reservation,
        $history,
        $seal,
        true,
        str_repeat('X', 32)
    );

    ledgerInitCheck(
        'Incorrect journal binding key rejected',
        $wrongBindingKey['status'] ===
            'journal_slot_mismatch' &&
        ledgerInitBlocked($wrongBindingKey)
    );

    $wrongSlot = $reservation;
    $wrongSlot['journal_slot_id'] =
        hash('sha256', 'different-slot');

    $result = $run($wrongSlot, $history, $seal);

    ledgerInitCheck(
        'Altered journal slot rejected',
        $result['status'] ===
            'journal_slot_mismatch' &&
        ledgerInitBlocked($result)
    );

    $badSeal = $seal;
    $badSeal['head_mac'] = str_repeat('0', 64);

    $result = $run($reservation, $history, $badSeal);

    ledgerInitCheck(
        'Modified checkpoint seal rejected',
        $result['status'] ===
            'invalid_prepared_evidence' &&
        ledgerInitBlocked($result)
    );

    $tamperedRecord = $prepared;
    $tamperedRecord['event'] = 'transport_started';

    $result = $run(
        $reservation,
        [$tamperedRecord],
        $seal
    );

    ledgerInitCheck(
        'Tampered journal record rejected',
        $result['status'] ===
            'invalid_prepared_evidence' &&
        ledgerInitBlocked($result)
    );

    $result = $run(
        $reservation,
        $history,
        $seal,
        true,
        null,
        str_repeat('X', 32)
    );

    ledgerInitCheck(
        'Incorrect journal record key rejected',
        $result['status'] ===
            'invalid_prepared_evidence' &&
        ledgerInitBlocked($result)
    );

    $result = $run(
        $reservation,
        $history,
        $seal,
        true,
        null,
        null,
        str_repeat('X', 32)
    );

    ledgerInitCheck(
        'Incorrect checkpoint seal key rejected',
        $result['status'] ===
            'invalid_prepared_evidence' &&
        ledgerInitBlocked($result)
    );

    $otherAttempt = stTryNowNewJournalAttemptId();

    $otherPrepared = stTryNowBuildJournalRecord(
        $otherAttempt,
        $customerId,
        $campaignId,
        1,
        'prepared',
        '2026-11-25T10:00:00Z',
        str_repeat('0', 64),
        $recordKey
    );

    $result = $run(
        $reservation,
        [$otherPrepared],
        stTryNowBuildJournalHeadSeal(
            [$otherPrepared],
            $recordKey,
            $sealKey
        )
    );

    ledgerInitCheck(
        'Journal from another attempt rejected',
        $result['status'] ===
            'journal_identity_mismatch' &&
        ledgerInitBlocked($result)
    );

    $result = $run(
        $reservation,
        [],
        $seal
    );

    ledgerInitCheck(
        'Missing prepared journal rejected',
        $result['status'] ===
            'invalid_prepared_evidence' &&
        ledgerInitBlocked($result)
    );

    $initialized = $run(
        $reservation,
        $history,
        $seal
    );

    ledgerInitCheck(
        'Prepared checkpoint initialized in transaction',
        $initialized['status'] ===
            'initialized_uncommitted_test_only' &&
        $initialized[
            'checkpoint_initialized_in_transaction'
        ] === true &&
        $initialized['checkpoint_committed'] === false
    );

    $statement = $pdo->prepare(
        'SELECT attempt_id,
                journal_slot_id,
                recipient_digest,
                checkpoint_sequence,
                checkpoint_revision,
                checkpoint_event,
                checkpoint_head_mac,
                checkpoint_seal_mac,
                checkpoint_updated_at
         FROM try_now_sms_attempt_ledger
         WHERE campaign_id = ?
           AND customer_id = ?'
    );

    $statement->execute([
        $campaignId,
        $customerId,
    ]);

    $stored = $statement->fetch();

    ledgerInitCheck(
        'Checkpoint identity and HMAC stored correctly',
        is_array($stored) &&
        $stored['attempt_id'] === $attemptId &&
        $stored['journal_slot_id'] ===
            $reservation['journal_slot_id'] &&
        $stored['recipient_digest'] ===
            $reservation['recipient_digest'] &&
        (int) $stored['checkpoint_sequence'] === 1 &&
        (int) $stored['checkpoint_revision'] === 1 &&
        $stored['checkpoint_event'] === 'prepared' &&
        $stored['checkpoint_head_mac'] ===
            $seal['head_mac'] &&
        $stored['checkpoint_seal_mac'] ===
            $seal['seal_mac'] &&
        $stored['checkpoint_updated_at'] !== null
    );

    $repeated = $run(
        $reservation,
        $history,
        $seal
    );

    ledgerInitCheck(
        'Second checkpoint initialization rejected',
        $repeated['status'] ===
            'checkpoint_conflict_requires_review' &&
        ledgerInitBlocked($repeated)
    );

    $wrongRecipient = $reservation;
    $wrongRecipient['recipient_digest'] =
        hash('sha256', 'different-recipient');

    $result = $run(
        $wrongRecipient,
        $history,
        $seal
    );

    ledgerInitCheck(
        'Changed recipient digest rejected',
        $result['status'] ===
            'checkpoint_conflict_requires_review' &&
        ledgerInitBlocked($result)
    );

    $missingReservation = $reservation;
    $missingReservation['customer_id'] = 900000302;
    $missingReservation['attempt_id'] =
        stTryNowNewJournalAttemptId();

    $missingReservation['journal_slot_id'] =
        stTryNowJournalSlotId(
            $namespace,
            $campaignId,
            900000302,
            $bindingKey
        );

    $missingPrepared = stTryNowBuildJournalRecord(
        $missingReservation['attempt_id'],
        900000302,
        $campaignId,
        1,
        'prepared',
        '2026-11-25T10:00:00Z',
        str_repeat('0', 64),
        $recordKey
    );

    $result = $run(
        $missingReservation,
        [$missingPrepared],
        stTryNowBuildJournalHeadSeal(
            [$missingPrepared],
            $recordKey,
            $sealKey
        )
    );

    ledgerInitCheck(
        'Checkpoint without reservation rejected',
        $result['status'] ===
            'checkpoint_conflict_requires_review' &&
        ledgerInitBlocked($result)
    );

    $statement->execute([
        $campaignId,
        $customerId,
    ]);

    $unchanged = $statement->fetch();

    ledgerInitCheck(
        'Rejected operations preserve checkpoint',
        is_array($unchanged) &&
        (int) $unchanged['checkpoint_sequence'] === 1 &&
        (int) $unchanged['checkpoint_revision'] === 1 &&
        $unchanged['checkpoint_head_mac'] ===
            $seal['head_mac'] &&
        $unchanged['checkpoint_seal_mac'] ===
            $seal['seal_mac']
    );

    ledgerInitCheck(
        'Initialization leaves transaction open',
        $pdo->inTransaction()
    );

    ledgerInitCheck(
        'Initialization never authorises SMS',
        $initialized['safe_for_transport'] === false &&
        $initialized['automatic_send'] === false &&
        $initialized['automatic_retry'] === false &&
        $initialized['automatic_cleanup'] === false
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * Rollback is mandatory.
     * No COMMIT anywhere in this test.
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
    ledgerInitCheck(
        'All synthetic ledger changes rolled back',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    echo "PASS: All ledger initialization tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'FAIL: ' . $e->getMessage() . "\n"
    );
    exit(1);
}
