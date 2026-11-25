<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Reconciliation tests.
 *
 * Synthetic data only.
 * No MariaDB, Redis, HTTP or SMS operations.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-reconciliation.php';
require_once __DIR__ . '/../lib/try-now-runtime.php';

function reconciliationCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

reconciliationCheck(
    'Production delivery remains disabled',
    stTryNowProductionDeliveryEnabled() === false
);

$created = [
    'status' => 'created',
    'customer_id' => 123,
    'code' => 'Ab1234',
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

/*
 * Name, registration, attempt evidence,
 * provider response, expected status,
 * expected action, expected manual review,
 * expected provider acceptance.
 */
$cases = [
    [
        'Uncertain commit requires database inspection',
        ['status' => 'outcome_unknown'],
        'confirmed_not_attempted',
        null,
        'review_required',
        'inspect_customer_database',
        true, false,
    ],
    [
        'Unknown registration and attempt require full review',
        ['status' => 'outcome_unknown'],
        'unknown',
        null,
        'review_required',
        'verify_database_and_sendmode',
        true, false,
    ],
    [
        'Registered but unsent customer requires review',
        $created,
        'confirmed_not_attempted',
        null,
        'registered_sms_not_attempted',
        'review_unsent_registration',
        true, false,
    ],
    [
        'Missing provider response requires investigation',
        $created,
        'attempted',
        null,
        'review_required',
        'check_sendmode_records',
        true, false,
    ],
    [
        'Provider acceptance is not delivery',
        $created,
        'attempted',
        $accepted,
        'provider_accepted',
        'monitor_provider_delivery',
        false, true,
    ],
    [
        'Provider rejection requires review',
        $created,
        'attempted',
        $rejected,
        'provider_rejected',
        'review_provider_rejection',
        true, false,
    ],
    [
        'Unknown provider result requires investigation',
        $created,
        'attempted',
        $unknown,
        'review_required',
        'check_sendmode_records',
        true, false,
    ],
    [
        'Unknown attempt history cannot confirm acceptance',
        $created,
        'unknown',
        $accepted,
        'review_required',
        'verify_database_and_sendmode',
        true, false,
    ],
    [
        'Contradictory provider response requires review',
        $created,
        'confirmed_not_attempted',
        $accepted,
        'review_required',
        'verify_database_and_sendmode',
        true, false,
    ],
    [
        'Duplicate registration cannot trigger a new send',
        ['status' => 'duplicate'],
        'confirmed_not_attempted',
        null,
        'no_new_registration',
        'none',
        false, false,
    ],
    [
        'Unexpected transport after duplicate needs review',
        ['status' => 'duplicate'],
        'attempted',
        null,
        'review_required',
        'verify_database_and_sendmode',
        true, false,
    ],
    [
        'Invalid customer identity requires review',
        ['status' => 'created', 'customer_id' => 0],
        'attempted',
        $accepted,
        'review_required',
        'verify_database_and_sendmode',
        true, false,
    ],
    [
        'Unexpected registration result requires review',
        ['status' => 'unexpected'],
        'unknown',
        null,
        'review_required',
        'verify_database_and_sendmode',
        true, false,
    ],
    [
        'Invalid attempt evidence fails closed',
        $created,
        'invalid',
        null,
        'review_required',
        'verify_database_and_sendmode',
        true, false,
    ],
];

foreach ($cases as $case) {
    [
        $name,
        $registration,
        $attemptState,
        $provider,
        $expectedStatus,
        $expectedAction,
        $expectedReview,
        $expectedAccepted,
    ] = $case;

    $result = stTryNowReconciliationPlan(
        $registration,
        $attemptState,
        $provider
    );

    reconciliationCheck(
        $name,
        $result['status'] === $expectedStatus &&
        $result['next_action'] === $expectedAction &&
        $result['manual_review'] === $expectedReview &&
        $result['provider_accepted'] === $expectedAccepted &&
        $result['delivered'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false
    );
}

echo "PASS: All reconciliation tests.\n";
