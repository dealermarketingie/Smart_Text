<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Read-only database connection.
 *
 * Uses the private configuration outside httpdocs.
 * Does not insert, update, or delete any records.
 */
function stTryNowReadOnlyPdo(): PDO
{
    $configPath = dirname(__DIR__, 2) . '/private/db.php';

    if (!is_readable($configPath)) {
        throw new RuntimeException(
            'Private database configuration is unavailable.'
        );
    }

    $config = require $configPath;

    if (!is_array($config)) {
        throw new RuntimeException(
            'Invalid database configuration.'
        );
    }

    foreach (['host', 'database', 'username', 'password', 'charset'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException(
                'Incomplete database configuration.'
            );
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset']
    );

    return new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
}
