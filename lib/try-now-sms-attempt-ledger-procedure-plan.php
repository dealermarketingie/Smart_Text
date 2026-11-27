<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Restricted procedure-call planner.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * Authenticates journal evidence before constructing
 * arguments for the restricted ledger procedures.
 *
 * No PDO connection, SQL execution, commits, HTTP,
 * customer writes, filesystem changes or Sendmode.
 *
 * A successful plan NEVER authorises SMS delivery.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-commit-recovery.php';

function stTryNowRestrictedProcedurePlanResult(
    string $status
): array {
    return [
        'status' => $status,
        'procedure_call_candidate' => false,
        'procedure' => null,
        'parameters' => [],
        'requires_authoritative_cas' => true,
        'checkpoint_committed' => false,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}

/**
 * Supported operations:
 *   reserve
 *   initialize
 *   advance
 *
 * The reservation is always bound to a complete
 * authenticated journal beginning with "prepared".
 *
 * For advance, the previous checkpoint is derived
 * from the authenticated journal prefix, never from
 * arbitrary caller-supplied previous HMAC values.
 */
function stTryNowRestrictedProcedureCallPlan(
    string $operation,
    array $reservation,
    array $journalRecords,
    array $candidateSeal,
    string $namespace,
    string $bindingKey,
    string $recordKey,
    string $sealKey,
    bool $allowCliTest = false
): array {
    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return stTryNowRestrictedProcedurePlanResult(
            'disabled'
        );
    }

    if (!in_array($operation, [
        'reserve',
        'initialize',
        'advance',
    ], true)) {
        return stTryNowRestrictedProcedurePlanResult(
            'unsupported_operation'
        );
    }

    if (!stTryNowLedgerRecoveryReservationValid(
        $reservation
    )) {
        return stTryNowRestrictedProcedurePlanResult(
            'invalid_reservation'
        );
    }

    if (
        strlen($bindingKey) < 32 ||
        strlen($recordKey) < 32 ||
        strlen($sealKey) < 32 ||
        hash_equals($bindingKey, $recordKey) ||
        hash_equals($bindingKey, $sealKey) ||
        hash_equals($recordKey, $sealKey)
    ) {
        return stTryNowRestrictedProcedurePlanResult(
            'invalid_keys'
        );
    }

    $count = count($journalRecords);

    if (
        ($operation !== 'advance' && $count !== 1) ||
        ($operation === 'advance' &&
            $count !== 2 && $count !== 3)
    ) {
        return stTryNowRestrictedProcedurePlanResult(
            'invalid_journal_length'
        );
    }

    try {
        $expectedSlot = stTryNowJournalSlotId(
            $namespace,
            $reservation['campaign_id'],
            $reservation['customer_id'],
            $bindingKey
        );

        if (!hash_equals(
            $expectedSlot,
            $reservation['journal_slot_id']
        )) {
            return stTryNowRestrictedProcedurePlanResult(
                'journal_slot_mismatch'
            );
        }

        $verification = stTryNowVerifyJournalHeadSeal(
            $journalRecords,
            $candidateSeal,
            $recordKey,
            $sealKey
        );

        if ($verification['valid'] !== true) {
            return stTryNowRestrictedProcedurePlanResult(
                'invalid_authenticated_journal'
            );
        }

        $first = $journalRecords[0];

        if (
            $first['attempt_id'] !==
                $reservation['attempt_id'] ||
            $first['customer_id'] !==
                $reservation['customer_id'] ||
            $first['campaign_id'] !==
                $reservation['campaign_id']
        ) {
            return stTryNowRestrictedProcedurePlanResult(
                'journal_identity_mismatch'
            );
        }

        $identityParameters = [
            $reservation['campaign_id'],
            $reservation['customer_id'],
            $reservation['attempt_id'],
            $reservation['journal_slot_id'],
            $reservation['recipient_digest'],
        ];

        if ($operation === 'reserve') {
            $procedure =
                'try_now_ledger_probe_reserve';

            $parameters = $identityParameters;

        } elseif ($operation === 'initialize') {
            if (
                $candidateSeal['record_count'] !== 1 ||
                $candidateSeal['head_event'] !== 'prepared'
            ) {
                return stTryNowRestrictedProcedurePlanResult(
                    'invalid_initial_checkpoint'
                );
            }

            $procedure =
                'try_now_ledger_probe_initialize';

            $parameters = array_merge(
                $identityParameters,
                [
                    $candidateSeal['head_mac'],
                    $candidateSeal['seal_mac'],
                ]
            );

        } else {
            /*
             * The complete candidate journal has
             * already passed HMAC verification.
             *
             * Independently reconstruct the exact
             * previous authenticated checkpoint.
             */
            $previousCount = $count - 1;

            $previousSeal = stTryNowBuildJournalHeadSeal(
                array_slice(
                    $journalRecords,
                    0,
                    $previousCount
                ),
                $recordKey,
                $sealKey
            );

            $policy = stTryNowCheckpointAdvancementPlan(
                $journalRecords,
                $previousSeal,
                $candidateSeal,
                $recordKey,
                $sealKey
            );

            if (
                $policy['checkpoint_update_candidate']
                    !== true
            ) {
                return stTryNowRestrictedProcedurePlanResult(
                    'invalid_checkpoint_progression'
                );
            }

            $previousEvent =
                $previousSeal['head_event'];

            $nextEvent =
                $candidateSeal['head_event'];

            /*
             * Mirror the database state machine.
             * This is defence in depth:
             * the database remains authoritative.
             */
            $allowed =
                (
                    $previousCount === 1 &&
                    $previousEvent === 'prepared' &&
                    in_array($nextEvent, [
                        'transport_started',
                        'cancelled_before_transport',
                    ], true)
                ) ||
                (
                    $previousCount === 2 &&
                    $previousEvent ===
                        'transport_started' &&
                    in_array($nextEvent, [
                        'provider_accepted',
                        'provider_rejected',
                        'provider_unknown',
                    ], true)
                );

            if (!$allowed) {
                return stTryNowRestrictedProcedurePlanResult(
                    'invalid_checkpoint_transition'
                );
            }

            $procedure =
                'try_now_ledger_probe_advance';

            $parameters = array_merge(
                $identityParameters,
                [
                    $previousCount,
                    $previousSeal['head_event'],
                    $previousSeal['head_mac'],
                    $previousSeal['seal_mac'],

                    $candidateSeal['head_event'],
                    $candidateSeal['head_mac'],
                    $candidateSeal['seal_mac'],
                ]
            );
        }

        $result = stTryNowRestrictedProcedurePlanResult(
            'ready_for_restricted_procedure_test_only'
        );

        $result['procedure_call_candidate'] = true;
        $result['procedure'] = $procedure;
        $result['parameters'] = $parameters;

        return $result;

    } catch (Throwable $e) {
        return stTryNowRestrictedProcedurePlanResult(
            'invalid_authenticated_evidence'
        );
    }
}
