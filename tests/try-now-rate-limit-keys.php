<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Rate-limit key tests.
 *
 * Synthetic IP addresses and mobile numbers only.
 * No Redis connection, database access or SMS.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-rate-limit.php';

function checkRateKey(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

function expectInvalidRateKey(
    string $name,
    string $ip,
    string $mobile,
    string $secret
): void {
    $blocked = false;

    try {
        stTryNowRateLimitKeys($ip, $mobile, $secret);
    } catch (InvalidArgumentException $e) {
        $blocked = true;
    }

    checkRateKey($name, $blocked);
}

// Synthetic secret - never use the production secret.
$secret = str_repeat('a', 64);
$otherSecret = str_repeat('b', 64);

$keys = stTryNowRateLimitKeys(
    '192.0.2.10',
    '0871234567',
    $secret
);

checkRateKey(
    'Three rate-limit keys generated',
    count($keys) === 3
);

checkRateKey(
    'IP key has correct format',
    preg_match(
        '/^stxt:try-now:rate:v1:ip:[a-f0-9]{64}$/D',
        $keys[0]
    ) === 1
);

checkRateKey(
    'Mobile key has correct format',
    preg_match(
        '/^stxt:try-now:rate:v1:mobile:[a-f0-9]{64}$/D',
        $keys[1]
    ) === 1
);

checkRateKey(
    'Global key has correct namespace',
    $keys[2] === 'stxt:try-now:rate:v1:global'
);

checkRateKey(
    'Identical input produces identical keys',
    $keys === stTryNowRateLimitKeys(
        '192.0.2.10',
        '0871234567',
        $secret
    )
);

// Same Irish mobile in different formats.
$irish = stTryNowRateLimitKeys(
    '192.0.2.10',
    '+353871234567',
    $secret
);

checkRateKey(
    'Irish international format matches',
    $keys[1] === $irish[1]
);

$irish00353 = stTryNowRateLimitKeys(
    '192.0.2.10',
    '00353871234567',
    $secret
);

checkRateKey(
    'Irish 00353 format matches',
    $keys[1] === $irish00353[1]
);

// Same UK mobile in different formats.
$ukNational = stTryNowRateLimitKeys(
    '192.0.2.10',
    '07700900123',
    $secret
);

$ukInternational = stTryNowRateLimitKeys(
    '192.0.2.10',
    '+447700900123',
    $secret
);

checkRateKey(
    'UK mobile formats produce identical keys',
    $ukNational[1] === $ukInternational[1]
);

// Different IP must affect only the IP counter.
$otherIp = stTryNowRateLimitKeys(
    '192.0.2.11',
    '0871234567',
    $secret
);

checkRateKey(
    'Different IP produces different IP key',
    $keys[0] !== $otherIp[0]
);

checkRateKey(
    'Different IP preserves mobile and global keys',
    $keys[1] === $otherIp[1]
    && $keys[2] === $otherIp[2]
);

// Different mobile must affect only the mobile counter.
$otherMobile = stTryNowRateLimitKeys(
    '192.0.2.10',
    '0871234568',
    $secret
);

checkRateKey(
    'Different mobile produces different mobile key',
    $keys[1] !== $otherMobile[1]
);

checkRateKey(
    'Different mobile preserves IP and global keys',
    $keys[0] === $otherMobile[0]
    && $keys[2] === $otherMobile[2]
);

// Different secrets must produce different HMAC keys.
$changedSecret = stTryNowRateLimitKeys(
    '192.0.2.10',
    '0871234567',
    $otherSecret
);

checkRateKey(
    'Different secret changes visitor keys',
    $keys[0] !== $changedSecret[0]
    && $keys[1] !== $changedSecret[1]
);

checkRateKey(
    'Global key remains consistent',
    $keys[2] === $changedSecret[2]
);

// IPv6 addresses must also be supported.
$ipv6 = stTryNowRateLimitKeys(
    '2001:db8::10',
    '0871234567',
    $secret
);

checkRateKey(
    'IPv6 address supported',
    $ipv6[0] !== $keys[0]
    && $ipv6[1] === $keys[1]
);

// Identifiable information must not appear in keys.
$combined = implode('|', $keys);

checkRateKey(
    'No raw IP or mobile in Redis keys',
    strpos($combined, '192.0.2.10') === false
    && strpos($combined, '0871234567') === false
);

// Invalid inputs must fail closed.
expectInvalidRateKey(
    'Invalid IP rejected',
    'not-an-ip',
    '0871234567',
    $secret
);

expectInvalidRateKey(
    'Invalid mobile rejected',
    '192.0.2.10',
    '12345',
    $secret
);

expectInvalidRateKey(
    'Invalid secret rejected',
    '192.0.2.10',
    '0871234567',
    'invalid-secret'
);

echo "PASS: All rate-limit key tests.\n";
