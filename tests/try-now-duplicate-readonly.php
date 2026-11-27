<?php
declare(strict_types=1);

/**
 * Read-only duplicate detection test.
 * No customer modifications, emails or SMS messages.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-db.php';
require_once __DIR__ . '/../lib/try-now-duplicates.php';

try {
    $pdo = stTryNowReadOnlyPdo();

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM customer
         WHERE campaign_id = ?
           AND code REGEXP '^[A-Za-z]'"
    );

    foreach (stTryNowSamples() as $industry => $sample) {
        $id = (int) $sample['campaign_id'];

        $countStmt->execute([$id]);
        $existing = (int) $countStmt->fetchColumn();

        // These campaigns currently contain sample customers only.
        if ($existing !== 0) {
            throw new RuntimeException(
                'Campaign now contains real customers: ' . $id
            );
        }

        $irishDuplicate = stTryNowDuplicateExists(
            $pdo, $id, '089 000 0000'
        );

        $ukDuplicate = stTryNowDuplicateExists(
            $pdo, $id, '07700 900123'
        );

        if ($irishDuplicate || $ukDuplicate) {
            throw new RuntimeException(
                'Unexpected duplicate for campaign: ' . $id
            );
        }

        echo "PASS: $industry ($id)" . PHP_EOL;
    }

    echo "PASS: Read-only duplicate checks completed." . PHP_EOL;

} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
