<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Isolated MariaDB cleanup test.
 *
 * TEST DATABASE ONLY.
 * Creates and deletes one synthetic customer.
 * No production access, HTTP requests, SMS or email.
 *
 * Run only once.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-transaction.php';
require_once __DIR__ . '/../lib/try-now-cleanup.php';

function testCheck(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function expectProtected(
    PDO $pdo,
    string $name,
    int $customerId,
    int $campaignId,
    string $code,
    string $mobile,
    array $outcome,
    bool $enabled = true
): void {
    $result = stTryNowGuardedCleanup(
        $pdo,
        $customerId,
        $campaignId,
        $code,
        $mobile,
        $outcome,
        $enabled
    );

    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    testCheck(
        $name,
        $result['deleted'] === false
        && in_array(
            $result['status'],
            ['disabled', 'not_eligible'],
            true
        )
        && $count === 3
    );
}

$stage = 'database verification';

try {
    $config = require __DIR__ . '/../db-test.php';

    // Never allow this test to access production.
    if (
        ($config['host'] ?? '') !== '127.0.0.1' ||
        (int) ($config['port'] ?? 0) !== 33080 ||
        ($config['database'] ?? '') !== 'smarttext_try_now_test' ||
        ($config['username'] ?? '') !== 'try_now_test'
    ) {
        throw new RuntimeException('Unsafe database configuration.');
    }

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

    testCheck(
        'Isolated database verified',
        $identity['db'] === 'smarttext_try_now_test'
        && strpos($identity['db_user'], 'try_now_test@') === 0
    );

    testCheck(
        'Initial customer count is 2',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === 2
    );

    $stage = 'synthetic registration';

    $input = [
        'industry' => 'automotive',
        'firstName' => 'Cleanup',
        'lastNameCompany' => 'Test',
        'email' => 'cleanup@example.com',
        'mobile' => '0860000003',
        'consent' => 'on',
        'website' => '',
    ];

    $created = stTryNowRegisterCustomer(
        $pdo,
        $input,
        true
    );

    testCheck(
        'Synthetic customer created',
        ($created['status'] ?? '') === 'created'
        && ($created['customer_id'] ?? 0) > 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === 3
    );

    $customerId = (int) $created['customer_id'];
    $code = $created['code'];
    $mobile = '086 0000003';
    $campaignId = 7907;

    // Synthetic provider outcomes.
    $rejected = [
        'status' => 'rejected',
        'accepted' => false,
        'delivered' => false,
        'provider_code' => 14,
    ];

    $accepted = [
        'status' => 'accepted',
        'accepted' => true,
        'delivered' => false,
        'provider_code' => 0,
    ];

    $unknown = [
        'status' => 'unknown',
        'accepted' => false,
        'delivered' => false,
        'provider_code' => null,
    ];

    $stage = 'cleanup protections';

    // Deletion must be explicitly enabled.
    expectProtected(
        $pdo, 'Deletion disabled',
        $customerId, $campaignId, $code, $mobile,
        $rejected, false
    );

    // Provider outcomes that must preserve the customer.
    foreach ([
        'Accepted SMS protected' => $accepted,
        'Unknown SMS protected' => $unknown,
    ] as $name => $outcome) {
        expectProtected(
            $pdo, $name,
            $customerId, $campaignId, $code, $mobile,
            $outcome
        );
    }

    // Exact-record matching.
    expectProtected(
        $pdo, 'Incorrect customer ID protected',
        $customerId + 100000, $campaignId,
        $code, $mobile, $rejected
    );

    expectProtected(
        $pdo, 'Incorrect campaign protected',
        $customerId, 7908,
        $code, $mobile, $rejected
    );

    // Change the case of the first character.
    $wrongCode = (
        ctype_upper($code[0])
            ? strtolower($code[0])
            : strtoupper($code[0])
    ) . substr($code, 1);

    expectProtected(
        $pdo, 'Case-sensitive code matching',
        $customerId, $campaignId,
        $wrongCode, $mobile, $rejected
    );

    expectProtected(
        $pdo, 'Incorrect mobile protected',
        $customerId, $campaignId,
        $code, '086 0000004', $rejected
    );

    // Existing customer engagement must prevent deletion.
    foreach (['clicked', 'booked', 'called'] as $field) {
        $update = $pdo->prepare(
            "UPDATE customer SET `$field` = ? WHERE id = ?"
        );

        $update->execute([1, $customerId]);

        expectProtected(
            $pdo, "$field engagement protected",
            $customerId, $campaignId,
            $code, $mobile, $rejected
        );

        $update->execute([0, $customerId]);
    }

    // Older records must not qualify.
    $pdo->prepare(
        'UPDATE customer
         SET date_added = CURRENT_TIMESTAMP - INTERVAL 10 MINUTE
         WHERE id = ?'
    )->execute([$customerId]);

    expectProtected(
        $pdo, 'Older customer protected',
        $customerId, $campaignId,
        $code, $mobile, $rejected
    );

    // Restore the test timestamp.
    $pdo->prepare(
        'UPDATE customer
         SET date_added = CURRENT_TIMESTAMP
         WHERE id = ?'
    )->execute([$customerId]);

    $stage = 'explicit rejection cleanup';

    // Only now should the exact synthetic record be removed.
    $result = stTryNowGuardedCleanup(
        $pdo,
        $customerId,
        $campaignId,
        $code,
        $mobile,
        $rejected,
        true
    );

    testCheck(
        'Explicit rejection permits guarded cleanup',
        $result['status'] === 'deleted'
        && $result['deleted'] === true
    );

    testCheck(
        'Customer count restored to 2',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === 2
    );

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM customer WHERE id = ?'
    );
    $stmt->execute([$customerId]);

    testCheck(
        'Only synthetic customer removed',
        (int) $stmt->fetchColumn() === 0
    );

    testCheck(
        'No transaction remains open',
        !$pdo->inTransaction()
    );

    echo "PASS: Isolated MariaDB cleanup test completed.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL: Test stopped during $stage. Do not rerun.\n"
    );
    exit(1);
}
