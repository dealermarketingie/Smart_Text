<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Protected real-COMMIT probe.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * --preflight: read-only, no transaction or COMMIT.
 * --commit: one guarded synthetic database COMMIT.
 *
 * Root supplies the exact fixture manifest via STDIN.
 * PHP never opens the root-owned manifest itself.
 *
 * No production writes, HTTP, Sendmode or retries.
 * A real COMMIT requires root-controlled cleanup.
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

$mode = $argv[1] ?? null;

if (
    $argc !== 2 ||
    !in_array(
        $mode,
        ['--preflight', '--commit'],
        true
    )
) {
    fwrite(STDERR, "FAIL: Invalid test mode.\n");
    exit(1);
}

$root = dirname(__DIR__);

require_once $root .
    '/lib/try-now-sms-attempt-ledger-protected-recovery.php';

function protectedCommitCheck(
    string $label,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $label\n");
        throw new RuntimeException(
            'Protected test verification failed.'
        );
    }

    echo "PASS: $label\n";
}

function protectedCommitNeverSends(
    array $result
): bool {
    return
        ($result['checkpoint_committed'] ?? null)
            === false &&
        ($result['review_required'] ?? null)
            === true &&
        ($result['safe_for_transport'] ?? null)
            === false &&
        ($result['automatic_send'] ?? null)
            === false &&
        ($result['automatic_retry'] ?? null)
            === false &&
        ($result['automatic_cleanup'] ?? null)
            === false;
}

/**
 * Validate the exact Step 503 fixture.
 *
 * This test is deliberately not reusable for other
 * customer identities or another manifest.
 */
function protectedCommitManifest(
    string $input
): array {
    $expectedHash =
        '5fe612d75fd0e931d80115d2bdefe1d2f86b266bd7cbe43acf5fb0cca0d954fc';

    if (
        $input === '' ||
        strlen($input) > 4096 ||
        substr($input, -1) !== "\n" ||
        strpos($input, "\r") !== false ||
        !hash_equals(
            $expectedHash,
            hash('sha256', $input)
        )
    ) {
        throw new RuntimeException(
            'Unexpected protected fixture fingerprint.'
        );
    }

    $patterns = [
        'campaign_id' => '/\A7907\z/D',
        'customer_id' => '/\A900001801\z/D',
        'attempt_id' => '/\A[0-9a-f]{32}\z/D',
        'journal_slot_id' => '/\A[0-9a-f]{64}\z/D',
        'recipient_digest' => '/\A[0-9a-f]{64}\z/D',
        'checkpoint_sequence' => '/\A1\z/D',
        'checkpoint_revision' => '/\A1\z/D',
        'checkpoint_event' => '/\Aprepared\z/D',
        'checkpoint_head_mac' => '/\A[0-9a-f]{64}\z/D',
        'checkpoint_seal_mac' => '/\A[0-9a-f]{64}\z/D',
    ];

    $lines = explode(
        "\n",
        substr($input, 0, -1)
    );

    if (count($lines) !== 10) {
        throw new RuntimeException(
            'Incorrect protected manifest length.'
        );
    }

    $manifest = [];
    $index = 0;

    foreach ($patterns as $name => $pattern) {
        $prefix = $name . '=';

        if (
            strpos(
                $lines[$index],
                $prefix
            ) !== 0
        ) {
            throw new RuntimeException(
                'Unexpected manifest field order.'
            );
        }

        $value = substr(
            $lines[$index],
            strlen($prefix)
        );

        if (preg_match($pattern, $value) !== 1) {
            throw new RuntimeException(
                'Invalid synthetic fixture field.'
            );
        }

        $manifest[$name] = $value;
        $index++;
    }

    if (hash_equals(
        $manifest['checkpoint_head_mac'],
        $manifest['checkpoint_seal_mac']
    )) {
        throw new RuntimeException(
            'Invalid checkpoint authentication values.'
        );
    }

    return $manifest;
}

