<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Admission HTTP tests.
 *
 * No HTTP server, Redis, database or SMS.
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-admission-http.php';

function httpAdmissionCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

// Simulate a Redis rate-limit decision.
$limited = stTryNowAdmissionOutcome([
    'status' => 'limited',
    'allowed' => false,
    'retry_after' => 120,
]);

$response = stTryNowAdmissionHttpResponse($limited);

httpAdmissionCheck(
    'Rate limit produces HTTP 429',
    $response['status'] === 429
);

httpAdmissionCheck(
    'Retry-After header contains 120 seconds',
    ($response['headers']['Retry-After'] ?? null) === '120'
);

httpAdmissionCheck(
    'Rate-limited response never permits delivery',
    $response['body']['ok'] === false &&
    $response['body']['sent'] === false
);

httpAdmissionCheck(
    'Response uses JSON and disables caching',
    $response['headers']['Content-Type']
        === 'application/json; charset=utf-8' &&
    $response['headers']['Cache-Control']
        === 'no-store, private'
);

// Each limit type must produce the same safe response.
foreach (['ip', 'mobile', 'global'] as $scope) {
    $outcome = stTryNowAdmissionOutcome([
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => 60,
        'scope' => $scope,
    ]);

    $result = stTryNowAdmissionHttpResponse($outcome);

    httpAdmissionCheck(
        "$scope limit produces HTTP 429",
        $result['status'] === 429 &&
        $result['headers']['Retry-After'] === '60'
    );
}

// Simulate a Redis failure.
$redisFailure = stTryNowEvaluateLiveAdmission(
    static function (): array {
        throw new RuntimeException(
            'Synthetic Redis connection failure'
        );
    }
);

$unavailable = stTryNowAdmissionHttpResponse(
    $redisFailure
);

httpAdmissionCheck(
    'Redis failure produces HTTP 503',
    $unavailable['status'] === 503
);

httpAdmissionCheck(
    'HTTP 503 has no Retry-After header',
    !isset($unavailable['headers']['Retry-After'])
);

httpAdmissionCheck(
    'HTTP 503 never reports SMS sent',
    $unavailable['body']['ok'] === false &&
    $unavailable['body']['sent'] === false
);

// Disabled admission must fail closed.
$disabled = stTryNowAdmissionOutcome([
    'status' => 'disabled',
    'allowed' => false,
    'retry_after' => 0,
]);

httpAdmissionCheck(
    'Disabled admission produces HTTP 503',
    stTryNowAdmissionHttpResponse($disabled)['status'] === 503
);

// Accidentally passing an allowed result to the
// failure handler must never produce a success response.
$allowed = stTryNowAdmissionOutcome([
    'status' => 'allowed',
    'allowed' => true,
    'retry_after' => 0,
]);

httpAdmissionCheck(
    'Allowed result cannot bypass failure handler',
    stTryNowAdmissionHttpResponse($allowed)['status'] === 503
);

// Reject malformed retry intervals.
foreach (['60', 0, -1, 86401] as $retry) {
    $result = stTryNowAdmissionHttpResponse([
        'proceed' => false,
        'http_status' => 429,
        'retry_after' => $retry,
    ]);

    httpAdmissionCheck(
        'Invalid retry interval fails closed',
        $result['status'] === 503 &&
        !isset($result['headers']['Retry-After'])
    );
}

// Never return an internal error message to visitors.
$result = stTryNowAdmissionHttpResponse([
    'proceed' => false,
    'http_status' => 503,
    'message' => 'Private Redis connection details',
]);

httpAdmissionCheck(
    'Internal error details are not disclosed',
    strpos(
        json_encode($result['body']),
        'Private Redis'
    ) === false
);

echo "PASS: All admission HTTP response tests.\n";
