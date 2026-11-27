<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Delivery result tests.
 *
 * Synthetic results only.
 * No Redis, database, HTTP or SMS operations.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-delivery-result.php';

function deliveryResultCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

$created = [
    'status' => 'created',
    'customer_id' => 123,
    'code' => 'Ab1234',
    'link' => 'https://stxt.me/LJfjMU/Ab12347907',
];

$accepted = [
    'status' => 'accepted',
    'accepted' => true,
    'delivered' => false,
    'provider_code' => 0,
];

$rejected = [
    'status' => 'rejected',
    'accepted' => false,
    'delivered' => false,
    'provider_code' => 14,
];

$unknown = [
    'status' => 'unknown',
    'accepted' => false,
    'delivered' => false,
    'provider_code' => null,
];

$disabled = [
    'status' => 'disabled',
    'accepted' => false,
    'delivered' => false,
    'provider_code' => null,
];

foreach (
    ['disabled', 'invalid', 'duplicate', 'unavailable']
    as $registrationStatus
) {
    $result = stTryNowFinalDeliveryDecision(
        ['status' => $registrationStatus],
        null
    );

    deliveryResultCheck(
        "$registrationStatus prevents delivery",
        $result['status'] === 'not_registered' &&
        $result['customer_action'] === 'none' &&
        $result['provider_accepted'] === false
    );
}

$success = stTryNowFinalDeliveryDecision(
    $created,
    $accepted
);

deliveryResultCheck(
    'Provider acceptance recognised',
    $success['status'] === 'provider_accepted' &&
    $success['provider_accepted'] === true
);

deliveryResultCheck(
    'Acceptance does not imply delivery',
    $success['delivered'] === false
);

deliveryResultCheck(
    'Accepted customer remains preserved',
    $success['customer_action'] === 'preserve'
);

deliveryResultCheck(
    'Accepted SMS cannot automatically retry',
    $success['automatic_retry'] === false
);

$explicitRejection = stTryNowFinalDeliveryDecision(
    $created,
    $rejected
);

deliveryResultCheck(
    'Explicit rejection requires review',
    $explicitRejection['status'] === 'review_required' &&
    $explicitRejection['manual_review'] === true
);

deliveryResultCheck(
    'Rejected SMS does not automatically delete',
    $explicitRejection['customer_action'] === 'preserve' &&
    $explicitRejection['automatic_cleanup'] === false
);

$uncertain = stTryNowFinalDeliveryDecision(
    $created,
    $unknown
);

deliveryResultCheck(
    'Unknown SMS outcome requires review',
    $uncertain['status'] === 'review_required' &&
    $uncertain['provider_accepted'] === false
);

deliveryResultCheck(
    'Unknown SMS outcome cannot automatically retry',
    $uncertain['automatic_retry'] === false
);

$transportDisabled = stTryNowFinalDeliveryDecision(
    $created,
    $disabled
);

deliveryResultCheck(
    'Disabled SMS after registration requires review',
    $transportDisabled['status'] === 'review_required' &&
    $transportDisabled['customer_action'] === 'preserve'
);

$missingResponse = stTryNowFinalDeliveryDecision(
    $created,
    null
);

deliveryResultCheck(
    'Missing provider response requires review',
    $missingResponse['status'] === 'review_required'
);

$invalidRegistration = $created;
$invalidRegistration['customer_id'] = 0;

deliveryResultCheck(
    'Invalid registered customer ID fails closed',
    stTryNowFinalDeliveryDecision(
        $invalidRegistration,
        $accepted
    )['status'] === 'review_required'
);

$invalidRegistration = $created;
$invalidRegistration['code'] = 'invalid-code';

deliveryResultCheck(
    'Invalid registered customer code fails closed',
    stTryNowFinalDeliveryDecision(
        $invalidRegistration,
        $accepted
    )['status'] === 'review_required'
);

$unexpected = stTryNowFinalDeliveryDecision(
    ['status' => 'unexpected'],
    $accepted
);

deliveryResultCheck(
    'Unexpected registration outcome fails closed',
    $unexpected['status'] === 'review_required' &&
    $unexpected['provider_accepted'] === false
);

// Inconsistent provider responses cannot imply success.
$inconsistent = stTryNowFinalDeliveryDecision(
    $created,
    [
        'status' => 'accepted',
        'accepted' => true,
        'delivered' => true,
    ]
);

deliveryResultCheck(
    'Inconsistent SMS response requires review',
    $inconsistent['status'] === 'review_required'
);

// No result may request automatic retry or cleanup.
$allResults = [
    $success,
    $explicitRejection,
    $uncertain,
    $transportDisabled,
    $missingResponse,
    $unexpected,
    $inconsistent,
];

$safe = true;

foreach ($allResults as $item) {
    if (
        $item['automatic_retry'] !== false ||
        $item['automatic_cleanup'] !== false
    ) {
        $safe = false;
    }
}

deliveryResultCheck(
    'Automatic retry and cleanup always disabled',
    $safe
);

echo "PASS: All delivery result tests.\n";
