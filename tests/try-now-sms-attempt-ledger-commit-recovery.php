<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Commit recovery tests.
 *
 * ISOLATED PLESK CLI ONLY.
 *
 * Commit acknowledgements are SIMULATED.
 * The independent database observation is real.
 *
 * No database COMMIT is executed.
 * All synthetic reservations are rolled back.
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
    . '/lib/try-now-sms-attempt-ledger-commit-recovery.php';

function recoveryCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function recoveryNeverSends(
    array $result
): bool {
    return
        $result['checkpoint_committed'] === false &&
        $result['safe_for_transport'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false &&
        $result['review_required'] === true;
}

function recoveryConnect(array $config): PDO
{
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
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($identity) ||
        $identity['db'] !== 'smarttext_try_now_test' ||
        strpos(
            (string) $identity['db_user'],
            'try_now_test@'
        ) !== 0
    ) {
        throw new RuntimeException(
            'Isolated MariaDB identity mismatch.'
        );
    }

    return $pdo;
}

$writer = null;
$observer = null;
$failure = null;

try {
    recoveryCheck(
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
            'Private test configuration missing.'
        );
    }

    $config = require $configFile;

    recoveryCheck(
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

    $writer = recoveryConnect($config);
    $observer = recoveryConnect($config);

    recoveryCheck(
        'Two authenticated isolated sessions opened',
        $writer instanceof PDO &&
        $observer instanceof PDO
    );

    $writerId = (int) $writer->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    $observerId = (int) $observer->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    recoveryCheck(
        'Writer and observer have separate sessions',
        $writerId > 0 &&
        $observerId > 0 &&
        $writerId !== $observerId
    );

    recoveryCheck(
        'Ledger initially contains zero rows',
        (int) $observer->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    $namespace = 'isolated-test';
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);
    $sealKey = str_repeat('S', 32);

    $campaignId = 7907;
    $customerId = 900000501;
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
            'synthetic-recipient:501',
            str_repeat('K', 32)
        ),
    ];

    $prepared = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        1,
        'prepared',
        '2026-11-26T10:00:00Z',
        str_repeat('0', 64),
        $recordKey
    );

    $history = [$prepared];

    $seal = stTryNowBuildJournalHeadSeal(
        $history,
        $recordKey,
        $sealKey
    );

    $assess = static function (
        string $signal,
        array $observation,
        ?array $overrideReservation = null,
        ?array $overrideHistory = null,
        ?array $overrideSeal = null
    ) use (
        $reservation,
        $history,
        $seal,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    ): array {
        return stTryNowAssessSyntheticLedgerCommitEvidence(
            $overrideReservation ?? $reservation,
            $overrideHistory ?? $history,
            $overrideSeal ?? $seal,
            $namespace,
            $bindingKey,
            $recordKey,
            $sealKey,
            $signal,
            $observation,
            true
        );
    };

    $disabled = stTryNowAssessSyntheticLedgerCommitEvidence(
        $reservation,
        $history,
        $seal,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey,
        'acknowledged',
        ['status' => 'absent'],
        false
    );

    recoveryCheck(
        'Commit assessment disabled by default',
        $disabled['status'] === 'disabled' &&
        recoveryNeverSends($disabled)
    );

    $invalidSignal = $assess(
        'retry_commit',
        ['status' => 'absent']
    );

    recoveryCheck(
        'Unknown commit signal rejected',
        $invalidSignal['status'] ===
            'invalid_commit_signal' &&
        recoveryNeverSends($invalidSignal)
    );

    $writer->beginTransaction();

    $reserved = stTryNowReserveSyntheticLedgerAttempt(
        $writer,
        $reservation,
        true
    );

    recoveryCheck(
        'Synthetic reservation created without commit',
        $reserved['status'] ===
            'reserved_uncommitted_test_only'
    );

    $initialized =
        stTryNowInitializeSyntheticLedgerCheckpoint(
            $writer,
            $reservation,
            $history,
            $seal,
            $namespace,
            $bindingKey,
            $recordKey,
            $sealKey,
            true
        );

    recoveryCheck(
        'Prepared checkpoint initialized without commit',
        $initialized['status'] ===
            'initialized_uncommitted_test_only'
    );

    $fresh = stTryNowObserveSyntheticLedgerCheckpoint(
        $observer,
        $reservation,
        $writerId,
        true
    );

    recoveryCheck(
        'Uncommitted checkpoint is invisible to observer',
        $fresh['status'] === 'absent' &&
        $fresh['row'] === null
    );

    $wrongObserver = stTryNowObserveSyntheticLedgerCheckpoint(
        $writer,
        $reservation,
        $writerId,
        true
    );

    recoveryCheck(
        'Active writer transaction cannot act as observer',
        $wrongObserver['status'] ===
            'untrusted_observer'
    );

    $sameSession = stTryNowObserveSyntheticLedgerCheckpoint(
        $observer,
        $reservation,
        $observerId,
        true
    );

    recoveryCheck(
        'Observer session cannot impersonate fresh session',
        $sameSession['status'] ===
            'untrusted_observer'
    );

    $notAttempted = $assess(
        'not_attempted',
        $fresh
    );

    recoveryCheck(
        'Unattempted commit is not confirmed',
        $notAttempted['status'] ===
            'commit_not_attempted_test_only' &&
        recoveryNeverSends($notAttempted)
    );

    $acknowledgedButAbsent = $assess(
        'acknowledged',
        $fresh
    );

    recoveryCheck(
        'Acknowledgement without persistence requires review',
        $acknowledgedButAbsent['status'] ===
            'acknowledged_but_absent_requires_review' &&
        recoveryNeverSends($acknowledgedButAbsent)
    );

    $lostAndAbsent = $assess(
        'acknowledgement_lost',
        $fresh
    );

    recoveryCheck(
        'Lost acknowledgement with absent row is uncertain',
        $lostAndAbsent['status'] ===
            'commit_outcome_unknown_requires_review' &&
        recoveryNeverSends($lostAndAbsent)
    );

    /*
     * Synthetic snapshot:
     *
     * Used ONLY to test decision logic for
     * a hypothetical committed checkpoint.
     *
     * This is NOT a real committed database row.
     */
    $simulatedRow = [
        'campaign_id' => $campaignId,
        'customer_id' => $customerId,
        'attempt_id' => $attemptId,
        'journal_slot_id' =>
            $reservation['journal_slot_id'],
        'recipient_digest' =>
            $reservation['recipient_digest'],
        'checkpoint_sequence' => 1,
        'checkpoint_revision' => 1,
        'checkpoint_event' => 'prepared',
        'checkpoint_head_mac' => $seal['head_mac'],
        'checkpoint_seal_mac' => $seal['seal_mac'],
        'checkpoint_updated_at' =>
            '2026-11-26 10:00:00.000001',
    ];

    $simulatedFound = [
        'status' => 'found',
        'row' => $simulatedRow,
    ];

    $acknowledged = $assess(
        'acknowledged',
        $simulatedFound
    );

    recoveryCheck(
        'Acknowledged matching snapshot classified test-only',
        $acknowledged['status'] ===
            'acknowledged_and_observed_test_only' &&
        $acknowledged[
            'fresh_checkpoint_observed'
        ] === true &&
        $acknowledged[
            'commit_acknowledgement_reported'
        ] === true &&
        recoveryNeverSends($acknowledged)
    );

    $lostButPresent = $assess(
        'acknowledgement_lost',
        $simulatedFound
    );

    recoveryCheck(
        'Lost acknowledgement with matching snapshot needs review',
        $lostButPresent['status'] ===
            'observed_after_lost_ack_requires_review' &&
        $lostButPresent[
            'fresh_checkpoint_observed'
        ] === true &&
        recoveryNeverSends($lostButPresent)
    );

    $errorButPresent = $assess(
        'failed_or_unknown',
        $simulatedFound
    );

    recoveryCheck(
        'Commit exception with matching snapshot needs review',
        $errorButPresent['status'] ===
            'observed_after_commit_error_requires_review' &&
        recoveryNeverSends($errorButPresent)
    );

    $missingObservation = $assess(
        'acknowledged',
        ['status' => 'read_unavailable']
    );

    recoveryCheck(
        'Unavailable independent read fails closed',
        $missingObservation['status'] ===
            'fresh_read_unavailable_requires_review' &&
        recoveryNeverSends($missingObservation)
    );

    $wrongAttempt = $simulatedRow;
    $wrongAttempt['attempt_id'] =
        stTryNowNewJournalAttemptId();

    $result = $assess(
        'acknowledged',
        [
            'status' => 'found',
            'row' => $wrongAttempt,
        ]
    );

    recoveryCheck(
        'Different persisted attempt rejected',
        $result['status'] ===
            'persisted_checkpoint_conflict_requires_review' &&
        recoveryNeverSends($result)
    );

    $wrongMac = $simulatedRow;
    $wrongMac['checkpoint_seal_mac'] =
        str_repeat('f', 64);

    $result = $assess(
        'acknowledged',
        [
            'status' => 'found',
            'row' => $wrongMac,
        ]
    );

    recoveryCheck(
        'Different persisted checkpoint HMAC rejected',
        $result['status'] ===
            'persisted_checkpoint_conflict_requires_review' &&
        recoveryNeverSends($result)
    );

    $wrongRecipient = $simulatedRow;
    $wrongRecipient['recipient_digest'] =
        hash('sha256', 'different-recipient');

    $result = $assess(
        'acknowledged',
        [
            'status' => 'found',
            'row' => $wrongRecipient,
        ]
    );

    recoveryCheck(
        'Changed recipient binding rejected',
        $result['status'] ===
            'persisted_checkpoint_conflict_requires_review' &&
        recoveryNeverSends($result)
    );

    $tamperedSeal = $seal;
    $tamperedSeal['seal_mac'] =
        str_repeat('0', 64);

    $result = $assess(
        'acknowledged',
        $simulatedFound,
        null,
        null,
        $tamperedSeal
    );

    recoveryCheck(
        'Unauthenticated expected checkpoint rejected',
        $result['status'] ===
            'invalid_expected_evidence' &&
        recoveryNeverSends($result)
    );

    recoveryCheck(
        'Writer transaction remains under caller control',
        $writer->inTransaction()
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * No COMMIT exists in this test.
     * Roll back all synthetic ledger changes.
     */
    if (
        $writer instanceof PDO &&
        $writer->inTransaction()
    ) {
        try {
            $writer->rollBack();

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
    $afterRollback = stTryNowObserveSyntheticLedgerCheckpoint(
        $observer,
        $reservation,
        $writerId,
        true
    );

    recoveryCheck(
        'Rolled-back checkpoint remains absent',
        $afterRollback['status'] === 'absent'
    );

    recoveryCheck(
        'Isolated ledger remains empty',
        (int) $observer->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    echo "PASS: All isolated commit recovery tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'FAIL: ' . $e->getMessage() . "\n"
    );
    exit(1);
}
