<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once __DIR__ . '/../lib/try-now-live-response.php';
$cases = [
    ['accepted', 200, true],
    ['already_requested', 409, false],
    ['invalid', 422, false],
    ['provider_rejected', 502, false],
    ['provider_unknown', 503, false],
    ['unavailable', 503, false],
];
foreach ($cases as [$name, $http, $accepted]) {
    $response = stTryNowLiveResponse($name, ['mobile' => 'Invalid number']);
    $pass = $response['http_status'] === $http &&
        $response['extras']['accepted'] === $accepted &&
        is_string($response['message']) &&
        $response['message'] !== '';
    if ($name === 'accepted') {
        $pass = $pass && $response['extras']['mode'] === 'live' &&
            $response['extras']['status'] === 'accepted' &&
            $response['extras']['delivered'] === false;
    }
    if ($name === 'provider_unknown') {
        $pass = $pass && strpos($response['message'], 'do not submit again') !== false;
    }
    if (!$pass) {
        fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $name . ' response contract' . PHP_EOL;
}
try {
    stTryNowLiveResponse('not_a_real_status');
    fwrite(STDERR, "FAIL: unexpected result accepted\n");
    exit(1);
} catch (InvalidArgumentException $expected) {
    echo "PASS: invalid state rejected\n";
}
echo "PASS: No database, Redis, email or SMS operations.\n";
