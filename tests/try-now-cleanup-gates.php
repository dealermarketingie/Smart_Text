<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Customer cleanup safety tests.
 *
 * In-memory SQLite only.
 * No customer deletion, production database access,
 * HTTP requests or SMS delivery.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-cleanup.php';

function checkCleanup(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$rejected = [
    'status' => 'rejected',
    'accepted' => false,
    'delivered' => false,
    'provider_code' => 14,
];

$accepted = [
    'status' => 'accepted',
    'accepted' => true,
    'delivered' => false,
    'provider_code' => 0,
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

function attemptCleanup(
    PDO $pdo,
    array $result,
    bool $allowDeletion,
    int $customerId = 123,
    int $campaignId = 7907,
    string $code = 'aB1234',
    string $mobile = '089 0000000'
): array {
    return stTryNowGuardedCleanup(
        $pdo,
        $customerId,
        $campaignId,
        $code,
        $mobile,
        $result,
        $allowDeletion
    );
}

// Cleanup must be disabled by default.
$result = stTryNowGuardedCleanup(
    $pdo,
    123,
    7907,
    'aB1234',
    '089 0000000',
    $rejected
);

checkCleanup(
    'Cleanup disabled by default',
    $result['status'] === 'disabled'
    && $result['deleted'] === false
);

// Explicit false must prevent cleanup.
$result = attemptCleanup($pdo, $rejected, false);

checkCleanup(
    'Explicit deletion disable respected',
    $result['status'] === 'disabled'
    && $result['deleted'] === false
);

// These outcomes must never qualify for deletion.
$blockedCases = [
    'Accepted SMS' => $accepted,
    'Unknown SMS outcome' => $unknown,
    'Disabled transport' => $disabled,
    'Missing response' => [],
    'Rejection without provider code' => [
        'status' => 'rejected',
        'accepted' => false,
        'delivered' => false,
    ],
    'Rejection with zero provider code' => [
        'status' => 'rejected',
        'accepted' => false,
        'delivered' => false,
        'provider_code' => 0,
    ],
    'Inconsistent rejection' => [
        'status' => 'rejected',
        'accepted' => true,
        'delivered' => false,
        'provider_code' => 14,
    ],
];

foreach ($blockedCases as $name => $response) {
    $result = attemptCleanup($pdo, $response, true);

    checkCleanup(
        "$name protected",
        $result['status'] === 'not_eligible'
        && $result['deleted'] === false
    );
}

// Invalid identifiers must be rejected.
$invalidCases = [
    'Invalid customer ID' =>
        [0, 7907, 'aB1234', '089 0000000'],

    'Unapproved campaign' =>
        [123, 9999, 'aB1234', '089 0000000'],

    'Invalid customer code' =>
        [123, 7907, '123456', '089 0000000'],

    'Invalid mobile' =>
        [123, 7907, 'aB1234', '12345'],
];

foreach ($invalidCases as $name => $values) {
    $blocked = false;

    try {
        attemptCleanup(
            $pdo,
            $rejected,
            true,
            $values[0],
            $values[1],
            $values[2],
            $values[3]
        );
    } catch (InvalidArgumentException $e) {
        $blocked = true;
    }

    checkCleanup("$name rejected", $blocked);
}

// Even a qualifying rejection cannot delete via SQLite.
$blocked = false;

try {
    attemptCleanup($pdo, $rejected, true);
} catch (RuntimeException $e) {
    $blocked = (
        $e->getMessage() ===
        'Cleanup requires MySQL/MariaDB.'
    );
}

checkCleanup(
    'Non-MySQL cleanup blocked',
    $blocked
);

// No transaction should have been started.
checkCleanup(
    'No database transaction started',
    !$pdo->inTransaction()
);

echo "PASS: All cleanup safety tests.\n";
