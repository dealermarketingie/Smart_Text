<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Isolated MariaDB registration test.
 *
 * Runs from the private Plesk test directory only.
 * Never connects to the production database.
 * No SMS or email operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-transaction.php';

function testAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo "PASS: $message\n";
}

try {
    $config = require __DIR__ . '/../db-test.php';

    // Hard stop if the test connection is not exactly as expected.
    testAssert(
        $config['host'] === '127.0.0.1'
        && (int) $config['port'] === 33080
        && $config['database'] === 'smarttext_try_now_test'
        && $config['username'] === 'try_now_test',
        'Isolated database configuration'
    );

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
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

    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch();

    testAssert(
        $identity['db'] === 'smarttext_try_now_test'
        && strpos($identity['db_user'], 'try_now_test@') === 0,
        'Connected to isolated MariaDB'
    );

    $campaignCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM campaign'
    )->fetchColumn();

    $customerCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    testAssert(
        $campaignCount === 6 && $customerCount === 0,
        'Test database contains six campaigns and no customers'
    );

    // Synthetic registration details.
    $input = [
        'industry' => 'automotive',
        'firstName' => 'Seán',
        'lastNameCompany' => 'Test Customer',
        'email' => 'registration@example.com',
        'mobile' => '089 000 0000',
        'consent' => 'on',
        'website' => '',
    ];

    // This is the ONLY test that permits an INSERT.
    // The connection has already been verified as isolated.
    $result = stTryNowRegisterCustomer(
        $pdo,
        $input,
        true
    );

    testAssert(
        $result['status'] === 'created',
        'Customer registration succeeded'
    );

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM customer
         WHERE campaign_id = ?
           AND code = ?
           AND first_name = ?
           AND last_name = ?
           AND mobile = ?'
    );

    $stmt->execute([
        7907,
        $result['code'],
        'Seán',
        'Test Customer',
        '089 0000000',
    ]);

    testAssert(
        (int) $stmt->fetchColumn() === 1,
        'Correct customer data stored'
    );

    testAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === 1,
        'Exactly one customer created'
    );

    testAssert(
        preg_match(
            '~^https://stxt\.me/LJfjMU/[A-Za-z][A-Za-z0-9]{5}7907$~D',
            $result['link']
        ) === 1,
        'Personalised link generated correctly'
    );

    echo "PASS: Isolated MariaDB registration test completed.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
