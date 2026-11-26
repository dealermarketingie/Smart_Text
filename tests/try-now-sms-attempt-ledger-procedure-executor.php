<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Restricted executor integration.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * Synthetic journal evidence.
 * Restricted MariaDB connections.
 * No COMMIT, no Sendmode, no production writes.
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
    '/lib/try-now-sms-attempt-ledger-procedure-executor.php';

function executorCheck(
    string $label,
    bool $condition
): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function executorNeverSends(array $result): bool
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

function executorExpect(
    string $label,
    array $result,
    string $status,
    bool $executed = false
): void {
    executorCheck(
        $label,
        ($result['status'] ?? null) === $status &&
        ($result['procedure_executed_in_transaction']
            ?? null) === $executed &&
        executorNeverSends($result)
    );
}

function executorConnect(
    array $config,
    string $role
): PDO {
    $account = $config[$role] ?? null;
    $username = 'try_now_ledger_' . $role;

    if (
        !in_array($role, ['executor', 'reader'], true) ||
        !is_array($account) ||
        ($account['username'] ?? null) !== $username ||
        !is_string($account['password'] ?? null) ||
        $account['password'] === ''
    ) {
        throw new RuntimeException(
            'Invalid private database account'
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

    executorCheck(
        ucfirst($role) . ' identity verified',
        $pdo->query(
            'SELECT CURRENT_USER()'
        )->fetchColumn() ===
            $username . '@172.17.0.1'
    );

    return $pdo;
}

function executorFixture(
    int $customerId,
    string $namespace,
    string $bindingKey,
    string $recordKey,
    string $sealKey
): array {
    $attemptId = bin2hex(random_bytes(16));
    $campaignId = 7907;

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

    $first = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        1,
        'prepared',
        '2026-11-26T10:00:00Z',
        str_repeat('0', 64),
        $recordKey
    );

    $started = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        2,
        'transport_started',
        '2026-11-26T10:00:01Z',
        $first['mac'],
        $recordKey
    );

    $provider = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        3,
        'provider_unknown',
        '2026-11-26T10:00:02Z',
        $started['mac'],
        $recordKey
    );

    $cancelled = stTryNowBuildJournalRecord(
        $attemptId,
        $customerId,
        $campaignId,
        2,
        'cancelled_before_transport',
        '2026-11-26T10:00:01Z',
        $first['mac'],
        $recordKey
    );

    $histories = [
        'prepared' => [$first],
        'started' => [$first, $started],
        'provider' => [$first, $started, $provider],
        'cancelled' => [$first, $cancelled],
    ];

    $stages = [];

    foreach ($histories as $name => $records) {
        $stages[$name] = [
            'records' => $records,
            'seal' => stTryNowBuildJournalHeadSeal(
                $records,
                $recordKey,
                $sealKey
            ),
        ];
    }

    return [
        'reservation' => $reservation,
        'stages' => $stages,
    ];
}

$executor = null;
$reader = null;
$failure = null;

