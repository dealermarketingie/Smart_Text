<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Restricted ledger procedure executor.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * - Re-authenticates journal evidence internally.
 * - Uses only three hardcoded probe procedures.
 * - Requires the exact restricted executor account.
 * - Requires a caller-owned active transaction.
 *
 * Never begins, commits or rolls back transactions.
 * Never calls Sendmode or authorises SMS.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-procedure-plan.php';

function stTryNowRestrictedExecutionResult(
    string $status
): array {
    return [
        'status' => $status,
        'procedure_executed_in_transaction' => false,
        'checkpoint_committed' => false,
        'review_required' => true,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}

/**
 * Execute one authenticated operation against the
 * disposable security-probe ledger.
 *
 * Important:
 * The caller supplies journal evidence, NOT a
 * previously prepared procedure-call plan.
 *
 * This function regenerates and validates the
 * procedure arguments before issuing SQL.
 */
function stTryNowExecuteRestrictedSyntheticLedgerProcedure(
    PDO $pdo,
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
        return stTryNowRestrictedExecutionResult(
            'disabled'
        );
    }

    /*
     * Verify the active database connection first.
     * The isolated executor uses no default database.
     *
     * The database server must match the established
     * MariaDB 11.4 isolated test environment.
     */
    try {
        if (
            $pdo->getAttribute(
                PDO::ATTR_DRIVER_NAME
            ) !== 'mysql' ||
            $pdo->getAttribute(
                PDO::ATTR_ERRMODE
            ) !== PDO::ERRMODE_EXCEPTION
        ) {
            return stTryNowRestrictedExecutionResult(
                'unauthorised_connection'
            );
        }

        if (!$pdo->inTransaction()) {
            return stTryNowRestrictedExecutionResult(
                'transaction_required'
            );
        }

        $identity = $pdo->query(
            'SELECT
                DATABASE() AS db,
                CURRENT_USER() AS db_user,
                @@port AS server_port,
                VERSION() AS server_version'
        )->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($identity) ||
            $identity['db'] !== null ||
            $identity['db_user'] !==
                'try_now_ledger_executor@172.17.0.1' ||
            (int) $identity['server_port'] !== 3306 ||
            !is_string($identity['server_version']) ||
            strpos(
                $identity['server_version'],
                '11.4.'
            ) !== 0
        ) {
            return stTryNowRestrictedExecutionResult(
                'unauthorised_connection'
            );
        }

    } catch (Throwable $e) {
        return stTryNowRestrictedExecutionResult(
            'database_outcome_requires_review'
        );
    }

    /*
     * The planner is called internally.
     * Never trust an externally supplied array of
     * SQL arguments or procedure names.
     */
    $plan = stTryNowRestrictedProcedureCallPlan(
        $operation,
        $reservation,
        $journalRecords,
        $candidateSeal,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey,
        true
    );

    if (
        $plan['procedure_call_candidate'] !== true ||
        $plan['status'] !==
            'ready_for_restricted_procedure_test_only'
    ) {
        return stTryNowRestrictedExecutionResult(
            'plan_' . $plan['status']
        );
    }

    /*
     * Fixed SQL allowlist.
     *
     * The plan can never select an arbitrary table
     * or stored procedure.
     */
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

    $rule = $allowed[$operation] ?? null;

    if (
        !is_array($rule) ||
        $plan['procedure'] !== $rule['procedure'] ||
        !is_array($plan['parameters']) ||
        count($plan['parameters']) !==
            $rule['parameters'] ||
        array_keys($plan['parameters']) !==
            range(0, $rule['parameters'] - 1)
    ) {
        return stTryNowRestrictedExecutionResult(
            'unapproved_procedure_plan'
        );
    }

    $placeholders = implode(
        ', ',
        array_fill(
            0,
            $rule['parameters'],
            '?'
        )
    );

    $sql = 'CALL smarttext_try_now_test.' .
        $rule['procedure'] .
        '(' . $placeholders . ')';

    $statement = null;

    try {
        $statement = $pdo->prepare($sql);

        if (!$statement instanceof PDOStatement) {
            return stTryNowRestrictedExecutionResult(
                'database_outcome_requires_review'
            );
        }

        $statement->execute($plan['parameters']);

        if (!$statement->closeCursor()) {
            return stTryNowRestrictedExecutionResult(
                'database_outcome_requires_review'
            );
        }

        /*
         * A procedure must not silently terminate
         * the caller's transaction.
         */
        if (!$pdo->inTransaction()) {
            return stTryNowRestrictedExecutionResult(
                'transaction_lost_requires_review'
            );
        }

        $result = stTryNowRestrictedExecutionResult(
            'executed_uncommitted_test_only'
        );

        $result[
            'procedure_executed_in_transaction'
        ] = true;

        return $result;

    } catch (PDOException $e) {
        if ($statement instanceof PDOStatement) {
            try {
                $statement->closeCursor();
            } catch (Throwable $ignored) {
                // Preserve the original SQL failure.
            }
        }

        $errorNumber = (int) (
            $e->errorInfo[1] ?? 0
        );

        if ($errorNumber === 1062) {
            return stTryNowRestrictedExecutionResult(
                'reservation_exists_requires_review'
            );
        }

        if ($errorNumber === 1644) {
            return stTryNowRestrictedExecutionResult(
                'procedure_rejected_requires_review'
            );
        }

        if (in_array(
            $errorNumber,
            [1044, 1142, 1143, 1370],
            true
        )) {
            return stTryNowRestrictedExecutionResult(
                'permission_denied_requires_review'
            );
        }

        return stTryNowRestrictedExecutionResult(
            'database_outcome_requires_review'
        );

    } catch (Throwable $e) {
        if ($statement instanceof PDOStatement) {
            try {
                $statement->closeCursor();
            } catch (Throwable $ignored) {
            }
        }

        return stTryNowRestrictedExecutionResult(
            'database_outcome_requires_review'
        );
    }
}
