<?php
/**
 * Smart Text Try Now API.
 * Simulation remains the default. Live sending is allowed only when ALL
 * independent server-side gates are explicitly enabled, and Redis admission,
 * customer registration and durable SMS attempt tracking succeed.
 * PHP 7.4+ compatible.
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
require_once dirname(__DIR__) . '/lib/try-now-admission-http.php';

function stTryNowReply(int $status, string $message, array $extras = []): void
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $status >= 200 && $status < 300, 'message' => $message] + $extras,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    if (empty($_SESSION['try_now_token'])) {
        $_SESSION['try_now_token'] = bin2hex(random_bytes(32));
    }
    stTryNowReply(200, '', [
        'token' => $_SESSION['try_now_token'],
        'mode' => stTryNowProductionDeliveryEnabled() ? 'live' : 'simulation',
    ]);
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
 * Reject a partially enabled configuration. Turning on only one of the
 * production switches must not silently use the simulation path.
 */
$someLiveGateEnabled = ST_TRY_NOW_PUBLIC_LIVE_ENABLED ||
    ST_TRY_NOW_PRODUCTION_WRITES_ENABLED ||
    ST_TRY_NOW_SMS_COPY_APPROVED ||
    ST_TRY_NOW_SENDMODE_LIVE_ENABLED;

if ($someLiveGateEnabled && !stTryNowProductionDeliveryEnabled()) {
    stTryNowReply(503, 'Try Now is temporarily unavailable.', [
        'mode' => 'unavailable', 'sent' => false,
    ]);
}

if (stTryNowProductionDeliveryEnabled()) {
    // Registration-specific validation before consuming Redis allowances.
    require_once dirname(__DIR__) . '/lib/try-now-registration.php';
    $registration = stTryNowPrepareRegistration($_POST);
    if (($registration['ok'] ?? null) !== true) {
        stTryNowReply(422, 'Please check your details.', [
            'errors' => $registration['errors'] ?? [],
        ]);
    }

    // Server-controlled, atomic Redis limits. On any failure, stop here.
    $admission = stTryNowEvaluateLiveAdmission(
        function () use ($validation): array {
            return stTryNowCheckLiveRateLimit($_SERVER, $validation);
        }
    );
    if (($admission['proceed'] ?? null) !== true) {
        stTryNowSendAdmissionHttpFailure($admission);
    }

    require_once dirname(__DIR__) . '/lib/try-now-live-delivery.php';
    $result = stTryNowExecuteLiveRequest($_POST);
    stTryNowReply($result['http_status'], $result['message'], $result['extras']);
}

// SIMULATION ONLY: no DB writes, Redis consumption, SMS or email.
$admission = stTryNowCheckLiveRateLimit($_SERVER, $validation);
if (($admission['status'] ?? null) !== 'disabled' ||
    ($admission['allowed'] ?? null) !== false) {
    stTryNowSendAdmissionHttpFailure(stTryNowAdmissionUnavailableOutcome());
}

stTryNowReply(200, 'Sample details validated in simulation mode. No SMS was sent.', [
    'mode' => 'simulation',
    'sent' => false,
    'sample' => $validation['sample']['sample_name'],
]);
