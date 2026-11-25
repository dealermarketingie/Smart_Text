<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal provisioning workflow.
 *
 * CLI TESTING ONLY.
 * Never authorises SMS transport.
 *
 * Combines:
 *  1. Exclusive journal creation
 *  2. Authenticated prepared record
 *  3. File fflush/fsync
 *  4. Directory fsync
 *  5. Independent journal verification
 *
 * No database, Redis, HTTP or SMS operations.
 */

require_once __DIR__
    . '/try-now-attempt-journal-provision.php';

require_once __DIR__
    . '/try-now-attempt-journal-dir-sync.php';

/**
 * Verify one freshly provisioned journal.
 *
 * This does not establish that earlier journal
 * histories were never deleted or replaced.
 */
function stTryNowVerifySyntheticPreparedJournal(
    string $path,
    string $attemptId,
    int $customerId,
    int $campaignId,
    string $recordKey
): bool {
    if (is_link($path) || !is_file($path)) {
        return false;
    }

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        return false;
    }

    $locked = false;

    try {
        if (!@flock($handle, LOCK_SH | LOCK_NB)) {
            return false;
        }

        $locked = true;

        $fileStat = @fstat($handle);
        $pathStat = @lstat($path);

        if (
            !is_array($fileStat) ||
            !is_array($pathStat) ||
            $fileStat['dev'] !== $pathStat['dev'] ||
            $fileStat['ino'] !== $pathStat['ino'] ||
            $fileStat['size'] < 1 ||
            $fileStat['size'] > 4096
        ) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '/') {
            if (
                ($fileStat['mode'] & 0170000)
                    !== 0100000 ||
                ($fileStat['mode'] & 0077) !== 0 ||
                $fileStat['nlink'] !== 1 ||
                (
                    function_exists('posix_geteuid') &&
                    $fileStat['uid'] !== posix_geteuid()
                )
            ) {
                return false;
            }
        }

        $contents = @stream_get_contents(
            $handle,
            4097
        );

        if (
            !is_string($contents) ||
            strlen($contents) !== $fileStat['size'] ||
            substr($contents, -1) !== "\n" ||
            substr_count($contents, "\n") !== 1
        ) {
            return false;
        }

        $line = substr($contents, 0, -1);

        $record = json_decode(
            $line,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($record)) {
            return false;
        }

        $canonical = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );

        if ($canonical !== $line) {
            return false;
        }

        $verified = stTryNowVerifyJournalHistory(
            [$record],
            $recordKey
        );

        return
            $verified['valid'] === true &&
            $verified['attempt_state'] === 'unknown' &&
            $record['event'] === 'prepared' &&
            $record['sequence'] === 1 &&
            $record['attempt_id'] === $attemptId &&
            $record['customer_id'] === $customerId &&
            $record['campaign_id'] === $campaignId;

    } catch (Throwable $e) {
        return false;

    } finally {
        if ($locked) {
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }
}

/**
 * Test-only provisioning sequence.
 *
 * Failure modes simulate interruption AFTER
 * successful file or directory synchronization.
 *
 * They do not simulate actual disk hardware faults.
 *
 * Every outcome remains unsafe for SMS transport.
 */
function stTryNowProvisionAndVerifySyntheticJournal(
    string $directory,
    string $namespace,
    int $campaignId,
    int $customerId,
    string $attemptId,
    string $bindingKey,
    string $recordKey,
    bool $allowCliTest = false,
    string $failureMode = 'none'
): array {
    $result = [
        'status' => 'disabled',
        'file_synced' => false,
        'directory_synced' => false,
        'record_verified' => false,
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

    if (!in_array(
        $failureMode,
        [
            'none',
            'after_file_sync',
            'after_directory_sync',
        ],
        true
    )) {
        $result['status'] = 'invalid_test_mode';
        return $result;
    }

    /*
     * Existing provisioning performs exclusive
     * creation, HMAC record construction, file
     * flushing and file fsync.
     */
    $created = stTryNowProvisionSyntheticJournalSlot(
        $directory,
        $namespace,
        $campaignId,
        $customerId,
        $attemptId,
        $bindingKey,
        $recordKey,
        true
    );

    if (
        $created['status'] !== 'created_test_only' ||
        $created['file_synced'] !== true
    ) {
        $result['status'] =
            'provision_' . $created['status'];

        return $result;
    }

    $result['file_synced'] = true;

    /*
     * Simulate interruption after file fsync,
     * before directory synchronization.
     *
     * Never delete the journal to "retry".
     */
    if ($failureMode === 'after_file_sync') {
        $result['status'] =
            'interrupted_after_file_sync';

        return $result;
    }

    $synced = stTryNowSyncSyntheticJournalDirectory(
        $directory,
        true
    );

    if (
        $synced['status']
            !== 'directory_synced_test_only' ||
        $synced['directory_synced'] !== true
    ) {
        $result['status'] =
            'directory_' . $synced['status'];

        return $result;
    }

    $result['directory_synced'] = true;

    /*
     * Simulate interruption before reopening
     * and verifying the prepared record.
     */
    if ($failureMode === 'after_directory_sync') {
        $result['status'] =
            'interrupted_after_directory_sync';

        return $result;
    }

    /*
     * Recalculate the deterministic filename.
     * Do not trust a path supplied by the caller.
     */
    try {
        $slotId = stTryNowJournalSlotId(
            $namespace,
            $campaignId,
            $customerId,
            $bindingKey
        );

        $filename = stTryNowJournalSlotFilename(
            $slotId
        );

        $resolvedDirectory = realpath($directory);

        if (
            $resolvedDirectory === false ||
            is_link($directory) ||
            dirname($resolvedDirectory)
                !== realpath(sys_get_temp_dir()) ||
            preg_match(
                '/\Asttj-[0-9a-f]{16}\z/D',
                basename($resolvedDirectory)
            ) !== 1
        ) {
            $result['status'] =
                'verification_failed';

            return $result;
        }

        $path = $resolvedDirectory
            . DIRECTORY_SEPARATOR
            . $filename;

        if (!stTryNowVerifySyntheticPreparedJournal(
            $path,
            $attemptId,
            $customerId,
            $campaignId,
            $recordKey
        )) {
            $result['status'] =
                'verification_failed';

            return $result;
        }

        $result['record_verified'] = true;
        $result['status'] = 'verified_test_only';

        return $result;

    } catch (Throwable $e) {
        $result['status'] = 'verification_failed';
        return $result;
    }
}
