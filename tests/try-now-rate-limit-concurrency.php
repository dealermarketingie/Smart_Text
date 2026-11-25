<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Redis concurrency test.
 *
 * 20 concurrent processes against a global limit of 5.
 * Random, temporary test keys only.
 *
 * No production counters, database access, SMS or email.
 * Run on Plesk CLI only.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-rate-limit.php';

foreach ([
    'pcntl_fork',
    'pcntl_waitpid',
    'pcntl_wifexited',
    'pcntl_wexitstatus',
] as $function) {
    if (!function_exists($function)) {
        fwrite(STDERR, "FAIL: Process control unavailable.\n");
        exit(1);
    }
}

$workerCount = 20;
$globalLimit = 5;

// Completely separate from production rate-limit keys.
$prefix = 'stxt:try-now:rate:test:concurrency:'
    . bin2hex(random_bytes(16)) . ':';

$globalKey = $prefix . 'global';

$keys = [$globalKey];
$workers = [];

for ($i = 0; $i < $workerCount; $i++) {
    $ipKey = $prefix . 'ip:' . $i;
    $mobileKey = $prefix . 'mobile:' . $i;

    $workers[$i] = [$ipKey, $mobileKey, $globalKey];

    $keys[] = $ipKey;
    $keys[] = $mobileKey;
}

// Give all processes time to start before evaluating.
$startAt = microtime(true) + 3.0;

$children = [];
$forkFailed = false;

for ($i = 0; $i < $workerCount; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        $forkFailed = true;
        break;
    }

    if ($pid === 0) {
        // Child process: open its own Redis connection.
        $client = null;
        $exitCode = 12;

        try {
            $client = stTryNowRedisConnect();

            // Coordinate the start of all workers.
            $remaining = $startAt - microtime(true);

            if ($remaining > 0) {
                usleep((int) ($remaining * 1000000));
            }

            $result = $client->eval(
                stTryNowRateLimitScript(),
                array_merge(
                    $workers[$i],
                    [
                        5, 5, $globalLimit,
                        120, 120, 120
                    ]
                ),
                3
            );

            if ($result === [1, 0, 0]) {
                // Request allowed.
                $exitCode = 0;

            } elseif (
                is_array($result)
                && count($result) === 3
                && $result[0] === 0
                && $result[1] === 3
                && $result[2] > 0
            ) {
                // Blocked by global limit.
                $exitCode = 10;
            }

        } catch (Throwable $e) {
            $exitCode = 12;

        } finally {
            if ($client instanceof Redis) {
                try {
                    $client->close();
                } catch (Throwable $ignored) {
                }
            }
        }

        exit($exitCode);
    }

    // Parent tracks child process IDs.
    $children[] = $pid;
}

// Collect all results before inspecting Redis.
$allowed = 0;
$blocked = 0;
$errors = 0;

foreach ($children as $pid) {
    $status = 0;
    $waited = pcntl_waitpid($pid, $status);

    if (
        $waited !== $pid
        || !pcntl_wifexited($status)
    ) {
        $errors++;
        continue;
    }

    $code = pcntl_wexitstatus($status);

    if ($code === 0) {
        $allowed++;
    } elseif ($code === 10) {
        $blocked++;
    } else {
        $errors++;
    }
}

$redis = null;
$passed = false;

try {
    if ($forkFailed || count($children) !== 20) {
        throw new RuntimeException(
            'Unable to start all 20 workers.'
        );
    }

    if ($errors !== 0) {
        throw new RuntimeException(
            'One or more workers failed.'
        );
    }

    echo "PASS: 20 concurrent workers completed.\n";

    if ($allowed !== 5 || $blocked !== 15) {
        throw new RuntimeException(
            "Unexpected results: $allowed allowed, "
            . "$blocked blocked."
        );
    }

    echo "PASS: Exactly 5 requests allowed.\n";
    echo "PASS: Exactly 15 requests rejected.\n";

    // Connect only after all child processes have exited.
    $redis = stTryNowRedisConnect();

    $globalCount = (int) $redis->get($globalKey);

    if ($globalCount !== 5) {
        throw new RuntimeException(
            'Global counter exceeded or missed its limit.'
        );
    }

    echo "PASS: Global counter stopped at 5.\n";

    // Verify that rejected requests did not
    // increment their individual counters.
    $ipTotal = 0;
    $mobileTotal = 0;

    foreach ($workers as $workerKeys) {
        $ipCount = (int) $redis->get($workerKeys[0]);
        $mobileCount = (int) $redis->get($workerKeys[1]);

        if (
            !in_array($ipCount, [0, 1], true)
            || !in_array($mobileCount, [0, 1], true)
            || $ipCount !== $mobileCount
        ) {
            throw new RuntimeException(
                'Partial counter update detected.'
            );
        }

        $ipTotal += $ipCount;
        $mobileTotal += $mobileCount;
    }

    if ($ipTotal !== 5 || $mobileTotal !== 5) {
        throw new RuntimeException(
            'Individual counter totals are incorrect.'
        );
    }

    echo "PASS: No partial counter increments.\n";

    $ttl = $redis->ttl($globalKey);

    if ($ttl <= 0 || $ttl > 120) {
        throw new RuntimeException(
            'Global counter expiry is invalid.'
        );
    }

    echo "PASS: Global counter expiry verified.\n";

    $passed = true;

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'FAIL: ' . $e->getMessage() . PHP_EOL
    );

} finally {
    // Clean up ONLY this test's randomly named keys.
    // The 120-second expiry provides fallback cleanup.
    if (!$redis instanceof Redis) {
        try {
            $redis = stTryNowRedisConnect();
        } catch (Throwable $ignored) {
        }
    }

    if ($redis instanceof Redis) {
        try {
            foreach ($keys as $key) {
                $redis->del($key);
            }

            echo "PASS: Temporary test keys cleaned up.\n";

        } catch (Throwable $e) {
            $passed = false;
            fwrite(
                STDERR,
                "FAIL: Test-key cleanup failed.\n"
            );

        } finally {
            $redis->close();
        }
    } else {
        $passed = false;
        fwrite(
            STDERR,
            "FAIL: Unable to verify test-key cleanup.\n"
        );
    }
}

if (!$passed) {
    exit(1);
}

echo "PASS: Redis concurrency test completed.\n";
