<?php
/**
 * Try Now API — VALIDATION SIMULATION ONLY.
 * Deliberately has no DB credentials, MySQL calls, network sends or mail calls.
 * Not suitable for live sample delivery until backend stage is completed.
 * PHP 7.4-compatible.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('smarttext_try_now');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => $https,
    'use_strict_mode' => true,
]);

require_once dirname(__DIR__) . '/lib/try-now-support.php';
require_once dirname(__DIR__) . '/lib/try-now-runtime.php';
require_once dirname(__DIR__) . '/lib/try-now-request-guard.php';

function stTryNowReply(int $status, string $message, array $extras = []): void
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'message' => $message] + $extras,
                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    if (empty($_SESSION['try_now_token'])) {
        $_SESSION['try_now_token'] = bin2hex(random_bytes(32));
    }
    stTryNowReply(200, '', ['token' => $_SESSION['try_now_token'], 'mode' => 'simulation']);
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    stTryNowReply(405, 'Unsupported request method.');
}

if (empty($_SESSION['try_now_token']) ||
    !hash_equals($_SESSION['try_now_token'], (string) ($_SERVER['HTTP_X_TRY_NOW_TOKEN'] ?? ''))) {
    stTryNowReply(403, 'Request validation failed. Refresh the form and try again.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
    stTryNowReply(413, 'Request is too large.');
}

$validation = stTryNowValidateInput($_POST);
if (!$validation['ok']) {
    stTryNowReply(422, 'Please check your details.', ['errors' => $validation['errors']]);
}

/*
 * Production delivery has not been integrated yet.
 *
 * Even if every runtime switch is enabled, fail closed
 * rather than accidentally activating a partial workflow.
 *
 * This guard will be replaced only when the complete
 * production workflow has been implemented and tested.
 */
if (stTryNowProductionDeliveryEnabled()) {
    stTryNowReply(
        503,
        'Live sample delivery is not yet available.',
        [
            'mode' => 'unavailable',
            'sent' => false,
        ]
    );
}

/*
 * Verify the admission guard remains disabled
 * while this API operates in simulation mode.
 *
 * The preceding HTTP 503 guard ensures an
 * incomplete live workflow never consumes
 * Redis allowances.
 */
try {
    $admission = stTryNowCheckLiveRateLimit(
        $_SERVER,
        $validation
    );

    if (
        ($admission['status'] ?? null) !== 'disabled' ||
        ($admission['allowed'] ?? null) !== false
    ) {
        throw new RuntimeException(
            'Unexpected Try Now admission state.'
        );
    }

} catch (Throwable $e) {
    // Fail closed without revealing internal details.
    stTryNowReply(
        503,
        'Try Now is temporarily unavailable.',
        [
            'mode' => 'unavailable',
            'sent' => false,
        ]
    );
}

// IMPORTANT: No insert, no duplicate lookup, no customer link sent, no Sendmode
// request, no campaign counter increment and no email notification here.
// Full delivery requires the next controlled implementation stage.
stTryNowReply(200, 'Sample details validated in simulation mode. No SMS was sent.', [
    'mode' => 'simulation',
    'sent' => false,
    'sample' => $validation['sample']['sample_name'],
]);
