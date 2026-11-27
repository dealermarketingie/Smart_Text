<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - End-to-end dry-run tests.
 *
 * No database connections, writes, credentials,
 * network requests or real SMS messages.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-registration.php';
require_once __DIR__ . '/../lib/try-now-sendmode-transport.php';

$countries = [
    'Ireland' => [
        'mobile' => '0890000000',
        'recipient' => '353890000000',
        'optout' => "\nOptout to 50015",
    ],
    'UK' => [
        'mobile' => '07700900123',
        'recipient' => '447700900123',
        'optout' => "\nOptout 07507319809",
    ],
];

function checkWorkflow(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

foreach (stTryNowSamples() as $industry => $sample) {
    foreach ($countries as $country => $details) {

        // Synthetic visitor details.
        $input = [
            'industry' => $industry,
            'firstName' => 'Seán',
            'lastNameCompany' => 'Test Company',
            'email' => 'test@example.com',
            'mobile' => $details['mobile'],
            'consent' => 'on',
            'website' => '',
        ];

        // 1. Validate and prepare customer details.
        $registration = stTryNowPrepareRegistration($input);

        checkWorkflow(
            "$industry / $country - registration",
            $registration['ok'] === true
            && $registration['customer']['campaign_id']
                === $sample['campaign_id']
        );

        // 2. Prepare an SMS using a fictional customer code.
        // No customer record is created.
        $sms = stTryNowPrepareSms(
            $industry,
            $registration['customer']['mobile'],
            'aB1234'
        );

        $expectedLink = stTryNowBuildCustomerLink(
            $industry,
            'aB1234'
        );

        checkWorkflow(
            "$industry / $country - SMS preparation",
            $sms['sent'] === false
            && $sms['payload']['recipients'] === [
                $details['recipient']
            ]
            && strpos(
                $sms['payload']['messagetext'],
                $expectedLink
            ) !== false
            && substr(
                $sms['payload']['messagetext'],
                -strlen($details['optout'])
            ) === $details['optout']
        );

        // 3. Build the documented Sendmode REST request.
        $request = stTryNowBuildSendmodeRequest($sms);

        parse_str($request['body'], $form);

        $decoded = json_decode(
            $form['message'] ?? '',
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        checkWorkflow(
            "$industry / $country - REST request",
            $request['method'] === 'POST'
            && $request['mode'] === 'dry-run'
            && $decoded === $sms['payload']
        );

        // 4. Verify the hard-disabled transport.
        // No real credentials are supplied.
        $transport = stTryNowSendmodeTransport(
            $request,
            'NOT_A_REAL_API_KEY',
            'SmartText'
        );

        checkWorkflow(
            "$industry / $country - delivery blocked",
            $transport['status'] === 'disabled'
            && $transport['accepted'] === false
            && $transport['delivered'] === false
        );
    }
}

// Consent must be present before processing.
$invalid = [
    'industry' => 'automotive',
    'firstName' => 'Test',
    'lastNameCompany' => 'Company',
    'email' => 'test@example.com',
    'mobile' => '0890000000',
    'consent' => '',
    'website' => '',
];

$result = stTryNowPrepareRegistration($invalid);

checkWorkflow(
    'Registration without consent rejected',
    $result['ok'] === false
);

// Confirm the safety switch remains disabled.
checkWorkflow(
    'Live Sendmode delivery remains disabled',
    ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false
);

echo "PASS: End-to-end dry-run workflow completed.\n";
