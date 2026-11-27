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

    // Fail closed if the private configuration points
    // to anything other than our approved read-only account.
    if (
        $config['database'] !== 'smarttext' ||
        $config['username'] !== 'smarttext_readonly'
    ) {
        throw new RuntimeException(
            'Unexpected read-only database configuration.'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO(
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

    // Confirm the identity reported by MariaDB itself.
    // This performs a metadata-only SELECT.
    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($identity) ||
        ($identity['db'] ?? null) !== 'smarttext' ||
        !is_string($identity['db_user'] ?? null) ||
        strpos(
            $identity['db_user'],
            'smarttext_readonly@'
        ) !== 0
    ) {
        throw new RuntimeException(
            'Unexpected read-only database connection identity.'
        );
    }

    return $pdo;
}
