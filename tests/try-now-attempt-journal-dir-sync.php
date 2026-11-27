<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Directory durability tests.
 *
 * Disposable filesystem objects only.
 * No database, Redis, HTTP or SMS operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-dir-sync.php';

function dirSyncCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

$directory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'sttj-'
    . bin2hex(random_bytes(8));

$marker = $directory
    . DIRECTORY_SEPARATOR
    . 'synthetic-marker';

$link = $directory . '.link';
$failed = false;

try {
    if (!@mkdir($directory, 0700)) {
        throw new RuntimeException(
            'Unable to create private test directory.'
        );
    }

    @chmod($directory, 0700);

    dirSyncCheck(
        'Production delivery remains disabled',
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false
    );

    $disabled = stTryNowSyncSyntheticJournalDirectory(
        $directory,
        false
    );

    dirSyncCheck(
        'Explicit CLI authorisation required',
        $disabled['status'] === 'disabled' &&
        $disabled['directory_synced'] === false &&
        $disabled['safe_for_transport'] === false
    );

    if (PHP_OS_FAMILY === 'Linux') {
        /*
         * Write and synchronize one synthetic file
         * before testing the directory synchronization.
         */
        $handle = @fopen($marker, 'x+b');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create synthetic marker.'
            );
        }

        try {
            if (
                fwrite($handle, 'synthetic-test-only')
                    !== strlen('synthetic-test-only') ||
                !fflush($handle) ||
                !fsync($handle)
            ) {
                throw new RuntimeException(
                    'Synthetic file synchronization failed.'
                );
            }
        } finally {
            fclose($handle);
        }

        $synced = stTryNowSyncSyntheticJournalDirectory(
            $directory,
            true
        );

        dirSyncCheck(
            'Linux directory synchronization succeeds',
            $synced['status']
                === 'directory_synced_test_only' &&
            $synced['directory_synced'] === true
        );

        dirSyncCheck(
            'Synchronization never authorises SMS',
            $synced['safe_for_transport'] === false &&
            $synced['automatic_send'] === false &&
            $synced['automatic_retry'] === false &&
            $synced['automatic_cleanup'] === false
        );

        $unrestricted = stTryNowSyncSyntheticJournalDirectory(
            sys_get_temp_dir(),
            true
        );

        dirSyncCheck(
            'Unrestricted temporary directory rejected',
            $unrestricted['status']
                === 'invalid_directory'
        );

        chmod($directory, 0755);
        clearstatcache(true, $directory);

        try {
            $insecure = stTryNowSyncSyntheticJournalDirectory(
                $directory,
                true
            );
        } finally {
            chmod($directory, 0700);
            clearstatcache(true, $directory);
        }

        dirSyncCheck(
            'Insecure directory permissions rejected',
            $insecure['status']
                === 'invalid_directory'
        );

        if (!@symlink($directory, $link)) {
            throw new RuntimeException(
                'Unable to create synthetic symlink.'
            );
        }

        $linked = stTryNowSyncSyntheticJournalDirectory(
            $link,
            true
        );

        dirSyncCheck(
            'Symlink directory rejected',
            $linked['status']
                === 'invalid_directory'
        );

    } else {
        $unsupported = stTryNowSyncSyntheticJournalDirectory(
            $directory,
            true
        );

        dirSyncCheck(
            'Non-Linux synchronization remains unavailable',
            $unsupported['status']
                === 'unsupported_platform' &&
            $unsupported['directory_synced'] === false &&
            $unsupported['safe_for_transport'] === false
        );

        echo "NOTE: Linux directory tests reserved for Plesk.\n";
    }

    echo "PASS: All directory synchronization tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL: " . $e->getMessage() . "\n"
    );
    $failed = true;

} finally {
    if (is_link($link)) {
        @unlink($link);
    }

    if (is_file($marker)) {
        @unlink($marker);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

exit($failed ? 1 : 0);
