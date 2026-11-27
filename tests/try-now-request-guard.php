<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Request admission guard tests.
 *
 * Synthetic request data only.
 * No Redis connections, database writes, SMS or email.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-request-guard.php';

function admissionCheck(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

function admissionRejects(
    string $name,
    array $server,
    array $validation
): void {
    $blocked = false;

    try {
        stTryNowAdmissionIdentifiers(
            $server,
            $validation
        );
    } catch (InvalidArgumentException $e) {
        $blocked = true;
    } catch (RuntimeException $e) {
        $blocked = true;
    }

    admissionCheck($name, $blocked);
}

// Synthetic submission.
$form = [
    'industry' => 'automotive',
    'firstName' => 'Security',
    'lastNameCompany' => 'Test',
    'email' => 'security@example.com',
    'mobile' => '0871234567',
    'consent' => 'on',
    'website' => '',
];

$validation = stTryNowValidateInput($form);

admissionCheck(
    'Valid submission accepted by validator',
    $validation['ok'] === true
);

// All production switches must remain disabled.
admissionCheck(
    'Production delivery disabled',
    stTryNowProductionDeliveryEnabled() === false
);

// Disabled guard must not connect to Redis.
$result = stTryNowCheckLiveRateLimit(
    ['REMOTE_ADDR' => '192.0.2.10'],
    $validation
);

admissionCheck(
    'Simulation mode blocks live admission',
    $result === [
        'status' => 'disabled',
        'allowed' => false,
        'retry_after' => 0,
    ]
);

// Even malformed data must not bypass the disabled gate.
$result = stTryNowCheckLiveRateLimit([], []);

admissionCheck(
    'Disabled gate runs before request processing',
    $result['status'] === 'disabled'
    && $result['allowed'] === false
);

// Obtain trusted identifiers from validated input.
$identifiers = stTryNowAdmissionIdentifiers(
    [
        'REMOTE_ADDR' => '192.0.2.10',
    ],
    $validation
);

admissionCheck(
    'Trusted IPv4 address selected',
    $identifiers['ip'] === '192.0.2.10'
);

admissionCheck(
    'Irish mobile correctly normalised',
    $identifiers['mobile'] === '087 1234567'
);

// Spoofed headers must never affect the selected IP.
$spoofed = stTryNowAdmissionIdentifiers(
    [
        'REMOTE_ADDR' => '192.0.2.10',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        'HTTP_X_REAL_IP' => '198.51.100.99',
    ],
    $validation
);

admissionCheck(
    'Spoofed forwarding headers ignored',
    $spoofed === $identifiers
);

// IPv6 support.
$ipv6 = stTryNowAdmissionIdentifiers(
    [
        'REMOTE_ADDR' => '2001:db8::10',
    ],
    $validation
);

admissionCheck(
    'IPv6 visitor supported',
    $ipv6['ip'] === '2001:db8::10'
);

// UK mobile number.
$ukForm = $form;
$ukForm['mobile'] = '+447700900123';

$ukValidation = stTryNowValidateInput($ukForm);

$ukIdentifiers = stTryNowAdmissionIdentifiers(
    ['REMOTE_ADDR' => '192.0.2.11'],
    $ukValidation
);

admissionCheck(
    'UK mobile correctly normalised',
    $ukIdentifiers['mobile'] === '07700 900123'
);

// Different representations of the same mobile.
$internationalForm = $form;
$internationalForm['mobile'] = '+353871234567';

$internationalValidation = stTryNowValidateInput(
    $internationalForm
);

$international = stTryNowAdmissionIdentifiers(
    ['REMOTE_ADDR' => '192.0.2.10'],
    $internationalValidation
);

admissionCheck(
    'Irish mobile formats produce identical identifiers',
    $international === $identifiers
);

// Never trust forwarding headers without REMOTE_ADDR.
admissionRejects(
    'Missing visitor IP rejected',
    [],
    $validation
);

admissionRejects(
    'Forwarding header alone rejected',
    [
        'HTTP_X_FORWARDED_FOR' => '192.0.2.10',
    ],
    $validation
);

admissionRejects(
    'Invalid visitor IP rejected',
    [
        'REMOTE_ADDR' => 'invalid-ip',
    ],
    $validation
);

// Form validation must precede rate limiting.
$invalidForm = $form;
$invalidForm['consent'] = '';

admissionRejects(
    'Submission without consent rejected',
    ['REMOTE_ADDR' => '192.0.2.10'],
    stTryNowValidateInput($invalidForm)
);

admissionRejects(
    'Unvalidated submission rejected',
    ['REMOTE_ADDR' => '192.0.2.10'],
    []
);

// Reject manipulated or non-normalised mobile values.
$tampered = $validation;
$tampered['normalised_mobile'] = '0871234567';

admissionRejects(
    'Non-normalised mobile rejected',
    ['REMOTE_ADDR' => '192.0.2.10'],
    $tampered
);

$tampered['normalised_mobile'] = '12345';

admissionRejects(
    'Invalid mobile identifier rejected',
    ['REMOTE_ADDR' => '192.0.2.10'],
    $tampered
);

echo "PASS: All request admission guard tests.\n";
