<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Isolated workflow integration test.
 *
 * TEST DATABASE ONLY.
 * Simulated SMS outcomes; no real SMS or email.
 * PHP 7.4 compatible.
 *
 * Run only once. Do not automatically rerun after a failure.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-workflow.php';

function workflowCheck(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function workflowCount(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();
}

function workflowCustomerExists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM customer WHERE id = ?'
    );

    $stmt->execute([$id]);

    return (int) $stmt->fetchColumn() === 1;
}

function workflowForm(string $mobile): array
{
    return [
        'industry' => 'automotive',
        'firstName' => 'Workflow',
        'lastNameCompany' => 'Test',
        'email' => 'workflow@example.com',
        'mobile' => $mobile,
        'consent' => 'on',
        'website' => '',
    ];
}

$accepted = [
    'status' => 'accepted',
    'accepted' => true,
    'delivered' => false,
    'provider_code' => 0,
];

$rejected = [
    'status' => 'rejected',
    'accepted' => false,
    'delivered' => false,
    'provider_code' => 14,
];

$unknown = [
    'status' => 'unknown',
    'accepted' => false,
    'delivered' => false,
    'provider_code' => null,
];

$disabled = [
    'status' => 'disabled',
    'accepted' => false,
    'delivered' => false,
    'provider_code' => null,
];

$stage = 'database verification';

try {
    $config = require __DIR__ . '/../db-test.php';

    // Check configuration before connecting.
    if (
        ($config['host'] ?? '') !== '127.0.0.1' ||
        (int) ($config['port'] ?? 0) !== 33080 ||
        ($config['database'] ?? '') !== 'smarttext_try_now_test' ||
        ($config['username'] ?? '') !== 'try_now_test'
    ) {
        throw new RuntimeException(
            'Unsafe database configuration.'
        );
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

    workflowCheck(
        'Isolated database verified',
        $identity['db'] === 'smarttext_try_now_test'
        && strpos($identity['db_user'], 'try_now_test@') === 0
    );

    workflowCheck(
        'Initial customer count is 2',
        workflowCount($pdo) === 2
    );

    $stage = 'disabled and validation tests';

    $result = stTryNowRunIsolatedWorkflow(
        $pdo,
        workflowForm('0860000041'),
        $accepted
    );

    workflowCheck(
        'Workflow disabled by default',
        $result['status'] === 'disabled'
        && workflowCount($pdo) === 2
    );

    $result = stTryNowRunIsolatedWorkflow(
        $pdo,
        workflowForm('0860000041'),
        $disabled,
        true
    );

    workflowCheck(
        'Disabled SMS prevents registration',
        $result['status'] === 'disabled'
        && workflowCount($pdo) === 2
    );

    $invalid = workflowForm('0860000041');
    $invalid['consent'] = '';

    $result = stTryNowRunIsolatedWorkflow(
        $pdo,
        $invalid,
        $accepted,
        true
    );

    workflowCheck(
        'Missing consent prevents registration',
        $result['status'] === 'invalid'
        && workflowCount($pdo) === 2
    );

    $stage = 'accepted outcome';

    $result = stTryNowRunIsolatedWorkflow(
        $pdo,
        workflowForm('0860000041'),
        $accepted,
        true
    );

    $acceptedId = (int) ($result['customer_id'] ?? 0);

    workflowCheck(
        'Accepted outcome creates customer',
        $result['status'] === 'simulated_accepted'
        && $acceptedId > 0
        && workflowCount($pdo) === 3
        && workflowCustomerExists($pdo, $acceptedId)
    );

    workflowCheck(
        'Simulated acceptance sends no SMS',
        $result['sms_sent'] === false
        && $result['automatic_retry'] === false
    );

    $stage = 'duplicate protection';

    // Same number in international format.
    $result = stTryNowRunIsolatedWorkflow(
        $pdo,
        workflowForm('+353860000041'),
        $accepted,
        true
    );

    workflowCheck(
        'Duplicate registration prevented',
        $result['status'] === 'duplicate'
        && workflowCount($pdo) === 3
    );

    $stage = 'explicit rejection';

    $result = stTryNowRunIsolatedWorkflow(
        $pdo,
        workflowForm('0860000042'),
        $rejected,
        true
    );

    $rejectedId = (int) ($result['customer_id'] ?? 0);

    workflowCheck(
        'Rejected SMS triggers guarded cleanup',
        $result['status'] === 'simulated_rejected_cleaned'
        && ($result['customer_deleted'] ?? false) === true
        && $rejectedId > 0
        && !workflowCustomerExists($pdo, $rejectedId)
        && workflowCount($pdo) === 3
    );

    $stage = 'unknown outcome';

    $result = stTryNowRunIsolatedWorkflow(
        $pdo,
        workflowForm('0860000043'),
        $unknown,
        true
    );

    $unknownId = (int) ($result['customer_id'] ?? 0);

    workflowCheck(
        'Unknown outcome retains customer',
        $result['status'] === 'review_required'
        && $unknownId > 0
        && workflowCustomerExists($pdo, $unknownId)
        && workflowCount($pdo) === 4
    );

    workflowCheck(
        'Unknown outcome cannot automatically retry',
        $result['sms_sent'] === false
        && $result['automatic_retry'] === false
    );

    $stage = 'final verification';

    workflowCheck(
        'Accepted customer remains intact',
        workflowCustomerExists($pdo, $acceptedId)
    );

    workflowCheck(
        'No open database transaction',
        !$pdo->inTransaction()
    );

    workflowCheck(
        'Final customer count is 4',
        workflowCount($pdo) === 4
    );

    echo "PASS: Isolated workflow integration completed.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "FAIL during $stage. Do not rerun. "
        . "Inspect test database state first.\n"
    );

    exit(1);
}
