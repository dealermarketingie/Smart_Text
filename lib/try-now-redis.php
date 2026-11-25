<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Dedicated Redis connection.
 *
 * Uses private credentials outside httpdocs.
 * Connects only to the dedicated localhost Redis instance.
 *
 * No SMS, database writes or customer operations.
 *
 * PHP 7.4 compatible.
 */

function stTryNowRedisConnect(): Redis
{
    if (!class_exists('Redis')) {
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );
    }

    // Works from both httpdocs/lib and try-now-check/lib.
    $passwordFile = dirname(__DIR__, 2)
        . '/private/try-now-redis.password';

    if (
        !is_file($passwordFile) ||
        is_link($passwordFile) ||
        !is_readable($passwordFile)
    ) {
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );
    }

    $password = @file_get_contents($passwordFile);

    if (!is_string($password)) {
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );
    }

    // Remove the newline written by our setup script.
    $password = rtrim($password, "\r\n");

    if (!preg_match('/^[a-f0-9]{64}$/D', $password)) {
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );
    }

    $redis = new Redis();

    try {
        // Only our private localhost Redis endpoint.
        if (!$redis->connect('127.0.0.1', 16379, 2.0)) {
            throw new RuntimeException(
                'Redis connection failed.'
            );
        }

        // Avoid PHP requests waiting indefinitely.
        if (!$redis->setOption(
            Redis::OPT_READ_TIMEOUT,
            2.0
        )) {
            throw new RuntimeException(
                'Redis timeout configuration failed.'
            );
        }

        if ($redis->auth($password) !== true) {
            throw new RuntimeException(
                'Redis authentication failed.'
            );
        }

        unset($password);

        return $redis;

    } catch (Throwable $e) {
        unset($password);

        try {
            $redis->close();
        } catch (Throwable $ignored) {
            // Preserve the original failure.
        }

        // Never expose connection details or credentials
        // to the public website.
        throw new RuntimeException(
            'Try Now rate limiting is unavailable.'
        );
    }
}
