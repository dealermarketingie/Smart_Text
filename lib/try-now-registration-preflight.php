<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Registration preflight.
 *
 * Checks registration readiness and admission before
 * any future database write or SMS operation.
 *
 * No database connections or SMS transmission.
 * Not connected to the public API yet.
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-registration.php';
require_once __DIR__ . '/try-now-request-guard.php';
require_once __DIR__ . '/try-now-admission-http.php';

/**
 * Interpret a completed admission decision.
 *
 * Synthetic decisions can be tested without Redis.
 */
function stTryNowRegistrationAdmissionResult(
    array $outcome
): array {
    if (
        ($outcome['proceed'] ?? null) === true &&
        array_key_exists('http_status', $outcome) &&
        $outcome['http_status'] === null &&
        array_key_exists('retry_after', $outcome) &&
        $outcome['retry_after'] === null &&
        array_key_exists('message', $outcome) &&
        $outcome['message'] === null
    ) {
        return [
            'status' => 'admitted',
            'admitted' => true,
            'http_failure' => null,
        ];
    }

    return [
        'status' => 'blocked',
        'admitted' => false,
        'http_failure' =>
            stTryNowAdmissionHttpResponse($outcome),
    ];
}

/**
 * Prepare a future production registration.
 *
 * Sequence:
 * 1. Verify all production delivery gates.
 * 2. Validate customer registration details.
 * 3. Check Redis admission.
 * 4. Return a decision without writing anything.
 *
 * No database connection is created here.
 */
function stTryNowRegistrationPreflight(
    array $server,
    array $post
): array {
    // Hard stop while production delivery is disabled.
    if (!stTryNowProductionDeliveryEnabled()) {
        return [
            'status' => 'disabled',
            'admitted' => false,
            'http_failure' =>
                stTryNowAdmissionHttpResponse(
                    stTryNowAdmissionUnavailableOutcome()
                ),
        ];
    }

    // Validate all customer fields before Redis admission.
    $prepared = stTryNowPrepareRegistration($post);

    if (!$prepared['ok']) {
        return [
            'status' => 'invalid',
            'admitted' => false,
            'errors' => $prepared['errors'],
            'http_failure' => null,
        ];
    }

    // Registration preparation has already validated
    // and normalised this mobile number.
    $validation = [
        'ok' => true,
        'normalised_mobile' =>
            $prepared['customer']['mobile'],
    ];

    // Redis failures automatically become HTTP 503 decisions.
    $outcome = stTryNowEvaluateLiveAdmission(
        static function () use (
            $server,
            $validation
        ): array {
            return stTryNowCheckLiveRateLimit(
                $server,
                $validation
            );
        }
    );

    return stTryNowRegistrationAdmissionResult($outcome);
}
