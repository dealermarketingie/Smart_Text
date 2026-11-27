<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Restricted probe recovery tests.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * Uses synthetic journal records and HMAC keys.
 * Never commits a transaction.
 * All writes are rolled back.
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

require_once $root .
    '/lib/try-now-sms-attempt-ledger-probe-recovery.php';

function probeRecoveryCheck(
    string $label,
    bool $condition
): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function probeRecoveryNeverSends(
    array $result
): bool {
    return
        ($result['review_required'] ?? null)
            === true &&
        ($result['checkpoint_committed'] ?? null)
            === false &&
        ($result['safe_for_transport'] ?? null)
            === false &&
        ($result['automatic_send'] ?? null)
            === false &&
        ($result['automatic_retry'] ?? null)
            === false &&
        ($result['automatic_cleanup'] ?? null)
            === false;
}

function probeRecoveryExpect(
    string $label,
    array $result,
    string $expectedStatus
): void {
    probeRecoveryCheck(
        $label,
        ($result['status'] ?? null) ===
            $expectedStatus &&
        probeRecoveryNeverSends($result)
    );
}

function probeRecoveryConnection(
    array $config,
    string $role
): PDO {
    if (!in_array(
        $role,
        ['executor', 'reader'],
        true
    )) {
        throw new RuntimeException(
            'Invalid database role'
        );
    }

    $account = $config[$role] ?? null;
    $username = 'try_now_ledger_' . $role;

    if (
        !is_array($account) ||
        ($account['username'] ?? null) !== $username ||
        !is_string(
            $account['password'] ?? null
        ) ||
        $account['password'] === ''
    ) {
        throw new RuntimeException(
            'Invalid private account configuration'
        );
    }

    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=33080;charset=utf8mb4',
        $username,
        $account['password'],
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    probeRecoveryCheck(
        ucfirst($role) . ' identity verified',
        $pdo->query(
            'SELECT CURRENT_USER()'
        )->fetchColumn() ===
            $username . '@172.17.0.1'
    );

    return $pdo;
}

$writer = null;
$reader = null;
$failure = null;

