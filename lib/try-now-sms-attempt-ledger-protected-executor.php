<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Protected-key ledger executor.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * No caller-supplied HMAC keys or SQL parameters.
 * Operates only on disposable security-probe ledger.
 *
 * Does not begin, commit or roll back transactions.
 * Never calls Sendmode or authorises SMS.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-protected-keys.php';

require_once __DIR__
    . '/try-now-sms-attempt-ledger-procedure-executor.php';

function stTryNowProtectedExecutionResult(
    string $status
): array {
    $result = stTryNowRestrictedExecutionResult(
        $status
    );

    $result['protected_binding_verified'] = false;
    $result['requires_authoritative_cas'] = true;

    return $result;
}

/**
 * Execute an authenticated synthetic ledger operation.
 *
 * Accepted inputs:
 * - Restricted PDO connection
 * - Operation: reserve, initialize or advance
 * - Synthetic reservation and journal evidence
 * - Explicit CLI test opt-in
 *
 * Cryptographic keys are loaded internally.
 * No arbitrary procedures, tables or SQL accepted.
 */
function stTryNowExecuteProtectedSyntheticLedgerProcedure(
    PDO $pdo,
    string $operation,
    array $reservation,
    array $journalRecords,
    array $candidateSeal,
    bool $allowCliTest = false
): array {
    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return stTryNowProtectedExecutionResult(
            'disabled'
        );
    }

    $allowed = [
        'reserve' => [
            'procedure' =>
                'try_now_ledger_probe_reserve',
            'parameters' => 5,
        ],
        'initialize' => [
            'procedure' =>
                'try_now_ledger_probe_initialize',
            'parameters' => 7,
        ],
        'advance' => [
            'procedure' =>
                'try_now_ledger_probe_advance',
            'parameters' => 12,
        ],
    ];

    if (!isset($allowed[$operation])) {
        return stTryNowProtectedExecutionResult(
            'unsupported_operation'
        );
    }

    if (!stTryNowLedgerRecoveryReservationValid(
        $reservation
    )) {
        return stTryNowProtectedExecutionResult(
            'invalid_reservation'
        );
    }

    if (!stTryNowProtectedProbeSyntheticValid(
        $reservation['campaign_id'],
        $reservation['customer_id'],
        $reservation['attempt_id']
    )) {
        return stTryNowProtectedExecutionResult(
            'invalid_synthetic_identity'
        );
    }

    /*
     * The only source of cryptographic material
     * is the fixed private test keyring.
     */
    $keys = stTryNowProtectedProbeLoadKeys();

    if ($keys === null) {
        return stTryNowProtectedExecutionResult(
            'protected_keyring_unavailable'
        );
    }

    try {
        /*
         * The reservation's recipient digest must
         * match the independently derived synthetic
         * binding from the protected keyring.
         */
        $expectedRecipient =
            stTryNowProtectedProbeRecipientDigest(
                $reservation['attempt_id'],
                $keys['recipient']
            );

        if (!hash_equals(
            $expectedRecipient,
            $reservation['recipient_digest']
        )) {
            return stTryNowProtectedExecutionResult(
                'recipient_binding_mismatch'
            );
        }

        /*
         * Authenticate the complete journal and
         * construct the approved procedure plan.
         *
         * No database interaction occurs here.
         */
        $plan = stTryNowRestrictedProcedureCallPlan(
            $operation,
            $reservation,
            $journalRecords,
            $candidateSeal,
            $keys['namespace'],
            $keys['binding'],
            $keys['record'],
            $keys['seal'],
            true
        );

        if (
            $plan['procedure_call_candidate'] !== true ||
            $plan['status'] !==
                'ready_for_restricted_procedure_test_only'
        ) {
            return stTryNowProtectedExecutionResult(
                'plan_' . $plan['status']
            );
        }

        $rule = $allowed[$operation];

        if (
            $plan['procedure'] !==
                $rule['procedure'] ||
            !is_array($plan['parameters']) ||
            count($plan['parameters']) !==
                $rule['parameters']
        ) {
            return stTryNowProtectedExecutionResult(
                'unapproved_procedure_plan'
            );
        }

        /*
         * The lower executor:
         * - checks the restricted database account
         * - requires an active transaction
         * - reconstructs the plan independently
         * - calls only the approved probe procedure
         *
         * It receives the protected keys only from
         * this trusted server-side adapter.
         */
        $execution =
            stTryNowExecuteRestrictedSyntheticLedgerProcedure(
                $pdo,
                $operation,
                $reservation,
                $journalRecords,
                $candidateSeal,
                $keys['namespace'],
                $keys['binding'],
                $keys['record'],
                $keys['seal'],
                true
            );

        $status = $execution['status'] ??
            'database_outcome_requires_review';

        if (
            !is_string($status) ||
            $status === ''
        ) {
            return stTryNowProtectedExecutionResult(
                'database_outcome_requires_review'
            );
        }

        /*
         * A successful procedure call is not a
         * committed checkpoint.
         *
         * The caller must retain transaction
         * control and separately assess COMMIT.
         */
        if (
            $status ===
                'executed_uncommitted_test_only'
        ) {
            if (
                ($execution[
                    'procedure_executed_in_transaction'
                ] ?? null) !== true ||
                !$pdo->inTransaction()
            ) {
                return stTryNowProtectedExecutionResult(
                    'transaction_lost_requires_review'
                );
            }

            $result =
                stTryNowProtectedExecutionResult(
                    'executed_uncommitted_test_only'
                );

            $result[
                'protected_binding_verified'
            ] = true;

            $result[
                'procedure_executed_in_transaction'
            ] = true;

            return $result;
        }

        /*
         * Every rejected or uncertain operation
         * returns a non-deliverable result.
         */
        return stTryNowProtectedExecutionResult(
            $status
        );

    } catch (Throwable $e) {
        return stTryNowProtectedExecutionResult(
            'protected_execution_requires_review'
        );
    }
}
