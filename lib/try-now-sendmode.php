<?php
declare(strict_types=1);
// LOCAL TEST COPY. DO NOT PACKAGE.
require_once __DIR__ . '/try-now-sms.php';
function stTryNowBuildSendmodeRequest(array $preparedSms): array {
    if (($preparedSms['mode'] ?? '') !== 'dry-run' || ($preparedSms['sent'] ?? null) !== false || ($preparedSms['provider'] ?? '') !== 'sendmode') throw new InvalidArgumentException('Expected dry run');
    $payload = $preparedSms['payload'] ?? null;
    if (!is_array($payload) || !isset($payload['messagetext'], $payload['recipients']) || !is_string($payload['messagetext']) || !is_array($payload['recipients']) || count($payload['recipients']) !== 1 || !is_string($payload['recipients'][0])) throw new InvalidArgumentException('Invalid payload');
    $recipient = $payload['recipients'][0];
    if (!preg_match('/^(3538[0-9]{8}|447[0-9]{9})$/D', $recipient)) throw new InvalidArgumentException('Invalid recipient');
    $expectedOptout = strpos($recipient, '353') === 0 ? "\nOptout to 50015" : "\nOptout 07507319809";
    $message = $payload['messagetext'];

    // Final safety check: never forward UCS-2 or unnormalised text.
    if (!stTryNowSmsIsGsm7Safe($message)) {
        throw new InvalidArgumentException(
            'Non GSM-7 character in Sendmode SMS payload.'
        );
    }
    if (substr($message, -strlen($expectedOptout)) !== $expectedOptout) throw new InvalidArgumentException('Missing country-specific optout');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return ['mode'=>'dry-run', 'sent'=>false, 'method'=>'POST', 'url'=>'https://rest.sendmode.com/v2/send', 'content_type'=>'application/x-www-form-urlencoded', 'body'=>http_build_query(['message'=>$json], '', '&', PHP_QUERY_RFC3986)];
}
