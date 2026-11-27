<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Visitor IP security tests.
 *
 * Synthetic request environments only.
 * No HTTP, database, Redis or SMS operations.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-client-ip.php';

function checkClientIp(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

function expectClientIpRejected(
    string $name,
    array $server
): void {
    $blocked = false;

    try {
        stTryNowClientIp($server);
    } catch (RuntimeException $e) {
        $blocked = true;
    }

    checkClientIp($name, $blocked);
}

$realIp = '198.51.100.24';

checkClientIp(
    'Valid IPv4 accepted',
    stTryNowClientIp([
        'REMOTE_ADDR' => $realIp,
    ]) === $realIp
);

// Visitor-supplied forwarding headers must be ignored.
checkClientIp(
    'Spoofed X-Forwarded-For ignored',
    stTryNowClientIp([
        'REMOTE_ADDR' => $realIp,
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
    ]) === $realIp
);

checkClientIp(
    'Spoofed X-Real-IP ignored',
    stTryNowClientIp([
        'REMOTE_ADDR' => $realIp,
        'HTTP_X_REAL_IP' => '203.0.113.99',
    ]) === $realIp
);

checkClientIp(
    'Multiple spoofed forwarding addresses ignored',
    stTryNowClientIp([
        'REMOTE_ADDR' => $realIp,
        'HTTP_X_FORWARDED_FOR' =>
            '203.0.113.1, 203.0.113.2',
        'HTTP_X_REAL_IP' => '203.0.113.3',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.4',
    ]) === $realIp
);

// IPv6 must also work.
checkClientIp(
    'Valid IPv6 accepted',
    stTryNowClientIp([
        'REMOTE_ADDR' => '2001:db8::1234',
    ]) === '2001:db8::1234'
);

// Changing REMOTE_ADDR changes the selected visitor.
checkClientIp(
    'Different visitor IP recognised',
    stTryNowClientIp([
        'REMOTE_ADDR' => '198.51.100.25',
        'HTTP_X_FORWARDED_FOR' => $realIp,
    ]) === '198.51.100.25'
);

// Never fall back to untrusted headers.
expectClientIpRejected(
    'Missing REMOTE_ADDR rejected',
    [
        'HTTP_X_FORWARDED_FOR' => $realIp,
    ]
);

expectClientIpRejected(
    'Invalid REMOTE_ADDR rejected',
    [
        'REMOTE_ADDR' => 'invalid-ip',
    ]
);

expectClientIpRejected(
    'Empty REMOTE_ADDR rejected',
    [
        'REMOTE_ADDR' => '',
    ]
);

expectClientIpRejected(
    'Non-string REMOTE_ADDR rejected',
    [
        'REMOTE_ADDR' => ['198.51.100.24'],
    ]
);

echo "PASS: All visitor IP security tests.\n";
