<?php
declare(strict_types=1);

/**
 * Try Now - Live admission outcome tests.
 *
 * Synthetic outcomes only.
 * No Redis, database, network or SMS operations.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-admission-outcome.php';

function outcomeCheck(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

$allowed = stTryNowAdmissionOutcome([
    'status' => 'allowed',
    'allowed' => true,
    'retry_after' => 0,
]);

outcomeCheck(
    'Explicitly allowed request may proceed',
    $allowed['proceed'] === true &&
    $allowed['http_status'] === null
);

foreach (['ip', 'mobile', 'global'] as $scope) {
    $limited = stTryNowAdmissionOutcome([
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => 180,
        'scope' => $scope,
    ]);

    outcomeCheck(
        "$scope limit produces HTTP 429",
        $limited['proceed'] === false &&
        $limited['http_status'] === 429 &&
        $limited['retry_after'] === 180
    );
}

$oneSecond = stTryNowAdmissionOutcome([
    'status' => 'limited',
    'allowed' => false,
    'retry_after' => 1,
]);

outcomeCheck(
    'Minimum retry interval accepted',
    $oneSecond['http_status'] === 429
);

$oneDay = stTryNowAdmissionOutcome([
    'status' => 'limited',
    'allowed' => false,
    'retry_after' => 86400,
]);

outcomeCheck(
    'Maximum retry interval accepted',
    $oneDay['http_status'] === 429
);

$invalidCases = [
    'Disabled admission' => [
        'status' => 'disabled',
        'allowed' => false,
        'retry_after' => 0,
    ],
    'Unknown status' => [
        'status' => 'unexpected',
        'allowed' => true,
        'retry_after' => 0,
    ],
    'Missing fields' => [],
    'Allowed with incorrect retry interval' => [
        'status' => 'allowed',
        'allowed' => true,
        'retry_after' => 10,
    ],
    'Limited with permission granted' => [
        'status' => 'limited',
        'allowed' => true,
        'retry_after' => 60,
    ],
    'Zero retry interval' => [
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => 0,
    ],
    'Negative retry interval' => [
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => -1,
    ],
    'Excessive retry interval' => [
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => 86401,
    ],
    'Non-integer retry interval' => [
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => '60',
    ],
];

foreach ($invalidCases as $name => $input) {
    $result = stTryNowAdmissionOutcome($input);

    outcomeCheck(
        "$name fails closed",
        $result['proceed'] === false &&
        $result['http_status'] === 503
    );
}

$success = stTryNowEvaluateLiveAdmission(
    function (): array {
        return [
            'status' => 'allowed',
            'allowed' => true,
            'retry_after' => 0,
        ];
    }
);

outcomeCheck(
    'Callback success permits progression',
    $success['proceed'] === true
);

$redisFailure = stTryNowEvaluateLiveAdmission(
    function (): array {
        throw new RuntimeException(
            'Synthetic Redis connection failure'
        );
    }
);

outcomeCheck(
    'Redis failure produces HTTP 503',
    $redisFailure['proceed'] === false &&
    $redisFailure['http_status'] === 503
);

$unexpectedResult = stTryNowEvaluateLiveAdmission(
    function () {
        return 'unexpected-response';
    }
);

outcomeCheck(
    'Unexpected callback response fails closed',
    $unexpectedResult['proceed'] === false &&
    $unexpectedResult['http_status'] === 503
);

echo "PASS: All admission outcome tests.\n";
