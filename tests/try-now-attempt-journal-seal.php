<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal head seal tests.
 *
 * Synthetic authenticated records only.
 * No persistent files or external services.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-seal.php';

require_once __DIR__
    . '/../lib/try-now-runtime.php';

function sealCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

function sealRejects(callable $operation): bool
{
    try {
        $operation();
    } catch (InvalidArgumentException $e) {
        return true;
    }

    return false;
}

sealCheck(
    'Production delivery remains disabled',
    ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
    ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
    ST_TRY_NOW_SMS_COPY_APPROVED === false &&
    ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
    ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
    stTryNowProductionDeliveryEnabled() === false
);

// Disposable keys. Never use in production.
$recordKey = str_repeat('J', 32);
$sealKey = str_repeat('S', 32);
$wrongKey = str_repeat('X', 32);

$attemptId = stTryNowNewJournalAttemptId();
$otherAttemptId = stTryNowNewJournalAttemptId();

$timestamp = '2026-11-25T10:00:00Z';
$genesis = str_repeat('0', 64);

$make = static function (
    int $sequence,
    string $event,
    string $previousMac,
    ?string $id = null
) use (
    $attemptId,
    $timestamp,
    $recordKey
): array {
    return stTryNowBuildJournalRecord(
        $id ?? $attemptId,
        123,
        7907,
        $sequence,
        $event,
        $timestamp,
        $previousMac,
        $recordKey
    );
};

$prepared = $make(
    1,
    'prepared',
    $genesis
);

$started = $make(
    2,
    'transport_started',
    $prepared['mac']
);

$accepted = $make(
    3,
    'provider_accepted',
    $started['mac']
);

$cancelled = $make(
    2,
    'cancelled_before_transport',
    $prepared['mac']
);

$preparedHistory = [$prepared];
$startedHistory = [$prepared, $started];
$acceptedHistory = [$prepared, $started, $accepted];
$cancelledHistory = [$prepared, $cancelled];

$preparedSeal = stTryNowBuildJournalHeadSeal(
    $preparedHistory,
    $recordKey,
    $sealKey
);

$startedSeal = stTryNowBuildJournalHeadSeal(
    $startedHistory,
    $recordKey,
    $sealKey
);

$acceptedSeal = stTryNowBuildJournalHeadSeal(
    $acceptedHistory,
    $recordKey,
    $sealKey
);

$cancelledSeal = stTryNowBuildJournalHeadSeal(
    $cancelledHistory,
    $recordKey,
    $sealKey
);

sealCheck(
    'Checkpoint contains canonical journal identity',
    $startedSeal['version'] === 1 &&
    $startedSeal['attempt_id'] === $attemptId &&
    $startedSeal['customer_id'] === 123 &&
    $startedSeal['campaign_id'] === 7907 &&
    $startedSeal['record_count'] === 2 &&
    $startedSeal['head_event'] === 'transport_started' &&
    $startedSeal['head_mac'] === $started['mac']
);

sealCheck(
    'Checkpoint excludes direct contact details',
    !array_key_exists('mobile', $startedSeal) &&
    !array_key_exists('email', $startedSeal) &&
    !array_key_exists('sms_body', $startedSeal) &&
    !array_key_exists('link', $startedSeal)
);

$verify = static function (
    array $records,
    ?array $seal,
    ?string $key = null
) use (
    $recordKey,
    $sealKey
): array {
    return stTryNowVerifyJournalHeadSeal(
        $records,
        $seal,
        $recordKey,
        $key ?? $sealKey
    );
};

$preparedResult = $verify(
    $preparedHistory,
    $preparedSeal
);

sealCheck(
    'Prepared journal checkpoint verifies',
    $preparedResult['valid'] === true &&
    $preparedResult['attempt_state'] === 'unknown'
);

$startedResult = $verify(
    $startedHistory,
    $startedSeal
);

sealCheck(
    'Transport-start checkpoint verifies',
    $startedResult['valid'] === true &&
    $startedResult['attempt_state'] === 'attempted'
);

$acceptedResult = $verify(
    $acceptedHistory,
    $acceptedSeal
);

sealCheck(
    'Provider acceptance checkpoint verifies',
    $acceptedResult['valid'] === true &&
    $acceptedResult['attempt_state'] === 'attempted' &&
    $acceptedResult['provider_evidence'] === 'accepted'
);

$cancelledResult = $verify(
    $cancelledHistory,
    $cancelledSeal
);

sealCheck(
    'Pre-transport cancellation checkpoint verifies',
    $cancelledResult['valid'] === true &&
    $cancelledResult['attempt_state']
        === 'confirmed_not_attempted'
);

/*
 * Critical regression:
 *
 * The remaining prepared record is authentic,
 * but the independently supplied checkpoint
 * still identifies transport_started.
 */
sealCheck(
    'Deleted transport-start record is detected',
    $verify(
        $preparedHistory,
        $startedSeal
    )['valid'] === false
);

sealCheck(
    'Deleted provider-result record is detected',
    $verify(
        $startedHistory,
        $acceptedSeal
    )['valid'] === false
);

sealCheck(
    'Stale checkpoint rejects longer history',
    $verify(
        $startedHistory,
        $preparedSeal
    )['valid'] === false
);

$missing = $verify(
    $startedHistory,
    null
);

sealCheck(
    'Missing independent checkpoint fails closed',
    $missing['valid'] === false &&
    $missing['status'] === 'missing_seal'
);

sealCheck(
    'Wrong checkpoint key rejected',
    $verify(
        $startedHistory,
        $startedSeal,
        $wrongKey
    )['valid'] === false
);

$tamperedSeal = $startedSeal;
$tamperedSeal['record_count'] = 3;

sealCheck(
    'Modified checkpoint rejected',
    $verify(
        $startedHistory,
        $tamperedSeal
    )['valid'] === false
);

$tamperedRecord = $started;
$tamperedRecord['event'] = 'provider_accepted';

sealCheck(
    'Modified journal record rejected',
    $verify(
        [$prepared, $tamperedRecord],
        $startedSeal
    )['valid'] === false
);

$otherPrepared = $make(
    1,
    'prepared',
    $genesis,
    $otherAttemptId
);

$otherSeal = stTryNowBuildJournalHeadSeal(
    [$otherPrepared],
    $recordKey,
    $sealKey
);

sealCheck(
    'Checkpoint from another attempt rejected',
    $verify(
        $preparedHistory,
        $otherSeal
    )['valid'] === false
);

sealCheck(
    'Missing journal fails closed',
    $verify(
        [],
        $startedSeal
    )['valid'] === false
);

sealCheck(
    'Record and checkpoint keys must be distinct',
    sealRejects(
        static function () use (
            $preparedHistory,
            $recordKey
        ): void {
            stTryNowBuildJournalHeadSeal(
                $preparedHistory,
                $recordKey,
                $recordKey
            );
        }
    )
);

sealCheck(
    'Invalid journal cannot be checkpointed',
    sealRejects(
        static function () use (
            $recordKey,
            $sealKey
        ): void {
            stTryNowBuildJournalHeadSeal(
                [],
                $recordKey,
                $sealKey
            );
        }
    )
);

sealCheck(
    'Verified checkpoint never authorises SMS',
    $startedResult['safe_for_transport'] === false &&
    $startedResult['automatic_send'] === false &&
    $startedResult['automatic_retry'] === false &&
    $startedResult['automatic_cleanup'] === false
);

echo "PASS: All journal checkpoint tests.\n";
