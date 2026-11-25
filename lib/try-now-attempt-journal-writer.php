<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Append-only journal prototype.
 *
 * CLI TESTING ONLY.
 * Not connected to the public API or Sendmode.
 *
 * Requires an existing regular file.
 * Never truncates or repairs a damaged journal.
 *
 * PHP 8.1+ required for fsync().
 */

require_once __DIR__
    . '/try-now-attempt-journal-format.php';
require_once __DIR__ . '/try-now-runtime.php';

function stTryNowAppendSyntheticJournalEvent(
    string $path,
    string $attemptId,
    int $customerId,
    int $campaignId,
    string $event,
    string $hmacKey,
    bool $allowCliTest = false
): array {
    $result = [
        'status' => 'disabled',
        'durable' => false,
        'attempt_state' => 'unknown',
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
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED !== false
    ) {
        return $result;
    }

    $result['status'] = 'unavailable';

    // Never open or create a file without durable
    // synchronization capability.
    if (
        !function_exists('fsync') ||
        strlen($hmacKey) < 32 ||
        preg_match('/^[0-9a-f]{32}$/D', $attemptId) !== 1 ||
        $customerId <= 0 ||
        !is_file($path) ||
        is_link($path)
    ) {
        return $result;
    }

    // r+b requires an existing file.
    // Unlike c+b, it will not create one.
    $handle = @fopen($path, 'r+b');

    if ($handle === false) {
        return $result;
    }

    $locked = false;

    try {
        // Fail closed when another process owns
        // the advisory exclusive lock.
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            $result['status'] = 'busy';
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
            ($fileStat['mode'] & 0170000) !== 0100000 ||
            $fileStat['size'] > 4096
        ) {
            return $result;
        }

        // Unix test files must not be accessible
        // to the owner's group or other users.
        if (
            DIRECTORY_SEPARATOR === '/' &&
            ($fileStat['mode'] & 0077) !== 0
        ) {
            return $result;
        }

        if (@rewind($handle) !== true) {
            return $result;
        }

        $contents = stream_get_contents($handle, 4097);

        if (
            !is_string($contents) ||
            strlen($contents) !== $fileStat['size']
        ) {
            return $result;
        }

        $records = [];

        if ($contents !== '') {
            // A missing final newline could indicate
            // an interrupted or partial write.
            if (substr($contents, -1) !== "\n") {
                $result['status'] = 'corrupt';
                return $result;
            }

            $lines = explode(
                "\n",
                substr($contents, 0, -1)
            );

            if (count($lines) > 3) {
                $result['status'] = 'corrupt';
                return $result;
            }

            foreach ($lines as $line) {
                try {
                    $record = json_decode(
                        $line,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (Throwable $e) {
                    $result['status'] = 'corrupt';
                    return $result;
                }

                if (!is_array($record)) {
                    $result['status'] = 'corrupt';
                    return $result;
                }

                // Reject noncanonical serialization.
                $canonical = json_encode(
                    $record,
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE |
                    JSON_THROW_ON_ERROR
                );

                if ($canonical !== $line) {
                    $result['status'] = 'corrupt';
                    return $result;
                }

                $records[] = $record;
            }

            $verified = stTryNowVerifyJournalHistory(
                $records,
                $hmacKey
            );

            if (
                $verified['valid'] !== true ||
                $records[0]['attempt_id'] !== $attemptId ||
                $records[0]['customer_id'] !== $customerId ||
                $records[0]['campaign_id'] !== $campaignId
            ) {
                $result['status'] = 'corrupt';
                return $result;
            }

        } elseif ($event !== 'prepared') {
            // A new history must begin with preparation.
            $result['status'] = 'blocked';
            return $result;
        }

        // Validate the proposed event transition.
        $events = [];

        foreach ($records as $record) {
            $events[] = ['event' => $record['event']];
        }

        $events[] = ['event' => $event];

        $classification = stTryNowClassifyAttemptHistory(
            $events
        );

        if ($classification['sequence_valid'] !== true) {
            $result['status'] = 'blocked';
            return $result;
        }

        $sequence = count($records) + 1;

        $previousMac = $records === []
            ? str_repeat('0', 64)
            : $records[count($records) - 1]['mac'];

        $record = stTryNowBuildJournalRecord(
            $attemptId,
            $customerId,
            $campaignId,
            $sequence,
            $event,
            gmdate('Y-m-d\TH:i:s\Z'),
            $previousMac,
            $hmacKey
        );

        $line = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ) . "\n";

        if (strlen($contents) + strlen($line) > 4096) {
            $result['status'] = 'blocked';
            return $result;
        }

        if (@fseek($handle, 0, SEEK_END) !== 0) {
            return $result;
        }

        // Handle partial fwrite() results explicitly.
        $offset = 0;
        $length = strlen($line);

        while ($offset < $length) {
            $written = @fwrite(
                $handle,
                substr($line, $offset)
            );

            if ($written === false || $written === 0) {
                $result['status'] = 'write_failed';
                return $result;
            }

            $offset += $written;
        }

        // Do not return success unless both operations
        // confirm completion.
        if (
            !@fflush($handle) ||
            !@fsync($handle)
        ) {
            $result['status'] = 'durability_unknown';
            return $result;
        }

        $result['status'] = 'appended';
        $result['durable'] = true;
        $result['attempt_state'] =
            $classification['attempt_state'];

        return $result;

    } catch (Throwable $e) {
        // Never expose filesystem or HMAC internals.
        $result['status'] = 'unavailable';
        return $result;

    } finally {
        if ($locked) {
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }
}
