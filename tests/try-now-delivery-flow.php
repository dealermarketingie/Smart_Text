<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Guarded delivery-flow tests.
 *
 * Pure synthetic CLI tests.
 * No databases, Redis, network or SMS.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-delivery-flow.php';

require_once __DIR__
    . '/../lib/try-now-registration-preflight.php';

function deliveryFlowCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

deliveryFlowCheck(
    'Production delivery remains disabled',
    stTryNowProductionDeliveryEnabled() === false
);

$post = [
    'industry' => 'automotive',
    'firstName' => 'Delivery',
    'lastNameCompany' => 'Test',
    'email' => 'delivery@example.com',
    'mobile' => '0871234567',
    'consent' => 'on',
    'website' => '',
];

$registration = [
    'status' => 'created',
    'customer_id' => 123,
    'code' => 'Ab1234',
    'link' => 'https://stxt.me/LJfjMU/Ab12347907',
];

$acceptedBody = '{"status":"OK","statusCode":0}';

$rejectedBody = '{"status":"ERROR","statusCode":14}';

$admitted = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionOutcome([
        'status' => 'allowed',
        'allowed' => true,
        'retry_after' => 0,
    ])
);

// Explicit test authorisation must be required.
$disabled = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    $registration,
    200,
    $acceptedBody
);

deliveryFlowCheck(
    'Flow disabled by default',
    $disabled['status'] === 'disabled' &&
    $disabled['sms_request_prepared'] === false
);

// The actual production preflight remains disabled.
$realPreflight = stTryNowRegistrationPreflight(
    ['REMOTE_ADDR' => '192.0.2.10'],
    $post
);

$blocked = stTryNowSimulateDeliveryFlow(
    $realPreflight,
    $post,
    $registration,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'Real disabled preflight blocks delivery',
    $blocked['status'] === 'blocked' &&
    $blocked['http_status'] === 503 &&
    $blocked['sms_request_prepared'] === false
);

// Synthetic Redis rate-limit rejection.
$limited = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionOutcome([
        'status' => 'limited',
        'allowed' => false,
        'retry_after' => 120,
    ])
);

$blocked = stTryNowSimulateDeliveryFlow(
    $limited,
    $post,
    $registration,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'HTTP 429 prevents SMS preparation',
    $blocked['status'] === 'blocked' &&
    $blocked['http_status'] === 429 &&
    $blocked['sms_request_prepared'] === false
);

// Synthetic Redis unavailability.
$unavailable = stTryNowRegistrationAdmissionResult(
    stTryNowAdmissionUnavailableOutcome()
);

$blocked = stTryNowSimulateDeliveryFlow(
    $unavailable,
    $post,
    $registration,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'HTTP 503 prevents SMS preparation',
    $blocked['status'] === 'blocked' &&
    $blocked['http_status'] === 503 &&
    $blocked['sms_request_prepared'] === false
);

// Successful synthetic provider acceptance.
$accepted = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    $registration,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'Customer link verified',
    $accepted['link_verified'] === true
);

deliveryFlowCheck(
    'Sendmode request structure prepared',
    $accepted['sms_request_prepared'] === true
);

deliveryFlowCheck(
    'Provider acceptance remains explicitly simulated',
    $accepted['status'] ===
        'simulated_provider_accepted' &&
    $accepted['provider_response_simulated'] === true &&
    $accepted['simulated_provider_status'] === 'accepted'
);

deliveryFlowCheck(
    'Simulation never reports an SMS sent',
    $accepted['sent'] === false &&
    $accepted['delivered'] === false
);

deliveryFlowCheck(
    'Accepted customer preserved',
    $accepted['customer_action'] === 'preserve'
);

// Explicit Sendmode rejection.
$rejected = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    $registration,
    400,
    $rejectedBody,
    true
);

deliveryFlowCheck(
    'Provider rejection requires review',
    $rejected['status'] === 'review_required' &&
    $rejected['simulated_provider_status'] ===
        'rejected'
);

deliveryFlowCheck(
    'Rejected customer preserved',
    $rejected['customer_action'] === 'preserve' &&
    $rejected['automatic_cleanup'] === false
);

