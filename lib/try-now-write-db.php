<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Production write connection.
 *
 * DISABLED BY DEFAULT.
 *
 * Uses separate, least-privilege credentials.
 * Never uses the production read-only account.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-runtime.php';

/**
 * Validate production write configuration without
 * opening a database connection.
 *
 * Pure function, suitable for automated tests.
 */
function stTryNowWriteConfigValid(array $config): bool
{
    foreach (
        ['host', 'database', 'username', 'password', 'charset']
        as $key
    ) {
        if (
            !isset($config[$key]) ||
            !is_string($config[$key]) ||
            $config[$key] === ''
        ) {
            return false;
        }
    }

    return
        $config['database'] === 'smarttext'
        && $config['username'] === 'smarttext_try_now_writer'
        && in_array(
            $config['host'],
            ['localhost', '127.0.0.1'],
            true
        )
        && in_array(
            $config['charset'],
            ['utf8mb3', 'utf8mb4'],
            true
        );
}

function stTryNowProductionWritePdo(): PDO
{
    // No credential access or database connection
    // unless all production delivery gates are enabled.
    if (!stTryNowProductionDeliveryEnabled()) {
        throw new RuntimeException(
            'Production Try Now writes are disabled.'
        );
    }

    // Separate private credentials, outside httpdocs.
    // This file must NOT be created until approved.
    $configPath = dirname(__DIR__, 2)
        . '/private/try-now-write-db.php';

    if (!is_file($configPath) ||
        !is_readable($configPath)) {
        throw new RuntimeException(
            'Production write configuration is unavailable.'
        );
    }

    $config = require $configPath;

    if (
        !is_array($config) ||
        !stTryNowWriteConfigValid($config)
    ) {
        throw new RuntimeException(
            'Invalid production write configuration.'
        );
    }

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['database'],
            $config['charset']
        ),
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    // Verify the identity actually authenticated by MariaDB.
    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($identity) ||
        ($identity['db'] ?? null) !== 'smarttext' ||
        !is_string($identity['db_user'] ?? null) ||
        strpos(
            $identity['db_user'],
            'smarttext_try_now_writer@'
        ) !== 0
    ) {
        throw new RuntimeException(
            'Unexpected production write connection identity.'
        );
    }

    return $pdo;
}
