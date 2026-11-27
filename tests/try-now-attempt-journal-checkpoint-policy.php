<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Checkpoint progression tests.
 *
 * Synthetic authenticated records only.
 * No persistent files, database, Redis or SMS.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-checkpoint-policy.php';

require_once __DIR__
    . '/../lib/try-now-runtime.php';

function checkpointPolicyCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

checkpointPolicyCheck(
    'Production delivery remains disabled',
    ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
    ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
    ST_TRY_NOW_SMS_COPY_APPROVED === false &&
    ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
    ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
    stTryNowProductionDeliveryEnabled() === false
);

// Synthetic keys only.
$recordKey = str_repeat('R', 32);
$sealKey = str_repeat('S', 32);
$attemptId = stTryNowNewJournalAttemptId();
$timestamp = '2026-11-25T10:00:00Z';

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
    str_repeat('0', 64)
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

$rejected = $make(
    3,
    'provider_rejected',
    $started['mac']
);

$unknown = $make(
    3,
    'provider_unknown',
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
$rejectedHistory = [$prepared, $started, $rejected];
$unknownHistory = [$prepared, $started, $unknown];
$cancelledHistory = [$prepared, $cancelled];

$seal = static function (
    array $records
) use ($recordKey, $sealKey): array {
    return stTryNowBuildJournalHeadSeal(
        $records,
        $recordKey,
        $sealKey
    );
};

$preparedSeal = $seal($preparedHistory);
$startedSeal = $seal($startedHistory);
$acceptedSeal = $seal($acceptedHistory);

$plan = static function (
    array $records,
    ?array $previousSeal,
    array $newSeal,
    ?string $overrideRecordKey = null,
    ?string $overrideSealKey = null
) use (
    $recordKey,
    $sealKey
): array {
    return stTryNowCheckpointAdvancementPlan(
        $records,
        $previousSeal,
        $newSeal,
        $overrideRecordKey ?? $recordKey,
        $overrideSealKey ?? $sealKey
    );
};

$assertCandidate = static function (
    string $name,
    array $result
): void {
    checkpointPolicyCheck(
        $name,
        $result['status'] ===
            'candidate_for_atomic_cas_test_only' &&
        $result['checkpoint_update_candidate'] === true &&
        $result['requires_authoritative_cas'] === true &&
        $result['safe_for_transport'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false
    );
};

$assertBlocked = static function (
    string $name,
    array $result
): void {
    checkpointPolicyCheck(
        $name,
        $result['checkpoint_update_candidate'] === false &&
        $result['requires_authoritative_cas'] === true &&
        $result['safe_for_transport'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false
    );
};

$assertCandidate(
    'Prepared to transport-start progression accepted',
    $plan(
        $startedHistory,
        $preparedSeal,
        $startedSeal
    )
);

$assertCandidate(
    'Transport-start to provider acceptance accepted',
    $plan(
        $acceptedHistory,
        $startedSeal,
        $acceptedSeal
    )
);

$assertCandidate(
    'Transport-start to provider rejection accepted',
    $plan(
        $rejectedHistory,
        $startedSeal,
        $seal($rejectedHistory)
    )
);

$assertCandidate(
    'Transport-start to unknown provider result accepted',
    $plan(
        $unknownHistory,
        $startedSeal,
        $seal($unknownHistory)
    )
);

$assertCandidate(
    'Prepared to cancellation progression accepted',
    $plan(
        $cancelledHistory,
        $preparedSeal,
        $seal($cancelledHistory)
    )
);

$missing = $plan(
    $startedHistory,
    null,
    $startedSeal
);

checkpointPolicyCheck(
    'Missing persisted checkpoint requires review',
    $missing['status'] ===
        'missing_persisted_checkpoint' &&
    $missing['checkpoint_update_candidate'] === false
);

$assertBlocked(
    'Duplicate checkpoint advancement rejected',
    $plan(
        $startedHistory,
        $startedSeal,
        $startedSeal
    )
);

$assertBlocked(
    'Checkpoint rollback rejected',
    $plan(
        $startedHistory,
        $acceptedSeal,
        $startedSeal
    )
);

$assertBlocked(
    'Skipped checkpoint advancement rejected',
    $plan(
        $acceptedHistory,
        $preparedSeal,
        $acceptedSeal
    )
);

$assertBlocked(
    'Prepared-only history cannot advance itself',
    $plan(
        $preparedHistory,
        $preparedSeal,
        $preparedSeal
    )
);

$alteredPrevious = $preparedSeal;
$alteredPrevious['seal_mac'] = str_repeat('0', 64);

$assertBlocked(
    'Modified persisted checkpoint rejected',
    $plan(
        $startedHistory,
        $alteredPrevious,
        $startedSeal
    )
);

$alteredCandidate = $startedSeal;
$alteredCandidate['head_mac'] = str_repeat('0', 64);

$assertBlocked(
    'Modified candidate checkpoint rejected',
    $plan(
        $startedHistory,
        $preparedSeal,
        $alteredCandidate
    )
);

$otherPrepared = $make(
    1,
    'prepared',
    str_repeat('0', 64),
    stTryNowNewJournalAttemptId()
);

$assertBlocked(
    'Checkpoint from another attempt rejected',
    $plan(
        $startedHistory,
        $seal([$otherPrepared]),
        $startedSeal
    )
);

$modifiedStarted = $started;
$modifiedStarted['event'] = 'provider_accepted';

$assertBlocked(
    'Tampered journal history rejected',
    $plan(
        [$prepared, $modifiedStarted],
        $preparedSeal,
        $startedSeal
    )
);

$assertBlocked(
    'Missing transport-start record rejected',
    $plan(
        $preparedHistory,
        $preparedSeal,
        $startedSeal
    )
);

$assertBlocked(
    'Incorrect record authentication key rejected',
    $plan(
        $startedHistory,
        $preparedSeal,
        $startedSeal,
        str_repeat('X', 32)
    )
);

$assertBlocked(
    'Incorrect checkpoint authentication key rejected',
    $plan(
        $startedHistory,
        $preparedSeal,
        $startedSeal,
        null,
        str_repeat('X', 32)
    )
);

$assertBlocked(
    'Empty journal cannot advance checkpoint',
    $plan(
        [],
        $preparedSeal,
        $startedSeal
    )
);

$invalidHistory = [
    $prepared,
    $started,
    $started,
];

$assertBlocked(
    'Contradictory journal events rejected',
    $plan(
        $invalidHistory,
        $startedSeal,
        $acceptedSeal
    )
);

$assertCandidate(
    'Valid checkpoint candidate still cannot send SMS',
    $plan(
        $startedHistory,
        $preparedSeal,
        $startedSeal
    )
);

echo "PASS: All checkpoint progression tests.\n";