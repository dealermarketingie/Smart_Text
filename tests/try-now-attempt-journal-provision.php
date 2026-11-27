<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal provisioning tests.
 *
 * Uses one disposable private temporary directory.
 * No database, Redis, HTTP or SMS operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-provision.php';

function provisionCheck(
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

$createdPaths = [];
$failed = false;

try {
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException(
            'Cannot create disposable test directory.'
        );
    }

    @chmod($directory, 0700);

    $namespace = 'isolated-smarttext-test';
    $campaignId = 7907;
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);
    $attemptId = stTryNowNewJournalAttemptId();

    $slotPath = static function (
        int $customerId
    ) use (
        $directory,
        $namespace,
        $campaignId,
        $bindingKey
    ): string {
        $slotId = stTryNowJournalSlotId(
            $namespace,
            $campaignId,
            $customerId,
            $bindingKey
        );

        return $directory
            . DIRECTORY_SEPARATOR
            . stTryNowJournalSlotFilename(
                $slotId
            );
    };

    $provision = static function (
        int $customerId,
        string $id,
        bool $enabled = true,
        ?string $targetDirectory = null,
        ?string $overrideRecordKey = null
    ) use (
        $directory,
        $namespace,
        $campaignId,
        $bindingKey,
        $recordKey
    ): array {
        return stTryNowProvisionSyntheticJournalSlot(
            $targetDirectory ?? $directory,
            $namespace,
            $campaignId,
            $customerId,
            $id,
            $bindingKey,
            $overrideRecordKey ?? $recordKey,
            $enabled
        );
    };

    provisionCheck(
        'Production delivery remains disabled',
        stTryNowProductionDeliveryEnabled() === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false
    );

    $primaryPath = $slotPath(123);
    $createdPaths[] = $primaryPath;

    $disabled = $provision(
        123,
        $attemptId,
        false
    );

    provisionCheck(
        'Provisioning requires explicit CLI permission',
        $disabled['status'] === 'disabled' &&
        !file_exists($primaryPath)
    );

    $created = $provision(
        123,
        $attemptId
    );

    provisionCheck(
        'Exclusive journal creation succeeds',
        $created['status']
            === 'created_test_only' &&
        $created['file_synced'] === true &&
        is_file($primaryPath)
    );

    provisionCheck(
        'Creation never authorises transport',
        $created['directory_synced'] === false &&
        $created['safe_for_transport'] === false &&
        $created['automatic_send'] === false &&
        $created['automatic_retry'] === false &&
        $created['automatic_cleanup'] === false
    );

    $contents = file_get_contents(
        $primaryPath
    );

    $record = json_decode(
        trim($contents),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $verified = stTryNowVerifyJournalHistory(
        [$record],
        $recordKey
    );

    provisionCheck(
        'Initial prepared record has valid HMAC',
        $verified['valid'] === true &&
        $verified['attempt_state'] === 'unknown' &&
        $record['event'] === 'prepared' &&
        $record['sequence'] === 1 &&
        $record['attempt_id'] === $attemptId &&
        $record['customer_id'] === 123 &&
        $record['campaign_id'] === $campaignId &&
        substr($contents, -1) === "\n"
    );

    $secondAttemptId = stTryNowNewJournalAttemptId();

    $duplicate = $provision(
        123,
        $secondAttemptId
    );

    provisionCheck(
        'New attempt ID cannot replace existing journal',
        $duplicate['status'] === 'already_exists' &&
        file_get_contents($primaryPath)
            === $contents
    );

    /*
     * A different customer receives a different
     * deterministic journal filename.
     */
    $otherPath = $slotPath(124);
    $createdPaths[] = $otherPath;

    $other = $provision(
        124,
        stTryNowNewJournalAttemptId()
    );

    provisionCheck(
        'Different customer uses separate journal slot',
        $other['status'] === 'created_test_only' &&
        is_file($otherPath) &&
        $otherPath !== $primaryPath
    );

    /*
     * Pre-existing contents must never be erased.
     */
    $sentinelPath = $slotPath(125);
    $createdPaths[] = $sentinelPath;

    file_put_contents(
        $sentinelPath,
        'existing-journal-do-not-replace'
    );

    @chmod($sentinelPath, 0600);

    $sentinel = $provision(
        125,
        stTryNowNewJournalAttemptId()
    );

    provisionCheck(
        'Pre-existing journal cannot be overwritten',
        $sentinel['status'] === 'already_exists' &&
        file_get_contents($sentinelPath)
            === 'existing-journal-do-not-replace'
    );

    $outside = $provision(
        126,
        stTryNowNewJournalAttemptId(),
        true,
        sys_get_temp_dir()
    );

    provisionCheck(
        'Unrestricted temporary directory rejected',
        $outside['status'] === 'invalid_directory'
    );

    $badAttempt = $provision(
        127,
        'invalid-attempt-id'
    );

    provisionCheck(
        'Malformed attempt identifier rejected',
        $badAttempt['status'] === 'invalid_input' &&
        !file_exists($slotPath(127))
    );

    $reusedKey = $provision(
        128,
        stTryNowNewJournalAttemptId(),
        true,
        null,
        $bindingKey
    );

    provisionCheck(
        'Same key cannot authenticate records and slots',
        $reusedKey['status'] === 'invalid_input' &&
        !file_exists($slotPath(128))
    );

    if (DIRECTORY_SEPARATOR === '/') {
        $mode = fileperms($primaryPath);

        provisionCheck(
            'New journal is owner-only on Unix',
            ($mode & 0077) === 0
        );

        /*
         * An existing symlink at the intended
         * journal filename must never be followed.
         */
        $symlinkPath = $slotPath(129);
        $createdPaths[] = $symlinkPath;

        if (!symlink(
            $primaryPath,
            $symlinkPath
        )) {
            throw new RuntimeException(
                'Cannot create test symlink.'
            );
        }

        $linked = $provision(
            129,
            stTryNowNewJournalAttemptId()
        );

        provisionCheck(
            'Existing journal symlink rejected',
            $linked['status'] === 'already_exists' &&
            file_get_contents($primaryPath)
                === $contents
        );
    }

    echo "PASS: All journal provisioning tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL: " . $e->getMessage() . "\n"
    );

    $failed = true;

} finally {
    /*
     * Cleanup is confined to paths generated inside
     * this one disposable test directory.
     */
    foreach (array_reverse($createdPaths) as $path) {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

exit($failed ? 1 : 0);
