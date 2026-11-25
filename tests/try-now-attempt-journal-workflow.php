<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Provisioning workflow tests.
 *
 * Disposable synthetic filesystem objects only.
 * No database, Redis or Sendmode operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-workflow.php';

function workflowCheck(
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

$paths = [];
$failed = false;

try {
    if (!@mkdir($directory, 0700)) {
        throw new RuntimeException(
            'Cannot create test directory.'
        );
    }

    @chmod($directory, 0700);

    $namespace = 'isolated-smarttext-test';
    $bindingKey = str_repeat('B', 32);
    $recordKey = str_repeat('R', 32);

    $slotPath = static function (
        int $customerId
    ) use (
        $directory,
        $namespace,
        $bindingKey
    ): string {
        return $directory
            . DIRECTORY_SEPARATOR
            . stTryNowJournalSlotFilename(
                stTryNowJournalSlotId(
                    $namespace,
                    7907,
                    $customerId,
                    $bindingKey
                )
            );
    };

    $run = static function (
        int $customerId,
        string $attemptId,
        bool $enabled = true,
        string $failureMode = 'none'
    ) use (
        $directory,
        $namespace,
        $bindingKey,
        $recordKey
    ): array {
        return stTryNowProvisionAndVerifySyntheticJournal(
            $directory,
            $namespace,
            7907,
            $customerId,
            $attemptId,
            $bindingKey,
            $recordKey,
            $enabled,
            $failureMode
        );
    };

    workflowCheck(
        'Production delivery remains disabled',
        stTryNowProductionDeliveryEnabled() === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false
    );

    $attemptId = stTryNowNewJournalAttemptId();

    $primaryPath = $slotPath(123);
    $paths[] = $primaryPath;

    $disabled = $run(
        123,
        $attemptId,
        false
    );

    workflowCheck(
        'Workflow requires explicit CLI authorisation',
        $disabled['status'] === 'disabled' &&
        !file_exists($primaryPath)
    );

    $created = $run(123, $attemptId);

    if (PHP_OS_FAMILY === 'Linux') {
        workflowCheck(
            'Linux workflow synchronizes and verifies journal',
            $created['status']
                === 'verified_test_only' &&
            $created['file_synced'] === true &&
            $created['directory_synced'] === true &&
            $created['record_verified'] === true
        );
    } else {
        workflowCheck(
            'Unsupported directory sync fails closed',
            $created['status']
                === 'directory_unsupported_platform' &&
            $created['file_synced'] === true &&
            $created['directory_synced'] === false &&
            $created['record_verified'] === false
        );
    }

    workflowCheck(
        'Combined workflow never authorises SMS',
        $created['safe_for_transport'] === false &&
        $created['automatic_send'] === false &&
        $created['automatic_retry'] === false &&
        $created['automatic_cleanup'] === false
    );

    $original = file_get_contents(
        $primaryPath
    );

    workflowCheck(
        'Prepared journal remains authenticated',
        stTryNowVerifySyntheticPreparedJournal(
            $primaryPath,
            $attemptId,
            123,
            7907,
            $recordKey
        )
    );

    $duplicate = $run(
        123,
        stTryNowNewJournalAttemptId()
    );

    workflowCheck(
        'Second attempt cannot recreate journal',
        $duplicate['status']
            === 'provision_already_exists' &&
        file_get_contents($primaryPath)
            === $original &&
        $duplicate['safe_for_transport'] === false
    );

    /*
     * Simulate interruption after the prepared
     * record was synchronized but before the
     * containing directory was synchronized.
     */
    $interruptedPath = $slotPath(124);
    $paths[] = $interruptedPath;

    $interrupted = $run(
        124,
        stTryNowNewJournalAttemptId(),
        true,
        'after_file_sync'
    );

    workflowCheck(
        'Interruption after file sync fails closed',
        $interrupted['status']
            === 'interrupted_after_file_sync' &&
        $interrupted['file_synced'] === true &&
        $interrupted['directory_synced'] === false &&
        $interrupted['record_verified'] === false &&
        $interrupted['safe_for_transport'] === false &&
        is_file($interruptedPath)
    );

    $secondTry = $run(
        124,
        stTryNowNewJournalAttemptId()
    );

    workflowCheck(
        'Interrupted journal cannot be replaced',
        $secondTry['status']
            === 'provision_already_exists' &&
        is_file($interruptedPath) &&
        $secondTry['safe_for_transport'] === false
    );

    if (PHP_OS_FAMILY === 'Linux') {
        $unverifiedPath = $slotPath(125);
        $paths[] = $unverifiedPath;

        $unverified = $run(
            125,
            stTryNowNewJournalAttemptId(),
            true,
            'after_directory_sync'
        );

        workflowCheck(
            'Interruption after directory sync fails closed',
            $unverified['status']
                === 'interrupted_after_directory_sync' &&
            $unverified['file_synced'] === true &&
            $unverified['directory_synced'] === true &&
            $unverified['record_verified'] === false &&
            $unverified['safe_for_transport'] === false &&
            is_file($unverifiedPath)
        );
    }

    $invalid = $run(
        126,
        stTryNowNewJournalAttemptId(),
        true,
        'invalid-failure-mode'
    );

    workflowCheck(
        'Invalid test failure mode creates nothing',
        $invalid['status']
            === 'invalid_test_mode' &&
        !file_exists($slotPath(126)) &&
        $invalid['safe_for_transport'] === false
    );

    echo "PASS: All journal workflow tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL: " . $e->getMessage() . "\n"
    );

    $failed = true;

} finally {
    foreach ($paths as $path) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

exit($failed ? 1 : 0);
