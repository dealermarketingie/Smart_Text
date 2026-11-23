<?php
// Copy to mail.local.php. Never commit the local file or place backup copies in the website.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
return [
    'transport' => 'smtp', // Set to 'mailpit' locally: fixed 127.0.0.1:1025, no authentication or TLS
    'mailpit_from_email' => 'hello@example.com',
    'mailpit_from_name' => 'Smart Text',
    'host' => '',
    'port' => 465,
    'encryption' => 'ssl', // tls (STARTTLS, usually 587) or ssl (SMTPS, usually 465)
    'ca_file' => '', // Optional local trusted CA bundle; leave blank on normal production hosting
    'username' => '',
    'password' => '',
    'from_email' => '', // Address authorised by your SMTP provider
    'from_name' => 'Smart Text',
    'to_email' => '', // Inbox that receives enquiries
];
