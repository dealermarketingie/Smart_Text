<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Synthetic registration sequencing.
 *
 * CLI TESTING ONLY.
 *
 * Verifies that registration is attempted only after
 * successful admission, and that SMS preparation
 * follows successful registration.
 *
 * Do not use for production delivery.
 * No database, Redis or SMS operations are implemented.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-registration-preflight.php';

/**
 * Execute controlled test callbacks in the correct order.
 *
 * The callbacks must be synthetic test doubles.
 * This function is NOT a production registration executor.
 */
function stTryNowRunSyntheticRegistrationSequence(
    array $preflight,
    callable $register,
    callable $prepareSms,
    bool $allowCliTest = false
): array {
    $result = [
        'status' => 'disabled',
        'registration_callback_called' => false,
        'sms_preparation_callback_called' => false,
        'sms_sent' => false,
        'automatic_retry' => false,
    ];

    /*
     * Never execute callbacks from a web request.
     *
     * Require explicit CLI test authorisation and
     * prevent this test helper from running when
     * production delivery has been enabled.
     */
    if (
        PHP_SAPI !== 'cli' ||
        !$allowCliTest ||
        stTryNowProductionDeliveryEnabled()
    ) {
        return $result;
    }

    /*
     * Only a complete, explicitly admitted
     * preflight decision may continue.
     */
    if (
        ($preflight['status'] ?? null) !== 'admitted' ||
        ($preflight['admitted'] ?? null) !== true ||
        !array_key_exists('http_failure', $preflight) ||
        $preflight['http_failure'] !== null
    ) {
        $result['status'] = 'blocked';
        return $result;
    }

    /*
     * Synthetic registration.
     *
     * Any exception or unsuccessful result prevents
     * the SMS preparation callback from running.
     */
    $result['registration_callback_called'] = true;

    try {
        $registration = $register();
    } catch (Throwable $e) {
        $result['status'] = 'registration_failed';
        return $result;
    }

    if (
        !is_array($registration) ||
        ($registration['status'] ?? null) !== 'created' ||
        !is_int($registration['customer_id'] ?? null) ||
        $registration['customer_id'] <= 0
    ) {
        $result['status'] = 'registration_failed';
        return $result;
    }

    /*
     * Synthetic SMS preparation only.
     *
     * No Sendmode transport is invoked here.
     * Failed preparation never triggers a retry.
     */
    $result['sms_preparation_callback_called'] = true;

    try {
        $prepared = $prepareSms($registration);
    } catch (Throwable $e) {
        $result['status'] = 'review_required';
        return $result;
    }

    if ($prepared !== true) {
        $result['status'] = 'review_required';
        return $result;
    }

    $result['status'] = 'synthetic_prepared';

    return $result;
}
