<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Atomic checkpoint tests.
 *
 * ISOLATED PLESK CLI ONLY.
 * Synthetic identities and HMAC keys.
 *
 * No COMMIT.
 * Every database change is rolled back.
 * No Sendmode, Redis, HTTP or customer writes.
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
    . '/lib/try-now-sms-attempt-ledger-advance.php';

function advanceCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function advanceBlocked(
    array $result
): bool {
    return
        $result[
            'checkpoint_advanced_in_transaction'
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
    advanceCheck(
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

    advanceCheck(
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

    advanceCheck(
        'Authenticated isolated database connection',
        is_array($identity) &&
        $identity['db'] ===
            'smarttext_try_now_test' &&
        strpos(
            $identity['db_user'],
            'try_now_test@'
        ) === 0
    );

    advanceCheck(
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
    $customerId = 900000401;
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
            'synthetic-recipient:401',
            str_repeat('K', 32)
        ),
    ];

    $timestamp = '2026-11-26T10:00:00Z';

    $prepared = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        1,
        'prepared',
        $timestamp,
        str_repeat('0', 64),
        $recordKey
    );

    $started = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        2,
        'transport_started',
        $timestamp,
        $prepared['mac'],
        $recordKey
    );

    $accepted = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        3,
        'provider_accepted',
        $timestamp,
        $started['mac'],
        $recordKey
    );

    $preparedHistory = [$prepared];
    $startedHistory = [$prepared, $started];
    $acceptedHistory = [$prepared, $started, $accepted];

    $makeSeal = static function (
        array $history
    ) use ($recordKey, $sealKey): array {
        return stTryNowBuildJournalHeadSeal(
            $history,
            $recordKey,
            $sealKey
        );
    };

    $preparedSeal = $makeSeal($preparedHistory);
    $startedSeal = $makeSeal($startedHistory);
    $acceptedSeal = $makeSeal($acceptedHistory);

    $run = static function (
        array $history,
        array $candidate,
        ?array $overrideReservation = null,
        bool $allow = true,
        ?string $overrideBindingKey = null,
        ?string $overrideRecordKey = null,
        ?string $overrideSealKey = null
    ) use (
        $pdo,
        $reservation,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    ): array {
        return stTryNowAdvanceSyntheticLedgerCheckpoint(
            $pdo,
            $overrideReservation ?? $reservation,
            $history,
            $candidate,
            $namespace,
            $overrideBindingKey ?? $bindingKey,
            $overrideRecordKey ?? $recordKey,
            $overrideSealKey ?? $sealKey,
            $allow
        );
    };

    $disabled = $run(
        $startedHistory,
        $startedSeal,
        null,
        false
    );

    advanceCheck(
        'Checkpoint advancement disabled by default',
        $disabled['status'] === 'disabled' &&
        advanceBlocked($disabled)
    );

    $noTransaction = $run(
        $startedHistory,
        $startedSeal
    );

    advanceCheck(
        'Advancement requires existing transaction',
        $noTransaction['status'] ===
            'transaction_required' &&
        advanceBlocked($noTransaction)
    );

    $pdo->beginTransaction();

    $reserved = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $reservation,
        true
    );

    advanceCheck(
        'Synthetic attempt reserved in transaction',
        $reserved['status'] ===
            'reserved_uncommitted_test_only'
    );

    $initialized = stTryNowInitializeSyntheticLedgerCheckpoint(
        $pdo,
        $reservation,
        $preparedHistory,
        $preparedSeal,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey,
        true
    );

    advanceCheck(
        'Prepared checkpoint initialized',
        $initialized['status'] ===
            'initialized_uncommitted_test_only'
    );

    $missing = $run(
        [],
        $startedSeal
    );

    advanceCheck(
        'Missing journal history rejected',
        $missing['status'] ===
            'invalid_journal_length' &&
        advanceBlocked($missing)
    );

    $wrongBinding = $run(
        $startedHistory,
        $startedSeal,
        null,
        true,
        str_repeat('X', 32)
    );

    advanceCheck(
        'Incorrect journal binding key rejected',
        $wrongBinding['status'] ===
            'journal_slot_mismatch' &&
        advanceBlocked($wrongBinding)
    );

    $alteredSeal = $startedSeal;
    $alteredSeal['head_mac'] = str_repeat('0', 64);

    $result = $run(
        $startedHistory,
        $alteredSeal
    );

    advanceCheck(
        'Modified candidate checkpoint rejected',
        $result['status'] ===
            'invalid_candidate_checkpoint' &&
        advanceBlocked($result)
    );

    $result = $run(
        $startedHistory,
        $startedSeal,
        null,
        true,
        null,
        str_repeat('X', 32)
    );

    advanceCheck(
        'Incorrect record authentication key rejected',
        $result['status'] ===
            'invalid_candidate_checkpoint' &&
        advanceBlocked($result)
    );

    $result = $run(
        $startedHistory,
        $startedSeal,
        null,
        true,
        null,
        null,
        str_repeat('X', 32)
    );

    advanceCheck(
        'Incorrect checkpoint key rejected',
        $result['status'] ===
            'invalid_candidate_checkpoint' &&
        advanceBlocked($result)
    );

    $otherAttempt = stTryNowNewJournalAttemptId();

    $otherPrepared = stTryNowBuildJournalRecord(
        $otherAttempt,
        $customerId,
        $campaignId,
        1,
        'prepared',
        $timestamp,
        str_repeat('0', 64),
        $recordKey
    );

    $otherStarted = stTryNowBuildJournalRecord(
        $otherAttempt,
        $customerId,
        $campaignId,
        2,
        'transport_started',
        $timestamp,
        $otherPrepared['mac'],
        $recordKey
    );

    $otherHistory = [
        $otherPrepared,
        $otherStarted,
    ];

    $result = $run(
        $otherHistory,
        $makeSeal($otherHistory)
    );

    advanceCheck(
        'Different SMS attempt identity rejected',
        $result['status'] ===
            'journal_identity_mismatch' &&
        advanceBlocked($result)
    );

    /*
     * The ledger is at sequence 1.
     * Skipping directly to sequence 3 must fail.
     */
    $skipped = $run(
        $acceptedHistory,
        $acceptedSeal
    );

    advanceCheck(
        'Skipped checkpoint advancement rejected',
        $skipped['status'] ===
            'checkpoint_conflict_requires_review' &&
        advanceBlocked($skipped)
    );

    $advanced = $run(
        $startedHistory,
        $startedSeal
    );

    advanceCheck(
        'Prepared to transport-start advancement succeeds',
        $advanced['status'] ===
            'advanced_uncommitted_test_only' &&
        $advanced[
            'checkpoint_advanced_in_transaction'
        ] === true &&
        $advanced['checkpoint_committed'] === false
    );

    $read = $pdo->prepare(
        'SELECT checkpoint_sequence,
                checkpoint_revision,
                checkpoint_event,
                checkpoint_head_mac,
                checkpoint_seal_mac
         FROM try_now_sms_attempt_ledger
         WHERE campaign_id = ?
           AND customer_id = ?'
    );

    $read->execute([
        $campaignId,
        $customerId,
    ]);

    $stored = $read->fetch();

    advanceCheck(
        'Transport-start checkpoint stored correctly',
        is_array($stored) &&
        (int) $stored['checkpoint_sequence'] === 2 &&
        (int) $stored['checkpoint_revision'] === 2 &&
        $stored['checkpoint_event'] ===
            'transport_started' &&
        $stored['checkpoint_head_mac'] ===
            $startedSeal['head_mac'] &&
        $stored['checkpoint_seal_mac'] ===
            $startedSeal['seal_mac']
    );

    $repeated = $run(
        $startedHistory,
        $startedSeal
    );

    advanceCheck(
        'Duplicate checkpoint advancement rejected',
        $repeated['status'] ===
            'checkpoint_conflict_requires_review' &&
        advanceBlocked($repeated)
    );

    /*
     * Temporarily corrupt the checkpoint within
     * a savepoint, then restore it.
     *
     * The entire outer transaction will still
     * be rolled back.
     */
    $pdo->exec(
        'SAVEPOINT synthetic_checkpoint_corruption'
    );

    $pdo->exec(
        "UPDATE try_now_sms_attempt_ledger
         SET checkpoint_head_mac = REPEAT('f', 64)
         WHERE campaign_id = 7907
           AND customer_id = 900000401"
    );

    $corrupted = $run(
        $acceptedHistory,
        $acceptedSeal
    );

    advanceCheck(
        'Corrupted persisted checkpoint rejected',
        $corrupted['status'] ===
            'persisted_checkpoint_mismatch' &&
        advanceBlocked($corrupted)
    );

    $pdo->exec(
        'ROLLBACK TO SAVEPOINT synthetic_checkpoint_corruption'
    );

    $pdo->exec(
        'RELEASE SAVEPOINT synthetic_checkpoint_corruption'
    );

    $providerAdvanced = $run(
        $acceptedHistory,
        $acceptedSeal
    );

    advanceCheck(
        'Transport-start to provider-result advancement succeeds',
        $providerAdvanced['status'] ===
            'advanced_uncommitted_test_only' &&
        $providerAdvanced[
            'checkpoint_advanced_in_transaction'
        ] === true
    );

    $read->execute([
        $campaignId,
        $customerId,
    ]);

    $completed = $read->fetch();

    advanceCheck(
        'Provider-result checkpoint stored correctly',
        is_array($completed) &&
        (int) $completed['checkpoint_sequence'] === 3 &&
        (int) $completed['checkpoint_revision'] === 3 &&
        $completed['checkpoint_event'] ===
            'provider_accepted' &&
        $completed['checkpoint_head_mac'] ===
            $acceptedSeal['head_mac'] &&
        $completed['checkpoint_seal_mac'] ===
            $acceptedSeal['seal_mac']
    );

    $repeatedProvider = $run(
        $acceptedHistory,
        $acceptedSeal
    );

    advanceCheck(
        'Completed checkpoint cannot advance again',
        $repeatedProvider['status'] ===
            'checkpoint_conflict_requires_review' &&
        advanceBlocked($repeatedProvider)
    );

    $changedRecipient = $reservation;
    $changedRecipient['recipient_digest'] =
        hash('sha256', 'different-recipient');

    $result = $run(
        $acceptedHistory,
        $acceptedSeal,
        $changedRecipient
    );

    advanceCheck(
        'Changed recipient identity rejected',
        advanceBlocked($result)
    );

    /*
     * Test cancellation with a different,
     * synthetic customer and attempt.
     */
    $cancelCustomer = 900000402;
    $cancelAttempt = stTryNowNewJournalAttemptId();

    $cancelReservation = [
        'campaign_id' => $campaignId,
        'customer_id' => $cancelCustomer,
        'attempt_id' => $cancelAttempt,
        'journal_slot_id' => stTryNowJournalSlotId(
            $namespace,
            $campaignId,
            $cancelCustomer,
            $bindingKey
        ),
        'recipient_digest' => hash_hmac(
            'sha256',
            'synthetic-recipient:402',
            str_repeat('K', 32)
        ),
    ];

    $cancelPrepared = stTryNowBuildJournalRecord(
        $cancelAttempt,
        $cancelCustomer,
        $campaignId,
        1,
        'prepared',
        $timestamp,
        str_repeat('0', 64),
        $recordKey
    );

    $cancelled = stTryNowBuildJournalRecord(
        $cancelAttempt,
        $cancelCustomer,
        $campaignId,
        2,
        'cancelled_before_transport',
        $timestamp,
        $cancelPrepared['mac'],
        $recordKey
    );

    $cancelPreparedHistory = [$cancelPrepared];
    $cancelHistory = [$cancelPrepared, $cancelled];

    $cancelReserved = stTryNowReserveSyntheticLedgerAttempt(
        $pdo,
        $cancelReservation,
        true
    );

    $cancelInitialized =
        stTryNowInitializeSyntheticLedgerCheckpoint(
            $pdo,
            $cancelReservation,
            $cancelPreparedHistory,
            $makeSeal($cancelPreparedHistory),
            $namespace,
            $bindingKey,
            $recordKey,
            $sealKey,
            true
        );

    advanceCheck(
        'Independent cancellation attempt initialized',
        $cancelReserved['status'] ===
            'reserved_uncommitted_test_only' &&
        $cancelInitialized['status'] ===
            'initialized_uncommitted_test_only'
    );

    $cancelResult = stTryNowAdvanceSyntheticLedgerCheckpoint(
        $pdo,
        $cancelReservation,
        $cancelHistory,
        $makeSeal($cancelHistory),
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey,
        true
    );

    advanceCheck(
        'Pre-transport cancellation checkpoint accepted',
        $cancelResult['status'] ===
            'advanced_uncommitted_test_only' &&
        $cancelResult[
            'checkpoint_advanced_in_transaction'
        ] === true &&
        $cancelResult['safe_for_transport'] === false
    );

    advanceCheck(
        'Transaction remains controlled by caller',
        $pdo->inTransaction()
    );

    advanceCheck(
        'Successful checkpoint updates never authorise SMS',
        $advanced['safe_for_transport'] === false &&
        $providerAdvanced['safe_for_transport'] === false &&
        $providerAdvanced['automatic_send'] === false &&
        $providerAdvanced['automatic_retry'] === false &&
        $providerAdvanced['automatic_cleanup'] === false
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
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
    advanceCheck(
        'All synthetic checkpoint changes rolled back',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    echo "PASS: All atomic checkpoint advancement tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'FAIL: ' . $e->getMessage() . "\n"
    );
    exit(1);
}