/**
 * Connect only to the established isolated MariaDB
 * instance using its restricted test accounts.
 */
function protectedCommitConnection(
    array $config,
    string $role
): array {
    if (!in_array(
        $role,
        ['executor', 'reader'],
        true
    )) {
        throw new RuntimeException(
            'Unapproved database role.'
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
            'Invalid restricted account.'
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

    $identity = $pdo->query(
        'SELECT
            DATABASE() AS db_name,
            CURRENT_USER() AS db_user,
            CONNECTION_ID() AS session_id,
            @@port AS server_port,
            VERSION() AS server_version'
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($identity) ||
        $identity['db_name'] !== null ||
        $identity['db_user'] !==
            $username . '@172.17.0.1' ||
        (int) $identity['session_id'] <= 0 ||
        (int) $identity['server_port'] !== 3306 ||
        !is_string(
            $identity['server_version']
        ) ||
        strpos(
            $identity['server_version'],
            '11.4.'
        ) !== 0
    ) {
        throw new RuntimeException(
            'Unexpected isolated database identity.'
        );
    }

    protectedCommitCheck(
        ucfirst($role) . ' identity verified',
        true
    );

    return [
        'pdo' => $pdo,
        'session_id' =>
            (int) $identity['session_id'],
    ];
}

$writer = null;
$reader = null;
$freshReader = null;
$failure = null;

echo "=== Protected real-COMMIT probe: $mode ===\n";

try {
    protectedCommitCheck(
        'Production operations remain disabled',
        stTryNowLedgerRecoveryTestAllowed(true)
    );

    /*
     * Only the root wrapper can read the protected
     * fixture manifest and pass it through STDIN.
     */
    $input = stream_get_contents(STDIN, 4097);

    if (!is_string($input)) {
        throw new RuntimeException(
            'Protected manifest input unavailable.'
        );
    }

    $manifest = protectedCommitManifest($input);

    protectedCommitCheck(
        'Exact Step 503 manifest verified',
        true
    );

    /*
     * The COMMIT mode has independent guards.
     * The preflight does not require these.
     *
     * Environment variables are not sufficient
     * on their own: the exact root-owned manifest
     * is also mandatory.
     */
    if ($mode === '--commit') {
        protectedCommitCheck(
            'Explicit protected COMMIT guards verified',
            getenv(
                'ST_TRY_NOW_PROTECTED_REAL_COMMIT_TEST'
            ) ===
                'ISOLATED_PROTECTED_PROBE_ONCE_ONLY' &&
            getenv(
                'ST_TRY_NOW_PROBE_MANIFEST_SHA256'
            ) ===
                hash('sha256', $input) &&
            getenv(
                'ST_TRY_NOW_PROTECTED_COMMIT_WRAPPER'
            ) ===
                'ROOT_EXACT_MATCH_CLEANUP_REQUIRED'
        );
    }

    /*
     * Reconstruct evidence using the private keyring.
     * No hardcoded or caller-supplied HMAC keys.
     */
    $evidence =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001801,
            $manifest['attempt_id'],
            true
        );

    protectedCommitCheck(
        'Protected prepared evidence authenticated',
        $evidence['status'] ===
            'synthetic_prepared_test_only' &&
        $evidence['evidence_ready'] === true &&
        protectedCommitNeverSends($evidence)
    );

    $reservation = $evidence['reservation'];
    $history = $evidence['journal_records'];
    $seal = $evidence['head_seal'];

    $expected = [
        'campaign_id' => '7907',
        'customer_id' => '900001801',
        'attempt_id' =>
            $reservation['attempt_id'],
        'journal_slot_id' =>
            $reservation['journal_slot_id'],
        'recipient_digest' =>
            $reservation['recipient_digest'],
        'checkpoint_sequence' => '1',
        'checkpoint_revision' => '1',
        'checkpoint_event' => 'prepared',
        'checkpoint_head_mac' =>
            $seal['head_mac'],
        'checkpoint_seal_mac' =>
            $seal['seal_mac'],
    ];

    protectedCommitCheck(
        'Private-key evidence exactly matches manifest',
        $manifest === $expected
    );

    foreach ([
        'reserve' =>
            ['try_now_ledger_probe_reserve', 5],
        'initialize' =>
            ['try_now_ledger_probe_initialize', 7],
    ] as $operation => $rule) {
        $plan =
            stTryNowProtectedProbeProcedurePlan(
                $operation,
                $reservation,
                $history,
                $seal,
                true
            );

        protectedCommitCheck(
            'Protected ' . $operation .
                ' procedure plan validated',
            $plan['procedure_call_candidate'] ===
                true &&
            $plan['procedure'] === $rule[0] &&
            $plan['parameter_count'] === $rule[1] &&
            protectedCommitNeverSends($plan)
        );
    }

    $configFile =
        $root . '/db-ledger-security-probe.php';

    protectedCommitCheck(
        'Private database credentials protected',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    protectedCommitCheck(
        'Isolated database configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $writerConnection =
        protectedCommitConnection(
            $config,
            'executor'
        );

    $readerConnection =
        protectedCommitConnection(
            $config,
            'reader'
        );

    $writer = $writerConnection['pdo'];
    $reader = $readerConnection['pdo'];

    $writerId = $writerConnection['session_id'];

    protectedCommitCheck(
        'Writer and reader sessions independent',
        $writerId !==
            $readerConnection['session_id']
    );

    $table =
        'smarttext_try_now_test.' .
        'try_now_sms_ledger_security_probe';

    protectedCommitCheck(
        'Disposable probe initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    $initial =
        stTryNowAssessProtectedSyntheticProbeCommit(
            $reader,
            $reservation,
            $history,
            $seal,
            'not_attempted',
            $writerId,
            true
        );

    protectedCommitCheck(
        'Independent protected preflight observes absence',
        $initial['status'] ===
            'commit_not_attempted_test_only' &&
        $initial['protected_binding_verified'] ===
            true &&
        $initial['independent_reader_verified'] ===
            true &&
        $initial['observation_status'] === 'absent' &&
        protectedCommitNeverSends($initial)
    );

    if ($mode === '--preflight') {
        /*
         * Preflight ends here.
         *
         * No transaction, stored procedure or
         * COMMIT has been executed.
         */
        protectedCommitCheck(
            'No database transaction started',
            !$writer->inTransaction() &&
            !$reader->inTransaction()
        );

        echo "PASS: No database writes performed.\n";
        echo "PASS: Protected real-COMMIT preflight completed.\n";

    } else {
        /*
         * COMMIT MODE.
         *
         * Only the later root-controlled cleanup
         * wrapper may invoke this branch.
         */
        protectedCommitCheck(
            'Synthetic writer transaction started',
            $writer->beginTransaction()
        );

        foreach (
            ['reserve', 'initialize']
            as $operation
        ) {
            $result =
                stTryNowExecuteProtectedSyntheticLedgerProcedure(
                    $writer,
                    $operation,
                    $reservation,
                    $history,
                    $seal,
                    true
                );

            protectedCommitCheck(
                'Protected ' . $operation .
                    ' executed uncommitted',
                $result['status'] ===
                    'executed_uncommitted_test_only' &&
                $result[
                    'procedure_executed_in_transaction'
                ] === true &&
                $result[
                    'protected_binding_verified'
                ] === true &&
                protectedCommitNeverSends($result)
            );
        }

        $before =
            stTryNowObserveRestrictedSyntheticProbeCheckpoint(
                $reader,
                $reservation,
                $writerId,
                true
            );

        protectedCommitCheck(
            'Uncommitted checkpoint invisible to reader',
            $before['status'] === 'absent' &&
            $before[
                'independent_reader_verified'
            ] === true
        );

        /*
         * Exactly one real MariaDB COMMIT.
         *
         * There is no automatic retry.
         */
        protectedCommitCheck(
            'Protected synthetic COMMIT acknowledged',
            $writer->commit() === true
        );

        protectedCommitCheck(
            'Writer transaction completed',
            !$writer->inTransaction()
        );

        $writer = null;

        /*
         * Open a fresh reader session after COMMIT.
         */
        $freshConnection =
            protectedCommitConnection(
                $config,
                'reader'
            );

        $freshReader = $freshConnection['pdo'];

        protectedCommitCheck(
            'Fresh reader has independent session',
            $freshConnection['session_id'] !==
                $writerId &&
            $freshConnection['session_id'] !==
                $readerConnection['session_id']
        );

        $observed =
            stTryNowObserveRestrictedSyntheticProbeCheckpoint(
                $freshReader,
                $reservation,
                $writerId,
                true
            );

        protectedCommitCheck(
            'Committed checkpoint found independently',
            $observed['status'] === 'found' &&
            $observed[
                'independent_reader_verified'
            ] === true &&
            is_array($observed['row']) &&
            stTryNowLedgerRecoveryRowMatches(
                $observed['row'],
                $reservation,
                $seal
            )
        );

        $assess = static function (
            string $signal,
            array $expectedSeal
        ) use (
            $freshReader,
            $reservation,
            $history,
            $writerId
        ): array {
            return
                stTryNowAssessProtectedSyntheticProbeCommit(
                    $freshReader,
                    $reservation,
                    $history,
                    $expectedSeal,
                    $signal,
                    $writerId,
                    true
                );
        };

        $acknowledged = $assess(
            'acknowledged',
            $seal
        );

        protectedCommitCheck(
            'Acknowledged COMMIT classified test-only',
            $acknowledged['status'] ===
                'acknowledged_and_observed_test_only' &&
            $acknowledged[
                'fresh_checkpoint_observed'
            ] === true &&
            $acknowledged[
                'commit_acknowledgement_reported'
            ] === true &&
            $acknowledged[
                'protected_binding_verified'
            ] === true &&
            $acknowledged[
                'independent_reader_verified'
            ] === true &&
            protectedCommitNeverSends($acknowledged)
        );

        /*
         * These are simulated reports against
         * the genuinely committed fixture.
         * No actual network failure is induced.
         */
        $lost = $assess(
            'acknowledgement_lost',
            $seal
        );

        protectedCommitCheck(
            'Simulated lost acknowledgement requires review',
            $lost['status'] ===
                'observed_after_lost_ack_requires_review' &&
            $lost[
                'fresh_checkpoint_observed'
            ] === true &&
            protectedCommitNeverSends($lost)
        );

        $uncertain = $assess(
            'failed_or_unknown',
            $seal
        );

        protectedCommitCheck(
            'Simulated commit error requires review',
            $uncertain['status'] ===
                'observed_after_commit_error_requires_review' &&
            protectedCommitNeverSends($uncertain)
        );

        $tamperedSeal = $seal;
        $tamperedSeal['seal_mac'] =
            str_repeat('0', 64);

        $tampered = $assess(
            'acknowledged',
            $tamperedSeal
        );

        protectedCommitCheck(
            'Tampered protected recovery evidence rejected',
            $tampered['status'] ===
                'invalid_expected_evidence' &&
            protectedCommitNeverSends($tampered)
        );

        protectedCommitCheck(
            'One committed fixture available for root cleanup',
            (int) $freshReader->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn() === 1
        );

        echo "PASS: Protected real-COMMIT recovery checks completed.\n";
        echo "NOTE: Root-only exact-match cleanup is required.\n";
    }

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * Roll back any remaining active transaction.
     *
     * A completed COMMIT cannot be rolled back;
     * that fixture requires the separate root-only
     * exact-match cleanup procedure.
     */
    foreach (
        [$freshReader, $reader, $writer]
        as $connection
    ) {
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

if ($failure !== null) {
    fwrite(
        STDERR,
        "FAIL: Protected real-COMMIT test requires review.\n"
    );
    exit(1);
}

echo "PASS: Protected probe mode $mode completed.\n";
