<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Directory synchronization prototype.
 *
 * CLI TESTING ONLY.
 * Linux only.
 *
 * No database, Redis, HTTP or SMS operations.
 *
 * Only accepts an existing private test directory:
 *   <system-temp>/sttj-<16 hexadecimal characters>
 *
 * Never authorises SMS transport.
 */

require_once __DIR__ . '/try-now-runtime.php';

function stTryNowSyncSyntheticJournalDirectory(
    string $directory,
    bool $allowCliTest = false
): array {
    $result = [
        'status' => 'disabled',
        'directory_synced' => false,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    if (
        PHP_SAPI !== 'cli' ||
        !$allowCliTest ||
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED !== false ||
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED !== false ||
        ST_TRY_NOW_SMS_COPY_APPROVED !== false ||
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED !== false ||
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED !== false ||
        stTryNowProductionDeliveryEnabled() !== false
    ) {
        return $result;
    }

    if (
        PHP_OS_FAMILY !== 'Linux' ||
        !function_exists('proc_open')
    ) {
        $result['status'] = 'unsupported_platform';
        return $result;
    }

    $result['status'] = 'invalid_directory';

    $tempRoot = realpath(sys_get_temp_dir());
    $resolved = realpath($directory);

    if (
        $tempRoot === false ||
        $resolved === false ||
        !is_dir($directory) ||
        is_link($directory) ||
        dirname($resolved) !== $tempRoot ||
        preg_match(
            '/\Asttj-[0-9a-f]{16}\z/D',
            basename($resolved)
        ) !== 1
    ) {
        return $result;
    }

    $directoryStat = @stat($resolved);

    if (
        !is_array($directoryStat) ||
        ($directoryStat['mode'] & 0170000) !== 0040000 ||
        ($directoryStat['mode'] & 0077) !== 0 ||
        $directoryStat['uid'] !== posix_geteuid() ||
        !is_writable($resolved)
    ) {
        return $result;
    }

    $result['status'] = 'sync_unavailable';

    // Fixed executable verified on the Plesk server.
    $pythonBinary = '/usr/bin/python3';

    if (
        !is_file($pythonBinary) ||
        !is_executable($pythonBinary)
    ) {
        return $result;
    }

    /*
     * Pass the directory as an argument, not
     * interpolated into Python or a shell command.
     *
     * Linux O_DIRECTORY and O_NOFOLLOW provide
     * additional validation at open time.
     */
    $pythonCode = <<<'PY'
import os
import stat
import sys

directory = sys.argv[1]

try:
    flags = (
        os.O_RDONLY |
        os.O_DIRECTORY |
        os.O_NOFOLLOW
    )

    fd = os.open(directory, flags)

    try:
        info = os.fstat(fd)

        if not stat.S_ISDIR(info.st_mode):
            sys.exit(2)

        if info.st_mode & 0o077:
            sys.exit(2)

        if info.st_uid != os.geteuid():
            sys.exit(2)

        os.fsync(fd)

    finally:
        os.close(fd)

except (OSError, ValueError):
    sys.exit(3)

sys.stdout.write("SYNCED\n")
PY;

    $pipes = [];

    try {
        // Array command: no shell interpolation.
        $process = @proc_open(
            [
                $pythonBinary,
                '-c',
                $pythonCode,
                $resolved,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return $result;
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if (
            $exitCode !== 0 ||
            $output !== "SYNCED\n" ||
            $error !== ''
        ) {
            $result['status'] = 'sync_failed';
            return $result;
        }

        $result['status'] =
            'directory_synced_test_only';

        $result['directory_synced'] = true;

        return $result;

    } catch (Throwable $e) {
        // Fail closed without revealing internal paths.
        $result['status'] = 'sync_failed';
        return $result;
    }
}
