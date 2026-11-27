<?php
declare(strict_types=1);

/**
 * Dedicated, least-privilege PDO connection for Try Now registrations.
 * The credentials stay in /var/www/vhosts/smarttext.com/private/.
 * PHP 7.4+ compatible. No writes are performed here.
 */
function stTryNowWritePdo(): PDO
{
    // Works for both the site/lib and try-now-check/lib directories.
    $path = dirname(__DIR__, 2) . '/private/try-now-write-db.php';

    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        throw new RuntimeException('Try Now write DB configuration unavailable.');
    }

    $config = require $path;

    if (!is_array($config) ||
        ($config['host'] ?? null) !== 'localhost' ||
        ($config['database'] ?? null) !== 'smarttext' ||
        ($config['username'] ?? null) !== 'smarttext_trynow' ||
        ($config['charset'] ?? null) !== 'utf8mb4' ||
        !is_string($config['password'] ?? null) ||
        $config['password'] === '') {
        throw new RuntimeException('Unexpected Try Now write DB configuration.');
    }

    $pdo = new PDO(
        'mysql:host=localhost;dbname=smarttext;charset=utf8mb4',
        'smarttext_trynow',
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    // Check the identity returned by the database rather than trusting config.
    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($identity) ||
        ($identity['db'] ?? null) !== 'smarttext' ||
        !is_string($identity['db_user'] ?? null) ||
        strpos($identity['db_user'], 'smarttext_trynow@') !== 0) {
        throw new RuntimeException('Unexpected Try Now write DB identity.');
    }

    return $pdo;
}
