<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Atomic Redis integration tests.
 *
 * Uses isolated, randomly named test keys only.
 * Does not touch production rate-limit counters.
 * No database access, SMS or email.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-rate-limit.php';

function redisRateCheck(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function redisRateEval(
    Redis $redis,
    array $keys
): array {
    // Deliberately small limits for testing:
    // IP: 2, mobile: 1, global: 2.
    // All windows expire after 120 seconds.
    $result = $redis->eval(
        stTryNowRateLimitScript(),
        array_merge(
            $keys,
            [2, 1, 2, 120, 120, 120]
        ),
        3
    );

    if (!is_array($result) || count($result) !== 3) {
        throw new RuntimeException(
            'Unexpected Lua response.'
        );
    }

    return $result;
}

$redis = null;
$error = null;

// This namespace is separate from production keys.
$prefix = 'stxt:try-now:rate:test:'
    . bin2hex(random_bytes(16)) . ':';

$ipA = $prefix . 'ip:a';
$ipB = $prefix . 'ip:b';

$mobileA = $prefix . 'mobile:a';
$mobileB = $prefix . 'mobile:b';
$mobileC = $prefix . 'mobile:c';

$globalA = $prefix . 'global:a';
$globalB = $prefix . 'global:b';

$corrupt = $prefix . 'ip:corrupt';

$testKeys = [
    $ipA, $ipB,
    $mobileA, $mobileB, $mobileC,
    $globalA, $globalB,
    $corrupt,
];

try {
    $redis = stTryNowRedisConnect();

    redisRateCheck(
        'Dedicated Redis connection established',
        $redis instanceof Redis
    );

    // First request must increment all three counters.
    $result = redisRateEval(
        $redis,
        [$ipA, $mobileA, $globalA]
    );

    redisRateCheck(
        'First request allowed',
        $result === [1, 0, 0]
    );

    redisRateCheck(
        'All three counters incremented',
        (int) $redis->get($ipA) === 1
        && (int) $redis->get($mobileA) === 1
        && (int) $redis->get($globalA) === 1
    );

    redisRateCheck(
        'Counter expiry configured',
        $redis->ttl($ipA) > 0
        && $redis->ttl($mobileA) > 0
        && $redis->ttl($globalA) > 0
    );

    // Same mobile must be blocked.
    $result = redisRateEval(
        $redis,
        [$ipA, $mobileA, $globalA]
    );

    redisRateCheck(
        'Mobile limit enforced',
        $result[0] === 0
        && $result[1] === 2
        && $result[2] > 0
    );

    redisRateCheck(
        'Rejected request does not increment counters',
        (int) $redis->get($ipA) === 1
        && (int) $redis->get($mobileA) === 1
        && (int) $redis->get($globalA) === 1
    );

    // Different mobile, same IP: permitted once more.
    $result = redisRateEval(
        $redis,
        [$ipA, $mobileB, $globalA]
    );

    redisRateCheck(
        'Second mobile allowed',
        $result === [1, 0, 0]
        && (int) $redis->get($ipA) === 2
        && (int) $redis->get($globalA) === 2
    );

    // Different IP and mobile, but global limit reached.
    $result = redisRateEval(
        $redis,
        [$ipB, $mobileC, $globalA]
    );

    redisRateCheck(
        'Global limit enforced',
        $result[0] === 0
        && $result[1] === 3
        && $result[2] > 0
        && $redis->exists($ipB) === 0
        && $redis->exists($mobileC) === 0
    );

    // New global counter, but original IP is exhausted.
    $result = redisRateEval(
        $redis,
        [$ipA, $mobileC, $globalB]
    );

    redisRateCheck(
        'IP limit enforced',
        $result[0] === 0
        && $result[1] === 1
        && $result[2] > 0
        && $redis->exists($mobileC) === 0
        && $redis->exists($globalB) === 0
    );

    // A malformed counter must fail closed.
    $redis->setEx($corrupt, 120, 'invalid-counter');

    $result = redisRateEval(
        $redis,
        [$corrupt, $mobileC, $globalB]
    );

    redisRateCheck(
        'Corrupt counter rejected',
        $result === [-1, 1, 0]
    );

    redisRateCheck(
        'Corrupt counter causes no partial increments',
        $redis->exists($mobileC) === 0
        && $redis->exists($globalB) === 0
    );

    echo "PASS: Atomic Redis integration completed.\n";

} catch (Throwable $e) {
    $error = $e->getMessage();

} finally {
    // Remove only our randomly named test keys.
    // Keys also expire automatically after 120 seconds.
    if ($redis instanceof Redis) {
        foreach ($testKeys as $key) {
            try {
                $redis->del($key);
            } catch (Throwable $ignored) {
                // Expiry provides secondary cleanup.
            }
        }

        try {
            $redis->close();
        } catch (Throwable $ignored) {
        }
    }
}

if ($error !== null) {
    fwrite(STDERR, "FAIL: $error\n");
    exit(1);
}

echo "PASS: Temporary Redis test keys cleaned up.\n";
