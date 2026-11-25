<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - SMS attempt evidence tests.
 *
 * Synthetic events only.
 * No database, Redis, filesystem writes or SMS.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-evidence.php';

require_once __DIR__
    . '/../lib/try-now-runtime.php';

function evidenceCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

evidenceCheck(
    'Production delivery remains disabled',
    stTryNowProductionDeliveryEnabled() === false
);

/*
 * Each case:
 *
 * Name
 * Ordered synthetic events
 * Expected attempt state
 * Expected provider evidence
 * Expected sequence validity
 */
$cases = [
    [
        'Missing journal provides no send evidence',
        [],
        'unknown', null, false,
    ],
    [
        'Prepared alone cannot prove no attempt',
        ['prepared'],
        'unknown', null, true,
    ],
    [
        'Explicit pre-transport cancellation',
        ['prepared', 'cancelled_before_transport'],
        'confirmed_not_attempted', null, true,
    ],
    [
        'Transport started with no response',
        ['prepared', 'transport_started'],
        'attempted', null, true,
    ],
    [
        'Provider acceptance remains an attempt',
        [
            'prepared',
            'transport_started',
            'provider_accepted',
        ],
        'attempted', 'accepted', true,
    ],
    [
        'Provider rejection remains an attempt',
        [
            'prepared',
            'transport_started',
            'provider_rejected',
        ],
        'attempted', 'rejected', true,
    ],
    [
        'Unknown provider response preserves attempt',
        [
            'prepared',
            'transport_started',
            'provider_unknown',
        ],
        'attempted', 'unknown', true,
    ],
    [
        'Transport without preparation fails closed',
        ['transport_started'],
        'unknown', null, false,
    ],
    [
        'Acceptance without transport fails closed',
        ['prepared', 'provider_accepted'],
        'unknown', null, false,
    ],
    [
        'Transport after cancellation fails closed',
        [
            'prepared',
            'cancelled_before_transport',
            'transport_started',
        ],
        'unknown', null, false,
    ],
    [
        'Contradictory provider responses fail closed',
        [
            'prepared',
            'transport_started',
            'provider_accepted',
            'provider_rejected',
        ],
        'unknown', null, false,
    ],
    [
        'Duplicate preparation fails closed',
        ['prepared', 'prepared'],
        'unknown', null, false,
    ],
    [
        'Unsupported event fails closed',
        [
            'prepared',
            'transport_started',
            'unsupported',
        ],
        'unknown', null, false,
    ],
    [
        'Unknown response without transport fails closed',
        ['prepared', 'provider_unknown'],
        'unknown', null, false,
    ],
];

foreach ($cases as $case) {
    [
        $name,
        $eventNames,
        $expectedAttempt,
        $expectedProvider,
        $expectedValidity,
    ] = $case;

    $events = [];

    foreach ($eventNames as $eventName) {
        $events[] = ['event' => $eventName];
    }

    $result = stTryNowClassifyAttemptHistory(
        $events
    );

    evidenceCheck(
        $name,
        $result['attempt_state'] === $expectedAttempt &&
        $result['provider_evidence'] === $expectedProvider &&
        $result['sequence_valid'] === $expectedValidity &&
        $result['delivered'] === false &&
        $result['automatic_send'] === false &&
        $result['automatic_retry'] === false &&
        $result['automatic_cleanup'] === false
    );
}

// Malformed journal entries cannot authorise sending.
$malformed = stTryNowClassifyAttemptHistory([
    ['event' => 'prepared'],
    ['event' => 42],
]);

evidenceCheck(
    'Malformed event data fails closed',
    $malformed['attempt_state'] === 'unknown' &&
    $malformed['sequence_valid'] === false &&
    $malformed['automatic_send'] === false
);

echo "PASS: All SMS attempt evidence tests.\n";
