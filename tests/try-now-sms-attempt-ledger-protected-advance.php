<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Protected advancement tests.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * Synthetic identities only.
 * All database writes are rolled back.
 *
 * No COMMIT, real transport or Sendmode.
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
    '/lib/try-now-sms-attempt-ledger-protected-advance-evidence.php';

function advanceCheck(
    string $label,
    bool $condition
): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function advanceNeverSends(array $result): bool
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

function advanceExpect(
    string $label,
    array $result,
    string $expectedStatus
): void {
    advanceCheck(
        $label,
        ($result['status'] ?? null) ===
            $expectedStatus &&
        ($result['evidence_ready'] ?? null) ===
            false &&
        advanceNeverSends($result)
    );
}

function advanceExecutionExpect(
    string $label,
    array $result,
    string $expectedStatus,
    bool $executed = false
): void {
    advanceCheck(
        $label,
        ($result['status'] ?? null) ===
            $expectedStatus &&
        ($result[
            'procedure_executed_in_transaction'
        ] ?? null) === $executed &&
        ($result[
            'protected_binding_verified'
        ] ?? null) === $executed &&
        advanceNeverSends($result)
    );
}

function advanceConnect(
    array $config,
    string $role
): PDO {
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

    advanceCheck(
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
    advanceCheck(
        'Production operations remain disabled',
        stTryNowLedgerRecoveryTestAllowed(true)
    );

    $prepared =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001601,
            bin2hex(random_bytes(16)),
            true
        );

    advanceCheck(
        'Protected prepared evidence constructed',
        $prepared['status'] ===
            'synthetic_prepared_test_only' &&
        $prepared['evidence_ready'] === true &&
        advanceNeverSends($prepared)
    );

    $reservation = $prepared['reservation'];
    $preparedRecords =
        $prepared['journal_records'];
    $preparedSeal = $prepared['head_seal'];

    $advance = static function (
        array $r,
        array $history,
        array $seal,
        string $event,
        string $timestamp,
        bool $enabled = true
    ): array {
        return stTryNowProtectedProbeAdvanceEvidence(
            $r,
            $history,
            $seal,
            $event,
            $timestamp,
            $enabled
        );
    };

    $t1 = '2026-11-26T10:00:01Z';
    $t2 = '2026-11-26T10:00:02Z';

    $started = $advance(
        $reservation,
        $preparedRecords,
        $preparedSeal,
        'transport_started',
        $t1
    );

    advanceCheck(
        'Protected transport-started evidence constructed',
        $started['status'] ===
            'synthetic_checkpoint_candidate_test_only' &&
        $started['evidence_ready'] === true &&
        count($started['journal_records']) === 2 &&
        $started['head_seal']['head_event'] ===
            'transport_started' &&
        advanceNeverSends($started)
    );

    $unknown = $advance(
        $reservation,
        $started['journal_records'],
        $started['head_seal'],
        'provider_unknown',
        $t2
    );

    advanceCheck(
        'Protected provider-unknown evidence constructed',
        $unknown['status'] ===
            'synthetic_checkpoint_candidate_test_only' &&
        $unknown['evidence_ready'] === true &&
        count($unknown['journal_records']) === 3 &&
        $unknown['head_seal']['head_event'] ===
            'provider_unknown' &&
        advanceNeverSends($unknown)
    );

    $cancelled = $advance(
        $reservation,
        $preparedRecords,
        $preparedSeal,
        'cancelled_before_transport',
        $t1
    );

    advanceCheck(
        'Protected cancellation evidence constructed',
        $cancelled['status'] ===
            'synthetic_checkpoint_candidate_test_only' &&
        $cancelled['evidence_ready'] === true &&
        $cancelled['head_seal']['head_event'] ===
            'cancelled_before_transport' &&
        advanceNeverSends($cancelled)
    );

    foreach (
        [
            'transport' => $started,
            'unknown' => $unknown,
            'cancellation' => $cancelled,
        ] as $label => $stage
    ) {
        $plan = stTryNowProtectedProbeProcedurePlan(
            'advance',
            $reservation,
            $stage['journal_records'],
            $stage['head_seal'],
            true
        );

        advanceCheck(
            'Protected ' . $label .
                ' procedure plan validated',
            $plan['procedure_call_candidate'] ===
                true &&
            $plan['procedure'] ===
                'try_now_ledger_probe_advance' &&
            $plan['parameter_count'] === 12 &&
            advanceNeverSends($plan)
        );
    }

    advanceExpect(
        'Advancement disabled by default',
        $advance(
            $reservation,
            $preparedRecords,
            $preparedSeal,
            'transport_started',
            $t1,
            false
        ),
        'disabled'
    );

    advanceExpect(
        'Provider-accepted fabrication rejected',
        $advance(
            $reservation,
            $started['journal_records'],
            $started['head_seal'],
            'provider_accepted',
            $t2
        ),
        'invalid_checkpoint_transition'
    );

    advanceExpect(
        'Provider-rejected fabrication rejected',
        $advance(
            $reservation,
            $started['journal_records'],
            $started['head_seal'],
            'provider_rejected',
            $t2
        ),
        'invalid_checkpoint_transition'
    );

    advanceExpect(
        'Provider result cannot skip transport state',
        $advance(
            $reservation,
            $preparedRecords,
            $preparedSeal,
            'provider_unknown',
            $t1
        ),
        'invalid_checkpoint_transition'
    );

    advanceExpect(
        'Transport state cannot repeat',
        $advance(
            $reservation,
            $started['journal_records'],
            $started['head_seal'],
            'transport_started',
            $t2
        ),
        'invalid_checkpoint_transition'
    );

    advanceExpect(
        'Cancellation cannot follow transport state',
        $advance(
            $reservation,
            $started['journal_records'],
            $started['head_seal'],
            'cancelled_before_transport',
            $t2
        ),
        'invalid_checkpoint_transition'
    );

    advanceExpect(
        'Cancelled attempt cannot advance',
        $advance(
            $reservation,
            $cancelled['journal_records'],
            $cancelled['head_seal'],
            'provider_unknown',
            $t2
        ),
        'invalid_checkpoint_transition'
    );

    advanceExpect(
        'Terminal provider state cannot advance',
        $advance(
            $reservation,
            $unknown['journal_records'],
            $unknown['head_seal'],
            'transport_started',
            '2026-11-26T10:00:03Z'
        ),
        'invalid_previous_length'
    );

    advanceExpect(
        'Duplicate event timestamp rejected',
        $advance(
            $reservation,
            $preparedRecords,
            $preparedSeal,
            'transport_started',
            '2026-11-26T10:00:00Z'
        ),
        'nonmonotonic_timestamp'
    );

    advanceExpect(
        'Invalid timestamp format rejected',
        $advance(
            $reservation,
            $preparedRecords,
            $preparedSeal,
            'transport_started',
            'not-a-time'
        ),
        'invalid_checkpoint_candidate'
    );

    $tamperedHistory = $preparedRecords;
    $tamperedHistory[0]['recorded_at_utc'] =
        '2026-11-26T09:59:59Z';

    advanceExpect(
        'Tampered previous journal rejected',
        $advance(
            $reservation,
            $tamperedHistory,
            $preparedSeal,
            'transport_started',
            $t1
        ),
        'invalid_previous_checkpoint'
    );

    $forgedSeal = $preparedSeal;
    $forgedSeal['seal_mac'] =
        str_repeat('0', 64);

    advanceExpect(
        'Forged previous checkpoint rejected',
        $advance(
            $reservation,
            $preparedRecords,
            $forgedSeal,
            'transport_started',
            $t1
        ),
        'invalid_previous_checkpoint'
    );

    $changedRecipient = $reservation;
    $changedRecipient['recipient_digest'] =
        str_repeat('0', 64);

    advanceExpect(
        'Forged recipient binding rejected',
        $advance(
            $changedRecipient,
            $preparedRecords,
            $preparedSeal,
            'transport_started',
            $t1
        ),
        'recipient_binding_mismatch'
    );

    $changedSlot = $reservation;
    $changedSlot['journal_slot_id'] =
        str_repeat('0', 64);

    advanceExpect(
        'Changed journal slot rejected',
        $advance(
            $changedSlot,
            $preparedRecords,
            $preparedSeal,
            'transport_started',
            $t1
        ),
        'journal_slot_mismatch'
    );

    $changedCustomer = $reservation;
    $changedCustomer['customer_id'] = 123;

    advanceExpect(
        'Non-synthetic customer rejected',
        $advance(
            $changedCustomer,
            $preparedRecords,
            $preparedSeal,
            'transport_started',
            $t1
        ),
        'invalid_synthetic_identity'
    );

    advanceCheck(
        'Advancement results contain no raw keys',
        !array_key_exists('keys', $started) &&
        !array_key_exists(
            'record_key_hex',
            $unknown
        ) &&
        !array_key_exists(
            'seal_key_hex',
            $cancelled
        ) &&
        !array_key_exists(
            'parameters',
            $started
        )
    );

    /*
     * Now exercise actual restricted procedures.
     *
     * This section uses MariaDB transactions
     * but NEVER commits them.
     */

    $configFile =
        $root . '/db-ledger-security-probe.php';

    advanceCheck(
        'Private database configuration protected',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    advanceCheck(
        'Isolated MariaDB configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $writer = advanceConnect($config, 'executor');
    $reader = advanceConnect($config, 'reader');

    $table =
        'smarttext_try_now_test.' .
        'try_now_sms_ledger_security_probe';

    advanceCheck(
        'Disposable probe initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    advanceCheck(
        'Restricted transaction started',
        $writer->beginTransaction()
    );

    $execute = static function (
        PDO $pdo,
        string $operation,
        array $r,
        array $history,
        array $seal
    ): array {
        return
            stTryNowExecuteProtectedSyntheticLedgerProcedure(
                $pdo,
                $operation,
                $r,
                $history,
                $seal,
                true
            );
    };

    advanceExecutionExpect(
        'Protected reservation executed',
        $execute(
            $writer,
            'reserve',
            $reservation,
            $preparedRecords,
            $preparedSeal
        ),
        'executed_uncommitted_test_only',
        true
    );

    advanceExecutionExpect(
        'Protected checkpoint initialized',
        $execute(
            $writer,
            'initialize',
            $reservation,
            $preparedRecords,
            $preparedSeal
        ),
        'executed_uncommitted_test_only',
        true
    );

    advanceExecutionExpect(
        'Protected transport checkpoint advanced',
        $execute(
            $writer,
            'advance',
            $reservation,
            $started['journal_records'],
            $started['head_seal']
        ),
        'executed_uncommitted_test_only',
        true
    );

    advanceExecutionExpect(
        'Stale transport checkpoint rejected',
        $execute(
            $writer,
            'advance',
            $reservation,
            $started['journal_records'],
            $started['head_seal']
        ),
        'procedure_rejected_requires_review'
    );

    advanceExecutionExpect(
        'Protected terminal checkpoint advanced',
        $execute(
            $writer,
            'advance',
            $reservation,
            $unknown['journal_records'],
            $unknown['head_seal']
        ),
        'executed_uncommitted_test_only',
        true
    );

    advanceExecutionExpect(
        'Terminal checkpoint cannot restart transport',
        $execute(
            $writer,
            'advance',
            $reservation,
            $started['journal_records'],
            $started['head_seal']
        ),
        'procedure_rejected_requires_review'
    );

    /*
     * Separate cancellation fixture.
     */
    $cancelPrepared =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001602,
            bin2hex(random_bytes(16)),
            true
        );

    advanceCheck(
        'Independent cancellation fixture constructed',
        $cancelPrepared['evidence_ready'] === true
    );

    $cancelReservation =
        $cancelPrepared['reservation'];

    $cancelStage = $advance(
        $cancelReservation,
        $cancelPrepared['journal_records'],
        $cancelPrepared['head_seal'],
        'cancelled_before_transport',
        $t1
    );

    advanceCheck(
        'Protected cancellation stage authenticated',
        $cancelStage['evidence_ready'] === true &&
        advanceNeverSends($cancelStage)
    );

    advanceExecutionExpect(
        'Cancellation reservation executed',
        $execute(
            $writer,
            'reserve',
            $cancelReservation,
            $cancelPrepared['journal_records'],
            $cancelPrepared['head_seal']
        ),
        'executed_uncommitted_test_only',
        true
    );

    advanceExecutionExpect(
        'Cancellation checkpoint initialized',
        $execute(
            $writer,
            'initialize',
            $cancelReservation,
            $cancelPrepared['journal_records'],
            $cancelPrepared['head_seal']
        ),
        'executed_uncommitted_test_only',
        true
    );

    advanceExecutionExpect(
        'Protected cancellation checkpoint advanced',
        $execute(
            $writer,
            'advance',
            $cancelReservation,
            $cancelStage['journal_records'],
            $cancelStage['head_seal']
        ),
        'executed_uncommitted_test_only',
        true
    );

    $cancelStarted = $advance(
        $cancelReservation,
        $cancelPrepared['journal_records'],
        $cancelPrepared['head_seal'],
        'transport_started',
        $t1
    );

    advanceCheck(
        'Conflicting transport evidence constructed',
        $cancelStarted['evidence_ready'] === true
    );

    advanceExecutionExpect(
        'Cancelled attempt cannot start transport',
        $execute(
            $writer,
            'advance',
            $cancelReservation,
            $cancelStarted['journal_records'],
            $cancelStarted['head_seal']
        ),
        'procedure_rejected_requires_review'
    );

    advanceCheck(
        'Uncommitted rows invisible to restricted reader',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    advanceCheck(
        'Caller retains transaction ownership',
        $writer->inTransaction()
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * No COMMIT operation is present.
     * Roll back on success and on every failure.
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

    advanceCheck(
        'All protected checkpoint changes rolled back',
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

echo "PASS: All protected advancement regressions.\n";
echo "PASS: Step 494 completed.\n";
