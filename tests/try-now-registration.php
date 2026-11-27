<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Registration preparation tests.
 *
 * No database connections, writes, SMS or email.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-registration.php';

function checkRegistration(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

$input = [
    'industry' => 'automotive',
    'firstName' => 'Éabha',
    'lastNameCompany' => 'Ó Briain',
    'email' => 'eabha@example.com',
    'mobile' => '087 123 4567',
    'consent' => 'on',
    'website' => '',
];

// Verify all six approved campaign mappings.
foreach (stTryNowSamples() as $industry => $sample) {
    $data = $input;
    $data['industry'] = $industry;

    $result = stTryNowPrepareRegistration($data);

    checkRegistration(
        "$industry mapping",
        $result['ok'] === true
        && $result['customer']['campaign_id'] === $sample['campaign_id']
        && $result['customer']['first_name'] === 'Éabha'
        && $result['customer']['last_name'] === 'Ó Briain'
        && $result['customer']['mobile'] === '087 1234567'
        && $result['email'] === 'eabha@example.com'
        && !isset($result['customer']['email'])
        && !isset($result['customer']['code'])
    );
}

// Verify the exact first_name database limit.
$data = $input;
$data['firstName'] = str_repeat('Á', 45);

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'First name: 45 accented characters accepted',
    $result['ok'] === true
);

$data['firstName'] = str_repeat('Á', 46);

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'First name: 46 accented characters rejected',
    $result['ok'] === false
    && isset($result['errors']['firstName'])
);

// Verify the exact last_name database limit.
$data = $input;
$data['lastNameCompany'] = str_repeat('Ó', 60);

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'Last name: 60 accented characters accepted',
    $result['ok'] === true
);

$data['lastNameCompany'] = str_repeat('Ó', 61);

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'Last name: 61 accented characters rejected',
    $result['ok'] === false
    && isset($result['errors']['lastNameCompany'])
);

// Existing form allows the optional surname/company field.
$data = $input;
$data['lastNameCompany'] = '';

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'Optional surname/company stored as empty string',
    $result['ok'] === true
    && $result['customer']['last_name'] === ''
);

// Validation must fail before any registration data is produced.
$data = $input;
$data['consent'] = '';

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'Registration rejected without consent',
    $result['ok'] === false
    && !isset($result['customer'])
);


/**
 * Verify compatibility with the existing MariaDB
 * utf8mb3 customer columns.
 */

// Emoji in first name must be rejected.
$data = $input;
$data['firstName'] = "Seán \u{1F642}";

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'Emoji in first name rejected',
    $result['ok'] === false
    && isset($result['errors']['firstName'])
);

// Emoji in surname/company must be rejected.
$data = $input;
$data['lastNameCompany'] = "Ó Briain \u{1F680}";

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'Emoji in surname/company rejected',
    $result['ok'] === false
    && isset($result['errors']['lastNameCompany'])
);

// Other supplementary Unicode characters must
// also be rejected, not just emoji.
$data = $input;
$data['firstName'] = "Éabha \u{20000}";

$result = stTryNowPrepareRegistration($data);

checkRegistration(
    'Supplementary Unicode character rejected',
    $result['ok'] === false
    && isset($result['errors']['firstName'])
);


echo "PASS: All registration preparation tests.\n";
