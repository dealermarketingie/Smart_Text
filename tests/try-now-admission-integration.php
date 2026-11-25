<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Admission integration tests.
 *
 * Tests the real admission guard together with
 * the HTTP outcome decision helper.
 *
 * No Redis connections, database writes or SMS.
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-request-guard.php';
require_once __DIR__ . '/../lib/try-now-admission-outcome.php';

function admissionIntegrationCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

// Production delivery must remain disabled.
admissionIntegrationCheck(
    'Production delivery remains disabled',
    stTryNowProductionDeliveryEnabled() === false
);

// Prepare a valid synthetic submission.
$validation = stTryNowValidateInput([
    'industry' => 'automotive',
    'firstName' => 'Security',
    'lastNameCompany' => 'Test',
    'email' => 'security@example.com',
    'mobile' => '0871234567',
    'consent' => 'on',
    'website' => '',
]);

admissionIntegrationCheck(
    'Synthetic submission validates successfully',
    $validation['ok'] === true
);

// Pass the real admission guard to the outcome handler.
$outcome = stTryNowEvaluateLiveAdmission(
    static function () use ($validation): array {
        return stTryNowCheckLiveRateLimit(
            ['REMOTE_ADDR' => '192.0.2.10'],
            $validation
        );
    }
);

admissionIntegrationCheck(
    'Disabled guard maps to HTTP 503',
    $outcome['http_status'] === 503
);

admissionIntegrationCheck(
    'Disabled guard never permits live delivery',
    $outcome['proceed'] === false
);

// An invalid request must not bypass the disabled gate.
// The disabled guard returns before accessing Redis.
$invalidOutcome = stTryNowEvaluateLiveAdmission(
    static function (): array {
        return stTryNowCheckLiveRateLimit(
            ['REMOTE_ADDR' => 'invalid-ip'],
            []
        );
    }
);

admissionIntegrationCheck(
    'Malformed request cannot enable delivery',
    $invalidOutcome['proceed'] === false &&
    $invalidOutcome['http_status'] === 503
);

// Synthetic limit result: no Redis connection needed.
$limitedOutcome = stTryNowEvaluateLiveAdmission(
    static function (): array {
        return [
            'status' => 'limited',
            'allowed' => false,
            'retry_after' => 120,
        ];
    }
);

admissionIntegrationCheck(
    'Rate-limited outcome maps to HTTP 429',
    $limitedOutcome['proceed'] === false &&
    $limitedOutcome['http_status'] === 429 &&
    $limitedOutcome['retry_after'] === 120
);

// Simulated Redis failure must fail closed.
$failureOutcome = stTryNowEvaluateLiveAdmission(
    static function (): array {
        throw new RuntimeException(
            'Synthetic Redis connection failure'
        );
    }
);

admissionIntegrationCheck(
    'Redis failure blocks delivery with HTTP 503',
    $failureOutcome['proceed'] === false &&
    $failureOutcome['http_status'] === 503
);

echo "PASS: All admission integration tests.\n";
