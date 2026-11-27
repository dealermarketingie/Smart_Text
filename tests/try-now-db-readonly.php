<?php
declare(strict_types=1);

/**
 * Read-only Smart Text campaign verification.
 * CLI only. No customer data, database writes or SMS.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/try-now-db.php';
require_once __DIR__ . '/../lib/try-now-support.php';

try {
    $pdo = stTryNowReadOnlyPdo();

    $stmt = $pdo->query(
        'SELECT id, campaign_link
         FROM campaign
         WHERE id IN (7907, 7908, 7909, 7910, 7911, 7912)'
    );

    $campaigns = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $samples = stTryNowSamples();

    foreach ($samples as $industry => $sample) {
        $id = $sample['campaign_id'];

        if (!isset($campaigns[$id])) {
            throw new RuntimeException('Missing campaign: ' . $id);
        }

        $expected = 'https://stxt.me/' . $sample['short_id'];
        $actual = rtrim((string) $campaigns[$id], '/');

        if ($actual !== $expected) {
            throw new RuntimeException(
                'Campaign URL mismatch: ' . $id
            );
        }

        echo 'PASS: ' . $industry . ' (' . $id . ')' . PHP_EOL;
    }

    echo 'PASS: All six campaigns verified.' . PHP_EOL;

} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
