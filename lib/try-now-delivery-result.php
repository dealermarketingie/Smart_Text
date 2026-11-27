<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Final delivery decision.
 *
 * PURE DECISION LOGIC.
 *
 * No database connections, customer deletion,
 * Redis operations or Sendmode requests.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-delivery-policy.php';

/**
 * Interpret registration and Sendmode outcomes.
 *
 * A provider acceptance is not proof that
 * the SMS reached the recipient.
 *
 * Never automatically retry or delete records.
 */
function stTryNowFinalDeliveryDecision(
    array $registration,
    ?array $providerResult
): array {
    // Conservative default: preserve potentially
    // committed records and require manual review.
    $result = [
        'status' => 'review_required',
        'customer_action' => 'preserve',
        'provider_accepted' => false,
        'delivered' => false,
        'manual_review' => true,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    $registrationStatus =
        $registration['status'] ?? null;

    // These registration results explicitly indicate
    // that no new customer was committed.
    if (in_array(
        $registrationStatus,
        ['disabled', 'invalid', 'duplicate', 'unavailable'],
        true
    )) {
        $result['status'] = 'not_registered';
        $result['customer_action'] = 'none';
        $result['manual_review'] = false;

        return $result;
    }

    // Unknown registration outcomes may conceal a
    // committed transaction. Preserve for review.
    if ($registrationStatus !== 'created') {
        return $result;
    }

    // Verify essential identifiers from the
    // completed registration transaction.
    $customerId = $registration['customer_id'] ?? null;
    $code = $registration['code'] ?? null;

    if (
        !is_int($customerId) ||
        $customerId <= 0 ||
        !is_string($code) ||
        preg_match(
            '/^[A-Za-z][A-Za-z0-9]{5}$/D',
            $code
        ) !== 1
    ) {
        return $result;
    }

    // Registration was committed, but an SMS
    // provider response may be unavailable.
    if ($providerResult === null) {
        return $result;
    }

    $decision = stTryNowDeliveryDecision(
        $providerResult
    );

    if ($decision['status'] === 'accepted') {
        $result['status'] = 'provider_accepted';
        $result['provider_accepted'] = true;
        $result['manual_review'] = false;

        // Still not a delivery confirmation.
        return $result;
    }

    // Rejected, unknown or disabled Sendmode
    // results retain the customer for review.
    //
    // Production deletion is deliberately excluded.
    return $result;
}
