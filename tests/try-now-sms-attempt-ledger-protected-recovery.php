<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Protected recovery regression.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * Uses protected keys and synthetic checkpoints.
 * Only restricted MariaDB connections are allowed.
 *
 * All database writes are rolled back.
 * No COMMIT, transport, SMS or automatic retry.
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
    '/lib/try-now-sms-attempt-ledger-protected-recovery.php';

function protectedRecoveryCheck(
    string $label,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function protectedRecoveryNeverSends(
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

function protectedRecoveryExpect(
    string $label,
    array $result,
    string $expectedStatus,
    bool $authenticated,
    bool $independent
): void {
    protectedRecoveryCheck(
        $label,
        ($result['status'] ?? null) ===
            $expectedStatus &&
        ($result[
            'protected_binding_verified'
        ] ?? null) === $authenticated &&
        ($result[
            'independent_reader_verified'
        ] ?? null) === $independent &&
        protectedRecoveryNeverSends($result)
    );
}

function protectedRecoveryConnect(
    array $config,
    string $role
): PDO {
    if (!in_array(
        $role,
        ['reader', 'executor'],
        true
    )) {
        throw new RuntimeException(
            'Invalid restricted database role.'
        );
    }

    $account = $config[$role] ?? null;
    $username = 'try_now_ledger_' . $role;

    if (
        !is_array($account) ||
        ($account['username'] ?? null) !==
            $username ||
        !is_string(
            $account['password'] ?? null
        ) ||
        $account['password'] === ''
    ) {
        throw new RuntimeException(
            'Invalid restricted credentials.'
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

    protectedRecoveryCheck(
        ucfirst($role) . ' database identity verified',
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
    protectedRecoveryCheck(
        'Production operations remain disabled',
        stTryNowLedgerRecoveryTestAllowed(true)
    );

    $configFile =
        $root . '/db-ledger-security-probe.php';

    protectedRecoveryCheck(
        'Restricted credentials have safe permissions',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    protectedRecoveryCheck(
        'Isolated database configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $writer = protectedRecoveryConnect(
        $config,
        'executor'
    );

    $reader = protectedRecoveryConnect(
        $config,
        'reader'
    );

    $writerId = (int) $writer->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    $readerId = (int) $reader->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    protectedRecoveryCheck(
        'Writer and reader sessions are independent',
        $writerId > 0 &&
        $readerId > 0 &&
        $writerId !== $readerId
    );

    $table =
        'smarttext_try_now_test.' .
        'try_now_sms_ledger_security_probe';

    protectedRecoveryCheck(
        'Disposable probe initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    /*
     * Generate authenticated evidence without
     * loading or exposing raw keys in this test.
     */
    $prepared =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001701,
            bin2hex(random_bytes(16)),
            true
        );

    protectedRecoveryCheck(
        'Protected prepared evidence constructed',
        $prepared['status'] ===
            'synthetic_prepared_test_only' &&
        $prepared['evidence_ready'] === true &&
        protectedRecoveryNeverSends($prepared)
    );

    $reservation = $prepared['reservation'];
    $history = $prepared['journal_records'];
    $seal = $prepared['head_seal'];

    $started =
        stTryNowProtectedProbeAdvanceEvidence(
            $reservation,
            $history,
            $seal,
            'transport_started',
            '2026-11-26T10:00:01Z',
            true
        );

    protectedRecoveryCheck(
        'Protected transport evidence constructed',
        $started['evidence_ready'] === true &&
        protectedRecoveryNeverSends($started)
    );

    $unknown =
        stTryNowProtectedProbeAdvanceEvidence(
            $reservation,
            $started['journal_records'],
            $started['head_seal'],
            'provider_unknown',
            '2026-11-26T10:00:02Z',
            true
        );

    protectedRecoveryCheck(
        'Protected provider-unknown evidence constructed',
        $unknown['evidence_ready'] === true &&
        protectedRecoveryNeverSends($unknown)
    );

    $cancelled =
        stTryNowProtectedProbeAdvanceEvidence(
            $reservation,
            $history,
            $seal,
            'cancelled_before_transport',
            '2026-11-26T10:00:01Z',
            true
        );

    protectedRecoveryCheck(
        'Protected cancellation evidence constructed',
        $cancelled['evidence_ready'] === true &&
        protectedRecoveryNeverSends($cancelled)
    );

    $assess = static function (
        PDO $connection,
        string $signal,
        array $r,
        array $records,
        array $head,
        int $sessionId,
        bool $enabled = true
    ): array {
        return
            stTryNowAssessProtectedSyntheticProbeCommit(
                $connection,
                $r,
                $records,
                $head,
                $signal,
                $sessionId,
                $enabled
            );
    };

    protectedRecoveryExpect(
        'Protected recovery disabled by default',
        $assess(
            $reader,
            'not_attempted',
            $reservation,
            $history,
            $seal,
            $writerId,
            false
        ),
        'disabled',
        false,
        false
    );

    $initial = $assess(
        $reader,
        'not_attempted',
        $reservation,
        $history,
        $seal,
        $writerId
    );

    protectedRecoveryExpect(
        'Independent reader confirms absent checkpoint',
        $initial,
        'commit_not_attempted_test_only',
        true,
        true
    );

    protectedRecoveryCheck(
        'Absence is reported without commit evidence',
        $initial['observation_status'] === 'absent' &&
        $initial['fresh_checkpoint_observed']
            === false &&
        $initial[
            'commit_acknowledgement_reported'
        ] === false
    );

    foreach (
        [
            'transport' => $started,
            'provider_unknown' => $unknown,
            'cancelled' => $cancelled,
        ] as $name => $stage
    ) {
        protectedRecoveryExpect(
            'Protected ' . $name .
                ' history verified by independent reader',
            $assess(
                $reader,
                'not_attempted',
                $reservation,
                $stage['journal_records'],
                $stage['head_seal'],
                $writerId
            ),
            'commit_not_attempted_test_only',
            true,
            true
        );
    }

    protectedRecoveryExpect(
        'Invalid commit signal rejected',
        $assess(
            $reader,
            'unexpected_signal',
            $reservation,
            $history,
            $seal,
            $writerId
        ),
        'invalid_commit_signal',
        false,
        false
    );

    protectedRecoveryExpect(
        'Invalid writer session rejected',
        $assess(
            $reader,
            'acknowledged',
            $reservation,
            $history,
            $seal,
            0
        ),
        'invalid_writer_session',
        false,
        false
    );

    $changedRecipient = $reservation;
    $changedRecipient['recipient_digest'] =
        str_repeat('0', 64);

    protectedRecoveryExpect(
        'Forged recipient digest rejected',
        $assess(
            $reader,
            'acknowledged',
            $changedRecipient,
            $history,
            $seal,
            $writerId
        ),
        'recipient_binding_mismatch',
        false,
        false
    );

    $changedSlot = $reservation;
    $changedSlot['journal_slot_id'] =
        str_repeat('0', 64);

    protectedRecoveryExpect(
        'Altered journal slot rejected',
        $assess(
            $reader,
            'acknowledged',
            $changedSlot,
            $history,
            $seal,
            $writerId
        ),
        'journal_slot_mismatch',
        false,
        false
    );

    $changedCustomer = $reservation;
    $changedCustomer['customer_id'] = 123;

    protectedRecoveryExpect(
        'Non-synthetic customer rejected',
        $assess(
            $reader,
            'acknowledged',
            $changedCustomer,
            $history,
            $seal,
            $writerId
        ),
        'invalid_synthetic_identity',
        false,
        false
    );

    $tamperedHistory = $history;
    $tamperedHistory[0]['recorded_at_utc'] =
        '2026-11-26T09:59:59Z';

    protectedRecoveryExpect(
        'Altered authenticated history rejected',
        $assess(
            $reader,
            'acknowledged',
            $reservation,
            $tamperedHistory,
            $seal,
            $writerId
        ),
        'invalid_expected_evidence',
        false,
        false
    );

    $forgedSeal = $seal;
    $forgedSeal['seal_mac'] =
        str_repeat('0', 64);

    protectedRecoveryExpect(
        'Forged head seal rejected',
        $assess(
            $reader,
            'acknowledged',
            $reservation,
            $history,
            $forgedSeal,
            $writerId
        ),
        'invalid_expected_evidence',
        false,
        false
    );

    /*
     * The reader must not be mistaken for the
     * writer's session.
     */
    protectedRecoveryExpect(
        'Reader cannot verify its own writer identity',
        $assess(
            $reader,
            'acknowledged',
            $reservation,
            $history,
            $seal,
            $readerId
        ),
        'fresh_read_unavailable_requires_review',
        true,
        false
    );

    protectedRecoveryExpect(
        'Executor cannot impersonate read-only observer',
        $assess(
            $writer,
            'acknowledged',
            $reservation,
            $history,
            $seal,
            $writerId
        ),
        'fresh_read_unavailable_requires_review',
        true,
        false
    );

    protectedRecoveryCheck(
        'Reader transaction started for rejection test',
        $reader->beginTransaction()
    );

    protectedRecoveryExpect(
        'Reader inside transaction rejected',
        $assess(
            $reader,
            'acknowledged',
            $reservation,
            $history,
            $seal,
            $writerId
        ),
        'fresh_read_unavailable_requires_review',
        true,
        false
    );

    $reader->rollBack();

    /*
     * The writer now creates an uncommitted
     * reservation and prepared checkpoint.
     *
     * No COMMIT is performed.
     */
    protectedRecoveryCheck(
        'Synthetic writer transaction started',
        $writer->beginTransaction()
    );

    $reserve =
        stTryNowExecuteProtectedSyntheticLedgerProcedure(
            $writer,
            'reserve',
            $reservation,
            $history,
            $seal,
            true
        );

    protectedRecoveryCheck(
        'Protected reservation executed uncommitted',
        $reserve['status'] ===
            'executed_uncommitted_test_only' &&
        $reserve[
            'procedure_executed_in_transaction'
        ] === true &&
        protectedRecoveryNeverSends($reserve)
    );

    $initialize =
        stTryNowExecuteProtectedSyntheticLedgerProcedure(
            $writer,
            'initialize',
            $reservation,
            $history,
            $seal,
            true
        );

    protectedRecoveryCheck(
        'Protected checkpoint initialized uncommitted',
        $initialize['status'] ===
            'executed_uncommitted_test_only' &&
        $initialize[
            'procedure_executed_in_transaction'
        ] === true &&
        protectedRecoveryNeverSends($initialize)
    );

    protectedRecoveryExpect(
        'Uncommitted checkpoint not visible to reader',
        $assess(
            $reader,
            'not_attempted',
            $reservation,
            $history,
            $seal,
            $writerId
        ),
        'commit_not_attempted_test_only',
        true,
        true
    );

    protectedRecoveryExpect(
        'Acknowledged but absent requires review',
        $assess(
            $reader,
            'acknowledged',
            $reservation,
            $history,
            $seal,
            $writerId
        ),
        'acknowledged_but_absent_requires_review',
        true,
        true
    );

    protectedRecoveryExpect(
        'Lost acknowledgement remains uncertain',
        $assess(
            $reader,
            'acknowledgement_lost',
            $reservation,
            $history,
            $seal,
            $writerId
        ),
        'commit_outcome_unknown_requires_review',
        true,
        true
    );

    protectedRecoveryExpect(
        'Failed or unknown COMMIT remains uncertain',
        $assess(
            $reader,
            'failed_or_unknown',
            $reservation,
            $history,
            $seal,
            $writerId
        ),
        'commit_outcome_unknown_requires_review',
        true,
        true
    );

    protectedRecoveryCheck(
        'Writer retains transaction ownership',
        $writer->inTransaction()
    );

    protectedRecoveryCheck(
        'Uncommitted checkpoint remains invisible',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * The test contains no COMMIT operation.
     *
     * Always roll back any active transaction.
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
            'Restricted reader unavailable.'
        );
    }

    protectedRecoveryCheck(
        'All synthetic recovery writes rolled back',
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

echo "PASS: All protected recovery regressions.\n";
echo "PASS: Step 499 completed.\n";
