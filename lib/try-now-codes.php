<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Customer code allocation helpers.
 *
 * Read-only checks only.
 * No customer inserts or SMS operations.
 */

require_once __DIR__ . '/try-now-support.php';

function stTryNowCustomerCodeExists(
    PDO $pdo,
    int $campaignId,
    string $code
): bool {
    if (
        $campaignId <= 0 ||
        !preg_match('/^[A-Za-z][A-Za-z0-9]{5}$/D', $code)
    ) {
        throw new InvalidArgumentException(
            'Invalid campaign or customer code.'
        );
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM customer
         WHERE campaign_id = ?
           AND code = ?
         LIMIT 1'
    );

    $stmt->execute([$campaignId, $code]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Generate a candidate not currently present in the campaign.
 * Final uniqueness must also be protected during database insertion.
 */
function stTryNowGenerateUnusedCustomerCode(
    PDO $pdo,
    int $campaignId
): string {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = stTryNowGenerateCustomerCode();

        if (!stTryNowCustomerCodeExists($pdo, $campaignId, $code)) {
            return $code;
        }
    }

    throw new RuntimeException(
        'Unable to allocate a customer code.'
    );
}
