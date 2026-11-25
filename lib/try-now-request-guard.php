<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Request admission guard.
 *
 * Validates visitor identity before applying Redis limits.
 * Rate limiting is disabled while live delivery is disabled.
 *
 * No database writes, SMS or email.
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-client-ip.php';
require_once __DIR__ . '/try-now-rate-limit.php';
require_once __DIR__ . '/try-now-runtime.php';

/**
 * Obtain trusted identifiers from a previously validated form.
 *
 * Never use visitor-supplied forwarding headers.
 */
function stTryNowAdmissionIdentifiers(
    array $server,
    array $validation
): array {
    if (($validation['ok'] ?? null) !== true) {
        throw new InvalidArgumentException(
            'A validated Try Now submission is required.'
        );
    }

    $mobile = $validation['normalised_mobile'] ?? null;

    if (
        !is_string($mobile) ||
        stTryNowNormalizeMobile($mobile) !== $mobile
    ) {
        throw new InvalidArgumentException(
            'Invalid rate-limit mobile identifier.'
        );
    }

    return [
        'ip' => stTryNowClientIp($server),
        'mobile' => $mobile,
    ];
}

/**
 * Check live-submission admission.
 *
 * Must be called only after CSRF and form validation.
 *
 * While production delivery is disabled, this function
 * must not connect to Redis or consume any allowance.
 *
 * Redis failures throw an exception: the API must
 * respond with HTTP 503 and never continue to SMS.
 */
function stTryNowCheckLiveRateLimit(
    array $server,
    array $validation
): array {
    // First gate: no Redis counters in simulation mode.
    if (!stTryNowProductionDeliveryEnabled()) {
        return [
            'status' => 'disabled',
            'allowed' => false,
            'retry_after' => 0,
        ];
    }

    $identifiers = stTryNowAdmissionIdentifiers(
        $server,
        $validation
    );

    $result = stTryNowConsumeRateLimit(
        $identifiers['ip'],
        $identifiers['mobile']
    );

    if (
        ($result['allowed'] ?? null) === true &&
        ($result['retry_after'] ?? null) === 0
    ) {
        return [
            'status' => 'allowed',
            'allowed' => true,
            'retry_after' => 0,
        ];
    }

    if (
        ($result['allowed'] ?? null) === false &&
        is_int($result['retry_after'] ?? null) &&
        $result['retry_after'] > 0
    ) {
        return [
            'status' => 'limited',
            'allowed' => false,
            'retry_after' => $result['retry_after'],
        ];
    }

    // Unknown Redis responses must never permit delivery.
    throw new RuntimeException(
        'Try Now request admission is unavailable.'
    );
}
