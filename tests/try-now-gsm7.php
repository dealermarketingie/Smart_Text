<?php
declare(strict_types=1);
/** Offline Try Now GSM-7 conversion tests. No DB, Redis, Sendmode or mail. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once __DIR__ . '/../lib/try-now-sms.php';
require_once __DIR__ . '/../lib/try-now-sendmode.php';

function gsmCheck(bool $condition, string $name): void {
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL); exit(1); }
    echo 'PASS: ' . $name . PHP_EOL;
}
$examples = [
    'ŠKODA' => 'SKODA',
    'Seán Áine Ó Briain' => 'Sean Aine O Briain',
    'á í ó ú Á Í Ó Ú' => 'a i o u A I O U',
    'Šárka Łukasz François Zoë' => 'Sarka Lukasz Francois Zoe',
    'Dvořák Bjørn Ægir Straße' => 'Dvorak Bjorn AEgir Strasse',
    '“Offer” — don’t miss…' => "\"Offer\" - don't miss...",
    'Save 25€ • terms™' => 'Save 25EUR - terms(TM)',
    "Hi Jane 😊!" => 'Hi Jane !',
    'Hi' . "\u{00A0}" . 'there' => 'Hi there',
    "Hi `Jo`" => "Hi 'Jo'",
    "Line one\nLine two\nLink" => "Line one\nLine two\nLink",
    "Jo\u{0301}se" => 'Jose',
];
foreach ($examples as $input => $expected) {
    $actual = stTryNowSmsToGsm7($input);
    gsmCheck($actual === $expected && stTryNowSmsIsGsm7Safe($actual),
             'conversion: ' . $expected);
    gsmCheck(stTryNowSmsToGsm7($actual) === $actual, 'idempotent conversion');
}
foreach (["\xC3\x28", '中文', 'Привет', "\x01bad"] as $invalid) {
    $rejected = false;
    try { stTryNowSmsToGsm7($invalid); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    gsmCheck($rejected, 'invalid/untransliterable characters fail closed');
}
$industries = array_keys(stTryNowSamples());
foreach ($industries as $industry) {
    foreach (['0871234567', '07700900123'] as $mobile) {
        $sms = stTryNowPrepareSms($industry, $mobile, 'aB1234', 'Šárka');
        $msg = $sms['payload']['messagetext'];
        $originalTemplate = stTryNowSmsTemplate($industry);
        gsmCheck(stTryNowSmsIsGsm7Safe($msg) && strpos($msg, 'Hi Sarka.') === 0,
                 "$industry / $mobile: safe personalised SMS");
        gsmCheck(substr_count($msg, "\n") === substr_count($originalTemplate, "\n"),
                 "$industry / $mobile: original line breaks preserved");
        stTryNowBuildSendmodeRequest($sms);
    }
}
$unsafe = stTryNowPrepareSms('automotive', '0871234567', 'aB1234', 'Sean');
$unsafe['payload']['messagetext'] = 'Hi ŠKODA' . "\nOptout to 50015";
$rejected = false;
try { stTryNowBuildSendmodeRequest($unsafe); }
catch (InvalidArgumentException $e) { $rejected = true; }
gsmCheck($rejected, 'Sendmode builder rejects bypassed non GSM-7 payload');

echo 'PASS: GSM-7 normalisation suite; no SMS, HTTP or database operations.' . PHP_EOL;
