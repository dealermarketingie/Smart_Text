<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal writer tests.
 *
 * Disposable synthetic files only.
 * No database, Redis or Sendmode operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-writer.php';

function writerCheck(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

$paths = [];
$failed = false;

try {
    writerCheck(
        'Production delivery remains disabled',
        stTryNowProductionDeliveryEnabled() === false
    );

    $key = str_repeat('T', 32);
    $attemptId = stTryNowNewJournalAttemptId();

    $newFile = static function () use (&$paths): string {
        $path = tempnam(
            sys_get_temp_dir(),
            'sttj_'
        );

        if ($path === false) {
            throw new RuntimeException(
                'Unable to create disposable test file.'
            );
        }

        $paths[] = $path;
        @chmod($path, 0600);

        return $path;
    };

    $append = static function (
        string $path,
        string $event,
        ?string $id = null,
        bool $enabled = true
    ) use ($attemptId, $key): array {
        return stTryNowAppendSyntheticJournalEvent(
            $path,
            $id ?? $attemptId,
            123,
            7907,
            $event,
            $key,
            $enabled
        );
    };

    $path = $newFile();

    $disabled = $append(
        $path,
        'prepared',
        null,
        false
    );

    writerCheck(
        'Writer disabled without explicit authorisation',
        $disabled['status'] === 'disabled' &&
        filesize($path) === 0
    );

    $prepared = $append($path, 'prepared');

    writerCheck(
        'Prepared event durably appended',
        $prepared['status'] === 'appended' &&
        $prepared['durable'] === true &&
        $prepared['attempt_state'] === 'unknown'
    );

    $started = $append($path, 'transport_started');

    writerCheck(
        'Transport-start event durably appended',
        $started['status'] === 'appended' &&
        $started['durable'] === true &&
        $started['attempt_state'] === 'attempted'
    );

    $accepted = $append($path, 'provider_accepted');

    writerCheck(
        'Provider acceptance recorded without sending',
        $accepted['status'] === 'appended' &&
        $accepted['durable'] === true &&
        $accepted['attempt_state'] === 'attempted' &&
        $accepted['automatic_send'] === false
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

    writerCheck(
        'Persisted HMAC chain verifies',
        count($records) === 3 &&
        $verified['valid'] === true &&
        $verified['attempt_state'] === 'attempted' &&
        $verified['provider_evidence'] === 'accepted'
    );

    $before = file_get_contents($path);
    $repeat = $append($path, 'transport_started');

    writerCheck(
        'Completed journal cannot restart transport',
        $repeat['status'] === 'blocked' &&
        file_get_contents($path) === $before
    );

    $other = $newFile();
    $append($other, 'prepared');

    $wrongIdentity = $append(
        $other,
        'transport_started',
        stTryNowNewJournalAttemptId()
    );

    writerCheck(
        'Different attempt identity rejected',
        $wrongIdentity['status'] === 'corrupt'
    );

    $cancelledPath = $newFile();
    $append($cancelledPath, 'prepared');
    $cancelled = $append(
        $cancelledPath,
        'cancelled_before_transport'
    );
    $afterCancellation = $append(
        $cancelledPath,
        'transport_started'
    );

    writerCheck(
        'Cancelled attempt cannot start transport',
        $cancelled['status'] === 'appended' &&
        $cancelled['attempt_state']
            === 'confirmed_not_attempted' &&
        $afterCancellation['status'] === 'blocked'
    );

    $corruptPath = $newFile();
    $append($corruptPath, 'prepared');

    file_put_contents(
        $corruptPath,
        '{"incomplete":',
        FILE_APPEND
    );

    $corruptBefore = file_get_contents($corruptPath);
    $corrupt = $append(
        $corruptPath,
        'transport_started'
    );

    writerCheck(
        'Truncated journal fails closed',
        $corrupt['status'] === 'corrupt' &&
        file_get_contents($corruptPath)
            === $corruptBefore
    );

    $missing = $newFile();
    unlink($missing);

    $missingResult = $append(
        $missing,
        'prepared'
    );

    writerCheck(
        'Missing file cannot be silently recreated',
        $missingResult['status'] === 'unavailable' &&
        !file_exists($missing)
    );

    echo "PASS: All journal writer tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL: " . $e->getMessage() . "\n"
    );
    $failed = true;

} finally {
    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

exit($failed ? 1 : 0);