try {
    probeRecoveryCheck(
        'Production operations remain disabled',
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false
    );

    $configFile =
        $root . '/db-ledger-security-probe.php';

    probeRecoveryCheck(
        'Private configuration has safe permissions',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    probeRecoveryCheck(
        'Isolated database configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $writer = probeRecoveryConnection(
        $config,
        'executor'
    );

    $reader = probeRecoveryConnection(
        $config,
        'reader'
    );

    $table =
        'smarttext_try_now_test.' .
        'try_now_sms_ledger_security_probe';

    probeRecoveryCheck(
        'Disposable probe initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    $writerId = (int) $writer->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    $readerId = (int) $reader->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    probeRecoveryCheck(
        'Independent session identities verified',
        $writerId > 0 &&
        $readerId > 0 &&
        $writerId !== $readerId
    );

    /*
     * Entire fixture is synthetic.
     * Do not use production journal keys.
     */
    $namespace = 'synthetic-recovery-probe';
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);
    $sealKey = str_repeat('S', 32);

    $campaignId = 7907;
    $customerId = 900000951;
    $attemptId = bin2hex(random_bytes(16));

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
            'synthetic-recipient:' . $attemptId,
            str_repeat('D', 32)
        ),
    ];

    $record = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        1,
        'prepared',
        '2026-11-26T10:00:00Z',
        str_repeat('0', 64),
        $recordKey
    );

    $history = [$record];

    $seal = stTryNowBuildJournalHeadSeal(
        $history,
        $recordKey,
        $sealKey
    );

    $observe = static function (
        PDO $connection,
        int $sessionId,
        bool $allow = true
    ) use ($reservation): array {
        return
            stTryNowObserveRestrictedSyntheticProbeCheckpoint(
                $connection,
                $reservation,
                $sessionId,
                $allow
            );
    };

    $assess = static function (
        string $signal,
        ?PDO $connection = null,
        ?array $overrideSeal = null
    ) use (
        $reader,
        $reservation,
        $history,
        $seal,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey,
        $writerId
    ): array {
        return
            stTryNowAssessRestrictedSyntheticProbeCommit(
                $connection ?? $reader,
                $reservation,
                $history,
                $overrideSeal ?? $seal,
                $namespace,
                $bindingKey,
                $recordKey,
                $sealKey,
                $signal,
                $writerId,
                true
            );
    };

    probeRecoveryCheck(
        'Recovery observation disabled by default',
        $observe(
            $reader,
            $writerId,
            false
        )['status'] === 'disabled'
    );

    probeRecoveryCheck(
        'Invalid writer session ID rejected',
        $observe(
            $reader,
            0
        )['status'] ===
            'invalid_observation_request'
    );

    probeRecoveryCheck(
        'Reader cannot be mistaken for writer',
        $observe(
            $reader,
            $readerId
        )['status'] ===
            'untrusted_observer'
    );

    probeRecoveryCheck(
        'Executor cannot impersonate reader',
        $observe(
            $writer,
            $writerId
        )['status'] ===
            'untrusted_observer'
    );

    probeRecoveryCheck(
        'Reader transaction started for negative test',
        $reader->beginTransaction()
    );

    probeRecoveryCheck(
        'Reader inside transaction rejected',
        $observe(
            $reader,
            $writerId
        )['status'] ===
            'untrusted_observer'
    );

    $reader->rollBack();

    $initial = $observe(
        $reader,
        $writerId
    );

    probeRecoveryCheck(
        'Verified independent reader sees absent row',
        $initial['status'] === 'absent' &&
        $initial['row'] === null &&
        $initial[
            'independent_reader_verified'
        ] === true
    );

    probeRecoveryExpect(
        'Unattempted commit remains unconfirmed',
        $assess('not_attempted'),
        'commit_not_attempted_test_only'
    );

    probeRecoveryExpect(
        'Invalid commit signal rejected',
        $assess('unexpected'),
        'invalid_commit_signal'
    );

    /*
     * Create one synthetic uncommitted
     * reservation and prepared checkpoint.
     */
    probeRecoveryCheck(
        'Writer transaction started',
        $writer->beginTransaction()
    );

    $execute = static function (
        string $operation
    ) use (
        $writer,
        $reservation,
        $history,
        $seal,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    ): array {
        return
            stTryNowExecuteRestrictedSyntheticLedgerProcedure(
                $writer,
                $operation,
                $reservation,
                $history,
                $seal,
                $namespace,
                $bindingKey,
                $recordKey,
                $sealKey,
                true
            );
    };

    $reserved = $execute('reserve');

    probeRecoveryCheck(
        'Synthetic reservation executed uncommitted',
        $reserved['status'] ===
            'executed_uncommitted_test_only' &&
        $reserved[
            'procedure_executed_in_transaction'
        ] === true &&
        probeRecoveryNeverSends($reserved)
    );

    $initialized = $execute('initialize');

    probeRecoveryCheck(
        'Prepared checkpoint executed uncommitted',
        $initialized['status'] ===
            'executed_uncommitted_test_only' &&
        $initialized[
            'procedure_executed_in_transaction'
        ] === true &&
        probeRecoveryNeverSends($initialized)
    );

    $during = $observe(
        $reader,
        $writerId
    );

    probeRecoveryCheck(
        'Uncommitted checkpoint invisible to reader',
        $during['status'] === 'absent' &&
        $during[
            'independent_reader_verified'
        ] === true
    );

    /*
     * All commit signals below are simulated.
     * No COMMIT is executed.
     */
    probeRecoveryExpect(
        'Acknowledged but absent requires review',
        $assess('acknowledged'),
        'acknowledged_but_absent_requires_review'
    );

    probeRecoveryExpect(
        'Lost acknowledgement remains uncertain',
        $assess('acknowledgement_lost'),
        'commit_outcome_unknown_requires_review'
    );

    probeRecoveryExpect(
        'Failed or unknown commit remains uncertain',
        $assess('failed_or_unknown'),
        'commit_outcome_unknown_requires_review'
    );

    $tamperedSeal = $seal;
    $tamperedSeal['seal_mac'] =
        str_repeat('0', 64);

    probeRecoveryExpect(
        'Tampered authenticated checkpoint rejected',
        $assess(
            'acknowledged',
            null,
            $tamperedSeal
        ),
        'invalid_expected_evidence'
    );

    probeRecoveryExpect(
        'Untrusted reader fails closed',
        $assess(
            'acknowledged',
            $writer
        ),
        'fresh_read_unavailable_requires_review'
    );

    probeRecoveryCheck(
        'Reader transaction started for recovery denial',
        $reader->beginTransaction()
    );

    $blocked = $assess('acknowledged');

    probeRecoveryExpect(
        'Recovery within reader transaction rejected',
        $blocked,
        'fresh_read_unavailable_requires_review'
    );

    probeRecoveryCheck(
        'Denied reader observation marked untrusted',
        ($blocked['observation_status'] ?? null) ===
            'untrusted_observer' &&
        ($blocked[
            'independent_reader_verified'
        ] ?? null) === false
    );

    $reader->rollBack();

    probeRecoveryCheck(
        'Writer transaction remains under caller control',
        $writer->inTransaction()
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * No COMMIT exists anywhere in this test.
     */
    foreach ([$reader, $writer] as $connection) {
        if (
            $connection instanceof PDO &&
            $connection->inTransaction()
        ) {
            try {
                $connection->rollBack();
            } catch (Throwable $e) {
                if ($failure === null) {
                    $failure = $e;
                }
            }
        }
    }
}

try {
    if (!$reader instanceof PDO) {
        throw new RuntimeException(
            'Independent reader unavailable'
        );
    }

    $after = $observe(
        $reader,
        $writerId
    );

    probeRecoveryCheck(
        'Rolled-back reservation remains absent',
        $after['status'] === 'absent' &&
        $after[
            'independent_reader_verified'
        ] === true
    );

    probeRecoveryCheck(
        'Disposable probe contains zero rows',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

} catch (Throwable $e) {
    if ($failure === null) {
        $failure = $e;
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . $failure->getMessage() . "\n"
    );
    exit(1);
}

echo "PASS: All restricted probe recovery tests.\n";
echo "PASS: Step 469 completed.\n";