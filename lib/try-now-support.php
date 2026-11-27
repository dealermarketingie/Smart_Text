<?php
/**
 * Smart Text Try Now: pure helpers for the simulation stage.
 * PHP 7.4-compatible. This file performs NO database, SMS or email operations.
 */
declare(strict_types=1);

function stTryNowSamples(): array
{
    // Short IDs are case-sensitive. Do not substitute John/Mary sample codes
    // for a real visitor's unique customer code.
    return [
        'automotive' => [
            'campaign_id' => 7907, 'short_id' => 'LJfjMU',
            'sample_name' => 'Audi Dealership — All-Access Test-Drive Event',
        ],
        'healthcare' => [
            'campaign_id' => 7908, 'short_id' => 'mQ66my',
            'sample_name' => 'GoodSight Healthcare — Appointment Booking',
        ],
        'realestate' => [
            'campaign_id' => 7909, 'short_id' => 'egD7zR',
            'sample_name' => 'Apex Property — Property Search & Viewing',
        ],
        'retail' => [
            'campaign_id' => 7910, 'short_id' => 'iNbkVV',
            'sample_name' => 'Wear & Where — Shaping Jeans Back in Stock',
        ],
        'travel' => [
            'campaign_id' => 7911, 'short_id' => 'SOvd9j',
            'sample_name' => 'The Perk Hotel — Flash Sale',
        ],
        'fitness' => [
            'campaign_id' => 7912, 'short_id' => 'ixRaH3',
            'sample_name' => 'Titans Gym — Renewal Reminder',
        ],
    ];
}

function stTryNowSampleForIndustry(string $industry): ?array
{
    // The browser submits ONLY the industry. The server owns all campaign data.
    $samples = stTryNowSamples();
    return $samples[$industry] ?? null;
}

/** Return the existing site's stored mobile format (or null if unsupported). */
function stTryNowNormalizeMobile(string $input): ?string
{
    $digits = preg_replace('/[^0-9]/', '', $input);
    if (!is_string($digits)) return null;

    // Longest international prefixes first; then optional trunk zero.
    foreach (['00353', '0044', '353', '44'] as $prefix) {
        if (strpos($digits, $prefix) === 0) {
            $digits = substr($digits, strlen($prefix));
            break;
        }
    }
    if (strpos($digits, '0') === 0) {
        $digits = substr($digits, 1);
    }

    if (preg_match('/^8[0-9]{8}$/D', $digits)) {
        return '0' . substr($digits, 0, 2) . ' ' . substr($digits, 2);
    }
    if (preg_match('/^7[0-9]{9}$/D', $digits)) {
        return '0' . substr($digits, 0, 4) . ' ' . substr($digits, 4);
    }
    return null;
}

/**
 * New visitor codes: first character A-Z or a-z, followed by five characters
 * from 0-9/A-Z/a-z. Uses a CSPRNG rather than the legacy mt_rand().
 * This does NOT prove uniqueness; the live DB stage must check collisions.
 */
function stTryNowGenerateCustomerCode(): string
{
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $code = $letters[random_int(0, strlen($letters) - 1)];
    for ($i = 1; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

/** Construct for unit tests only. It is NOT a deliverable link until DB insert. */
function stTryNowBuildCustomerLink(string $industry, string $code): string
{
    $sample = stTryNowSampleForIndustry($industry);
    if ($sample === null || !preg_match('/^[A-Za-z][A-Za-z0-9]{5}$/D', $code)) {
        throw new InvalidArgumentException('Unknown industry or invalid customer code');
    }
    return 'https://stxt.me/' . $sample['short_id'] . '/' . $code . $sample['campaign_id'];
}

/** No HTML sanitisation here: preserve international names; escape on output. */
function stTryNowValidateInput(array $post): array
{
    $errors = [];
    $limits = [
        'industry' => 32,
        'firstName' => 100,
        'lastNameCompany' => 150,
        'email' => 254,
        'mobile' => 40,
        'consent' => 8,
        'website' => 200,
    ];
    $values = [];
    foreach ($limits as $key => $maxBytes) {
        $raw = $post[$key] ?? '';
        if (!is_string($raw) || strlen($raw) > $maxBytes || strpos($raw, "\0") !== false) {
            $errors[$key] = 'Invalid field value.';
            $values[$key] = '';
        } else {
            $values[$key] = trim($raw);
        }
    }

    if ($values['firstName'] === '') $errors['firstName'] = 'First name is required.';
    if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $values['email'])) {
        $errors['email'] = 'Enter a valid email address.';
    }
    $mobile = stTryNowNormalizeMobile($values['mobile']);
    if ($mobile === null) $errors['mobile'] = 'Enter a UK or Irish mobile number.';
    if ($values['consent'] !== 'on' && $values['consent'] !== '1') {
        $errors['consent'] = 'Consent is required.';
    }
    $sample = stTryNowSampleForIndustry($values['industry']);
    if ($sample === null) $errors['industry'] = 'This industry has no approved sample.';

    // Honeypot: never process a form where an automated filler provided a value.
    if ($values['website'] !== '') $errors['website'] = 'Invalid request.';

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'values' => $values,
        'normalised_mobile' => $mobile,
        'sample' => $sample,
    ];
}
