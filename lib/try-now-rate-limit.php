<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Redis rate limiting.
 *
 * Atomic IP, mobile and global request limits.
 * No customer records, database writes, SMS or email.
 *
 * Not connected to the public API yet.
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-redis.php';
require_once __DIR__ . '/try-now-support.php';

/**
 * Build keys without storing identifiable information.
 *
 * The secret is supplied separately so this function
 * can be tested without accessing private credentials.
 */
function stTryNowRateLimitKeys(
    string $ip,
    string $mobile,
    string $secret
): array {
    $packedIp = @inet_pton($ip);
    $normalisedMobile = stTryNowNormalizeMobile($mobile);

    if (
        $packedIp === false ||
        $normalisedMobile === null ||
        !preg_match('/^[a-f0-9]{64}$/D', $secret)
    ) {
        throw new InvalidArgumentException(
            'Invalid rate-limit identifiers.'
        );
    }

    $prefix = 'stxt:try-now:rate:v1:';

    return [
        $prefix . 'ip:' . hash_hmac(
            'sha256',
            'ip:' . bin2hex($packedIp),
            $secret
        ),

        $prefix . 'mobile:' . hash_hmac(
            'sha256',
            'mobile:' . $normalisedMobile,
            $secret
        ),

        $prefix . 'global',
    ];
}

/**
 * Redis checks all limits BEFORE incrementing.
 *
 * KEYS:
 *   1 = IP
 *   2 = mobile
 *   3 = global
 *
 * ARGV:
 *   1-3 = maximum request counts
 *   4-6 = expiry durations in seconds
 */
function stTryNowRateLimitScript(): string
{
    return <<<'LUA'
local limits = {
    tonumber(ARGV[1]),
    tonumber(ARGV[2]),
    tonumber(ARGV[3])
}

local windows = {
    tonumber(ARGV[4]),
    tonumber(ARGV[5]),
    tonumber(ARGV[6])
}

-- First examine every counter without modifying it.
for i = 1, 3 do
    local value = redis.call('GET', KEYS[i])

    if value then
        local count = tonumber(value)
        local remaining = redis.call('PTTL', KEYS[i])

        -- Corrupt or non-expiring counters fail closed.
        if not count
           or count < 0
           or count ~= math.floor(count)
           or remaining <= 0 then
            return {-1, i, 0}
        end

        if count >= limits[i] then
            return {
                0,
                i,
                math.ceil(remaining / 1000)
            }
        end
    end
end

-- All limits passed. Update all three counters.
for i = 1, 3 do
    local count = redis.call('INCR', KEYS[i])

    -- Start the expiry window with the first request.
    if count == 1 then
        redis.call('EXPIRE', KEYS[i], windows[i])
    end
end

return {1, 0, 0}
LUA;
}

/**
 * Consume one request allowance across all three limits.
 *
 * Returns:
 *   allowed: boolean
 *   scope: null, ip, mobile or global
 *   retry_after: seconds
 *
 * Throws on configuration or Redis failures.
 * The calling workflow must fail closed.
 */
function stTryNowConsumeRateLimit(
    string $ip,
    string $mobile
): array {
    $secretFile = dirname(__DIR__, 2)
        . '/private/try-now-rate-limit.key';

    if (
        !is_file($secretFile) ||
        is_link($secretFile) ||
        !is_readable($secretFile)
    ) {
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );
    }

    $secret = @file_get_contents($secretFile);

    if (!is_string($secret)) {
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );
    }

    $secret = rtrim($secret, "\r\n");

    $keys = stTryNowRateLimitKeys(
        $ip,
        $mobile,
        $secret
    );

    unset($secret);

    // Limits: IP 10/hour, mobile 3/day, global 100/day.
    $arguments = array_merge(
        $keys,
        [10, 3, 100, 3600, 86400, 86400]
    );

    $redis = null;

    try {
        $redis = stTryNowRedisConnect();

        // Three Redis keys, followed by six arguments.
        $result = $redis->eval(
            stTryNowRateLimitScript(),
            $arguments,
            3
        );

        if (
            !is_array($result) ||
            count($result) !== 3 ||
            !is_int($result[0]) ||
            !is_int($result[1]) ||
            !is_int($result[2])
        ) {
            throw new RuntimeException(
                'Unexpected Redis rate-limit response.'
            );
        }

        if ($result === [1, 0, 0]) {
            return [
                'allowed' => true,
                'scope' => null,
                'retry_after' => 0,
            ];
        }

        if (
            $result[0] === 0 &&
            in_array($result[1], [1, 2, 3], true) &&
            $result[2] > 0
        ) {
            $scopes = [
                1 => 'ip',
                2 => 'mobile',
                3 => 'global',
            ];

            return [
                'allowed' => false,
                'scope' => $scopes[$result[1]],
                'retry_after' => $result[2],
            ];
        }

        throw new RuntimeException(
            'Invalid Redis counter state.'
        );

    } catch (Throwable $e) {
        // Never bypass rate limiting on Redis errors.
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );

    } finally {
        if ($redis instanceof Redis) {
            try {
                $redis->close();
            } catch (Throwable $ignored) {
                // Preserve the original outcome.
            }
        }
    }
}
