<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Restricted real-COMMIT probe.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * --preflight : read-only; no transaction or COMMIT
 * --commit    : one synthetic prepared checkpoint
 *
 * The root-owned manifest is supplied via STDIN.
 * No production database, HTTP or Sendmode access.
 *
 * The COMMIT mode requires a separate environment
 * guard and must run only inside the root-controlled
 * cleanup wrapper prepared for this fixture.
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
    !in_array($mode, [
        '--preflight',
        '--commit',
    ], true)
) {
    fwrite(STDERR, "FAIL: Invalid test mode.\n");
    exit(1);
}

$root = dirname(__DIR__);

require_once $root .
    '/lib/try-now-sms-attempt-ledger-probe-recovery.php';

function realProbeCheck(
    string $label,
    bool $condition
): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function realProbeNeverSends(array $result): bool
{
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

function realProbeConnection(
    array $config,
    string $role
): array {
    if (!in_array(
        $role,
        ['executor', 'reader'],
        true
    )) {
        throw new RuntimeException('Invalid database role.');
    }

    $account = $config[$role] ?? null;
    $username = 'try_now_ledger_' . $role;

    if (
        !is_array($account) ||
        ($account['username'] ?? null) !== $username ||
        !is_string($account['password'] ?? null) ||
        $account['password'] === ''
    ) {
        throw new RuntimeException(
            'Invalid restricted database account.'
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
        !is_string($identity['server_version']) ||
        strpos(
            $identity['server_version'],
            '11.4.'
        ) !== 0
    ) {
        throw new RuntimeException(
            'Unexpected MariaDB connection identity.'
        );
    }

    realProbeCheck(
        ucfirst($role) . ' identity verified',
        true
    );

    return [
        'pdo' => $pdo,
        'session_id' => (int) $identity['session_id'],
    ];
}

/**
 * Parse exactly the ten ordered manifest fields.
 * The fixture is always synthetic.
 */
function realProbeManifest(string $input): array
{
    if (
        $input === '' ||
        strlen($input) > 4096 ||
        substr($input, -1) !== "\n" ||
        strpos($input, "\r") !== false
    ) {
        throw new RuntimeException(
            'Invalid fixture manifest encoding.'
        );
    }

    $lines = explode(
        "\n",
        substr($input, 0, -1)
    );

    $patterns = [
        'campaign_id' =>
            '/\A7907\z/D',
        'customer_id' =>
            '/\A900001[0-9]{3}\z/D',
        'attempt_id' =>
            '/\A[0-9a-f]{32}\z/D',
        'journal_slot_id' =>
            '/\A[0-9a-f]{64}\z/D',
        'recipient_digest' =>
            '/\A[0-9a-f]{64}\z/D',
        'checkpoint_sequence' =>
            '/\A1\z/D',
        'checkpoint_revision' =>
            '/\A1\z/D',
        'checkpoint_event' =>
            '/\Aprepared\z/D',
        'checkpoint_head_mac' =>
            '/\A[0-9a-f]{64}\z/D',
        'checkpoint_seal_mac' =>
            '/\A[0-9a-f]{64}\z/D',
    ];

    if (count($lines) !== count($patterns)) {
        throw new RuntimeException(
            'Incorrect fixture manifest length.'
        );
    }

    $manifest = [];
    $i = 0;

    foreach ($patterns as $key => $pattern) {
        $prefix = $key . '=';
        $line = $lines[$i];

        if (strpos($line, $prefix) !== 0) {
            throw new RuntimeException(
                'Unexpected manifest field order.'
            );
        }

        $value = substr($line, strlen($prefix));

        if (preg_match($pattern, $value) !== 1) {
            throw new RuntimeException(
                'Invalid synthetic manifest field.'
            );
        }

        $manifest[$key] = $value;
        $i++;
    }

    if (
        hash_equals(
            $manifest['checkpoint_head_mac'],
            $manifest['checkpoint_seal_mac']
        )
    ) {
        throw new RuntimeException(
            'Identical checkpoint HMAC values.'
        );
    }

    return $manifest;
}

$writer = null;
$reader = null;
$freshReader = null;
$failure = null;

echo "=== Restricted real-COMMIT probe: $mode ===\n";

try {
    realProbeCheck(
        'Production operations remain disabled',
        stTryNowLedgerRecoveryTestAllowed(true)
    );

    /*
     * Root passes the manifest through STDIN.
     * PHP never opens the root-owned manifest.
     */
    $input = stream_get_contents(STDIN, 4097);

    if (!is_string($input)) {
        throw new RuntimeException(
            'Fixture input unavailable.'
        );
    }

    $manifest = realProbeManifest($input);

    realProbeCheck(
        'Strict synthetic manifest validated',
        true
    );

    if ($mode === '--commit') {
        realProbeCheck(
            'Explicit isolated COMMIT guard verified',
            getenv('ST_TRY_NOW_REAL_COMMIT_TEST') ===
                'ISOLATED_PROBE_ONE_FIXTURE_ONLY' &&
            getenv('ST_TRY_NOW_PROBE_MANIFEST_SHA256') ===
                hash('sha256', $input)
        );
    }

    /*
     * Reconstruct the original Step 475 evidence.
     * These are synthetic keys, not production keys.
     */
    $namespace = 'synthetic-real-commit-probe';
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);
    $sealKey = str_repeat('S', 32);
    $recipientKey = str_repeat('D', 32);

    $campaignId = 7907;
    $customerId = (int) $manifest['customer_id'];
    $attemptId = $manifest['attempt_id'];

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
            $recipientKey
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

    $verified = stTryNowVerifyJournalHeadSeal(
        $history,
        $seal,
        $recordKey,
        $sealKey
    );

    realProbeCheck(
        'Authenticated journal evidence verified',
        $verified['valid'] === true
    );

    $expected = [
        'campaign_id' => (string) $campaignId,
        'customer_id' => (string) $customerId,
        'attempt_id' => $attemptId,
        'journal_slot_id' =>
            $reservation['journal_slot_id'],
        'recipient_digest' =>
            $reservation['recipient_digest'],
        'checkpoint_sequence' => '1',
        'checkpoint_revision' => '1',
        'checkpoint_event' => 'prepared',
        'checkpoint_head_mac' => $seal['head_mac'],
        'checkpoint_seal_mac' => $seal['seal_mac'],
    ];

    realProbeCheck(
        'Reconstructed evidence exactly matches manifest',
        $manifest === $expected
    );

    $configFile =
        $root . '/db-ledger-security-probe.php';

    realProbeCheck(
        'Private credentials remain protected',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    realProbeCheck(
        'Isolated configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $writerConnection = realProbeConnection(
        $config,
        'executor'
    );

    $readerConnection = realProbeConnection(
        $config,
        'reader'
    );

    $writer = $writerConnection['pdo'];
    $reader = $readerConnection['pdo'];

    $writerId = $writerConnection['session_id'];
    $readerId = $readerConnection['session_id'];

    realProbeCheck(
        'Writer and reader sessions are independent',
        $writerId !== $readerId
    );

    $table =
        'smarttext_try_now_test.' .
        'try_now_sms_ledger_security_probe';

    realProbeCheck(
        'Disposable probe initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    $initial =
        stTryNowObserveRestrictedSyntheticProbeCheckpoint(
            $reader,
            $reservation,
            $writerId,
            true
        );

    realProbeCheck(
        'Restricted reader sees no existing reservation',
        $initial['status'] === 'absent' &&
        $initial['independent_reader_verified'] === true
    );

    /*
     * The preflight stops here.
     * No transaction or stored procedure is called.
     */
    if ($mode === '--preflight') {
        echo "PASS: No transaction started.\n";
        echo "PASS: No database writes performed.\n";
        echo "PASS: Real-COMMIT fixture preflight completed.\n";

    } else {
        /*
         * BEGIN is controlled by this test.
         * The executor itself never controls
         * transactions.
         */
        realProbeCheck(
            'Synthetic writer transaction started',
            $writer->beginTransaction()
        );

        $invoke = static function (
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

        $reserved = $invoke('reserve');

        realProbeCheck(
            'Authenticated reservation executed uncommitted',
            $reserved['status'] ===
                'executed_uncommitted_test_only' &&
            $reserved[
                'procedure_executed_in_transaction'
            ] === true &&
            realProbeNeverSends($reserved)
        );

        $initialized = $invoke('initialize');

        realProbeCheck(
            'Authenticated checkpoint initialized uncommitted',
            $initialized['status'] ===
                'executed_uncommitted_test_only' &&
            $initialized[
                'procedure_executed_in_transaction'
            ] === true &&
            realProbeNeverSends($initialized)
        );

        $beforeCommit =
            stTryNowObserveRestrictedSyntheticProbeCheckpoint(
                $reader,
                $reservation,
                $writerId,
                true
            );

        realProbeCheck(
            'Uncommitted checkpoint invisible to reader',
            $beforeCommit['status'] === 'absent' &&
            $beforeCommit[
                'independent_reader_verified'
            ] === true
        );

        /*
         * ONE real COMMIT occurs here.
         *
         * No second fixture, transport call or
         * automatic retry is performed.
         */
        realProbeCheck(
            'Synthetic MariaDB COMMIT acknowledged',
            $writer->commit() === true
        );

        realProbeCheck(
            'Writer transaction completed',
            !$writer->inTransaction()
        );

        /*
         * Release the original writer session.
         * Reconnect a new restricted reader after
         * the successful COMMIT.
         */
        $writer = null;

        $freshConnection = realProbeConnection(
            $config,
            'reader'
        );

        $freshReader = $freshConnection['pdo'];

        realProbeCheck(
            'Fresh reader uses a separate session',
            $freshConnection['session_id'] !== $writerId
        );

        $observation =
            stTryNowObserveRestrictedSyntheticProbeCheckpoint(
                $freshReader,
                $reservation,
                $writerId,
                true
            );

        realProbeCheck(
            'Committed checkpoint found independently',
            $observation['status'] === 'found' &&
            $observation[
                'independent_reader_verified'
            ] === true &&
            is_array($observation['row']) &&
            stTryNowLedgerRecoveryRowMatches(
                $observation['row'],
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
            $namespace,
            $bindingKey,
            $recordKey,
            $sealKey,
            $writerId
        ): array {
            return
                stTryNowAssessRestrictedSyntheticProbeCommit(
                    $freshReader,
                    $reservation,
                    $history,
                    $expectedSeal,
                    $namespace,
                    $bindingKey,
                    $recordKey,
                    $sealKey,
                    $signal,
                    $writerId,
                    true
                );
        };

        $acknowledged = $assess(
            'acknowledged',
            $seal
        );

        realProbeCheck(
            'Acknowledged real COMMIT classified test-only',
            $acknowledged['status'] ===
                'acknowledged_and_observed_test_only' &&
            $acknowledged[
                'fresh_checkpoint_observed'
            ] === true &&
            $acknowledged[
                'commit_acknowledgement_reported'
            ] === true &&
            $acknowledged[
                'independent_reader_verified'
            ] === true &&
            realProbeNeverSends($acknowledged)
        );

        /*
         * The following signals are simulated
         * against the same genuinely committed row.
         *
         * They do not simulate an actual network
         * failure during COMMIT.
         */
        $lost = $assess(
            'acknowledgement_lost',
            $seal
        );

        realProbeCheck(
            'Simulated lost acknowledgement requires review',
            $lost['status'] ===
                'observed_after_lost_ack_requires_review' &&
            $lost['fresh_checkpoint_observed'] === true &&
            realProbeNeverSends($lost)
        );

        $uncertain = $assess(
            'failed_or_unknown',
            $seal
        );

        realProbeCheck(
            'Simulated commit error requires review',
            $uncertain['status'] ===
                'observed_after_commit_error_requires_review' &&
            realProbeNeverSends($uncertain)
        );

        $tamperedSeal = $seal;
        $tamperedSeal['seal_mac'] =
            str_repeat('0', 64);

        $tampered = $assess(
            'acknowledged',
            $tamperedSeal
        );

        realProbeCheck(
            'Tampered recovery evidence rejected',
            $tampered['status'] ===
                'invalid_expected_evidence' &&
            realProbeNeverSends($tampered)
        );

        realProbeCheck(
            'Committed fixture remains available for root cleanup',
            (int) $freshReader->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn() === 1
        );

        echo "PASS: Real-COMMIT recovery checks completed.\n";
        echo "NOTE: Root-only exact-match cleanup is required.\n";
    }

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * Roll back any still-active transaction.
     * A completed COMMIT cannot be rolled back;
     * its fixture requires root-only cleanup.
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
        'FAIL: ' . $failure->getMessage() . "\n"
    );
    exit(1);
}

echo "PASS: Restricted probe test mode $mode completed.\n";
