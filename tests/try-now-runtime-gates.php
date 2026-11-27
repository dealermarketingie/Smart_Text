<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Production runtime safety tests.
 *
 * No database connections, writes, emails or SMS.
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-runtime.php';

function runtimeCheck(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

// Confirm every real production switch is disabled.
$disabled = [
    'Public API' => ST_TRY_NOW_PUBLIC_LIVE_ENABLED,
    'Production writes' => ST_TRY_NOW_PRODUCTION_WRITES_ENABLED,
    'SMS copy approval' => ST_TRY_NOW_SMS_COPY_APPROVED,
    'Sendmode transport' => ST_TRY_NOW_SENDMODE_LIVE_ENABLED,
    'Production cleanup' => ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED,
];

foreach ($disabled as $name => $enabled) {
    runtimeCheck(
        "$name disabled",
        $enabled === false
    );
}

runtimeCheck(
    'Production delivery blocked',
    stTryNowProductionDeliveryEnabled() === false
);

runtimeCheck(
    'Production cleanup blocked',
    stTryNowProductionCleanupEnabled() === false
);

// Test all 16 possible delivery-gate combinations.
// Only all four TRUE may return TRUE.
for ($mask = 0; $mask < 16; $mask++) {
    $flags = [];

    for ($i = 0; $i < 4; $i++) {
        $flags[] = (bool) ($mask & (1 << $i));
    }

    $actual = stTryNowAllDeliveryGatesEnabled(
        $flags[0],
        $flags[1],
        $flags[2],
        $flags[3]
    );

    runtimeCheck(
        "Delivery gate combination $mask",
        $actual === ($mask === 15)
    );
}

// Cleanup requires BOTH delivery and cleanup approval.
for ($mask = 0; $mask < 4; $mask++) {
    $delivery = (bool) ($mask & 1);
    $cleanup = (bool) ($mask & 2);

    runtimeCheck(
        "Cleanup gate combination $mask",
        stTryNowCleanupGatesEnabled(
            $delivery,
            $cleanup
        ) === ($mask === 3)
    );
}

// Even an attempted transport call must remain disabled.
$result = stTryNowSendmodeTransport([]);

runtimeCheck(
    'Live SMS transport hard-disabled',
    ($result['status'] ?? '') === 'disabled'
    && ($result['accepted'] ?? null) === false
);

echo "PASS: All production runtime safety tests.\n";
