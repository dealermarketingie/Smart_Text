<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Atomic ledger checkpoint advancement.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * Advances authenticated checkpoint state:
 *   1 -> 2
 *   2 -> 3
 *
 * Also supports:
 *   prepared -> cancelled_before_transport
 *
 * Never opens a database connection.
 * Never begins, commits or rolls back transactions.
 * Never invokes Sendmode or authorises SMS.
 *
 * The caller owns the complete transaction.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-initialize.php';

require_once __DIR__
    . '/try-now-attempt-journal-checkpoint-policy.php';

function stTryNowLedgerAdvanceResult(
    string $status
): array {
    return [
        'status' => $status,
        'checkpoint_advanced_in_transaction' => false,
        'checkpoint_committed' => false,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}

function stTryNowAdvanceSyntheticLedgerCheckpoint(
    PDO $pdo,
    array $reservation,
    array $journalRecords,
    array $candidateSeal,
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
        return stTryNowLedgerAdvanceResult('disabled');
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
            array_column(stTryNowSamples(), 'campaign_id'),
            true
        )
    ) {
        return stTryNowLedgerAdvanceResult(
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
            return stTryNowLedgerAdvanceResult(
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
        return stTryNowLedgerAdvanceResult(
            'invalid_keys'
        );
    }

    try {
        $slot = stTryNowJournalSlotId(
            $namespace,
            $reservation['campaign_id'],
            $reservation['customer_id'],
            $bindingKey
        );

        if (!hash_equals(
            $slot,
            $reservation['journal_slot_id']
        )) {
            return stTryNowLedgerAdvanceResult(
                'journal_slot_mismatch'
            );
        }

    } catch (Throwable $e) {
        return stTryNowLedgerAdvanceResult(
            'invalid_journal_binding'
        );
    }

    /*
     * Only complete, sequential journals with
     * two or three authenticated records qualify.
     */
    $count = count($journalRecords);

    if ($count !== 2 && $count !== 3) {
        return stTryNowLedgerAdvanceResult(
            'invalid_journal_length'
        );
    }

    $candidate = stTryNowVerifyJournalHeadSeal(
        $journalRecords,
        $candidateSeal,
        $recordKey,
        $sealKey
    );

    if ($candidate['valid'] !== true) {
        return stTryNowLedgerAdvanceResult(
            'invalid_candidate_checkpoint'
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
        return stTryNowLedgerAdvanceResult(
            'journal_identity_mismatch'
        );
    }

    /*
     * Reconstruct the expected previous seal
     * from the authenticated journal prefix.
     *
     * Do not accept a caller-supplied previous
     * checkpoint as authoritative.
     */
    $previousCount = $count - 1;

    try {
        $previousSeal = stTryNowBuildJournalHeadSeal(
            array_slice(
                $journalRecords,
                0,
                $previousCount
            ),
            $recordKey,
            $sealKey
        );

        $plan = stTryNowCheckpointAdvancementPlan(
            $journalRecords,
            $previousSeal,
            $candidateSeal,
            $recordKey,
            $sealKey
        );

        if (
            $plan['checkpoint_update_candidate']
                !== true
        ) {
            return stTryNowLedgerAdvanceResult(
                'invalid_checkpoint_progression'
            );
        }

    } catch (Throwable $e) {
        return stTryNowLedgerAdvanceResult(
            'invalid_checkpoint_progression'
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
            return stTryNowLedgerAdvanceResult(
                'unauthorised_connection'
            );
        }

        if (!$pdo->inTransaction()) {
            return stTryNowLedgerAdvanceResult(
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
            return stTryNowLedgerAdvanceResult(
                'unauthorised_connection'
            );
        }

        /*
         * Read the current checkpoint state.
         *
         * This SELECT is not the concurrency
         * control. The conditional UPDATE below
         * performs the atomic comparison.
         */
        $read = $pdo->prepare(
            'SELECT attempt_id,
                    journal_slot_id,
                    recipient_digest,
                    checkpoint_sequence,
                    checkpoint_revision,
                    checkpoint_event,
                    checkpoint_head_mac,
                    checkpoint_seal_mac,
                    checkpoint_updated_at
             FROM try_now_sms_attempt_ledger
             WHERE campaign_id = ?
               AND customer_id = ?'
        );

        $read->execute([
            $reservation['campaign_id'],
            $reservation['customer_id'],
        ]);

        $stored = $read->fetch(PDO::FETCH_ASSOC);

        if (!is_array($stored)) {
            return stTryNowLedgerAdvanceResult(
                'checkpoint_conflict_requires_review'
            );
        }

        if (
            $stored['attempt_id'] !==
                $reservation['attempt_id'] ||
            $stored['journal_slot_id'] !==
                $reservation['journal_slot_id'] ||
            $stored['recipient_digest'] !==
                $reservation['recipient_digest']
        ) {
            return stTryNowLedgerAdvanceResult(
                'reservation_mismatch_requires_review'
            );
        }

        if (
            (int) $stored['checkpoint_sequence']
                !== $previousCount ||
            (int) $stored['checkpoint_revision']
                !== $previousCount
        ) {
            return stTryNowLedgerAdvanceResult(
                'checkpoint_conflict_requires_review'
            );
        }

        /*
         * The persisted checkpoint must match
         * the authenticated history prefix.
         */
        if (
            $stored['checkpoint_event'] !==
                $previousSeal['head_event'] ||
            !is_string(
                $stored['checkpoint_head_mac']
            ) ||
            !is_string(
                $stored['checkpoint_seal_mac']
            ) ||
            !hash_equals(
                $previousSeal['head_mac'],
                $stored['checkpoint_head_mac']
            ) ||
            !hash_equals(
                $previousSeal['seal_mac'],
                $stored['checkpoint_seal_mac']
            ) ||
            $stored['checkpoint_updated_at'] === null
        ) {
            return stTryNowLedgerAdvanceResult(
                'persisted_checkpoint_mismatch'
            );
        }

        /*
         * Atomic compare-and-swap.
         *
         * Match:
         * - The reserved customer and attempt
         * - The exact journal and recipient binding
         * - The previous sequence and revision
         * - The previous event and both HMACs
         *
         * A competing update makes rowCount() zero.
         */
        $update = $pdo->prepare(
            'UPDATE try_now_sms_attempt_ledger
             SET checkpoint_sequence = ?,
                 checkpoint_revision = ?,
                 checkpoint_event = ?,
                 checkpoint_head_mac = ?,
                 checkpoint_seal_mac = ?,
                 checkpoint_updated_at =
                    UTC_TIMESTAMP(6)
             WHERE campaign_id = ?
               AND customer_id = ?
               AND attempt_id = ?
               AND journal_slot_id = ?
               AND recipient_digest = ?
               AND checkpoint_sequence = ?
               AND checkpoint_revision = ?
               AND checkpoint_event = ?
               AND checkpoint_head_mac = ?
               AND checkpoint_seal_mac = ?
               AND checkpoint_updated_at IS NOT NULL'
        );

        $update->execute([
            $candidateSeal['record_count'],
            $candidateSeal['record_count'],
            $candidateSeal['head_event'],
            $candidateSeal['head_mac'],
            $candidateSeal['seal_mac'],

            $reservation['campaign_id'],
            $reservation['customer_id'],
            $reservation['attempt_id'],
            $reservation['journal_slot_id'],
            $reservation['recipient_digest'],

            $previousCount,
            $previousCount,
            $previousSeal['head_event'],
            $previousSeal['head_mac'],
            $previousSeal['seal_mac'],
        ]);

        if ($update->rowCount() !== 1) {
            return stTryNowLedgerAdvanceResult(
                'checkpoint_conflict_requires_review'
            );
        }

        $result = stTryNowLedgerAdvanceResult(
            'advanced_uncommitted_test_only'
        );

        $result[
            'checkpoint_advanced_in_transaction'
        ] = true;

        return $result;

    } catch (Throwable $e) {
        return stTryNowLedgerAdvanceResult(
            'database_outcome_requires_review'
        );
    }
}
