<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Customer registration preparation.
 *
 * Validates and maps submitted details to the existing
 * customer table. No database writes or SMS operations.
 */

require_once __DIR__ . '/try-now-support.php';

function stTryNowPrepareRegistration(array $post): array
{
    $validation = stTryNowValidateInput($post);

    if (!$validation['ok']) {
        return [
            'ok' => false,
            'errors' => $validation['errors'],
        ];
    }

    $values = $validation['values'];
    $errors = [];

    // Database limits are characters, not bytes.
    $firstLength = preg_match_all('/./us', $values['firstName']);
    $lastLength = preg_match_all('/./us', $values['lastNameCompany']);

    if ($firstLength === false || $firstLength > 45) {
        $errors['firstName'] = 'Maximum 45 characters allowed.';
    }

    if ($lastLength === false || $lastLength > 60) {
        $errors['lastNameCompany'] = 'Maximum 60 characters allowed.';
    }


    // MariaDB customer columns use utf8mb3.
    // Reject supplementary Unicode characters (including emoji).
    foreach (['firstName', 'lastNameCompany'] as $field) {
        if (isset($errors[$field])) {
            continue;
        }

        if (preg_match(
            '/[\x{10000}-\x{10FFFF}]/u',
            $values[$field]
        ) !== 0) {
            $errors[$field] =
                'Please remove emoji or unsupported characters.';
        }
    }


    if ($errors !== []) {
        return [
            'ok' => false,
            'errors' => $errors,
        ];
    }

    return [
        'ok' => true,
        'errors' => [],
        'customer' => [
            'first_name' => $values['firstName'],
            'last_name' => $values['lastNameCompany'],
            'mobile' => $validation['normalised_mobile'],
            'campaign_id' => (int) $validation['sample']['campaign_id'],
        ],
        // Retained in the request result for the future
        // lead-recording workflow; not a customer table column.
        'email' => $values['email'],
        'industry' => $values['industry'],
    ];
}
