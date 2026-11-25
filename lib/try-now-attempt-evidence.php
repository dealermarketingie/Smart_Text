<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - SMS attempt evidence.
 *
 * PURE DECISION LOGIC.
 *
 * No database, filesystem, HTTP, Redis or SMS operations.
 * Never triggers sending, retries or cleanup.
 *
 * PHP 7.4 compatible.
 */

/**
 * Interpret a complete, trusted sequence of
 * SMS attempt journal events.
 *
 * Supported events:
 *
 * prepared
 * cancelled_before_transport
 * transport_started
 * provider_accepted
 * provider_rejected
 * provider_unknown
 *
 * IMPORTANT:
 *
 * Missing or incomplete evidence is NOT proof
 * that Sendmode was never contacted.
 *
 * A future journal writer must durably record
 * transport_started BEFORE invoking Sendmode.
 *
 * If that journal operation fails, the transport
 * must not be invoked.
 *
 * cancelled_before_transport is only valid when
 * the controlled workflow can establish that no
 * transport invocation occurred.
 */
function stTryNowClassifyAttemptHistory(
    array $events
): array {
    $result = [
        'attempt_state' => 'unknown',
        'provider_evidence' => null,
        'sequence_valid' => false,
        'delivered' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    // An absent journal cannot prove non-delivery.
    if ($events === [] || count($events) > 4) {
        return $result;
    }

    /*
     * Explicitly permitted state transitions.
     *
     * Invalid, contradictory or reordered events
     * must fail closed.
     */
    $transitions = [
        'initial' => [
            'prepared' => 'prepared',
        ],
        'prepared' => [
            'cancelled_before_transport' => 'cancelled',
            'transport_started' => 'started',
        ],
        'started' => [
            'provider_accepted' => 'accepted',
            'provider_rejected' => 'rejected',
            'provider_unknown' => 'response_unknown',
        ],
    ];

    $state = 'initial';

    foreach ($events as $entry) {
        if (
            !is_array($entry) ||
            !is_string($entry['event'] ?? null)
        ) {
            return $result;
        }

        $next = $transitions[$state][$entry['event']]
            ?? null;

        if ($next === null) {
            return $result;
        }

        $state = $next;
    }

    // The event order is structurally valid.
    // This does not independently authenticate
    // the source or completeness of a journal.
    $result['sequence_valid'] = true;

    if ($state === 'cancelled') {
        $result['attempt_state'] =
            'confirmed_not_attempted';

        return $result;
    }

    if (in_array(
        $state,
        [
            'started',
            'accepted',
            'rejected',
            'response_unknown',
        ],
        true
    )) {
        $result['attempt_state'] = 'attempted';
    }

    if ($state === 'accepted') {
        $result['provider_evidence'] = 'accepted';

    } elseif ($state === 'rejected') {
        $result['provider_evidence'] = 'rejected';

    } elseif ($state === 'response_unknown') {
        $result['provider_evidence'] = 'unknown';
    }

    return $result;
}
