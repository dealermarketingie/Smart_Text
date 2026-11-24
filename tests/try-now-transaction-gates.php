<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Registration safety tests.
 *
 * In-memory SQLite only.
 * No production database, SMS or email operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-transaction.php';

function checkGate(string $name, bool $passed): void
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

$input = [
    'industry' => 'automotive',
    'firstName' => 'John',
    'lastNameCompany' => 'Test',
    'email' => 'john@example.com',
    'mobile' => '0871234567',
    'consent' => 'on',
    'website' => '',
];

// Registration must be disabled by default.
$result = stTryNowRegisterCustomer($pdo, $input);

checkGate(
    'Registration disabled by default',
    $result['status'] === 'disabled'
);

// Explicit false must also prevent registration.
$result = stTryNowRegisterCustomer(
    $pdo,
    $input,
    false
);

checkGate(
    'Explicit write disable respected',
    $result['status'] === 'disabled'
);

// Even invalid input cannot bypass the disabled gate.
$result = stTryNowRegisterCustomer(
    $pdo,
    [],
    false
);

checkGate(
    'Disabled gate runs before registration processing',
    $result['status'] === 'disabled'
);

// Explicit write permission must not permit SQLite writes.
$blocked = false;

try {
    stTryNowRegisterCustomer($pdo, $input, true);
} catch (RuntimeException $e) {
    $blocked = (
        $e->getMessage() ===
        'Registration requires MySQL/MariaDB.'
    );
}

checkGate(
    'Non-MySQL registration rejected',
    $blocked
);

// No transaction should remain open.
checkGate(
    'No database transaction started',
    !$pdo->inTransaction()
);

echo "PASS: All registration safety tests.\n";
