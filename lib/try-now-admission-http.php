<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Admission HTTP responses.
 *
 * Generates safe HTTP 429 and 503 responses.
 * No Redis, database or SMS operations.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-admission-outcome.php';

/**
 * Prepare a failure response.
 *
 * Even an accidentally supplied "allowed" result
 * must not trigger delivery through this function.
 */
function stTryNowAdmissionHttpResponse(
    array $outcome
): array {
    $retryAfter = $outcome['retry_after'] ?? null;

    $limited = (
        ($outcome['proceed'] ?? null) === false &&
        ($outcome['http_status'] ?? null) === 429 &&
        is_int($retryAfter) &&
        $retryAfter >= 1 &&
        $retryAfter <= 86400
    );

    $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
    ];

    if ($limited) {
        $headers['Retry-After'] = (string) $retryAfter;
    }

    return [
        'status' => $limited ? 429 : 503,
        'headers' => $headers,
        'body' => [
            'ok' => false,
            'message' => $limited
                ? 'Too many requests. Please try again later.'
                : 'Try Now is temporarily unavailable.',
            'mode' => 'unavailable',
            'sent' => false,
        ],
    ];
}

/**
 * Send a failure response and terminate processing.
 *
 * This function must only be used when admission
 * has failed. It never permits delivery.
 */
function stTryNowSendAdmissionHttpFailure(
    array $outcome
): void {
    $response = stTryNowAdmissionHttpResponse($outcome);

    http_response_code($response['status']);

    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value, true);
    }

    echo json_encode(
        $response['body'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}
