<?php
declare(strict_types=1);

/** Sendmode request preparation tests. No HTTP, credentials, database or SMS. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../lib/try-now-sendmode.php';

function checkSendmode(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $name . PHP_EOL;
}

$countries = [
    'Ireland' => ['mobile' => '0890000000', 'recipient' => '353890000000', 'optout' => "\nOptout to 50015"],
    'UK' => ['mobile' => '07700900123', 'recipient' => '447700900123', 'optout' => "\nOptout 07507319809"],
];
foreach (stTryNowSamples() as $industry => $sample) {
    foreach ($countries as $country => $details) {
        $sms = stTryNowPrepareSms($industry, $details['mobile'], 'aB1234', 'Seán');
        $request = stTryNowBuildSendmodeRequest($sms);
        parse_str($request['body'], $form);
        $message = json_decode($form['message'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $expectedLink = stTryNowBuildCustomerLink($industry, 'aB1234');
        $passed = $request['mode'] === 'dry-run'
            && $request['sent'] === false
            && $request['method'] === 'POST'
            && $request['url'] === 'https://rest.sendmode.com/v2/send'
            && $request['content_type'] === 'application/x-www-form-urlencoded'
            && count($form) === 1
            && isset($message['messagetext'])
            && $message['recipients'] === [$details['recipient']]
            && strpos($message['messagetext'], 'Hi Sean.' . "\n") === 0
            && stTryNowSmsIsGsm7Safe($message['messagetext'])
            && strpos($message['messagetext'], $expectedLink) !== false
            && substr($message['messagetext'], -strlen($details['optout'])) === $details['optout'];
        checkSendmode("$industry / $country", $passed);
    }
}

$validSms = stTryNowPrepareSms('automotive', '0890000000', 'aB1234', 'Seán');
$invalidCases = [];
$case = $validSms;
$case['sent'] = true;
$invalidCases['Already-sent payload'] = $case;
$case = $validSms;
$case['mode'] = 'live';
$invalidCases['Live-mode payload'] = $case;
$case = $validSms;
$case['payload']['recipients'][] = '353860000001';
$invalidCases['Multiple recipients'] = $case;
$case = $validSms;
$case['payload']['recipients'] = ['12345'];
$invalidCases['Invalid recipient'] = $case;
$case = $validSms;
$case['payload']['messagetext'] = 'Sample without optout';
$invalidCases['Missing opt-out'] = $case;
$case = $validSms;
$case['payload']['messagetext'] = 'Test message' . "\nOptout 07507319809";
$invalidCases['Wrong-country opt-out'] = $case;

foreach ($invalidCases as $name => $payload) {
    $rejected = false;
    try {
        stTryNowBuildSendmodeRequest($payload);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    checkSendmode($name . ' rejected', $rejected);
}
echo 'PASS: All Sendmode REST preparation tests.' . PHP_EOL;
