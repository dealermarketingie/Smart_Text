<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Sendmode transport safety tests.
 *
 * NO network requests, database writes or SMS delivery.
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-sendmode-transport.php';

function checkTransport(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

// Verify the hard-coded safety switch.
checkTransport(
    'Live delivery hard-disabled',
    ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false
);

// Prepare a valid SMS request without sending it.
$sms = stTryNowPrepareSms(
    'automotive',
    '0890000000',
    'aB1234'
);

$request = stTryNowBuildSendmodeRequest($sms);

$cases = [
    'Default configuration' => [
        $request, '', ''
    ],

    'Fake credentials supplied' => [
        $request, 'NOT_A_REAL_API_KEY', 'SmartText'
    ],

    'Empty request' => [
        [], '', ''
    ],

    'Modified endpoint' => [
        array_merge($request, [
            'url' => 'http://127.0.0.1:9999/test'
        ]),
        'NOT_A_REAL_API_KEY',
        'SmartText'
    ],

    'Invalid request body' => [
        array_merge($request, [
            'body' => 'invalid-request-body'
        ]),
        'NOT_A_REAL_API_KEY',
        'SmartText'
    ],
];

foreach ($cases as $name => $arguments) {
    $result = stTryNowSendmodeTransport(
        $arguments[0],
        $arguments[1],
        $arguments[2]
    );

    checkTransport(
        "$name blocked",
        $result['status'] === 'disabled'
        && $result['accepted'] === false
        && $result['delivered'] === false
        && $result['provider_code'] === null
    );
}

echo "PASS: All Sendmode transport safety tests.\n";