// Unknown outcome: a timeout may still have
// reached the SMS provider.
$timeout = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    $registration,
    0,
    '',
    true
);

deliveryFlowCheck(
    'Timeout requires review',
    $timeout['status'] === 'review_required' &&
    $timeout['simulated_provider_status'] === 'unknown'
);

deliveryFlowCheck(
    'Timeout cannot trigger automatic retry',
    $timeout['automatic_retry'] === false
);

// Malformed provider response.
$malformed = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    $registration,
    200,
    '{invalid-json',
    true
);

deliveryFlowCheck(
    'Malformed response requires review',
    $malformed['status'] === 'review_required'
);

// Duplicate registration cannot reach SMS preparation.
$duplicate = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    ['status' => 'duplicate'],
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'Duplicate registration prevents SMS preparation',
    $duplicate['status'] === 'not_registered' &&
    $duplicate['sms_request_prepared'] === false
);

// Unexpected registration outcome must fail closed.
$unexpected = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    ['status' => 'unexpected'],
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'Unexpected registration requires review',
    $unexpected['status'] === 'review_required' &&
    $unexpected['customer_action'] === 'preserve' &&
    $unexpected['sms_request_prepared'] === false
);

// Invalid customer ID.
$invalidId = $registration;
$invalidId['customer_id'] = 0;

$invalid = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    $invalidId,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'Invalid customer ID prevents SMS preparation',
    $invalid['status'] === 'review_required' &&
    $invalid['sms_request_prepared'] === false
);

// The link must match the registered industry and code.
$wrongLink = $registration;
$wrongLink['link'] = 'https://example.com/wrong';

$invalid = stTryNowSimulateDeliveryFlow(
    $admitted,
    $post,
    $wrongLink,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'Incorrect customer link prevents SMS preparation',
    $invalid['status'] === 'review_required' &&
    $invalid['link_verified'] === false &&
    $invalid['sms_request_prepared'] === false
);

// Invalid form input must stop before SMS preparation.
$invalidPost = $post;
$invalidPost['email'] = 'invalid-email';

$invalid = stTryNowSimulateDeliveryFlow(
    $admitted,
    $invalidPost,
    $registration,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'Invalid registration input stops delivery',
    $invalid['status'] === 'invalid' &&
    $invalid['http_status'] === 422 &&
    $invalid['sms_request_prepared'] === false
);

// Verify the existing SMS builders also handle UK
// mobile numbers and the UK opt-out instructions.
$ukPost = $post;
$ukPost['mobile'] = '07123456789';

$uk = stTryNowSimulateDeliveryFlow(
    $admitted,
    $ukPost,
    $registration,
    200,
    $acceptedBody,
    true
);

deliveryFlowCheck(
    'UK recipient passes guarded SMS preparation',
    $uk['status'] ===
        'simulated_provider_accepted' &&
    $uk['sms_request_prepared'] === true
);

$ukSms = stTryNowPrepareSms(
    'automotive',
    $ukPost['mobile'],
    $registration['code']
);

$ukRequest = stTryNowBuildSendmodeRequest($ukSms);

parse_str($ukRequest['body'], $ukForm);

$ukPayload = json_decode(
    $ukForm['message'],
    true
);

deliveryFlowCheck(
    'UK recipient and opt-out are correct',
    $ukPayload['recipients'][0] === '447123456789' &&
    substr(
        $ukPayload['messagetext'],
        -strlen('Optout 07507319809')
    ) === 'Optout 07507319809'
);

// All outcomes must forbid automatic retry and cleanup.
$results = [
    $disabled,
    $blocked,
    $accepted,
    $rejected,
    $timeout,
    $malformed,
    $duplicate,
    $unexpected,
    $invalid,
    $uk,
];

$safe = true;

foreach ($results as $item) {
    if (
        $item['automatic_retry'] !== false ||
        $item['automatic_cleanup'] !== false ||
        $item['sent'] !== false ||
        $item['delivered'] !== false
    ) {
        $safe = false;
    }
}

deliveryFlowCheck(
    'All outcomes forbid retry, cleanup and real delivery',
    $safe
);

echo "PASS: All guarded delivery-flow tests.\n";
