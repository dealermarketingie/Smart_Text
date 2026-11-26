<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Ledger checkpoint initialization.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * Initializes checkpoint sequence 1 from a valid,
 * authenticated "prepared" journal record.
 *
 * No connections, transaction control, filesystem
 * operations, Redis, HTTP or SMS.
 *
 * The caller owns the transaction.
 * No successful result authorises transport.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-reservation.php';

require_once __DIR__
    . '/try-now-attempt-journal-binding.php';

require_once __DIR__
    . '/try-now-attempt-journal-seal.php';

function stTryNowLedgerInitResult(
    string $status
): array {
    return [
        'status' => $status,
        'checkpoint_initialized_in_transaction' => false,
        'checkpoint_committed' => false,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}

/**
 * Initialize the first checkpoint against an
 * existing reserved attempt.
 *
 * The database UPDATE is conditional on:
 * - Exact reserved identity
 * - Exact independently calculated journal slot
 * - Exact recipient digest
 * - Checkpoint sequence and revision both zero
 * - All checkpoint fields still NULL
 *
 * Affected rows must equal exactly one.
 *
 * Does not prove the journal file was fsynced.
 * Does not prove a database COMMIT succeeded.
 */
function stTryNowInitializeSyntheticLedgerCheckpoint(
    PDO $pdo,
    array $reservation,
    array $journalRecords,
    array $preparedSeal,
    string $namespace,
    string $bindingKey,
    string $recordKey,
    string $sealKey,
    bool $allowCliTest = false
): array {
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
        return stTryNowLedgerInitResult('disabled');
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
        $reservation['customer_id'] > 4294967295 ||
        !in_array(
            $reservation['campaign_id'],
            array_column(
                stTryNowSamples(),
                'campaign_id'
            ),
            true
        )
    ) {
        return stTryNowLedgerInitResult(
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
                '/\A[0-9a-f]{' . $length . '}\z/',
                $reservation[$field]
            ) !== 1
        ) {
            return stTryNowLedgerInitResult(
                'invalid_reservation'
            );
        }
    }

    if (
        strlen($bindingKey) < 32 ||
        strlen($recordKey) < 32 ||
        strlen($sealKey) < 32 ||
        hash_equals($bindingKey, $recordKey) ||
        hash_equals($bindingKey, $sealKey) ||
        hash_equals($recordKey, $sealKey)
    ) {
        return stTryNowLedgerInitResult(
            'invalid_keys'
        );
    }

    /*
     * Recalculate the journal slot.
     * Do not blindly trust a caller-supplied ID.
     */
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
            return stTryNowLedgerInitResult(
                'journal_slot_mismatch'
            );
        }

    } catch (Throwable $e) {
        return stTryNowLedgerInitResult(
            'invalid_journal_binding'
        );
    }

    /*
     * Initialization accepts exactly one prepared
     * journal record, not an arbitrary history.
     */
    if (
        count($journalRecords) !== 1 ||
        !isset($journalRecords[0]) ||
        !is_array($journalRecords[0])
    ) {
        return stTryNowLedgerInitResult(
            'invalid_prepared_evidence'
        );
    }

    $record = $journalRecords[0];

    $verification = stTryNowVerifyJournalHeadSeal(
        $journalRecords,
        $preparedSeal,
        $recordKey,
        $sealKey
    );

    if (
        $verification['valid'] !== true ||
        ($record['event'] ?? null) !== 'prepared' ||
        ($record['sequence'] ?? null) !== 1 ||
        ($preparedSeal['record_count'] ?? null) !== 1 ||
        ($preparedSeal['head_event'] ?? null) !== 'prepared'
    ) {
        return stTryNowLedgerInitResult(
            'invalid_prepared_evidence'
        );
    }

    /*
     * The authenticated journal identity must
     * match the reservation exactly.
     */
    if (
        $record['attempt_id'] !==
            $reservation['attempt_id'] ||
        $record['customer_id'] !==
            $reservation['customer_id'] ||
        $record['campaign_id'] !==
            $reservation['campaign_id']
    ) {
        return stTryNowLedgerInitResult(
            'journal_identity_mismatch'
        );
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
            return stTryNowLedgerInitResult(
                'unauthorised_connection'
            );
        }

        if (!$pdo->inTransaction()) {
            return stTryNowLedgerInitResult(
                'transaction_required'
            );
        }

        $identity = $pdo->query(
            'SELECT DATABASE() AS db,
                    CURRENT_USER() AS db_user'
        )->fetch(PDO::FETCH_ASSOC);

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
            return stTryNowLedgerInitResult(
                'unauthorised_connection'
            );
        }

        /*
         * Atomic compare-and-swap:
         * update only an untouched reservation.
         *
         * No SELECT-then-UPDATE race.
         * No INSERT, REPLACE or UPSERT.
         */
        $update = $pdo->prepare(
            'UPDATE try_now_sms_attempt_ledger
             SET checkpoint_sequence = 1,
                 checkpoint_revision = 1,
                 checkpoint_event = ?,
                 checkpoint_head_mac = ?,
                 checkpoint_seal_mac = ?,
                 checkpoint_updated_at = UTC_TIMESTAMP(6)
             WHERE campaign_id = ?
               AND customer_id = ?
               AND attempt_id = ?
               AND journal_slot_id = ?
               AND recipient_digest = ?
               AND checkpoint_sequence = 0
               AND checkpoint_revision = 0
               AND checkpoint_event IS NULL
               AND checkpoint_head_mac IS NULL
               AND checkpoint_seal_mac IS NULL
               AND checkpoint_updated_at IS NULL'
        );

        $update->execute([
            'prepared',
            $preparedSeal['head_mac'],
            $preparedSeal['seal_mac'],
            $reservation['campaign_id'],
            $reservation['customer_id'],
            $reservation['attempt_id'],
            $reservation['journal_slot_id'],
            $reservation['recipient_digest'],
        ]);

        if ($update->rowCount() !== 1) {
            return stTryNowLedgerInitResult(
                'checkpoint_conflict_requires_review'
            );
        }

        $result = stTryNowLedgerInitResult(
            'initialized_uncommitted_test_only'
        );

        $result[
            'checkpoint_initialized_in_transaction'
        ] = true;

        return $result;

    } catch (Throwable $e) {
        return stTryNowLedgerInitResult(
            'database_outcome_requires_review'
        );
    }
}
