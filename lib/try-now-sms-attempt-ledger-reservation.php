<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - SMS attempt reservation.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * Never opens a database connection.
 * Never begins, commits or rolls back a transaction.
 * Never invokes Sendmode or authorises SMS.
 *
 * The caller owns the complete transaction.
 */

require_once __DIR__ . '/try-now-runtime.php';
require_once __DIR__ . '/try-now-support.php';

function stTryNowLedgerReservationResult(
    string $status
): array {
    return [
        'status' => $status,
        'reserved_in_current_transaction' => false,
        'reservation_committed' => false,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}

/**
 * Reserve one synthetic SMS attempt.
 *
 * Required input keys, in order:
 *   campaign_id
 *   customer_id
 *   attempt_id
 *   journal_slot_id
 *   recipient_digest
 *
 * The unique database constraints provide
 * concurrency protection.
 *
 * IMPORTANT:
 * A successful INSERT is still UNCOMMITTED.
 * It is not proof that a durable reservation exists.
 */
function stTryNowReserveSyntheticLedgerAttempt(
    PDO $pdo,
    array $reservation,
    bool $allowCliTest = false
): array {
    $disabled = stTryNowLedgerReservationResult(
        'disabled'
    );

    if (
        !$allowCliTest ||
        PHP_SAPI !== 'cli' ||
        DIRECTORY_SEPARATOR !== '/' ||
        realpath(__DIR__) !==
            '/var/www/vhosts/smarttext.com/try-now-check/lib' ||
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED !== false ||
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED !== false ||
        ST_TRY_NOW_SMS_COPY_APPROVED !== false ||
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED !== false ||
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED !== false ||
        stTryNowProductionDeliveryEnabled() !== false
    ) {
        return $disabled;
    }

    $expectedKeys = [
        'campaign_id',
        'customer_id',
        'attempt_id',
        'journal_slot_id',
        'recipient_digest',
    ];

    if (
        array_keys($reservation) !== $expectedKeys ||
        !is_int($reservation['campaign_id']) ||
        !is_int($reservation['customer_id']) ||
        $reservation['customer_id'] <= 0 ||
        !in_array(
            $reservation['campaign_id'],
            array_column(
                stTryNowSamples(),
                'campaign_id'
            ),
            true
        )
    ) {
        return stTryNowLedgerReservationResult(
            'invalid_reservation'
        );
    }

    foreach ([
        'attempt_id' => 32,
        'journal_slot_id' => 64,
        'recipient_digest' => 64,
    ] as $field => $length) {
        if (
            !is_string($reservation[$field]) ||
            preg_match(
                '/\A[0-9a-f]{' . $length . '}\z/D',
                $reservation[$field]
            ) !== 1
        ) {
            return stTryNowLedgerReservationResult(
                'invalid_reservation'
            );
        }
    }

    try {
        if (
            $pdo->getAttribute(
                PDO::ATTR_DRIVER_NAME
            ) !== 'mysql' ||
            $pdo->getAttribute(
                PDO::ATTR_ERRMODE
            ) !== PDO::ERRMODE_EXCEPTION
        ) {
            return stTryNowLedgerReservationResult(
                'unauthorised_connection'
            );
        }

        if (!$pdo->inTransaction()) {
            return stTryNowLedgerReservationResult(
                'transaction_required'
            );
        }

        $statement = $pdo->query(
            'SELECT DATABASE() AS db,
                    CURRENT_USER() AS db_user'
        );

        $identity = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (
            !is_array($identity) ||
            ($identity['db'] ?? null) !==
                'smarttext_try_now_test' ||
            !is_string(
                $identity['db_user'] ?? null
            ) ||
            strpos(
                $identity['db_user'],
                'try_now_test@'
            ) !== 0
        ) {
            return stTryNowLedgerReservationResult(
                'unauthorised_connection'
            );
        }

        /*
         * No SELECT-then-INSERT race.
         *
         * The INSERT itself is authoritative.
         * InnoDB enforces all four unique keys.
         */
        $insert = $pdo->prepare(
            'INSERT INTO try_now_sms_attempt_ledger
                (
                    campaign_id,
                    customer_id,
                    attempt_id,
                    journal_slot_id,
                    recipient_digest
                )
             VALUES (?, ?, ?, ?, ?)'
        );

        $insert->execute([
            $reservation['campaign_id'],
            $reservation['customer_id'],
            $reservation['attempt_id'],
            $reservation['journal_slot_id'],
            $reservation['recipient_digest'],
        ]);

        if ($insert->rowCount() !== 1) {
            return stTryNowLedgerReservationResult(
                'write_outcome_requires_review'
            );
        }

        $result = stTryNowLedgerReservationResult(
            'reserved_uncommitted_test_only'
        );

        $result[
            'reserved_in_current_transaction'
        ] = true;

        return $result;

    } catch (PDOException $e) {
        /*
         * Duplicate-key rejection must never
         * trigger recreation or retry.
         */
        if (
            (int) ($e->errorInfo[1] ?? 0) === 1062
        ) {
            return stTryNowLedgerReservationResult(
                'reservation_exists_requires_review'
            );
        }

        /*
         * Other SQL failures may involve
         * unavailable or uncertain state.
         */
        return stTryNowLedgerReservationResult(
            'database_outcome_requires_review'
        );

    } catch (Throwable $e) {
        return stTryNowLedgerReservationResult(
            'database_outcome_requires_review'
        );
    }
}
