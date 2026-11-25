<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Registration preflight tests.
 *
 * Synthetic inputs and admission decisions only.
 * No Redis connections, database writes or SMS.
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-registration-preflight.php';

function preflightCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

$post = [
    'industry' => 'automotive',
    'firstName' => 'Security',
    'lastNameCompany' => 'Test',
    'email' => 'security@example.com',
    'mobile' => '0871234567',
    'consent' => 'on',
    'website' => '',
];

$server = [
    'REMOTE_ADDR' => '192.0.2.10',
];

// No production gates may be enabled.
preflightCheck(
    'Production delivery remains disabled',
    stTryNowProductionDeliveryEnabled() === false
);

// Real preflight must stop before Redis or registration.
$disabled = stTryNowRegistrationPreflight(
    $server,
    $post
);

preflightCheck(
    'Production preflight is disabled',
    $disabled['status'] === 'disabled' &&
    $disabled['admitted'] === false
);

preflightCheck(
    'Disabled preflight returns HTTP 503 decision',
    $disabled['http_failure']['status'] === 503
);

// Malformed input cannot bypass the disabled gate.
$malformed = stTryNowRegistrationPreflight(
    ['REMOTE_ADDR' => 'invalid-ip'],
    []
);

preflightCheck(
    'Malformed request cannot bypass safety gates',
    $malformed['status'] === 'disabled' &&
    $malformed['admitted'] === false
);

// Verify the existing registration validator.
$prepared = stTryNowPrepareRegistration($post);

preflightCheck(
    'Customer registration details validate',
    $prepared['ok'] === true
);

$invalid = $post;
$invalid['consent'] = '';

preflightCheck(
    'Registration without consent is rejected',
    stTryNowPrepareRegistration($invalid)['ok']
        === false
);

// Test admission decisions without Redis.
$allowed = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionOutcome([
        'status' => 'allowed',
        'allowed' => true,
        'retry_after' => 0,
    ])
);

preflightCheck(
    'Explicit approval reaches admitted state',
    $allowed['status'] === 'admitted' &&
    $allowed['admitted'] === true &&
    $allowed['http_failure'] === null
);

// Exceeded limits must block registration.
$limited = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionOutcome([
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => 120,
    ])
);

preflightCheck(
    'Rate limit blocks registration with HTTP 429',
    $limited['admitted'] === false &&
    $limited['http_failure']['status'] === 429 &&
    $limited['http_failure']['headers']['Retry-After']
        === '120'
);

// Simulate a Redis failure.
$redisFailure = stTryNowRegistrationAdmissionResult(
    stTryNowEvaluateLiveAdmission(
        static function (): array {
            throw new RuntimeException(
                'Synthetic Redis failure'
            );
        }
    )
);

preflightCheck(
    'Redis failure blocks registration with HTTP 503',
    $redisFailure['admitted'] === false &&
    $redisFailure['http_failure']['status'] === 503
);

// Disabled admission cannot proceed.
$blocked = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionOutcome([
        'status' => 'disabled',
        'allowed' => false,
        'retry_after' => 0,
    ])
);

preflightCheck(
    'Disabled admission cannot reach registration',
    $blocked['admitted'] === false
);

// An inconsistent decision must fail closed.
$unexpected = stTryNowRegistrationAdmissionResult([
    'proceed' => true,
    'http_status' => 429,
    'retry_after' => 60,
]);

preflightCheck(
    'Inconsistent decision fails closed',
    $unexpected['admitted'] === false &&
    $unexpected['http_failure']['status'] === 503
);

echo "PASS: All registration preflight tests.\n";
