<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Protected keyring regression.
 *
 * ISOLATED PLESK CLI ONLY.
 *
 * No database connection, SQL, COMMIT or SMS.
 * Never prints or exports cryptographic keys.
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

require_once dirname(__DIR__)
    . '/lib/try-now-sms-attempt-ledger-protected-keys.php';

function protectedCheck(
    string $label,
    bool $condition
): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function protectedNeverSends(
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

try {
    protectedCheck(
        'Production operations remain disabled',
        stTryNowLedgerRecoveryTestAllowed(true)
    );

    /*
     * Pure schema tests use invented key strings.
     * Never insert real private keyring data here.
     */
    $schema = [
        'version' => 1,
        'environment' => 'isolated_probe_cli_only',
        'namespace' =>
            'synthetic-restricted-probe-v1',
        'binding_key_hex' => str_repeat('a', 64),
        'record_key_hex' => str_repeat('b', 64),
        'seal_key_hex' => str_repeat('c', 64),
        'recipient_key_hex' => str_repeat('d', 64),
    ];

    protectedCheck(
        'Valid keyring schema accepted',
        stTryNowProtectedProbeKeyringSchemaValid(
            $schema
        )
    );

    $duplicate = $schema;
    $duplicate['recipient_key_hex'] =
        $duplicate['record_key_hex'];

    protectedCheck(
        'Reused cryptographic key rejected',
        !stTryNowProtectedProbeKeyringSchemaValid(
            $duplicate
        )
    );

    $short = $schema;
    $short['binding_key_hex'] = str_repeat('a', 63);

    protectedCheck(
        'Short key rejected',
        !stTryNowProtectedProbeKeyringSchemaValid(
            $short
        )
    );

    $wrongEnvironment = $schema;
    $wrongEnvironment['environment'] = 'production';

    protectedCheck(
        'Production environment rejected',
        !stTryNowProtectedProbeKeyringSchemaValid(
            $wrongEnvironment
        )
    );

    $extra = $schema;
    $extra['unexpected_field'] = 'not-allowed';

    protectedCheck(
        'Unexpected configuration field rejected',
        !stTryNowProtectedProbeKeyringSchemaValid(
            $extra
        )
    );

    $wrongNamespace = $schema;
    $wrongNamespace['namespace'] = 'other-test';

    protectedCheck(
        'Unexpected namespace rejected',
        !stTryNowProtectedProbeKeyringSchemaValid(
            $wrongNamespace
        )
    );

    $attemptId = bin2hex(random_bytes(16));

    $disabled = stTryNowProtectedProbePreparedEvidence(
        7907,
        900001201,
        $attemptId
    );

    protectedCheck(
        'Protected evidence disabled by default',
        $disabled['status'] === 'disabled' &&
        $disabled['evidence_ready'] === false &&
        protectedNeverSends($disabled)
    );

    $invalidCustomer =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            123,
            $attemptId,
            true
        );

    protectedCheck(
        'Non-synthetic customer rejected',
        $invalidCustomer['status'] ===
            'invalid_synthetic_identity'
    );

    $invalidCampaign =
        stTryNowProtectedProbePreparedEvidence(
            7908,
            900001201,
            $attemptId,
            true
        );

    protectedCheck(
        'Unexpected campaign rejected',
        $invalidCampaign['status'] ===
            'invalid_synthetic_identity'
    );

    $uppercase =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001201,
            strtoupper($attemptId),
            true
        );

    protectedCheck(
        'Uppercase attempt ID rejected',
        $uppercase['status'] ===
            'invalid_synthetic_identity'
    );

    $prepared =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001201,
            $attemptId,
            true
        );

    protectedCheck(
        'Private keyring authenticated prepared evidence',
        $prepared['status'] ===
            'synthetic_prepared_test_only' &&
        $prepared['evidence_ready'] === true &&
        is_array($prepared['reservation']) &&
        count($prepared['journal_records']) === 1 &&
        is_array($prepared['head_seal']) &&
        protectedNeverSends($prepared)
    );

    protectedCheck(
        'No raw cryptographic keys returned',
        !array_key_exists('keys', $prepared) &&
        !array_key_exists(
            'binding_key_hex',
            $prepared
        ) &&
        !array_key_exists(
            'record_key_hex',
            $prepared
        ) &&
        !array_key_exists(
            'recipient_key_hex',
            $prepared
        )
    );

    $repeat =
        stTryNowProtectedProbePreparedEvidence(
            7907,
            900001201,
            $attemptId,
            true
        );

    protectedCheck(
        'Protected evidence is deterministic',
        $repeat['reservation'] ===
            $prepared['reservation'] &&
        $repeat['journal_records'] ===
            $prepared['journal_records'] &&
        $repeat['head_seal'] ===
            $prepared['head_seal']
    );

    $reservation = $prepared['reservation'];
    $records = $prepared['journal_records'];
    $seal = $prepared['head_seal'];

    $plan = static function (
        string $operation,
        array $r,
        array $history,
        array $head,
        bool $allow = true
    ): array {
        return stTryNowProtectedProbeProcedurePlan(
            $operation,
            $r,
            $history,
            $head,
            $allow
        );
    };

    $reserved = $plan(
        'reserve',
        $reservation,
        $records,
        $seal
    );

    protectedCheck(
        'Protected reservation plan accepted',
        $reserved['status'] ===
            'ready_for_restricted_procedure_test_only' &&
        $reserved['procedure_call_candidate'] === true &&
        $reserved['procedure'] ===
            'try_now_ledger_probe_reserve' &&
        $reserved['parameter_count'] === 5 &&
        protectedNeverSends($reserved)
    );

    $initialized = $plan(
        'initialize',
        $reservation,
        $records,
        $seal
    );

    protectedCheck(
        'Protected initialization plan accepted',
        $initialized['procedure_call_candidate'] ===
            true &&
        $initialized['procedure'] ===
            'try_now_ledger_probe_initialize' &&
        $initialized['parameter_count'] === 7 &&
        protectedNeverSends($initialized)
    );

    $changedRecipient = $reservation;
    $changedRecipient['recipient_digest'] =
        str_repeat('0', 64);

    $rejected = $plan(
        'reserve',
        $changedRecipient,
        $records,
        $seal
    );

    protectedCheck(
        'Forged recipient digest rejected',
        $rejected['status'] ===
            'recipient_binding_mismatch' &&
        $rejected['procedure_call_candidate'] === false &&
        protectedNeverSends($rejected)
    );

    $changedAttempt = $reservation;
    $changedAttempt['attempt_id'] =
        bin2hex(random_bytes(16));

    $rejected = $plan(
        'reserve',
        $changedAttempt,
        $records,
        $seal
    );

    protectedCheck(
        'Recipient digest tied to attempt identity',
        $rejected['status'] ===
            'recipient_binding_mismatch' &&
        protectedNeverSends($rejected)
    );

    $changedSlot = $reservation;
    $changedSlot['journal_slot_id'] =
        str_repeat('0', 64);

    $rejected = $plan(
        'reserve',
        $changedSlot,
        $records,
        $seal
    );

    protectedCheck(
        'Altered journal slot rejected',
        $rejected['status'] ===
            'plan_journal_slot_mismatch' &&
        protectedNeverSends($rejected)
    );

    $tamperedRecords = $records;
    $tamperedRecords[0]['recorded_at_utc'] =
        '2026-11-26T10:00:01Z';

    $rejected = $plan(
        'reserve',
        $reservation,
        $tamperedRecords,
        $seal
    );

    protectedCheck(
        'Tampered authenticated journal rejected',
        $rejected['status'] ===
            'plan_invalid_authenticated_journal' &&
        protectedNeverSends($rejected)
    );

    $tamperedSeal = $seal;
    $tamperedSeal['seal_mac'] =
        str_repeat('0', 64);

    $rejected = $plan(
        'initialize',
        $reservation,
        $records,
        $tamperedSeal
    );

    protectedCheck(
        'Forged checkpoint seal rejected',
        $rejected['status'] ===
            'plan_invalid_authenticated_journal' &&
        protectedNeverSends($rejected)
    );

    $rejected = $plan(
        'advance',
        $reservation,
        $records,
        $seal
    );

    protectedCheck(
        'Incomplete advancement journal rejected',
        $rejected['status'] ===
            'plan_invalid_journal_length' &&
        protectedNeverSends($rejected)
    );

    $rejected = $plan(
        'delete',
        $reservation,
        $records,
        $seal
    );

    protectedCheck(
        'Unapproved procedure rejected',
        $rejected['status'] ===
            'plan_unsupported_operation' &&
        protectedNeverSends($rejected)
    );

    $rejected = $plan(
        'reserve',
        $reservation,
        $records,
        $seal,
        false
    );

    protectedCheck(
        'Protected planning disabled by default',
        $rejected['status'] === 'disabled' &&
        protectedNeverSends($rejected)
    );

    echo "PASS: All protected-key regression tests.\n";
    echo "PASS: Step 484 completed.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL: Protected-key regression requires review.\n"
    );
    exit(1);
}
