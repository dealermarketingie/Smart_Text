<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Sendmode REST request preparation.
 *
 * DRY RUN ONLY.
 * Does not connect to Sendmode or use API credentials.
 * Does not write to a database.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-sms.php';

function stTryNowBuildSendmodeRequest(
    array $preparedSms
): array {
    // Accept only a validated dry-run SMS preparation.
    if (
        ($preparedSms['mode'] ?? '') !== 'dry-run' ||
        ($preparedSms['sent'] ?? null) !== false ||
        ($preparedSms['provider'] ?? '') !== 'sendmode'
    ) {
        throw new InvalidArgumentException(
            'Expected a Sendmode dry-run payload.'
        );
    }

    $payload = $preparedSms['payload'] ?? null;

    if (
        !is_array($payload) ||
        !isset($payload['messagetext'], $payload['recipients']) ||
        !is_string($payload['messagetext']) ||
        !is_array($payload['recipients']) ||
        count($payload['recipients']) !== 1 ||
        !is_string($payload['recipients'][0])
    ) {
        throw new InvalidArgumentException(
            'Invalid Sendmode message payload.'
        );
    }

    $recipient = $payload['recipients'][0];

    if (
        !preg_match(
            '/^(3538[0-9]{8}|447[0-9]{9})$/D',
            $recipient
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid international recipient.'
        );
    }

    // Ensure country-specific opt-out instructions.
    $expectedOptout = strpos($recipient, '353') === 0
        ? "\nOptout to 50015"
        : "\nOptout 07507319809";

    $message = $payload['messagetext'];

    if (
        substr($message, -strlen($expectedOptout))
        !== $expectedOptout
    ) {
        throw new InvalidArgumentException(
            'Missing country-specific opt-out.'
        );
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR
    );

    // Sendmode expects a form-encoded "message" field.
    $body = http_build_query(
        ['message' => $json],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    return [
        'mode' => 'dry-run',
        'sent' => false,
        'method' => 'POST',
        'url' => 'https://rest.sendmode.com/v2/send',
        'content_type' => 'application/x-www-form-urlencoded',
        'body' => $body,
    ];
}
