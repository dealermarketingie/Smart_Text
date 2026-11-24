<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Sendmode SMS preparation.
 *
 * DRY RUN ONLY.
 *
 * No HTTP requests.
 * No Sendmode credentials.
 * No database operations.
 * No real SMS delivery.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-support.php';

function stTryNowPrepareSms(
    string $industry,
    string $mobile,
    string $customerCode
): array {
    // Validate and normalise the recipient.
    $normalised = stTryNowNormalizeMobile($mobile);

    if ($normalised === null) {
        throw new InvalidArgumentException(
            'Invalid mobile number.'
        );
    }

    $digits = preg_replace('/\D/', '', $normalised);

    if (!is_string($digits)) {
        throw new RuntimeException(
            'Unable to normalise mobile number.'
        );
    }

    // Sendmode recipient in international format.
    if (preg_match('/^08[0-9]{8}$/D', $digits)) {
        // Ireland
        $recipient = '353' . substr($digits, 1);
        $optout = 'Optout to 50015';

    } elseif (preg_match('/^07[0-9]{9}$/D', $digits)) {
        // United Kingdom
        $recipient = '44' . substr($digits, 1);
        $optout = 'Optout 07507319809';

    } else {
        throw new InvalidArgumentException(
            'Unsupported mobile number.'
        );
    }


    // Build the personalised sample link.
    // In dry-run mode, this does not require a DB insert.
    $link = stTryNowBuildCustomerLink(
        $industry,
        $customerCode
    );

    // Initial SMS template for testing.
    $message = 'Your Smart Text sample: '
        . $link
        . "\n" . $optout;

    // Prepare the documented Sendmode message structure.
    // No sender ID is assumed until the approved ID is confirmed.
    return [
        'mode' => 'dry-run',
        'sent' => false,
        'provider' => 'sendmode',
        'payload' => [
            'messagetext' => $message,
            'recipients' => [$recipient],
        ],
    ];
}
