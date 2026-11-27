<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal format tests.
 *
 * Synthetic records only.
 * No filesystem, database, Redis or SMS operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-format.php';

require_once __DIR__
    . '/../lib/try-now-runtime.php';

function journalCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

journalCheck(
    'Production delivery remains disabled',
    stTryNowProductionDeliveryEnabled() === false
);

// This is a disposable synthetic key.
// Never use it for real journal records.
$key = str_repeat('K', 32);

$attemptId = stTryNowNewJournalAttemptId();
$otherAttemptId = stTryNowNewJournalAttemptId();
$timestamp = '2026-11-25T15:00:00Z';
$genesis = str_repeat('0', 64);

journalCheck(
    'CSPRNG attempt identifier has correct format',
    preg_match(
        '/^[0-9a-f]{32}$/D',
        $attemptId
    ) === 1 &&
    $attemptId !== $otherAttemptId
);

$make = static function (
    int $sequence,
    string $event,
    string $previousMac,
    ?string $id = null
) use (
    $attemptId,
    $timestamp,
    $key
): array {
    return stTryNowBuildJournalRecord(
        $id ?? $attemptId,
        123,
        7907,
        $sequence,
        $event,
        $timestamp,
        $previousMac,
        $key
    );
};

$prepared = $make(1, 'prepared', $genesis);

$cancelled = $make(
    2,
    'cancelled_before_transport',
    $prepared['mac']
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

$otherAttempt = $make(
    2,
    'transport_started',
    $prepared['mac'],
    $otherAttemptId
);

$invalidOrder = $make(
    2,
    'provider_accepted',
    $prepared['mac']
);

$tampered = $started;
$tampered['event'] = 'provider_accepted';

$badMac = $started;
$badMac['mac'] = str_repeat('0', 64);

/*
 * Name, records, expected validity,
 * expected attempt state, provider evidence.
 */
$cases = [
    [
        'Missing journal fails closed',
        [], false, 'unknown', null,
    ],
    [
        'Prepared-only history remains uncertain',
        [$prepared], true, 'unknown', null,
    ],
    [
        'Explicit cancellation confirms no attempt',
        [$prepared, $cancelled],
        true, 'confirmed_not_attempted', null,
    ],
    [
        'Transport started without response',
        [$prepared, $started],
        true, 'attempted', null,
    ],
    [
        'Signed provider acceptance verified',
        [$prepared, $started, $accepted],
        true, 'attempted', 'accepted',
    ],
    [
        'Signed provider rejection verified',
        [$prepared, $started, $rejected],
        true, 'attempted', 'rejected',
    ],
    [
        'Signed unknown provider result verified',
        [$prepared, $started, $unknown],
        true, 'attempted', 'unknown',
    ],
    [
        'Missing first record rejected',
        [$started], false, 'unknown', null,
    ],
    [
        'Out-of-order records rejected',
        [$started, $prepared],
        false, 'unknown', null,
    ],
    [
        'Different attempt identity rejected',
        [$prepared, $otherAttempt],
        false, 'unknown', null,
    ],
    [
        'Invalid signed event order rejected',
        [$prepared, $invalidOrder],
        false, 'unknown', null,
    ],
    [
        'Modified event rejected by HMAC',
        [$prepared, $tampered],
        false, 'unknown', null,
    ],
    [
        'Modified signature rejected',
        [$prepared, $badMac],
        false, 'unknown', null,
    ],
    [
        'Duplicate records rejected',
        [$prepared, $prepared],
        false, 'unknown', null,
    ],
];

foreach ($cases as $case) {
    [
        $name,
        $records,
        $valid,
        $attemptState,
        $providerEvidence,
    ] = $case;

    $result = stTryNowVerifyJournalHistory(
        $records,
        $key
    );

    journalCheck(
        $name,
        $result['valid'] === $valid &&
        $result['attempt_state'] === $attemptState &&
        $result['provider_evidence']
            === $providerEvidence &&
        $result['delivered'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false
    );
}

$wrongKey = stTryNowVerifyJournalHistory(
    [$prepared, $started],
    str_repeat('X', 32)
);

journalCheck(
    'Incorrect HMAC key rejects journal',
    $wrongKey['valid'] === false
);

$invalidTimestampRejected = false;

try {
    $make(
        1,
        'prepared',
        str_repeat('x', 64)
    );
} catch (InvalidArgumentException $e) {
    $invalidTimestampRejected = true;
}

journalCheck(
    'Malformed previous MAC rejected',
    $invalidTimestampRejected
);

journalCheck(
    'Journal excludes direct contact details',
    !array_key_exists('mobile', $prepared) &&
    !array_key_exists('email', $prepared) &&
    !array_key_exists('sms_body', $prepared) &&
    !array_key_exists('ip', $prepared) &&
    !array_key_exists('link', $prepared)
);

echo "PASS: All journal format tests.\n";
