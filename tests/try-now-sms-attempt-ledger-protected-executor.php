<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Protected executor regression.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * All identities and journal evidence are synthetic.
 * Every database change is rolled back.
 *
 * No COMMIT, customer writes, HTTP or Sendmode.
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
    '/lib/try-now-sms-attempt-ledger-protected-executor.php';

function protectedExecutionCheck(
    string $label,
    bool $condition
): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function protectedExecutionNeverSends(
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

function protectedExecutionExpect(
    string $label,
    array $result,
    string $status,
    bool $executed = false
): void {
    protectedExecutionCheck(
        $label,
        ($result['status'] ?? null) === $status &&
        ($result[
            'procedure_executed_in_transaction'
        ] ?? null) === $executed &&
        ($result[
            'protected_binding_verified'
        ] ?? null) === $executed &&
        protectedExecutionNeverSends($result)
    );
}

function protectedExecutionConnection(
    array $config,
    string $role
): PDO {
    if (!in_array(
        $role,
        ['executor', 'reader'],
        true
    )) {
        throw new RuntimeException(
            'Unexpected database role.'
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
            'Invalid private database configuration.'
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

    protectedExecutionCheck(
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
    protectedExecutionCheck(
        'Production operations remain disabled',
        stTryNowLedgerRecoveryTestAllowed(true)
    );

    $keyringFile =
        '/var/www/vhosts/smarttext.com/private/' .
        'try-now-ledger-security-probe-keys.json';

    protectedExecutionCheck(
        'Private cryptographic keyring protected',
        is_file($keyringFile) &&
        !is_link($keyringFile) &&
        (fileperms($keyringFile) & 0777) === 0600
    );

    $configFile =
        $root . '/db-ledger-security-probe.php';

    protectedExecutionCheck(
        'Private database configuration protected',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    protectedExecutionCheck(
        'Isolated MariaDB configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $writer = protectedExecutionConnection(
        $config,
        'executor'
    );

    $reader = protectedExecutionConnection(
        $config,
        'reader'
    );

    $table =
        'smarttext_try_now_test.' .
        'try_now_sms_ledger_security_probe';

    protectedExecutionCheck(
        'Disposable probe initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    /*
     * Prepare one synthetic reservation using
     * the protected private keyring.
     *
     * The test never needs the raw key values.
     */
    $attemptId = bin2hex(random_bytes(16));

    $prepared =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001501,
            $attemptId,
            true
        );

    protectedExecutionCheck(
        'Protected synthetic evidence constructed',
        $prepared['status'] ===
            'synthetic_prepared_test_only' &&
        $prepared['evidence_ready'] === true &&
        is_array($prepared['reservation']) &&
        count($prepared['journal_records']) === 1 &&
        is_array($prepared['head_seal']) &&
        protectedExecutionNeverSends($prepared)
    );

    $reservation = $prepared['reservation'];
    $records = $prepared['journal_records'];
    $seal = $prepared['head_seal'];

    $invoke = static function (
        PDO $connection,
        string $operation,
        array $r,
        array $history,
        array $head,
        bool $allow = true
    ): array {
        return
            stTryNowExecuteProtectedSyntheticLedgerProcedure(
                $connection,
                $operation,
                $r,
                $history,
                $head,
                $allow
            );
    };

    protectedExecutionExpect(
        'Protected executor disabled by default',
        $invoke(
            $writer,
            'reserve',
            $reservation,
            $records,
            $seal,
            false
        ),
        'disabled'
    );

    protectedExecutionExpect(
        'Transaction required before reservation',
        $invoke(
            $writer,
            'reserve',
            $reservation,
            $records,
            $seal
        ),
        'transaction_required'
    );

    protectedExecutionCheck(
        'Reader transaction started for negative test',
        $reader->beginTransaction()
    );

    protectedExecutionExpect(
        'Reader cannot impersonate restricted executor',
        $invoke(
            $reader,
            'reserve',
            $reservation,
            $records,
            $seal
        ),
        'unauthorised_connection'
    );

    $reader->rollBack();

    protectedExecutionCheck(
        'Restricted executor transaction started',
        $writer->beginTransaction()
    );

    protectedExecutionExpect(
        'Unsupported operation rejected',
        $invoke(
            $writer,
            'delete',
            $reservation,
            $records,
            $seal
        ),
        'unsupported_operation'
    );

    $changedCustomer = $reservation;
    $changedCustomer['customer_id'] = 123;

    protectedExecutionExpect(
        'Non-synthetic customer rejected',
        $invoke(
            $writer,
            'reserve',
            $changedCustomer,
            $records,
            $seal
        ),
        'invalid_synthetic_identity'
    );

    $changedCampaign = $reservation;
    $changedCampaign['campaign_id'] = 7908;

    protectedExecutionExpect(
        'Unapproved campaign rejected',
        $invoke(
            $writer,
            'reserve',
            $changedCampaign,
            $records,
            $seal
        ),
        'invalid_synthetic_identity'
    );

    $changedRecipient = $reservation;
    $changedRecipient['recipient_digest'] =
        str_repeat('0', 64);

    protectedExecutionExpect(
        'Forged recipient binding rejected',
        $invoke(
            $writer,
            'reserve',
            $changedRecipient,
            $records,
            $seal
        ),
        'recipient_binding_mismatch'
    );

    $changedAttempt = $reservation;
    $changedAttempt['attempt_id'] =
        bin2hex(random_bytes(16));

    protectedExecutionExpect(
        'Attempt substitution rejected',
        $invoke(
            $writer,
            'reserve',
            $changedAttempt,
            $records,
            $seal
        ),
        'recipient_binding_mismatch'
    );

    $changedSlot = $reservation;
    $changedSlot['journal_slot_id'] =
        str_repeat('0', 64);

    protectedExecutionExpect(
        'Changed journal slot rejected',
        $invoke(
            $writer,
            'reserve',
            $changedSlot,
            $records,
            $seal
        ),
        'plan_journal_slot_mismatch'
    );

    $tamperedRecords = $records;
    $tamperedRecords[0]['recorded_at_utc'] =
        '2026-11-26T10:00:01Z';

    protectedExecutionExpect(
        'Tampered journal record rejected',
        $invoke(
            $writer,
            'reserve',
            $reservation,
            $tamperedRecords,
            $seal
        ),
        'plan_invalid_authenticated_journal'
    );

    $tamperedSeal = $seal;
    $tamperedSeal['seal_mac'] =
        str_repeat('0', 64);

    protectedExecutionExpect(
        'Forged checkpoint seal rejected',
        $invoke(
            $writer,
            'initialize',
            $reservation,
            $records,
            $tamperedSeal
        ),
        'plan_invalid_authenticated_journal'
    );

    protectedExecutionExpect(
        'Incomplete advancement history rejected',
        $invoke(
            $writer,
            'advance',
            $reservation,
            $records,
            $seal
        ),
        'plan_invalid_journal_length'
    );

    protectedExecutionExpect(
        'Missing reservation field rejected',
        $invoke(
            $writer,
            'reserve',
            [
                'campaign_id' => 7907,
                'customer_id' => 900001501,
            ],
            $records,
            $seal
        ),
        'invalid_reservation'
    );

    protectedExecutionCheck(
        'Invalid operations produced no committed rows',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    /*
     * Execute the real restricted stored procedure
     * through the protected-key interface.
     *
     * The transaction remains uncommitted.
     */
    protectedExecutionExpect(
        'Protected reservation executed',
        $invoke(
            $writer,
            'reserve',
            $reservation,
            $records,
            $seal
        ),
        'executed_uncommitted_test_only',
        true
    );

    protectedExecutionExpect(
        'Duplicate reservation rejected',
        $invoke(
            $writer,
            'reserve',
            $reservation,
            $records,
            $seal
        ),
        'reservation_exists_requires_review'
    );

    protectedExecutionExpect(
        'Protected prepared checkpoint initialized',
        $invoke(
            $writer,
            'initialize',
            $reservation,
            $records,
            $seal
        ),
        'executed_uncommitted_test_only',
        true
    );

    protectedExecutionExpect(
        'Repeated initialization rejected',
        $invoke(
            $writer,
            'initialize',
            $reservation,
            $records,
            $seal
        ),
        'procedure_rejected_requires_review'
    );

    protectedExecutionCheck(
        'Uncommitted checkpoint invisible to reader',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    protectedExecutionCheck(
        'Caller retains transaction ownership',
        $writer->inTransaction()
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * No COMMIT exists anywhere in this test.
     *
     * Always roll back synthetic transactions.
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
            'Independent reader unavailable.'
        );
    }

    protectedExecutionCheck(
        'All protected synthetic writes rolled back',
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

echo "PASS: All protected executor regression tests.\n";
echo "PASS: Step 489 completed.\n";
