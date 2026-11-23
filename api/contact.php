<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
session_name('smarttext_contact');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);

function respond(int $status, string $message, array $extra = []): never {
    http_response_code($status);
    echo json_encode(['ok' => $status === 200, 'message' => $message] + $extra);
    exit;
}

$_SESSION['contact_token'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, '', ['token' => $_SESSION['contact_token']]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    respond(405, 'Method not allowed.');
}
if (!hash_equals($_SESSION['contact_token'], $_SERVER['HTTP_X_CONTACT_TOKEN'] ?? '')) {
    respond(403, 'Please refresh the page and try again.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
    respond(413, 'Your message is too long.');
}
$data = [];
foreach (['firstName' => 100, 'lastName' => 100, 'company' => 200, 'phone' => 50,
    'email' => 254, 'notes' => 5000, 'consent' => 10, 'website' => 200] as $key => $limit) {
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || mb_strlen($value) > $limit || str_contains($value, "\0")) {
        respond(422, 'Please check your details and shorten any long entries.');
    }
    $data[$key] = trim($value);
}
if ($data['website'] !== '') respond(422, 'Unable to submit this form.');
foreach (['firstName', 'lastName', 'company', 'phone', 'email'] as $key) {
    if ($data[$key] === '') respond(422, 'Please complete all required fields.');
}
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $data['email'])) {
    respond(422, 'Please enter a valid email address.');
}
if (strlen(preg_replace('/\D/', '', $data['phone'])) < 7) respond(422, 'Please enter a valid phone number.');
if ($data['consent'] !== 'on') respond(422, 'Please confirm your consent to continue.');
if (time() - ($_SESSION['contact_attempt'] ?? 0) < 30) {
    header('Retry-After: 30');
    respond(429, 'Please wait 30 seconds before trying again.');
}

$configPath = dirname(__DIR__) . '/config/mail.local.php';
if (!is_file($configPath)) respond(503, 'The contact form is temporarily unavailable. Please call +353 (0)1 907 3288.');
try {
    $config = require $configPath;
    $transport = $config['transport'] ?? 'smtp';
    if (!in_array($transport, ['smtp', 'mailpit'], true)) throw new RuntimeException('Invalid mail transport');
    $isMailpit = $transport === 'mailpit';
    if ($isMailpit) {
        // Local capture only: never send production credentials to the test server.
        $config = array_replace($config, [
            'host' => '127.0.0.1', 'port' => 1025, 'encryption' => '',
            'username' => '', 'password' => '', 'ca_file' => '',
            'from_email' => $config['mailpit_from_email'] ?? 'hello@example.com',
            'from_name' => $config['mailpit_from_name'] ?? 'Smart Text',
        ]);
    }
    foreach ($isMailpit ? ['from_email', 'to_email'] : ['host', 'username', 'password', 'from_email', 'to_email'] as $key) {
        if (empty($config[$key])) throw new RuntimeException('Missing configuration');
    }
    if ((!$isMailpit && !in_array($config['encryption'] ?? '', ['tls', 'ssl'], true))
        || !filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
        throw new RuntimeException('Invalid transport configuration');
    }
    require_once dirname(__DIR__) . '/lib/phpmailer/Exception.php';
    require_once dirname(__DIR__) . '/lib/phpmailer/PHPMailer.php';
    require_once dirname(__DIR__) . '/lib/phpmailer/SMTP.php';
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->Port = (int) $config['port'];
    $mail->SMTPSecure = $config['encryption'];
    if (!empty($config['ca_file'])) {
        if (!is_readable($config['ca_file'])) throw new RuntimeException('Missing CA bundle');
        $mail->SMTPOptions = ['ssl' => [
            'cafile' => $config['ca_file'],
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]];
    }
    $mail->SMTPAuth = !$isMailpit;
    $mail->SMTPAutoTLS = !$isMailpit;
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->Timeout = 15;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($config['from_email'], $config['from_name'] ?? 'Smart Text');
    $mail->addAddress($config['to_email']);
    $mail->addReplyTo($data['email'], $data['firstName'] . ' ' . $data['lastName']);
    $mail->Subject = 'Smart Text website: demo enquiry';
    $lines = ['New Smart Text website enquiry', ''];
    foreach (['firstName' => 'First name', 'lastName' => 'Last name', 'company' => 'Company',
        'phone' => 'Phone', 'email' => 'Email', 'notes' => 'Notes'] as $key => $label) {
        $lines[] = $label . ': ' . $data[$key];
    }
    $lines[] = 'Consent: agreed to be contacted about Smart Text and receive further information.';
    $lines[] = 'Submitted at: ' . gmdate('c');
    $mail->Body = implode("\n", $lines);
    $_SESSION['contact_attempt'] = time();
    $mail->send();
    respond(200, 'Thanks, your request is on its way.');
} catch (Throwable $error) {
    // Do not expose credentials, SMTP transcripts, or visitor details in responses/logs.
    error_log('Smart Text contact: SMTP delivery or configuration failed.');
    respond(503, 'We could not send your enquiry. Please try again later or call +353 (0)1 907 3288.');
}
