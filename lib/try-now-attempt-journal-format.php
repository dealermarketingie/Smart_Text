<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Attempt journal format.
 *
 * PURE RECORD CREATION AND VERIFICATION.
 *
 * No filesystem, database, Redis, HTTP or SMS operations.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-attempt-evidence.php';
require_once __DIR__ . '/try-now-support.php';

function stTryNowNewJournalAttemptId(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Build one authenticated journal record.
 *
 * The HMAC key must remain in private configuration.
 * Never commit a production key to Git.
 */
function stTryNowBuildJournalRecord(
    string $attemptId,
    int $customerId,
    int $campaignId,
    int $sequence,
    string $event,
    string $recordedAtUtc,
    string $previousMac,
    string $hmacKey
): array {
    $allowedEvents = [
        'prepared',
        'cancelled_before_transport',
        'transport_started',
        'provider_accepted',
        'provider_rejected',
        'provider_unknown',
    ];

    $allowedCampaigns = array_column(
        stTryNowSamples(),
        'campaign_id'
    );

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s\Z',
        $recordedAtUtc,
        new DateTimeZone('UTC')
    );

    if (
        strlen($hmacKey) < 32 ||
        preg_match('/^[0-9a-f]{32}$/D', $attemptId) !== 1 ||
        $customerId <= 0 ||
        !in_array($campaignId, $allowedCampaigns, true) ||
        $sequence < 1 ||
        $sequence > 3 ||
        !in_array($event, $allowedEvents, true) ||
        preg_match('/^[0-9a-f]{64}$/D', $previousMac) !== 1 ||
        $date === false ||
        $date->format('Y-m-d\TH:i:s\Z') !== $recordedAtUtc
    ) {
        throw new InvalidArgumentException(
            'Invalid journal record.'
        );
    }

    // Fixed field ordering makes HMAC calculation
    // deterministic across journal reads.
    $record = [
        'version' => 1,
        'attempt_id' => $attemptId,
        'customer_id' => $customerId,
        'campaign_id' => $campaignId,
        'sequence' => $sequence,
        'event' => $event,
        'recorded_at_utc' => $recordedAtUtc,
        'previous_mac' => $previousMac,
    ];

    $payload = json_encode(
        $record,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR
    );

    $record['mac'] = hash_hmac(
        'sha256',
        $payload,
        $hmacKey
    );

    return $record;
}

/**
 * Verify a complete journal history.
 *
 * Missing records, invalid signatures,
 * altered identities, sequence gaps and
 * contradictory events all fail closed.
 *
 * HMAC verifies record integrity relative to
 * the supplied key. It does not independently
 * prove that Sendmode received an SMS.
 */
function stTryNowVerifyJournalHistory(
    array $records,
    string $hmacKey
): array {
    $failed = [
        'valid' => false,
        'attempt_state' => 'unknown',
        'provider_evidence' => null,
        'delivered' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];

    if (
        count($records) < 1 ||
        count($records) > 3 ||
        strlen($hmacKey) < 32
    ) {
        return $failed;
    }

    $expectedKeys = [
        'version',
        'attempt_id',
        'customer_id',
        'campaign_id',
        'sequence',
        'event',
        'recorded_at_utc',
        'previous_mac',
        'mac',
    ];

    $previousMac = str_repeat('0', 64);
    $identity = null;
    $events = [];

    foreach ($records as $index => $record) {
        if (
            !is_int($index) ||
            !is_array($record) ||
            array_keys($record) !== $expectedKeys ||
            $record['version'] !== 1 ||
            !is_string($record['attempt_id']) ||
            !is_int($record['customer_id']) ||
            !is_int($record['campaign_id']) ||
            !is_int($record['sequence']) ||
            $record['sequence'] !== $index + 1 ||
            !is_string($record['event']) ||
            !is_string($record['recorded_at_utc']) ||
            !is_string($record['previous_mac']) ||
            !is_string($record['mac'])
        ) {
            return $failed;
        }

        if (!hash_equals(
            $previousMac,
            $record['previous_mac']
        )) {
            return $failed;
        }

        $recordIdentity = [
            $record['attempt_id'],
            $record['customer_id'],
            $record['campaign_id'],
        ];

        if ($identity === null) {
            $identity = $recordIdentity;

        } elseif ($recordIdentity !== $identity) {
            return $failed;
        }

        try {
            $expected = stTryNowBuildJournalRecord(
                $record['attempt_id'],
                $record['customer_id'],
                $record['campaign_id'],
                $record['sequence'],
                $record['event'],
                $record['recorded_at_utc'],
                $record['previous_mac'],
                $hmacKey
            );

        } catch (Throwable $e) {
            return $failed;
        }

        if (!hash_equals(
            $expected['mac'],
            $record['mac']
        )) {
            return $failed;
        }

        $previousMac = $record['mac'];

        $events[] = [
            'event' => $record['event'],
        ];
    }

    // Validate the full event state machine.
    $classification = stTryNowClassifyAttemptHistory(
        $events
    );

    if ($classification['sequence_valid'] !== true) {
        return $failed;
    }

    return [
        'valid' => true,
        'attempt_state' =>
            $classification['attempt_state'],
        'provider_evidence' =>
            $classification['provider_evidence'],
        'delivered' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}
