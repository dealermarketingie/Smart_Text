<?php
declare(strict_types=1);

/**
 * Step 536: Exact SMS copy + runtime substitution tests.
 * Works on PHP 7.4+. Never reads database credentials or sends an SMS.
 * Optional preview: php tests/try-now-sms-copy.php --preview
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/try-now-sms.php';

function stTryNowCopyCheck(bool $condition, string $description): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $description . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}

// Independent exact-text fixtures supplied for Step 536.
// The three invisible trailing spaces from the original real-estate draft
// were removed; all visible wording and line breaks remain identical.
$expected = [
    'fitness' => <<<'SMS'
Hi {first_name}.
Renew your membership today at Titans Gym.
Keep Enjoying,
Unlimited Gym access
Group fitness classes
Don't lose the progress you have worked SO hard for..
Simply CLICK BELOW & let's get working together!
{campaign_link}
{optout}
SMS
    ,
    'automotive' => <<<'SMS'
Hi {first_name}.
Discover the 271 Audi range at the
Audi Dealer All-Access Test-Drive Event
Experience our incredible range
& enjoy exclusive 271 offers.
For details & to view our range & offers
CLICK BELOW
{campaign_link}
{optout}
SMS
    ,
    'retail' => <<<'SMS'
Hi {first_name}.
The Incredible Shaping Jeans are BACK IN STOCK!
at Wear & Where, and Now available in 2 brand new colours
Midnight Black & Winter White.
Be Quick - they will sell out fast
Simply CLICK BELOW & Order your new favourite jeans today.
{campaign_link}
{optout}
SMS
    ,
    'travel' => <<<'SMS'
Hi {first_name}.
Fancy a last-minute escape?
Avail of our FLASH Sale at The Perk Hotel
Book in in the next 72 hours & enjoy 25% OFF B&B & room only rates!
Simply CLICK BELOW & Start packing...
{campaign_link}
{optout}
SMS
    ,
    'realestate' => <<<'SMS'
Hi {first_name}.
Ready to find your Perfect home?
Apex Property Consultants are ready & waiting to
guide you through every stage & get you home!
Expert Advice
Affordable Options
Your New Chapter starts here..
CLICK BELOW & lets start your search today
{campaign_link}
{optout}
SMS
    ,
    'healthcare' => <<<'SMS'
Hi {first_name}.
Looks like your next eye test is due.
Book before the end of October & take 10% OFF
Your next pair of prescription glasses or sunglasses!
Booking has NEVER been easier
Simply CLICK BELOW for details & to book in.
See you Soon!
{campaign_link}
{optout}
SMS
    ,
];

stTryNowCopyCheck(count($expected) === 6, 'Exactly six submitted templates');
foreach ($expected as $industry => $template) {
    stTryNowCopyCheck(
        stTryNowSmsTemplate($industry) === $template,
        $industry . ': every visible character and line break preserved'
    );
}

$recipients = [
    'Ireland' => ['087 1234567', '353871234567', 'Optout to 50015'],
    'UK' => ['07700 900123', '447700900123', 'Optout 07507319809'],
];
foreach ($expected as $industry => $template) {
    foreach ($recipients as $country => $recipient) {
        $prepared = stTryNowPrepareSms($industry, $recipient[0], 'aB1234', 'Seán');
        $payload = $prepared['payload'];
        $expectedBody = strtr($template, [
            '{first_name}' => 'Sean',
            '{campaign_link}' => stTryNowBuildCustomerLink($industry, 'aB1234'),
            '{optout}' => $recipient[2],
        ]);
        stTryNowCopyCheck(
            $prepared['mode'] === 'dry-run' &&
            $prepared['sent'] === false &&
            $prepared['provider'] === 'sendmode' &&
            $payload['recipients'] === [$recipient[1]] &&
            $payload['messagetext'] === $expectedBody &&
            strpos($expectedBody, '{') === false &&
            strpos($expectedBody, "\r") === false &&
            stTryNowSmsIsGsm7Safe($payload['messagetext']),
            $industry . ' / ' . $country . ': GSM-7 personalisation, link, opt-out and line breaks'
        );
    }
}

foreach (['', "Alex\nSpam", "Alex\rSpam", "Alex\x00Spam"] as $invalidName) {
    $rejected = false;
    try {
        stTryNowPrepareSms('fitness', '0871234567', 'aB1234', $invalidName);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    stTryNowCopyCheck($rejected, 'Missing or multiline name rejected');
}
stTryNowCopyCheck(stTryNowSmsTemplate('financial') === null, 'Unsupported industry cannot select a template');

if (in_array('--preview', $argv, true)) {
    foreach (array_keys($expected) as $industry) {
        echo PHP_EOL . '===== ' . strtoupper($industry) . ' (illustrative Irish test data, not sent) =====' . PHP_EOL;
        echo stTryNowPrepareSms($industry, '0871234567', 'aB1234', 'Seán')['payload']['messagetext'] . PHP_EOL;
    }
}
echo 'PASS: Step 536; no HTTP, database, Redis, email or SMS operations.' . PHP_EOL;
