<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal provisioning prototype.
 *
 * CLI TESTING ONLY.
 * No database, Redis, HTTP or SMS operations.
 *
 * Creates one private journal file exclusively and
 * writes its authenticated "prepared" record.
 *
 * IMPORTANT:
 * File fsync does not prove directory-entry durability.
 * This function NEVER authorises SMS transport.
 */

require_once __DIR__
    . '/try-now-attempt-journal-binding.php';
require_once __DIR__
    . '/try-now-attempt-journal-format.php';
require_once __DIR__
    . '/try-now-runtime.php';

function stTryNowProvisionSyntheticJournalSlot(
    string $directory,
    string $namespace,
    int $campaignId,
    int $customerId,
    string $attemptId,
    string $bindingKey,
    string $recordKey,
    bool $allowCliTest = false
): array {
    $result = [
        'status' => 'disabled',
        'file_synced' => false,
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

    $result['status'] = 'unavailable';

    if (
        !function_exists('fsync') ||
        strlen($bindingKey) < 32 ||
        strlen($recordKey) < 32 ||
        hash_equals($bindingKey, $recordKey) ||
        preg_match(
            '/\A[0-9a-f]{32}\z/D',
            $attemptId
        ) !== 1
    ) {
        $result['status'] = 'invalid_input';
        return $result;
    }

    try {
        $slotId = stTryNowJournalSlotId(
            $namespace,
            $campaignId,
            $customerId,
            $bindingKey
        );

        // Only synthetic campaigns accepted by the
        // existing journal-record format.
        if (!in_array(
            $campaignId,
            array_column(
                stTryNowSamples(),
                'campaign_id'
            ),
            true
        )) {
            $result['status'] = 'invalid_input';
            return $result;
        }

        $filename = stTryNowJournalSlotFilename(
            $slotId
        );

    } catch (Throwable $e) {
        $result['status'] = 'invalid_input';
        return $result;
    }

    /*
     * Restrict this prototype to a direct child of
     * the system temporary directory:
     *
     *   .../sttj-<16 hexadecimal characters>/
     *
     * Production directories are deliberately rejected.
     */
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
        ) !== 1 ||
        !is_writable($resolved)
    ) {
        $result['status'] = 'invalid_directory';
        return $result;
    }

    if (DIRECTORY_SEPARATOR === '/') {
        $directoryStat = @stat($resolved);

        if (
            !is_array($directoryStat) ||
            ($directoryStat['mode'] & 0077) !== 0 ||
            (
                function_exists('posix_geteuid') &&
                $directoryStat['uid'] !== posix_geteuid()
            )
        ) {
            $result['status'] = 'invalid_directory';
            return $result;
        }
    }

    $path = $resolved
        . DIRECTORY_SEPARATOR
        . $filename;

    /*
     * x+b means exclusive creation:
     *
     * - Fail if the filename already exists.
     * - Never truncate an existing journal.
     * - Never follow an existing symlink.
     *
     * umask(0077) restricts the newly created
     * file to owner-only access on Unix.
     */
    $oldUmask = umask(0077);

    try {
        $handle = @fopen($path, 'x+b');
    } finally {
        umask($oldUmask);
    }

    if ($handle === false) {
        clearstatcache(true, $path);

        $result['status'] =
            @lstat($path) !== false
                ? 'already_exists'
                : 'unavailable';

        return $result;
    }

    /*
     * The file now exists. Any subsequent failure
     * must leave it in place for manual investigation.
     */
    $result['status'] = 'creation_uncertain';
    $locked = false;

    try {
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            return $result;
        }

        $locked = true;

        $fileStat = @fstat($handle);
        $pathStat = @lstat($path);

        if (
            !is_array($fileStat) ||
            !is_array($pathStat) ||
            $fileStat['dev'] !== $pathStat['dev'] ||
            $fileStat['ino'] !== $pathStat['ino'] ||
            $fileStat['size'] !== 0
        ) {
            return $result;
        }

        if (DIRECTORY_SEPARATOR === '/') {
            if (
                ($fileStat['mode'] & 0170000)
                    !== 0100000 ||
                ($fileStat['mode'] & 0077) !== 0 ||
                $fileStat['nlink'] !== 1
            ) {
                return $result;
            }
        }

        $record = stTryNowBuildJournalRecord(
            $attemptId,
            $customerId,
            $campaignId,
            1,
            'prepared',
            gmdate('Y-m-d\TH:i:s\Z'),
            str_repeat('0', 64),
            $recordKey
        );

        $line = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ) . "\n";

        $length = strlen($line);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite(
                $handle,
                substr($line, $offset)
            );

            if (
                $written === false ||
                $written === 0
            ) {
                return $result;
            }

            $offset += $written;
        }

        if (
            !@fflush($handle) ||
            !@fsync($handle)
        ) {
            return $result;
        }

        /*
         * This confirms successful file synchronization,
         * NOT a crash-durable directory entry or an
         * independently sealed journal history.
         */
        $result['status'] = 'created_test_only';
        $result['file_synced'] = true;

        return $result;

    } catch (Throwable $e) {
        return $result;

    } finally {
        if ($locked) {
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }
}
