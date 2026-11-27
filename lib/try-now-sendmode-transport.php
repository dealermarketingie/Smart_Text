<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Sendmode HTTP transport.
 *
 * LIVE DELIVERY HARD-DISABLED.
 * Not connected to the public Try Now API.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-sendmode.php';
require_once __DIR__ . '/try-now-sendmode-response.php';

// Do not change until SMS copy, sender ID and
// production safeguards have been approved.
const ST_TRY_NOW_SENDMODE_LIVE_ENABLED = false;

function stTryNowSendmodeTransport(
    array $request,
    string $accessKey = '',
    string $senderId = ''
): array {
    // Hard safety gate. No HTTP request can occur.
    if (!ST_TRY_NOW_SENDMODE_LIVE_ENABLED) {
        return [
            'status' => 'disabled',
            'accepted' => false,
            'delivered' => false,
            'provider_code' => null,
        ];
    }

    // Validate configuration without exposing credentials.
    if (
        $accessKey === '' ||
        preg_match('/[\r\n]/', $accessKey) ||
        !preg_match('/^[A-Za-z0-9 ]{1,11}$/D', $senderId) ||
        trim($senderId) === ''
    ) {
        throw new RuntimeException(
            'Invalid Sendmode configuration.'
        );
    }

    // Fixed endpoint: reject arbitrary destinations.
    if (
        ($request['mode'] ?? '') !== 'dry-run' ||
        ($request['sent'] ?? null) !== false ||
        ($request['method'] ?? '') !== 'POST' ||
        ($request['url'] ?? '') !==
            'https://rest.sendmode.com/v2/send' ||
        ($request['content_type'] ?? '') !==
            'application/x-www-form-urlencoded' ||
        !isset($request['body']) ||
        !is_string($request['body'])
    ) {
        throw new InvalidArgumentException(
            'Invalid Sendmode request.'
        );
    }

    parse_str($request['body'], $form);

    if (
        count($form) !== 1 ||
        !isset($form['message']) ||
        !is_string($form['message'])
    ) {
        throw new InvalidArgumentException(
            'Invalid Sendmode request body.'
        );
    }

    $payload = json_decode(
        $form['message'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    // Recheck recipient and country-specific opt-out.
    $verified = stTryNowBuildSendmodeRequest([
        'mode' => 'dry-run',
        'sent' => false,
        'provider' => 'sendmode',
        'payload' => $payload,
    ]);

    if (!hash_equals($verified['body'], $request['body'])) {
        throw new InvalidArgumentException(
            'Sendmode request validation failed.'
        );
    }

    // Add the approved sender ID.
    $payload['senderid'] = $senderId;

    $body = http_build_query([
        'message' => json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ),
    ], '', '&', PHP_QUERY_RFC3986);

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL extension is unavailable.'
        );
    }

    $ch = curl_init('https://rest.sendmode.com/v2/send');

    if ($ch === false) {
        throw new RuntimeException(
            'Unable to initialise SMS transport.'
        );
    }

    try {
        $configured = curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: ' . $accessKey,
            ],
        ]);

        if (!$configured) {
            throw new RuntimeException(
                'Unable to configure SMS transport.'
            );
        }

        $response = curl_exec($ch);
        $httpStatus = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    } finally {
        curl_close($ch);
    }

    // A transport failure may still have reached Sendmode.
    // Do not automatically retry.
    if ($response === false) {
        return [
            'status' => 'unknown',
            'accepted' => false,
            'delivered' => false,
            'provider_code' => null,
        ];
    }

    return stTryNowParseSendmodeResponse(
        $httpStatus,
        $response
    );
}
