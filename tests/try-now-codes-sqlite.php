<?php
declare(strict_types=1);

/**
 * Synthetic customer-code collision tests.
 * In-memory SQLite only. No production database or SMS.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-codes.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Simulate a case-insensitive code column.
// We'll check the actual MariaDB collation separately.
$pdo->exec("
    CREATE TABLE customer (
        campaign_id INTEGER NOT NULL,
        code TEXT COLLATE NOCASE NOT NULL
    )
");

$insert = $pdo->prepare(
    'INSERT INTO customer (campaign_id, code) VALUES (?, ?)'
);

// Entirely fictional customer codes.
$insert->execute([7907, 'aBc123']);
$insert->execute([7910, 'ZyX987']);

$tests = [
    ['Existing code', 7907, 'aBc123', true],
    ['Case variation', 7907, 'ABC123', true],
    ['Different campaign', 7908, 'aBc123', false],
    ['Other campaign existing', 7910, 'ZyX987', true],
    ['Unknown code', 7907, 'QwE123', false],
];

foreach ($tests as [$name, $campaignId, $code, $expected]) {
    $actual = stTryNowCustomerCodeExists(
        $pdo,
        $campaignId,
        $code
    );

    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

// Verify allocation of a valid, currently unused code.
$generated = stTryNowGenerateUnusedCustomerCode($pdo, 7907);

if (!preg_match('/^[A-Za-z][A-Za-z0-9]{5}$/D', $generated)) {
    fwrite(STDERR, "FAIL: Generated code format\n");
    exit(1);
}

if (stTryNowCustomerCodeExists($pdo, 7907, $generated)) {
    fwrite(STDERR, "FAIL: Generated code already exists\n");
    exit(1);
}

echo "PASS: Generated code format\n";
echo "PASS: Generated code is unused\n";

// Check invalid code rejection.
try {
    stTryNowCustomerCodeExists($pdo, 7907, '123456');

    fwrite(STDERR, "FAIL: Invalid code accepted\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    echo "PASS: Invalid code rejected\n";
}

echo "PASS: All synthetic customer-code tests.\n";
