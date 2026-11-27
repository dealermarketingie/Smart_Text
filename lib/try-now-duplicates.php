<?php
declare(strict_types=1);

/**
 * Read-only duplicate detection for Smart Text samples.
 * No customer writes, SMS or email operations.
 */

require_once __DIR__ . '/try-now-support.php';

function stTryNowDuplicateExists(
    PDO $pdo,
    int $campaignId,
    string $mobile
): bool {
    $normalised = stTryNowNormalizeMobile($mobile);

    if ($normalised === null) {
        throw new InvalidArgumentException('Invalid mobile number.');
    }

    $national = preg_replace('/\D/', '', $normalised);

    $country = strpos($national, '08') === 0 ? '353' : '44';
    $international = $country . substr($national, 1);

    // Remove common formatting characters in stored numbers.
    // The campaign_id index limits the records examined.
    $cleanMobile = "
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            mobile, ' ', ''), '-', ''), '(', ''), ')', ''),
            '+', ''), '.', '')
    ";

    $sql = "
        SELECT 1
        FROM customer
        WHERE campaign_id = ?
          AND code REGEXP '^[A-Za-z]'
          AND $cleanMobile IN (?, ?, ?)
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $campaignId,
        $national,
        $international,
        '00' . $international
    ]);

    return $stmt->fetchColumn() !== false;
}
