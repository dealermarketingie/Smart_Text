<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Sendmode response parser.
 *
 * PURE FUNCTION - DRY-RUN DEVELOPMENT.
 *
 * No HTTP requests.
 * No API credentials.
 * No database operations.
 * No SMS delivery.
 *
 * PHP 7.4 compatible.
 */

function stTryNowParseSendmodeResponse(
    int $httpStatus,
    string $responseBody
): array {
    if ($httpStatus < 0 || $httpStatus > 599) {
        throw new InvalidArgumentException(
            'Invalid HTTP status.'
        );
    }

    $result = [
        'status' => 'unknown',
        'accepted' => false,
        'delivered' => false,
        'provider_code' => null,
    ];

    // Transport errors and server failures are ambiguous.
    // Do not assume the request was rejected.
    // Do not automatically retry an unknown outcome.
    if ($httpStatus === 0 || $httpStatus >= 500) {
        return $result;
    }

    $data = json_decode($responseBody, true);

    if (
        !is_array($data) ||
        !isset($data['status'], $data['statusCode']) ||
        !is_string($data['status']) ||
        !is_int($data['statusCode'])
    ) {
        return $result;
    }

    $result['provider_code'] = $data['statusCode'];

    // Confirm acceptance only when HTTP and Sendmode agree.
    if (
        $httpStatus >= 200 &&
        $httpStatus < 300 &&
        $data['status'] === 'OK' &&
        $data['statusCode'] === 0
    ) {
        $result['status'] = 'accepted';
        $result['accepted'] = true;

        // Acceptance is NOT a delivery confirmation.
        return $result;
    }

    // Explicit provider rejection.
    if (
        $httpStatus >= 200 &&
        $httpStatus < 500 &&
        $data['statusCode'] > 0 &&
        $data['status'] !== 'OK'
    ) {
        $result['status'] = 'rejected';
        return $result;
    }

    // Conflicting or incomplete response: outcome unknown.
    return $result;
}
