<?php
declare(strict_types=1);

/**
 * Test rollback after a MariaDB INSERT failure.
 * Isolated test database only. No SMS or email.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-transaction.php';

$config = require __DIR__ . '/../db-test.php';

if (
    $config['host'] !== '127.0.0.1' ||
    (int) $config['port'] !== 33080 ||
    $config['database'] !== 'smarttext_try_now_test' ||
    $config['username'] !== 'try_now_test'
) {
    fwrite(STDERR, "FAIL: Unsafe database configuration.\n");
    exit(1);
}

$pdo = null;
$triggerCreated = false;
$passed = false;

try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=33080;'
        . 'dbname=smarttext_try_now_test;charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch();

    if (
        $identity['db'] !== 'smarttext_try_now_test' ||
        strpos($identity['db_user'], 'try_now_test@') !== 0
    ) {
        throw new RuntimeException('Database identity mismatch.');
    }

    $before = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    if ($before !== 1) {
        throw new RuntimeException(
            'Expected exactly one existing test customer.'
        );
    }

    echo "PASS: Isolated database verified.\n";

    // Temporary trigger forces INSERT failure.
    $pdo->exec("
        CREATE TRIGGER st_try_now_rollback_test
        BEFORE INSERT ON customer
        FOR EACH ROW
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Synthetic rollback test failure'
    ");

    $triggerCreated = true;

    $input = [
        'industry' => 'automotive',
        'firstName' => 'Rollback',
        'lastNameCompany' => 'Test',
        'email' => 'rollback@example.com',
        'mobile' => '0860000001',
        'consent' => 'on',
        'website' => '',
    ];

    $failureCaught = false;

    try {
        stTryNowRegisterCustomer($pdo, $input, true);
    } catch (PDOException $e) {
        $failureCaught = (
            ($e->errorInfo[0] ?? '') === '45000'
        );
    }

    if (!$failureCaught) {
        throw new RuntimeException(
            'Expected INSERT failure was not detected.'
        );
    }

    echo "PASS: Database INSERT failure detected.\n";

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Transaction remained open after failure.'
        );
    }

    echo "PASS: Transaction rolled back.\n";

    $after = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    if ($after !== $before) {
        throw new RuntimeException(
            'Customer count changed after failed INSERT.'
        );
    }

    echo "PASS: Customer count remains unchanged.\n";

    $passed = true;

} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");

} finally {
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($triggerCreated) {
            try {
                $pdo->exec(
                    'DROP TRIGGER st_try_now_rollback_test'
                );

                echo "PASS: Temporary trigger removed.\n";
            } catch (Throwable $e) {
                fwrite(
                    STDERR,
                    "FAIL: Temporary trigger cleanup failed.\n"
                );
                $passed = false;
            }
        }
    }
}

if ($passed) {
    echo "PASS: Transaction rollback test completed.\n";
}

exit($passed ? 0 : 1);
