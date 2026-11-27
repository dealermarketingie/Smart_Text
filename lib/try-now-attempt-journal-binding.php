<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal identity binding.
 *
 * PURE FUNCTIONS ONLY.
 *
 * No filesystem, database, Redis, HTTP or SMS operations.
 * PHP 7.4 compatible.
 *
 * The namespace and binding key must eventually
 * come from trusted private server configuration.
 */

function stTryNowJournalSlotId(
    string $namespace,
    int $campaignId,
    int $customerId,
    string $bindingKey
): string {
    if (
        strlen($namespace) < 3 ||
        strlen($namespace) > 64 ||
        preg_match(
            '/\A[a-z][a-z0-9_-]*\z/D',
            $namespace
        ) !== 1 ||
        $campaignId <= 0 ||
        $customerId <= 0 ||
        strlen($bindingKey) < 32
    ) {
        throw new InvalidArgumentException(
            'Invalid journal binding inputs.'
        );
    }

    // Canonical data representation avoids ambiguous
    // concatenations and accidental ID collisions.
    $identity = json_encode(
        [
            'namespace' => $namespace,
            'campaign_id' => $campaignId,
            'customer_id' => $customerId,
        ],
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    // Separate this purpose cryptographically from
    // record authentication.
    $material = "smarttext:try-now:journal-slot:v1\0"
        . $identity;

    return hash_hmac(
        'sha256',
        $material,
        $bindingKey
    );
}

/**
 * Build a safe filename from a validated slot ID.
 *
 * This function does not create or open any file.
 */
function stTryNowJournalSlotFilename(
    string $slotId
): string {
    if (
        preg_match(
            '/\A[0-9a-f]{64}\z/D',
            $slotId
        ) !== 1
    ) {
        throw new InvalidArgumentException(
            'Invalid journal slot identifier.'
        );
    }

    return 'slot-' . $slotId . '.jsonl';
}
