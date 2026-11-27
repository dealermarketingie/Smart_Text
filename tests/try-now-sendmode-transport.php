<?php
declare(strict_types=1);

/** Sendmode transport hard-disable tests. No network, DB writes or SMS. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../lib/try-now-sendmode-transport.php';

function checkTransport(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $name . PHP_EOL;
}
checkTransport('Live delivery hard-disabled', ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false);
$sms = stTryNowPrepareSms('automotive', '0890000000', 'aB1234', 'Seán');
$request = stTryNowBuildSendmodeRequest($sms);
$cases = [
    'Default configuration' => [$request, '', ''],
    'Fake credentials supplied' => [$request, 'NOT_A_REAL_API_KEY', 'SmartText'],
    'Empty request' => [[], '', ''],
    'Modified endpoint' => [array_merge($request, ['url' => 'http://127.0.0.1:9999/test']), 'NOT_A_REAL_API_KEY', 'SmartText'],
    'Invalid request body' => [array_merge($request, ['body' => 'invalid-request-body']), 'NOT_A_REAL_API_KEY', 'SmartText'],
];
foreach ($cases as $name => $arguments) {
    $result = stTryNowSendmodeTransport($arguments[0], $arguments[1], $arguments[2]);
    checkTransport($name . ' blocked',
        $result['status'] === 'disabled'
        && $result['accepted'] === false
        && $result['delivered'] === false
        && $result['provider_code'] === null);
}
echo 'PASS: All Sendmode transport safety tests.' . PHP_EOL;
