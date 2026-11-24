<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Delivery decision tests.
 *
 * Synthetic Sendmode responses only.
 * No HTTP requests, database access or SMS delivery.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-delivery-policy.php';
require_once __DIR__ . '/../lib/try-now-sendmode-response.php';

$cases = [
    [
        'Accepted by Sendmode',
        stTryNowParseSendmodeResponse(
            200,
            '{"status":"OK","statusCode":0}'
        ),
        'accepted',
        'keep',
    ],
    [
        'Explicit provider rejection',
        stTryNowParseSendmodeResponse(
            401,
            '{"status":"INVALID_ACCESS_KEY","statusCode":14}'
        ),
        'rejected',
        'guarded_cleanup',
    ],
    [
        'Connection timeout',
        stTryNowParseSendmodeResponse(0, ''),
        'unknown',
        'keep_for_review',
    ],
    [
        'Sendmode server failure',
        stTryNowParseSendmodeResponse(
            503,
            '{"status":"SYSTEM_ERROR","statusCode":8}'
        ),
        'unknown',
        'keep_for_review',
    ],
    [
        'Malformed provider response',
        stTryNowParseSendmodeResponse(200, 'invalid-json'),
        'unknown',
        'keep_for_review',
    ],
    [
        'Transport disabled',
        [
            'status' => 'disabled',
            'accepted' => false,
            'delivered' => false,
        ],
        'disabled',
        'stop_before_registration',
    ],
    [
        'Inconsistent acceptance response',
        [
            'status' => 'accepted',
            'accepted' => false,
            'delivered' => false,
        ],
        'unknown',
        'keep_for_review',
    ],
    [
        'Inconsistent rejection response',
        [
            'status' => 'rejected',
            'accepted' => true,
            'delivered' => false,
        ],
        'unknown',
        'keep_for_review',
    ],
    [
        'Missing response fields',
        [],
        'unknown',
        'keep_for_review',
    ],
];

foreach ($cases as [$name, $response, $status, $action]) {
    $decision = stTryNowDeliveryDecision($response);

    $passed =
        $decision['status'] === $status
        && $decision['customer_action'] === $action
        && $decision['automatic_retry'] === false;

    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

echo "PASS: All delivery policy tests.\n";
