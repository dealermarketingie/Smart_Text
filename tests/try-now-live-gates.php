<?php
declare(strict_types=1);

/** Strictly offline safety check. Refuse to run if ANY live gate is enabled. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-runtime.php';

$gates = [
    'Public live' => ST_TRY_NOW_PUBLIC_LIVE_ENABLED,
    'Production DB writes' => ST_TRY_NOW_PRODUCTION_WRITES_ENABLED,
    'SMS copy approved' => ST_TRY_NOW_SMS_COPY_APPROVED,
    'Sendmode transport live' => ST_TRY_NOW_SENDMODE_LIVE_ENABLED,
];

foreach ($gates as $name => $enabled) {
    if ($enabled !== false) {
        fwrite(STDERR, 'FAIL: ' . $name . ' is enabled; stop the safety check.' . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $name . ' remains disabled' . PHP_EOL;
}

require_once __DIR__ . '/../lib/try-now-live-delivery.php';
$result = stTryNowExecuteLiveRequest([]);
if (($result['http_status'] ?? null) !== 503 ||
    ($result['extras']['status'] ?? null) !== 'unavailable' ||
    ($result['extras']['accepted'] ?? null) !== false) {
    fwrite(STDERR, "FAIL: Disabled workflow did not fail closed.\n");
    exit(1);
}

echo "PASS: Disabled live workflow fails closed before credentials, DB, Redis or SMS.\n";
