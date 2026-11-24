<?php
/** CLI tests. Run: php tests/try-now-dryrun.php */
declare(strict_types=1);
require_once __DIR__ . '/../lib/try-now-support.php';

function check($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$expect = [
    'automotive' => [7907, 'LJfjMU'],
    'healthcare' => [7908, 'mQ66my'],
    'realestate' => [7909, 'egD7zR'],
    'retail' => [7910, 'iNbkVV'],
    'travel' => [7911, 'SOvd9j'],
    'fitness' => [7912, 'ixRaH3'],
];
check(count(stTryNowSamples()) === 6, 'exactly six approved campaigns');
foreach ($expect as $industry => $data) {
    $sample = stTryNowSampleForIndustry($industry);
    check($sample['campaign_id'] === $data[0], $industry . ' campaign ID');
    check($sample['short_id'] === $data[1], $industry . ' short identifier case');
    $url = stTryNowBuildCustomerLink($industry, 'AzB123');
    check($url === 'https://stxt.me/' . $data[1] . '/AzB123' . $data[0], $industry . ' personalised link structure');
}
foreach (['luxuryretail', 'financial', 'recruitment', 'homeimprovement', 'unknown'] as $disabled) {
    check(stTryNowSampleForIndustry($disabled) === null, $disabled . ' must not have a sample');
}
check(stTryNowNormalizeMobile('+353 (87) 123 4567') === '087 1234567', 'international Irish mobile');
check(stTryNowNormalizeMobile('0871234567') === '087 1234567', 'domestic Irish mobile');
check(stTryNowNormalizeMobile('+44 7700 900123') === '07700 900123', 'international UK mobile');
check(stTryNowNormalizeMobile('07700 900123') === '07700 900123', 'domestic UK mobile');
check(stTryNowNormalizeMobile('1234567') === null, 'invalid mobile rejected');

for ($i = 0; $i < 100; $i++) {
    $code = stTryNowGenerateCustomerCode();
    check((bool) preg_match('/^[A-Za-z][A-Za-z0-9]{5}$/D', $code), 'secure code format');
}
$input = [
    'industry' => 'automotive', 'firstName' => 'Seán', 'lastNameCompany' => "O'Brien",
    'email' => 'sean@example.com', 'mobile' => '+353 87 123 4567', 'consent' => 'on',
];
check(stTryNowValidateInput($input)['ok'], 'valid international-name request');
$bad = $input;
$bad['industry'] = 'luxuryretail';
check(!stTryNowValidateInput($bad)['ok'], 'unsupported industry rejected');
$bad = $input;
$bad['mobile'] = '123';
check(!stTryNowValidateInput($bad)['ok'], 'invalid mobile rejected');
$bad = $input;
$bad['consent'] = '';
check(!stTryNowValidateInput($bad)['ok'], 'missing consent rejected');
$bad = $input;
$bad['website'] = 'spam';
check(!stTryNowValidateInput($bad)['ok'], 'honeypot rejected');

try {
    stTryNowBuildCustomerLink('automotive', '1a2s3d');
    check(false, 'sample code should be rejected as real customer code');
} catch (InvalidArgumentException $e) {
    // Expected: demo customers are not valid new customer codes.
}
echo "PASS: six mappings, normalisation, customer-code format, validation, disabled industries.\n";