try {
    executorCheck(
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

    executorCheck(
        'Private configuration has mode 600',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    executorCheck(
        'Isolated database configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $executor = executorConnect(
        $config,
        'executor'
    );

    $reader = executorConnect(
        $config,
        'reader'
    );

    $table =
        'smarttext_try_now_test.' .
        'try_now_sms_ledger_security_probe';

    executorCheck(
        'Disposable ledger initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    /*
     * Only synthetic keys.
     * Never load production journal keys here.
     */
    $namespace = 'synthetic-ledger-executor';
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);
    $sealKey = str_repeat('S', 32);

    $normal = executorFixture(
        900000901,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    );

    $cancelled = executorFixture(
        900000902,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    );

    /*
     * The adapter always regenerates the plan.
     * Callers cannot provide their own parameter
     * arrays or procedure names.
     */
    $invoke = static function (
        PDO $connection,
        string $operation,
        array $fixture,
        string $stage,
        bool $allow = true
    ) use (
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    ): array {
        $history = $fixture['stages'][$stage];

        return stTryNowExecuteRestrictedSyntheticLedgerProcedure(
            $connection,
            $operation,
            $fixture['reservation'],
            $history['records'],
            $history['seal'],
            $namespace,
            $bindingKey,
            $recordKey,
            $sealKey,
            $allow
        );
    };

    executorExpect(
        'Executor disabled by default',
        $invoke(
            $executor,
            'reserve',
            $normal,
            'prepared',
            false
        ),
        'disabled'
    );

    executorExpect(
        'Active transaction required',
        $invoke(
            $executor,
            'reserve',
            $normal,
            'prepared'
        ),
        'transaction_required'
    );

    /*
     * An authenticated reader still cannot act
     * as the restricted procedure executor.
     */
    executorCheck(
        'Reader transaction started for negative test',
        $reader->beginTransaction()
    );

    executorExpect(
        'Reader cannot impersonate executor',
        $invoke(
            $reader,
            'reserve',
            $normal,
            'prepared'
        ),
        'unauthorised_connection'
    );

    $reader->rollBack();

    executorCheck(
        'Executor transaction started',
        $executor->beginTransaction()
    );

    executorExpect(
        'Unsupported operation rejected',
        $invoke(
            $executor,
            'delete',
            $normal,
            'prepared'
        ),
        'plan_unsupported_operation'
    );

    $changedSlot = $normal;
    $changedSlot['reservation']['journal_slot_id'] =
        str_repeat('0', 64);

    executorExpect(
        'Changed journal binding rejected',
        $invoke(
            $executor,
            'reserve',
            $changedSlot,
            'prepared'
        ),
        'plan_journal_slot_mismatch'
    );

    $alteredSeal = $normal;
    $alteredSeal['stages']['prepared']['seal']['seal_mac'] =
        str_repeat('0', 64);

    executorExpect(
        'Unauthenticated checkpoint rejected',
        $invoke(
            $executor,
            'initialize',
            $alteredSeal,
            'prepared'
        ),
        'plan_invalid_authenticated_journal'
    );

    $wrongKeyResult =
        stTryNowExecuteRestrictedSyntheticLedgerProcedure(
            $executor,
            'reserve',
            $normal['reservation'],
            $normal['stages']['prepared']['records'],
            $normal['stages']['prepared']['seal'],
            $namespace,
            $bindingKey,
            str_repeat('X', 32),
            $sealKey,
            true
        );

    executorExpect(
        'Incorrect journal authentication key rejected',
        $wrongKeyResult,
        'plan_invalid_authenticated_journal'
    );

    executorCheck(
        'Invalid plans caused no database writes',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    /*
     * Execute the authenticated reservation.
     */
    executorExpect(
        'Authenticated reservation executed',
        $invoke(
            $executor,
            'reserve',
            $normal,
            'prepared'
        ),
        'executed_uncommitted_test_only',
        true
    );

    executorExpect(
        'Duplicate reservation fails closed',
        $invoke(
            $executor,
            'reserve',
            $normal,
            'prepared'
        ),
        'reservation_exists_requires_review'
    );

    executorExpect(
        'Prepared checkpoint initialized',
        $invoke(
            $executor,
            'initialize',
            $normal,
            'prepared'
        ),
        'executed_uncommitted_test_only',
        true
    );

    executorExpect(
        'Repeated initialization fails closed',
        $invoke(
            $executor,
            'initialize',
            $normal,
            'prepared'
        ),
        'procedure_rejected_requires_review'
    );

    executorExpect(
        'Authenticated transport checkpoint executed',
        $invoke(
            $executor,
            'advance',
            $normal,
            'started'
        ),
        'executed_uncommitted_test_only',
        true
    );

    executorExpect(
        'Stale transport checkpoint rejected',
        $invoke(
            $executor,
            'advance',
            $normal,
            'started'
        ),
        'procedure_rejected_requires_review'
    );

    executorExpect(
        'Authenticated terminal checkpoint executed',
        $invoke(
            $executor,
            'advance',
            $normal,
            'provider'
        ),
        'executed_uncommitted_test_only',
        true
    );

    executorExpect(
        'Completed attempt cannot restart',
        $invoke(
            $executor,
            'advance',
            $normal,
            'started'
        ),
        'procedure_rejected_requires_review'
    );

    /*
     * Independent cancellation pathway.
     */
    executorExpect(
        'Cancellation attempt reserved',
        $invoke(
            $executor,
            'reserve',
            $cancelled,
            'prepared'
        ),
        'executed_uncommitted_test_only',
        true
    );

    executorExpect(
        'Cancellation attempt initialized',
        $invoke(
            $executor,
            'initialize',
            $cancelled,
            'prepared'
        ),
        'executed_uncommitted_test_only',
        true
    );

    executorExpect(
        'Authenticated cancellation executed',
        $invoke(
            $executor,
            'advance',
            $cancelled,
            'cancelled'
        ),
        'executed_uncommitted_test_only',
        true
    );

    executorExpect(
        'Cancelled attempt cannot start transport',
        $invoke(
            $executor,
            'advance',
            $cancelled,
            'started'
        ),
        'procedure_rejected_requires_review'
    );

    executorCheck(
        'Uncommitted records invisible to reader',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    executorCheck(
        'Caller retains transaction ownership',
        $executor->inTransaction()
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * Never commit synthetic attempt evidence.
     */
    foreach ([$reader, $executor] as $connection) {
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

    executorCheck(
        'All synthetic attempts rolled back',
        (int) $reader->query(
            'SELECT COUNT(*)
             FROM smarttext_try_now_test.' .
            'try_now_sms_ledger_security_probe'
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

echo "PASS: All authenticated procedure execution tests.\n";
echo "PASS: Step 463 completed.\n";
