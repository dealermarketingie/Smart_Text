<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Authenticated procedure planner.
 *
 * ISOLATED PLESK CLI ONLY.
 * Synthetic cryptographic keys and identities only.
 * No database connection or writes.
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

require_once dirname(__DIR__) .
    '/lib/try-now-sms-attempt-ledger-procedure-plan.php';

function planCheck(string $label, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function planNeverSends(array $result): bool
{
    return
        $result['checkpoint_committed'] === false &&
        $result['safe_for_transport'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false;
}

try {
    planCheck(
        'Production operations remain disabled',
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false
    );

    $namespace = 'synthetic-ledger-probe';
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);
    $sealKey = str_repeat('S', 32);

    $attempt = bin2hex(random_bytes(16));
    $customerId = 900000801;
    $campaignId = 7907;

    $reservation = [
        'campaign_id' => $campaignId,
        'customer_id' => $customerId,
        'attempt_id' => $attempt,
        'journal_slot_id' => stTryNowJournalSlotId(
            $namespace,
            $campaignId,
            $customerId,
            $bindingKey
        ),
        'recipient_digest' => hash_hmac(
            'sha256',
            'synthetic-recipient:' . $attempt,
            str_repeat('D', 32)
        ),
    ];

    $record1 = stTryNowBuildJournalRecord(
        $attempt,
        $customerId,
        $campaignId,
        1,
        'prepared',
        '2026-11-26T10:00:00Z',
        str_repeat('0', 64),
        $recordKey
    );

    $record2 = stTryNowBuildJournalRecord(
        $attempt,
        $customerId,
        $campaignId,
        2,
        'transport_started',
        '2026-11-26T10:00:01Z',
        $record1['mac'],
        $recordKey
    );

    $record3 = stTryNowBuildJournalRecord(
        $attempt,
        $customerId,
        $campaignId,
        3,
        'provider_unknown',
        '2026-11-26T10:00:02Z',
        $record2['mac'],
        $recordKey
    );

    $prepared = [$record1];
    $started = [$record1, $record2];
    $completed = [$record1, $record2, $record3];

    $preparedSeal = stTryNowBuildJournalHeadSeal(
        $prepared, $recordKey, $sealKey
    );

    $startedSeal = stTryNowBuildJournalHeadSeal(
        $started, $recordKey, $sealKey
    );

    $completedSeal = stTryNowBuildJournalHeadSeal(
        $completed, $recordKey, $sealKey
    );

    $plan = static function (
        string $operation,
        array $r,
        array $records,
        array $seal,
        bool $allow = true
    ) use (
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey
    ): array {
        return stTryNowRestrictedProcedureCallPlan(
            $operation,
            $r,
            $records,
            $seal,
            $namespace,
            $bindingKey,
            $recordKey,
            $sealKey,
            $allow
        );
    };

    $disabled = $plan(
        'reserve', $reservation, $prepared,
        $preparedSeal, false
    );

    planCheck(
        'Procedure planning disabled by default',
        $disabled['status'] === 'disabled' &&
        $disabled['procedure_call_candidate'] === false &&
        planNeverSends($disabled)
    );

    $reserve = $plan(
        'reserve', $reservation,
        $prepared, $preparedSeal
    );

    planCheck(
        'Authenticated reservation plan accepted',
        $reserve['procedure_call_candidate'] === true &&
        $reserve['procedure'] ===
            'try_now_ledger_probe_reserve' &&
        $reserve['parameters'] === array_values(
            $reservation
        )
    );

    $initialize = $plan(
        'initialize', $reservation,
        $prepared, $preparedSeal
    );

    planCheck(
        'Authenticated initialization plan accepted',
        $initialize['procedure_call_candidate'] === true &&
        $initialize['procedure'] ===
            'try_now_ledger_probe_initialize' &&
        count($initialize['parameters']) === 7 &&
        $initialize['parameters'][5] ===
            $preparedSeal['head_mac'] &&
        $initialize['parameters'][6] ===
            $preparedSeal['seal_mac']
    );

    $advance2 = $plan(
        'advance', $reservation,
        $started, $startedSeal
    );

    planCheck(
        'Authenticated prepared-to-transport plan accepted',
        $advance2['procedure_call_candidate'] === true &&
        $advance2['procedure'] ===
            'try_now_ledger_probe_advance' &&
        count($advance2['parameters']) === 12 &&
        $advance2['parameters'][5] === 1 &&
        $advance2['parameters'][6] === 'prepared' &&
        $advance2['parameters'][7] ===
            $preparedSeal['head_mac'] &&
        $advance2['parameters'][8] ===
            $preparedSeal['seal_mac'] &&
        $advance2['parameters'][9] ===
            'transport_started'
    );

    $advance3 = $plan(
        'advance', $reservation,
        $completed, $completedSeal
    );

    planCheck(
        'Authenticated provider-result plan accepted',
        $advance3['procedure_call_candidate'] === true &&
        count($advance3['parameters']) === 12 &&
        $advance3['parameters'][5] === 2 &&
        $advance3['parameters'][6] ===
            'transport_started' &&
        $advance3['parameters'][9] ===
            'provider_unknown'
    );

    $cancelRecord = stTryNowBuildJournalRecord(
        $attempt,
        $customerId,
        $campaignId,
        2,
        'cancelled_before_transport',
        '2026-11-26T10:00:01Z',
        $record1['mac'],
        $recordKey
    );

    $cancelHistory = [$record1, $cancelRecord];

    $cancelSeal = stTryNowBuildJournalHeadSeal(
        $cancelHistory, $recordKey, $sealKey
    );

    $cancelPlan = $plan(
        'advance', $reservation,
        $cancelHistory, $cancelSeal
    );

    planCheck(
        'Authenticated cancellation plan accepted',
        $cancelPlan['procedure_call_candidate'] === true &&
        $cancelPlan['parameters'][9] ===
            'cancelled_before_transport'
    );

    $changedSlot = $reservation;
    $changedSlot['journal_slot_id'] = str_repeat('0', 64);

    planCheck(
        'Altered journal slot rejected',
        $plan(
            'reserve',
            $changedSlot,
            $prepared,
            $preparedSeal
        )['procedure_call_candidate'] === false
    );

    $wrongIdentity = $reservation;
    $wrongIdentity['attempt_id'] = bin2hex(random_bytes(16));

    planCheck(
        'Mismatched attempt identity rejected',
        $plan(
            'initialize',
            $wrongIdentity,
            $prepared,
            $preparedSeal
        )['procedure_call_candidate'] === false
    );

    $uppercase = $reservation;
    $uppercase['attempt_id'] = strtoupper($attempt);

    planCheck(
        'Uppercase attempt identity rejected',
        $plan(
            'reserve',
            $uppercase,
            $prepared,
            $preparedSeal
        )['procedure_call_candidate'] === false
    );

    $tamperedSeal = $preparedSeal;
    $tamperedSeal['seal_mac'] = str_repeat('0', 64);

    planCheck(
        'Tampered checkpoint seal rejected',
        $plan(
            'initialize',
            $reservation,
            $prepared,
            $tamperedSeal
        )['procedure_call_candidate'] === false
    );

    $tamperedRecord = $record1;
    $tamperedRecord['recorded_at_utc'] =
        '2026-11-26T11:00:00Z';

    planCheck(
        'Tampered journal record rejected',
        $plan(
            'initialize',
            $reservation,
            [$tamperedRecord],
            $preparedSeal
        )['procedure_call_candidate'] === false
    );

    planCheck(
        'Incomplete advancement history rejected',
        $plan(
            'advance',
            $reservation,
            $prepared,
            $preparedSeal
        )['procedure_call_candidate'] === false
    );

    planCheck(
        'Unsupported procedure operation rejected',
        $plan(
            'delete',
            $reservation,
            $prepared,
            $preparedSeal
        )['procedure_call_candidate'] === false
    );

    planCheck(
        'Reservation planning never authorises delivery',
        planNeverSends($reserve)
    );

    planCheck(
        'Initialization planning never authorises delivery',
        planNeverSends($initialize)
    );

    planCheck(
        'Advancement planning never authorises delivery',
        planNeverSends($advance2) &&
        planNeverSends($advance3) &&
        planNeverSends($cancelPlan)
    );

    planCheck(
        'No database connection is required by planner',
        $reserve['requires_authoritative_cas'] === true &&
        $initialize['requires_authoritative_cas'] === true &&
        $advance2['requires_authoritative_cas'] === true
    );

    echo "PASS: All restricted procedure planner tests.\n";
    echo "PASS: Step 457 completed.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'FAIL: ' . $e->getMessage() . "\n"
    );
    exit(1);
}
