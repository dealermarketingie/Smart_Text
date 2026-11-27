<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Manual reconciliation policy.
 *
 * PURE DECISION LOGIC ONLY.
 *
 * No database access, Redis, HTTP, SMS, customer
 * deletion, automatic retry or automatic sending.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-delivery-result.php';

/**
 * $attemptState must be one of:
 *
 * confirmed_not_attempted:
 *   Reliable evidence that transport was not invoked.
 *
 * attempted:
 *   The transport was invoked. Sendmode may have
 *   received the request even without a response.
 *
 * unknown:
 *   Attempt history cannot be established.
 *
 * Never infer confirmed_not_attempted merely
 * because a provider response is missing.
 */
function stTryNowReconciliationPlan(
    array $registration,
    string $attemptState,
    ?array $providerResult
): array {
    $result = [
        'status' => 'review_required',
        'next_action' => 'verify_database_and_sendmode',
        'manual_review' => true,
        'provider_accepted' => false,
        'delivered' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    if (!in_array(
        $attemptState,
        ['confirmed_not_attempted', 'attempted', 'unknown'],
        true
    )) {
        return $result;
    }

    $registrationStatus = $registration['status'] ?? null;

    // Explicit non-registration outcomes.
    // Any contradictory transport evidence needs review.
    if (in_array(
        $registrationStatus,
        ['disabled', 'invalid', 'duplicate', 'unavailable'],
        true
    )) {
        if (
            $attemptState === 'confirmed_not_attempted' &&
            $providerResult === null
        ) {
            $result['status'] = 'no_new_registration';
            $result['next_action'] = 'none';
            $result['manual_review'] = false;
        }

        return $result;
    }

    // The customer may already exist.
    // Never re-register automatically.
    if ($registrationStatus === 'outcome_unknown') {
        $result['next_action'] = (
            $attemptState === 'confirmed_not_attempted' &&
            $providerResult === null
        )
            ? 'inspect_customer_database'
            : 'verify_database_and_sendmode';

        return $result;
    }

    if ($registrationStatus !== 'created') {
        return $result;
    }

    // A created result needs valid database identifiers.
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

    // Registered, but transport definitely not invoked.
    // Still require an explicit human decision.
    if ($attemptState === 'confirmed_not_attempted') {
        if ($providerResult !== null) {
            return $result;
        }

        $result['status'] = 'registered_sms_not_attempted';
        $result['next_action'] = 'review_unsent_registration';

        return $result;
    }

    // Unknown attempt history cannot be treated as
    // proof of either sending or not sending.
    if ($attemptState !== 'attempted') {
        return $result;
    }

    $result['next_action'] = 'check_sendmode_records';

    if ($providerResult === null) {
        return $result;
    }

    $decision = stTryNowFinalDeliveryDecision(
        $registration,
        $providerResult
    );

    if ($decision['status'] === 'provider_accepted') {
        $result['status'] = 'provider_accepted';
        $result['next_action'] = 'monitor_provider_delivery';
        $result['manual_review'] = false;
        $result['provider_accepted'] = true;

        // Provider acceptance is not delivery proof.
        return $result;
    }

    if (
        stTryNowDeliveryDecision(
            $providerResult
        )['status'] === 'rejected'
    ) {
        $result['status'] = 'provider_rejected';
        $result['next_action'] = 'review_provider_rejection';
    }

    return $result;
}
