<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - approved industry SMS copy preparation.
 *
 * Preserves the supplied message wording and line breaks. Dynamic values are
 * substituted server-side using a validated first name, personalised customer
 * link and the existing destination-specific opt-out instruction.
 *
 * DRY RUN PREPARATION ONLY: no HTTP, database or provider operations.
 * PHP 7.4 compatible.
 */
require_once __DIR__ . '/try-now-support.php';
require_once __DIR__ . '/try-now-gsm7.php';

/** Return the precise industry SMS template (or null for unsupported industries). */
function stTryNowSmsTemplate(string $industry): ?string
{
    $templates = [
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

    return $templates[$industry] ?? null;
}

/**
 * Assemble a single-recipient Sendmode-ready message, without sending it.
 * The first name is mandatory; never silently substitute a dummy name.
 */
function stTryNowPrepareSms(
    string $industry,
    string $mobile,
    string $customerCode,
    string $firstName
): array {
    $template = stTryNowSmsTemplate($industry);
    if ($template === null) {
        throw new InvalidArgumentException('Unknown industry or SMS copy unavailable.');
    }

    $firstName = trim($firstName);
    $nameLength = preg_match_all('/./us', $firstName);
    // Prevent customer-supplied text from injecting extra SMS lines or Unicode
    // format separators. The existing registration validator also rejects
    // unsupported supplementary Unicode characters in the customer table.
    $hasControl = preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}]/u', $firstName);
    $hasSupplementary = preg_match('/[\x{10000}-\x{10FFFF}]/u', $firstName);
    if ($firstName === '' || $nameLength === false || $nameLength > 45 ||
        $hasControl !== 0 || $hasSupplementary !== 0) {
        throw new InvalidArgumentException('Invalid customer first name for SMS.');
    }

    $normalised = stTryNowNormalizeMobile($mobile);
    if ($normalised === null) {
        throw new InvalidArgumentException('Invalid mobile number.');
    }
    $digits = preg_replace('/\D/', '', $normalised);
    if (!is_string($digits)) {
        throw new RuntimeException('Unable to normalise mobile number.');
    }

    if (preg_match('/^08[0-9]{8}$/D', $digits)) {
        $recipient = '353' . substr($digits, 1);
        $optout = 'Optout to 50015';
    } elseif (preg_match('/^07[0-9]{9}$/D', $digits)) {
        $recipient = '44' . substr($digits, 1);
        $optout = 'Optout 07507319809';
    } else {
        throw new InvalidArgumentException('Unsupported mobile number.');
    }

    $link = stTryNowBuildCustomerLink($industry, $customerCode);
    $message = stTryNowSmsToGsm7(strtr($template, [
        '{first_name}' => $firstName,
        '{campaign_link}' => $link,
        '{optout}' => $optout,
    ]));

    return [
        'mode' => 'dry-run',
        'sent' => false,
        'provider' => 'sendmode',
        'payload' => [
            'messagetext' => $message,
            'recipients' => [$recipient],
        ],
    ];
}
