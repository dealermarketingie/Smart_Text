<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Registration sequencing tests.
 *
 * Synthetic callbacks only.
 * No database, Redis, network or SMS operations.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-registration-sequence.php';

function sequenceCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

sequenceCheck(
    'Production delivery remains disabled',
    stTryNowProductionDeliveryEnabled() === false
);

// Synthetic successful admission.
$admitted = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionOutcome([
        'status' => 'allowed',
        'allowed' => true,
        'retry_after' => 0,
    ])
);

$events = [];

$register = static function () use (&$events): array {
    $events[] = 'register';

    return [
        'status' => 'created',
        'customer_id' => 123,
    ];
};

$prepareSms = static function (
    array $registration
) use (&$events): bool {
    $events[] = 'prepare_sms';

    return $registration['customer_id'] === 123;
};

// Without explicit CLI test authorisation,
// even an admitted decision must do nothing.
$disabled = stTryNowRunSyntheticRegistrationSequence(
    $admitted,
    $register,
    $prepareSms
);

sequenceCheck(
    'Test execution disabled by default',
    $disabled['status'] === 'disabled' &&
    $events === []
);

// The real preflight is disabled by production gates.
$post = [
    'industry' => 'automotive',
    'firstName' => 'Security',
    'lastNameCompany' => 'Test',
    'email' => 'security@example.com',
    'mobile' => '0871234567',
    'consent' => 'on',
    'website' => '',
];

$realPreflight = stTryNowRegistrationPreflight(
    ['REMOTE_ADDR' => '192.0.2.10'],
    $post
);

$blocked = stTryNowRunSyntheticRegistrationSequence(
    $realPreflight,
    $register,
    $prepareSms,
    true
);

sequenceCheck(
    'Real disabled preflight cannot register',
    $blocked['status'] === 'blocked' &&
    $events === []
);

// HTTP 429 admission failure.
$limited = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionOutcome([
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => 120,
    ])
);

$blocked = stTryNowRunSyntheticRegistrationSequence(
    $limited,
    $register,
    $prepareSms,
    true
);

sequenceCheck(
    'HTTP 429 prevents registration',
    $blocked['status'] === 'blocked' &&
    $events === []
);

// HTTP 503 admission failure.
$unavailable = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionUnavailableOutcome()
);

$blocked = stTryNowRunSyntheticRegistrationSequence(
    $unavailable,
    $register,
    $prepareSms,
    true
);

sequenceCheck(
    'HTTP 503 prevents registration',
    $blocked['status'] === 'blocked' &&
    $events === []
);

// Inconsistent admission must never continue.
$blocked = stTryNowRunSyntheticRegistrationSequence(
    [
        'status' => 'admitted',
        'admitted' => true,
        'http_failure' => ['status' => 429],
    ],
    $register,
    $prepareSms,
    true
);

sequenceCheck(
    'Inconsistent admission is rejected',
    $blocked['status'] === 'blocked' &&
    $events === []
);

// Missing decision fields must fail closed.
$blocked = stTryNowRunSyntheticRegistrationSequence(
    [
        'status' => 'admitted',
        'admitted' => true,
    ],
    $register,
    $prepareSms,
    true
);

sequenceCheck(
    'Incomplete admission is rejected',
    $blocked['status'] === 'blocked' &&
    $events === []
);

// A synthetic approved admission can execute
// the callbacks in their required order.
$success = stTryNowRunSyntheticRegistrationSequence(
    $admitted,
    $register,
    $prepareSms,
    true
);

sequenceCheck(
    'Approved admission runs callbacks in order',
    $success['status'] === 'synthetic_prepared' &&
    $events === ['register', 'prepare_sms']
);

sequenceCheck(
    'Successful simulation never reports SMS sent',
    $success['sms_sent'] === false &&
    $success['automatic_retry'] === false
);

// A duplicate registration must stop before SMS.
$events = [];

$duplicate = stTryNowRunSyntheticRegistrationSequence(
    $admitted,
    static function () use (&$events): array {
        $events[] = 'register';
        return ['status' => 'duplicate'];
    },
    $prepareSms,
    true
);

sequenceCheck(
    'Duplicate registration prevents SMS preparation',
    $duplicate['status'] === 'registration_failed' &&
    $events === ['register']
);

// Registration exceptions must prevent SMS.
$events = [];

$failed = stTryNowRunSyntheticRegistrationSequence(
    $admitted,
    static function () use (&$events): array {
        $events[] = 'register';
        throw new RuntimeException(
            'Synthetic registration failure'
        );
    },
    $prepareSms,
    true
);

sequenceCheck(
    'Registration exception prevents SMS preparation',
    $failed['status'] === 'registration_failed' &&
    $events === ['register']
);

// Incomplete registration results must fail closed.
$events = [];

$invalidRegistration =
    stTryNowRunSyntheticRegistrationSequence(
        $admitted,
        static function () use (&$events): array {
            $events[] = 'register';

            return [
                'status' => 'created',
                'customer_id' => 0,
            ];
        },
        $prepareSms,
        true
    );

sequenceCheck(
    'Invalid customer ID prevents SMS preparation',
    $invalidRegistration['status']
        === 'registration_failed' &&
    $events === ['register']
);

// SMS preparation errors require review, not retry.
$events = [];

$review = stTryNowRunSyntheticRegistrationSequence(
    $admitted,
    $register,
    static function () use (&$events): bool {
        $events[] = 'prepare_sms';
        throw new RuntimeException(
            'Synthetic SMS preparation error'
        );
    },
    true
);

sequenceCheck(
    'SMS preparation failure requires review',
    $review['status'] === 'review_required' &&
    $events === ['register', 'prepare_sms'] &&
    $review['sms_sent'] === false &&
    $review['automatic_retry'] === false
);

// Explicit SMS preparation failure also requires review.
$events = [];

$review = stTryNowRunSyntheticRegistrationSequence(
    $admitted,
    $register,
    static function () use (&$events): bool {
        $events[] = 'prepare_sms';
        return false;
    },
    true
);

sequenceCheck(
    'Unsuccessful SMS preparation cannot trigger retry',
    $review['status'] === 'review_required' &&
    $review['automatic_retry'] === false &&
    $review['sms_sent'] === false
);

echo "PASS: All registration sequencing tests.\n";
