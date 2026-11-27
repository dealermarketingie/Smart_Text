<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal resilience tests.
 *
 * Disposable local files and synthetic events only.
 * Tests Linux cross-process locking where available.
 *
 * No MariaDB, Redis, Sendmode or real SMS operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/*
 * Child process: hold an exclusive file lock until
 * the parent process releases us through STDIN.
 */
if (($argv[1] ?? '') === '--hold-lock') {
    $path = $argv[2] ?? '';

    if (
        count($argv) !== 3 ||
        !is_file($path) ||
        is_link($path)
    ) {
        exit(2);
    }

    $handle = @fopen($path, 'r+b');

    if (
        $handle === false ||
        !@flock($handle, LOCK_EX | LOCK_NB)
    ) {
        exit(3);
    }

    echo "LOCKED\n";
    fflush(STDOUT);

    fgets(STDIN);

    @flock($handle, LOCK_UN);
    fclose($handle);
    exit(0);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-writer.php';

function resilienceCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

$files = [];
$process = null;
$pipes = [];
$failed = false;

try {
    resilienceCheck(
        'Production operations remain disabled',
        stTryNowProductionDeliveryEnabled() === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false
    );

    $key = str_repeat('R', 32);
    $attemptId = stTryNowNewJournalAttemptId();

    $newFile = static function () use (&$files): string {
        $path = tempnam(
            sys_get_temp_dir(),
            'sttj_'
        );

        if ($path === false || is_link($path)) {
            throw new RuntimeException(
                'Unable to create test journal.'
            );
        }

        $files[] = $path;
        @chmod($path, 0600);

        return $path;
    };

    $append = static function (
        string $path,
        string $event
    ) use ($attemptId, $key): array {
        return stTryNowAppendSyntheticJournalEvent(
            $path,
            $attemptId,
            123,
            7907,
            $event,
            $key,
            true
        );
    };

    /*
     * Test 1: Separate file handles must respect
     * the existing exclusive lock.
     */
    $path = $newFile();

    $lockHandle = fopen($path, 'r+b');

    if (
        $lockHandle === false ||
        !flock($lockHandle, LOCK_EX | LOCK_NB)
    ) {
        throw new RuntimeException(
            'Unable to acquire test lock.'
        );
    }

    try {
        $busy = $append($path, 'prepared');
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    resilienceCheck(
        'Exclusive lock blocks concurrent append',
        $busy['status'] === 'busy' &&
        file_get_contents($path) === ''
    );

    /*
     * Test 2: On Linux, verify locking between
     * genuinely separate PHP processes.
     */
    if (PHP_OS_FAMILY === 'Linux') {
        if (!function_exists('proc_open')) {
            throw new RuntimeException(
                'Cross-process test requires proc_open.'
            );
        }

        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--hold-lock',
                $path,
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Unable to start lock-holder process.'
            );
        }

        stream_set_timeout($pipes[1], 5);

        $ready = fgets($pipes[1]);

        if ($ready !== "LOCKED\n") {
            throw new RuntimeException(
                'Child process did not acquire lock.'
            );
        }

        $busy = $append($path, 'prepared');

        $unchanged = file_get_contents($path) === '';

        fwrite($pipes[0], "RELEASE\n");
        fclose($pipes[0]);
        unset($pipes[0]);

        fclose($pipes[1]);
        unset($pipes[1]);

        $childError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        unset($pipes[2]);

        $exitCode = proc_close($process);
        $process = null;

        resilienceCheck(
            'Separate Linux process blocks concurrent append',
            $busy['status'] === 'busy' &&
            $unchanged &&
            $exitCode === 0 &&
            $childError === ''
        );

    } else {
        echo "NOTE: Cross-process test reserved for Linux.\n";
    }

    /*
     * Test 3: A released lock permits normal
     * append operations again.
     */
    $prepared = $append($path, 'prepared');

    resilienceCheck(
        'Append succeeds after lock release',
        $prepared['status'] === 'appended' &&
        $prepared['durable'] === true
    );

    /*
     * Test 4: Simulate a process disappearing
     * after transport_started was recorded.
     *
     * We deliberately omit any provider response.
     */
    $started = $append(
        $path,
        'transport_started'
    );

    $contents = file_get_contents($path);

    $lines = explode(
        "\n",
        substr($contents, 0, -1)
    );

    $records = array_map(
        static function (string $line): array {
            return json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        },
        $lines
    );

    $verified = stTryNowVerifyJournalHistory(
        $records,
        $key
    );

    resilienceCheck(
        'Missing provider response preserves attempted state',
        $started['status'] === 'appended' &&
        $verified['valid'] === true &&
        $verified['attempt_state'] === 'attempted' &&
        $verified['provider_evidence'] === null &&
        $verified['automatic_send'] === false &&
        $verified['automatic_retry'] === false
    );

    /*
     * Test 5: A subsequent execution must not
     * restart the same transport operation.
     */
    $before = file_get_contents($path);

    $restart = $append(
        $path,
        'transport_started'
    );

    resilienceCheck(
        'Interrupted attempt cannot restart transport',
        $restart['status'] === 'blocked' &&
        file_get_contents($path) === $before
    );

    /*
     * Test 6: Simulate an interrupted append
     * that did not write its final newline.
     */
    $partialPath = $newFile();

    $append($partialPath, 'prepared');

    file_put_contents(
        $partialPath,
        '{"incomplete":',
        FILE_APPEND
    );

    $partialBefore = file_get_contents(
        $partialPath
    );

    $partial = $append(
        $partialPath,
        'transport_started'
    );

    resilienceCheck(
        'Partial journal record blocks further writes',
        $partial['status'] === 'corrupt' &&
        file_get_contents($partialPath)
            === $partialBefore
    );

    /*
     * Test 7: A complete newline does not make
     * malformed JSON into valid journal evidence.
     */
    $invalidPath = $newFile();

    $append($invalidPath, 'prepared');

    file_put_contents(
        $invalidPath,
        "{\"invalid\":true}\n",
        FILE_APPEND
    );

    $invalidBefore = file_get_contents(
        $invalidPath
    );

    $invalid = $append(
        $invalidPath,
        'transport_started'
    );

    resilienceCheck(
        'Malformed complete record blocks further writes',
        $invalid['status'] === 'corrupt' &&
        file_get_contents($invalidPath)
            === $invalidBefore
    );

    /*
     * Linux-specific file permission protection.
     */
    if (PHP_OS_FAMILY === 'Linux') {
        $insecurePath = $newFile();

        chmod($insecurePath, 0644);
        clearstatcache(true, $insecurePath);

        $insecure = $append(
            $insecurePath,
            'prepared'
        );

        chmod($insecurePath, 0600);

        resilienceCheck(
            'Group or world readable journal rejected',
            $insecure['status'] === 'unavailable' &&
            file_get_contents($insecurePath) === ''
        );

        /*
         * Test that the writer never follows
         * a journal path implemented as a symlink.
         */
        $target = $newFile();
        $link = $target . '.link';

        if (
            file_exists($link) ||
            is_link($link) ||
            !symlink($target, $link)
        ) {
            throw new RuntimeException(
                'Unable to create test symlink.'
            );
        }

        $files[] = $link;

        $symlinkResult = $append(
            $link,
            'prepared'
        );

        resilienceCheck(
            'Symlink journal path rejected',
            $symlinkResult['status'] === 'unavailable' &&
            file_get_contents($target) === ''
        );
    }

    echo "PASS: All journal resilience tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL: " . $e->getMessage() . "\n"
    );
    $failed = true;

} finally {
    // Release any child process still running
    // if a test exited unexpectedly.
    if (is_resource($process)) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        @proc_terminate($process);
        @proc_close($process);
    }

    foreach ($files as $path) {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
    }
}

exit($failed ? 1 : 0);
