<?php
declare(strict_types=1);

/**
 * Synthetic duplicate detection tests.
 * Uses an in-memory SQLite database only.
 * No production database, SMS or email access.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-duplicates.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// SQLite does not provide REGEXP by default.
// Register it to support our existing MySQL query.
$pdo->sqliteCreateFunction(
    'regexp',
    static function ($pattern, $value): int {
        if ($value === null) {
            return 0;
        }

        return preg_match(
            '~' . str_replace('~', '\~', $pattern) . '~',
            (string) $value
        ) === 1 ? 1 : 0;
    },
    2
);

$pdo->exec("
    CREATE TABLE customer (
        code TEXT NOT NULL,
        mobile TEXT NOT NULL,
        campaign_id INTEGER NOT NULL
    )
");

$insert = $pdo->prepare(
    'INSERT INTO customer (code, mobile, campaign_id)
     VALUES (?, ?, ?)'
);

// Synthetic customers - no real personal information.
$customers = [
    ['a12345', '087 123 4567', 7907],
    ['b12345', '+353 86 123 4567', 7907],
    ['c12345', '00353851234567', 7907],
    ['d12345', '07700 900123', 7908],
    ['e12345', '+44 7700 900124', 7908],
    ['f12345', '0044 7700 900125', 7908],

    // Predefined sample customer - must be excluded.
    ['1a2s3d', '089 000 0000', 7907],

    // Same mobile as Automotive, different campaign.
    ['g12345', '0871234567', 7909],
];

foreach ($customers as $customer) {
    $insert->execute($customer);
}

$tests = [
    ['Irish national', 7907, '0871234567', true],
    ['Irish +353', 7907, '+353871234567', true],
    ['Irish 00353', 7907, '00353871234567', true],
    ['Irish stored +353', 7907, '0861234567', true],
    ['Irish stored 00353', 7907, '0851234567', true],

    ['UK national', 7908, '07700900123', true],
    ['UK +44', 7908, '+447700900123', true],
    ['UK 0044', 7908, '00447700900123', true],
    ['UK stored +44', 7908, '07700900124', true],
    ['UK stored 0044', 7908, '07700900125', true],

    ['Different campaign IE', 7908, '0871234567', false],
    ['Different campaign UK', 7907, '07700900123', false],
    ['Same number in Property', 7909, '0871234567', true],

    ['Exclude sample customer', 7907, '0890000000', false],
    ['Unknown customer', 7907, '0879999999', false],
];

foreach ($tests as [$name, $campaign, $mobile, $expected]) {
    $actual = stTryNowDuplicateExists(
        $pdo,
        $campaign,
        $mobile
    );

    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

echo "PASS: All synthetic duplicate tests.\n";
