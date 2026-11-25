<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Trusted visitor IP.
 *
 * Uses only the address established by the web server.
 * Never trusts visitor-supplied forwarding headers.
 *
 * No database, Redis or SMS operations.
 * PHP 7.4 compatible.
 */

function stTryNowClientIp(array $server): string
{
    $ip = $server['REMOTE_ADDR'] ?? null;

    if (
        !is_string($ip) ||
        filter_var($ip, FILTER_VALIDATE_IP) === false
    ) {
        // The future API must fail closed.
        throw new RuntimeException(
            'Unable to determine visitor IP address.'
        );
    }

    return $ip;
}
