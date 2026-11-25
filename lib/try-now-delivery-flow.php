<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Guarded delivery flow simulation.
 *
 * CLI TESTING ONLY.
 *
 * No Redis access, database access, HTTP requests,
 * customer writes, cleanup or SMS transmission.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-runtime.php';
require_once __DIR__ . '/try-now-registration.php';
require_once __DIR__ . '/try-now-sendmode.php';
require_once __DIR__ . '/try-now-sendmode-response.php';
require_once __DIR__ . '/try-now-delivery-result.php';

function stTryNowSimulateDeliveryFlow(
    array $preflight,
    array $post,
    array $registration,
    int $simulatedHttpStatus,
    string $simulatedBody,
    bool $allowCliTest = false
): array {
    $result = [
        'mode' => 'synthetic-cli',
        'status' => 'disabled',
        'http_status' => null,
        'link_verified' => false,
        'sms_request_prepared' => false,
        'provider_response_simulated' => false,
        'simulated_provider_status' => null,
        'customer_action' => 'none',
        'sent' => false,
        'delivered' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    /*
     * Require explicit CLI authorisation.
     *
     * Do not permit this simulation to execute
     * if ANY production delivery switch is enabled.
     */
    if (
        PHP_SAPI !== 'cli' ||
        !$allowCliTest ||
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED !== false ||
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED !== false ||
        ST_TRY_NOW_SMS_COPY_APPROVED !== false ||
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED !== false ||
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED !== false
    ) {
        return $result;
    }

    /*
     * Stage 1: Admission.
     *
     * Only a complete admitted decision can proceed.
     */
    if (
        ($preflight['status'] ?? null) !== 'admitted' ||
        ($preflight['admitted'] ?? null) !== true ||
        !array_key_exists('http_failure', $preflight) ||
        $preflight['http_failure'] !== null
    ) {
        $result['status'] = 'blocked';

        $failure = $preflight['http_failure'] ?? null;

        $result['http_status'] = (
            is_array($failure) &&
            in_array(
                $failure['status'] ?? null,
                [429, 503],
                true
            )
        ) ? $failure['status'] : 503;

        return $result;
    }

    /*
     * Stage 2: Validate registration data.
     *
     * The provided registration result is synthetic.
     * No customer is created by this function.
     */
    $prepared = stTryNowPrepareRegistration($post);

    if (!$prepared['ok']) {
        // A customer may already have been committed.
        // Never discard that fact because the supplied
        // form data is now invalid.
        if (($registration['status'] ?? null) === 'created') {
            $result['status'] = 'review_required';
            $result['customer_action'] = 'preserve';

            return $result;
        }

        $result['status'] = 'invalid';
        $result['http_status'] = 422;

        return $result;
    }

    $decision = stTryNowFinalDeliveryDecision(
        $registration,
        null
    );

    $result['status'] = $decision['status'];
    $result['customer_action'] =
        $decision['customer_action'];

    if (($registration['status'] ?? null) !== 'created') {
        return $result;
    }

    // A "created" result must be treated as potentially
    // committed even when its details are malformed.
    $result['status'] = 'review_required';
    $result['customer_action'] = 'preserve';

    $customerId = $registration['customer_id'] ?? null;
    $code = $registration['code'] ?? null;
    $link = $registration['link'] ?? null;
    $registeredMobile = $registration['mobile'] ?? null;

    if (
        !is_int($customerId) ||
        $customerId <= 0 ||
        !is_string($code) ||
        preg_match(
            '/^[A-Za-z][A-Za-z0-9]{5}$/D',
            $code
        ) !== 1 ||
        !is_string($link) ||
        !is_string($registeredMobile) ||
        stTryNowNormalizeMobile($registeredMobile)
            !== $registeredMobile ||
        !hash_equals(
            $registeredMobile,
            $prepared['customer']['mobile']
        )
    ) {
        return $result;
    }

    /*
     * Stage 3: Prepare the actual SMS request structure.
     *
     * This constructs the Sendmode payload only.
     * It does NOT call stTryNowSendmodeTransport().
     */
    try {
        $expectedLink = stTryNowBuildCustomerLink(
            $prepared['industry'],
            $code
        );

        if (!hash_equals($expectedLink, $link)) {
            return $result;
        }

        $result['link_verified'] = true;

        $sms = stTryNowPrepareSms(
            $prepared['industry'],
            $prepared['customer']['mobile'],
            $code
        );

        $request = stTryNowBuildSendmodeRequest(
            $sms
        );

        if (
            $request['mode'] !== 'dry-run' ||
            $request['sent'] !== false ||
            $request['method'] !== 'POST'
        ) {
            return $result;
        }

        $result['sms_request_prepared'] = true;

        /*
         * Stage 4: Parse a synthetic provider response.
         *
         * No HTTP call is made.
         */
        $provider = stTryNowParseSendmodeResponse(
            $simulatedHttpStatus,
            $simulatedBody
        );

        /*
         * Stage 5: Apply the final delivery policy.
         *
         * All customer preservation and retry rules
         * are inherited from the tested coordinator.
         */
        $final = stTryNowFinalDeliveryDecision(
            $registration,
            $provider
        );

        $result['provider_response_simulated'] = true;
        $result['simulated_provider_status'] =
            $provider['status'];

        $result['customer_action'] =
            $final['customer_action'];

        // Avoid claiming an actual Sendmode acceptance.
        $result['status'] =
            $final['status'] === 'provider_accepted'
                ? 'simulated_provider_accepted'
                : $final['status'];

    } catch (Throwable $e) {
        // Fail closed. Never reveal internal details
        // or automatically retry the simulated operation.
        $result['status'] = 'review_required';
        $result['customer_action'] = 'preserve';
    }

    return $result;
}
