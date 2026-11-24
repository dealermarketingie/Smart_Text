<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Sendmode response tests.
 *
 * Synthetic responses only.
 * No HTTP requests, credentials, database access or SMS.
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-sendmode-response.php';

$cases = [
    [
        'Accepted response', 200,
        '{"status":"OK","statusCode":0}',
        'accepted', 0
    ],
    [
        'Accepted HTTP 202', 202,
        '{"status":"OK","statusCode":0}',
        'accepted', 0
    ],
    [
        'Invalid mobile number', 200,
        '{"status":"INVALID_MOBILE_NUMBER","statusCode":11}',
        'rejected', 11
    ],
    [
        'Invalid API key', 401,
        '{"status":"INVALID_ACCESS_KEY","statusCode":14}',
        'rejected', 14
    ],
    [
        'Unauthorised request', 403,
        '{"status":"UNAUTHORISED","statusCode":16}',
        'rejected', 16
    ],
    [
        'Missing parameter', 400,
        '{"status":"EXPECTED_PARAMETER_MISSING","statusCode":195}',
        'rejected', 195
    ],
    [
        'Connection failure or timeout', 0,
        '',
        'unknown', null
    ],
    [
        'Server error', 500,
        '{"status":"SYSTEM_ERROR","statusCode":8}',
        'unknown', null
    ],
    [
        'Server error with conflicting success', 503,
        '{"status":"OK","statusCode":0}',
        'unknown', null
    ],
    [
        'Malformed JSON', 200,
        'not-json',
        'unknown', null
    ],
    [
        'Empty response', 204,
        '',
        'unknown', null
    ],
    [
        'Conflicting status and error code', 200,
        '{"status":"OK","statusCode":14}',
        'unknown', 14
    ],
    [
        'Success code with error status', 200,
        '{"status":"INVALID_MOBILE_NUMBER","statusCode":0}',
        'unknown', 0
    ],
    [
        'String instead of integer code', 200,
        '{"status":"OK","statusCode":"0"}',
        'unknown', null
    ],
    [
        'Missing status code', 200,
        '{"status":"OK"}',
        'unknown', null
    ],
];

foreach ($cases as [$name, $http, $body, $expected, $code]) {
    $result = stTryNowParseSendmodeResponse($http, $body);

    $passed =
        $result['status'] === $expected
        && $result['accepted'] === ($expected === 'accepted')
        && $result['delivered'] === false
        && $result['provider_code'] === $code;

    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

// Reject impossible HTTP status values.
foreach ([-1, 600] as $http) {
    $rejected = false;

    try {
        stTryNowParseSendmodeResponse($http, '');
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }

    if (!$rejected) {
        fwrite(STDERR, "FAIL: Invalid HTTP status $http\n");
        exit(1);
    }

    echo "PASS: Invalid HTTP status $http rejected\n";
}

echo "PASS: All Sendmode response tests.\n";
