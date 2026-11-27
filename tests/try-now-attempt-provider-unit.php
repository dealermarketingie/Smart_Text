<?php
declare(strict_types=1);

/** Pure provider-outcome mapping tests. No database or network. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once __DIR__ . '/../lib/try-now-attempt-journal-db.php';

$cases = [
    ['accepted', ['status'=>'accepted','accepted'=>true,'delivered'=>false,'provider_code'=>0], 'provider_accepted'],
    ['rejected', ['status'=>'rejected','accepted'=>false,'delivered'=>false,'provider_code'=>11], 'provider_rejected'],
    ['unknown / timeout', ['status'=>'unknown','accepted'=>false,'delivered'=>false,'provider_code'=>null], 'provider_unknown'],
    ['unknown / conflicting response', ['status'=>'unknown','accepted'=>false,'delivered'=>false,'provider_code'=>0], 'provider_unknown'],
];
foreach ($cases as [$name,$input,$expected]) {
    $result = stTryNowJournalProviderResult($input);
    if ($result['state'] !== $expected || $result['event'] !== $expected) {
        throw new RuntimeException('Provider mapping test failed: '.$name);
    }
    echo 'PASS: '.$name." recorded as ".$expected."\n";
}
$invalid = [
    ['status'=>'accepted','accepted'=>true,'delivered'=>true,'provider_code'=>0],
    ['status'=>'accepted','accepted'=>false,'delivered'=>false,'provider_code'=>0],
    ['status'=>'accepted','accepted'=>true,'delivered'=>false,'provider_code'=>14],
    ['status'=>'rejected','accepted'=>false,'delivered'=>false,'provider_code'=>null],
    ['status'=>'rejected','accepted'=>true,'delivered'=>false,'provider_code'=>11],
    ['status'=>'unknown','accepted'=>true,'delivered'=>false,'provider_code'=>null],
    ['status'=>'unknown','accepted'=>false,'delivered'=>false,'provider_code'=>-1],
    ['status'=>'unknown','accepted'=>false,'delivered'=>false,'provider_code'=>'0'],
    ['status'=>'missing','accepted'=>false,'delivered'=>false,'provider_code'=>null],
];
foreach ($invalid as $i => $input) {
    try {
        stTryNowJournalProviderResult($input);
    } catch (InvalidArgumentException $e) {
        continue;
    }
    throw new RuntimeException('Invalid provider result accepted: case '.$i);
}
echo "PASS: All inconsistent provider results rejected.\n";
echo "PASS: No database operations or SMS transmission.\n";
