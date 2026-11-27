<?php
declare(strict_types=1);

/** Smart Text Try Now: SMS preparation tests. No database or SMS delivery. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../lib/try-now-sms.php';

$numbers = [
    ['name' => 'Ireland', 'mobile' => '0890000000', 'recipient' => '353890000000', 'optout' => 'Optout to 50015'],
    ['name' => 'UK', 'mobile' => '07700900123', 'recipient' => '447700900123', 'optout' => 'Optout 07507319809'],
];
foreach (stTryNowSamples() as $industry => $sample) {
    foreach ($numbers as $country) {
        $result = stTryNowPrepareSms($industry, $country['mobile'], 'aB1234', 'Seán');
        $payload = $result['payload'];
        $expectedLink = stTryNowBuildCustomerLink($industry, 'aB1234');
        $message = $payload['messagetext'];
        $ending = "\n" . $country['optout'];
        $passed = $result['mode'] === 'dry-run'
            && $result['sent'] === false
            && $payload['recipients'] === [$country['recipient']]
            && strpos($message, 'Hi Sean.' . "\n") === 0
            && stTryNowSmsIsGsm7Safe($message)
            && strpos($message, $expectedLink) !== false
            && substr($message, -strlen($ending)) === $ending;
        if (!$passed) {
            fwrite(STDERR, "FAIL: $industry / {$country['name']}\n");
            exit(1);
        }
        echo "PASS: $industry / {$country['name']}\n";
    }
}
echo "PASS: All SMS preparation tests.\n";
