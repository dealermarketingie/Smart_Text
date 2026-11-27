<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal head seal.
 *
 * PURE FUNCTIONS ONLY.
 * PHP 7.4 compatible.
 *
 * No filesystem, database, Redis, HTTP or SMS.
 *
 * A seal must eventually be persisted in an
 * independently protected monotonic store.
 *
 * HMAC alone cannot prevent rollback of both
 * the seal and its corresponding journal.
 */

require_once __DIR__
    . '/try-now-attempt-journal-format.php';

/**
 * Construct a signed checkpoint of the latest
 * complete and authenticated journal record.
 */
function stTryNowBuildJournalHeadSeal(
    array $records,
    string $recordKey,
    string $sealKey
): array {
    if (
        strlen($recordKey) < 32 ||
        strlen($sealKey) < 32 ||
        hash_equals($recordKey, $sealKey)
    ) {
        throw new InvalidArgumentException(
            'Invalid journal seal keys.'
        );
    }

    $verified = stTryNowVerifyJournalHistory(
        $records,
        $recordKey
    );

    if ($verified['valid'] !== true) {
        throw new InvalidArgumentException(
            'Cannot seal invalid journal history.'
        );
    }

    $first = $records[0];
    $last = $records[count($records) - 1];

    $seal = [
        'version' => 1,
        'attempt_id' => $first['attempt_id'],
        'customer_id' => $first['customer_id'],
        'campaign_id' => $first['campaign_id'],
        'record_count' => count($records),
        'head_event' => $last['event'],
        'head_mac' => $last['mac'],
    ];

    $payload = json_encode(
        $seal,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR
    );

    // Cryptographically separate the checkpoint
    // from journal-record authentication.
    $seal['seal_mac'] = hash_hmac(
        'sha256',
        "smarttext:try-now:head-seal:v1\0" . $payload,
        $sealKey
    );

    return $seal;
}

/**
 * Verify the journal against an independently
 * supplied checkpoint.
 *
 * This checks equality with the supplied seal.
 * It does NOT prove that the supplied seal is
 * the latest checkpoint ever persisted.
 *
 * All outcomes prohibit automatic transport.
 */
function stTryNowVerifyJournalHeadSeal(
    array $records,
    ?array $seal,
    string $recordKey,
    string $sealKey
): array {
    $result = [
        'status' => 'review_required',
        'valid' => false,
        'attempt_state' => 'unknown',
        'provider_evidence' => null,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    if ($seal === null) {
        $result['status'] = 'missing_seal';
        return $result;
    }

    try {
        $expected = stTryNowBuildJournalHeadSeal(
            $records,
            $recordKey,
            $sealKey
        );

        // Reject extra fields, missing fields,
        // altered field ordering and wrong types.
        if (array_keys($seal) !== array_keys($expected)) {
            return $result;
        }

        foreach ($expected as $field => $value) {
            if ($field === 'seal_mac') {
                if (
                    !is_string($seal[$field]) ||
                    !hash_equals(
                        $value,
                        $seal[$field]
                    )
                ) {
                    return $result;
                }

            } elseif ($seal[$field] !== $value) {
                return $result;
            }
        }

        $verified = stTryNowVerifyJournalHistory(
            $records,
            $recordKey
        );

        if ($verified['valid'] !== true) {
            return $result;
        }

        $result['status'] = 'head_verified_test_only';
        $result['valid'] = true;
        $result['attempt_state'] =
            $verified['attempt_state'];
        $result['provider_evidence'] =
            $verified['provider_evidence'];

        return $result;

    } catch (Throwable $e) {
        return $result;
    }
}
