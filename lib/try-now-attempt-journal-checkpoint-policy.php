<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Monotonic checkpoint policy.
 *
 * PURE DECISION LOGIC ONLY.
 * PHP 7.4 compatible.
 *
 * No filesystem, database, Redis, HTTP or SMS.
 *
 * Validates a proposed checkpoint advancement
 * against an independently supplied prior seal.
 *
 * Does NOT read, write or lock a persistent store.
 * Does NOT authorise SMS transport.
 */

require_once __DIR__
    . '/try-now-attempt-journal-seal.php';

function stTryNowCheckpointAdvancementPlan(
    array $journalRecords,
    ?array $persistedSeal,
    array $candidateSeal,
    string $recordKey,
    string $sealKey
): array {
    $result = [
        'status' => 'review_required',
        'checkpoint_update_candidate' => false,
        'requires_authoritative_cas' => true,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    /*
     * A missing authoritative checkpoint is
     * never interpreted as a fresh customer.
     *
     * First-time initialization requires a
     * separate, trusted admission protocol.
     */
    if ($persistedSeal === null) {
        $result['status'] = 'missing_persisted_checkpoint';
        return $result;
    }

    $recordCount = count($journalRecords);

    if ($recordCount < 2 || $recordCount > 3) {
        $result['status'] = 'invalid_journal_length';
        return $result;
    }

    /*
     * The proposed checkpoint must authenticate
     * the entire current journal.
     */
    $candidate = stTryNowVerifyJournalHeadSeal(
        $journalRecords,
        $candidateSeal,
        $recordKey,
        $sealKey
    );

    if ($candidate['valid'] !== true) {
        $result['status'] = 'invalid_candidate_checkpoint';
        return $result;
    }

    $previousCount =
        $persistedSeal['record_count'] ?? null;

    /*
     * Only one-record progression is accepted.
     *
     * No rollback, duplicate update, skipped
     * checkpoint or unexpected history length.
     */
    if (
        !is_int($previousCount) ||
        $previousCount < 1 ||
        $previousCount > 2 ||
        $previousCount + 1 !== $recordCount
    ) {
        $result['status'] = 'non_monotonic_checkpoint';
        return $result;
    }

    /*
     * Authenticate the previously persisted
     * checkpoint against the corresponding
     * journal prefix.
     *
     * This assumes the persisted checkpoint
     * came from an independently trusted store.
     */
    $prefix = array_slice(
        $journalRecords,
        0,
        $previousCount
    );

    $previous = stTryNowVerifyJournalHeadSeal(
        $prefix,
        $persistedSeal,
        $recordKey,
        $sealKey
    );

    if ($previous['valid'] !== true) {
        $result['status'] = 'persisted_checkpoint_mismatch';
        return $result;
    }

    /*
     * Reject changes to customer, campaign or
     * attempt identity across an advancement.
     */
    foreach ([
        'attempt_id',
        'customer_id',
        'campaign_id',
    ] as $field) {
        if ($persistedSeal[$field] !== $candidateSeal[$field]) {
            $result['status'] = 'identity_mismatch';
            return $result;
        }
    }

    /*
     * This is only permission to PROPOSE an
     * atomic, conditional checkpoint update.
     *
     * The future store must independently enforce
     * compare-and-swap and durable monotonicity.
     */
    $result['status'] =
        'candidate_for_atomic_cas_test_only';

    $result['checkpoint_update_candidate'] = true;

    return $result;
}