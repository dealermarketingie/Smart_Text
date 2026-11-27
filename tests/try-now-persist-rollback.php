<?php
declare(strict_types=1);

/**
 * Controlled integration test for Step 533.4.
 * Run from CLI ONLY. Temporarily inserts, reads back, then ROLLS BACK.
 * Sends NO SMS and makes NO requests to Sendmode.
 * InnoDB auto-increment counters may still advance after rollback.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-write-db.php';
require_once __DIR__ . '/../lib/try-now-persist.php';
$pdo = null;
$customerId = null;
$attemptId = null;
$eventId = null;
$failure = null;

try {
    $pdo = stTryNowWritePdo();
    echo "PASS: Dedicated write connection authenticated.\n";

    // Synthetic details. No actual SMS or email is sent.
    $post = [
        'industry' => 'automotive',
        'firstName' => 'TryNow',
        'lastNameCompany' => 'RollbackCheck',
        'email' => 'trynow-rollback@example.com',
        'mobile' => '089 000 0000',
        'consent' => 'on',
        'website' => '',
    ];

    $pdo->beginTransaction();
    $result = stTryNowInsertPreparedAttempt($pdo, $post);

    if (($result['status'] ?? '') === 'already_registered') {
        throw new RuntimeException(
            'The synthetic test mobile already has a registration; choose an unused test number.'
        );
    }
    if (($result['ok'] ?? false) !== true ||
        ($result['status'] ?? '') !== 'prepared' ||
        ($result['sent'] ?? null) !== false) {
        throw new RuntimeException('Customer/attempt preparation failed.');
    }

    $customerId = (int) $result['customer_id'];
    $attemptId = (int) $result['attempt_id'];

    $stmt = $pdo->prepare(
        'SELECT code, first_name, last_name, campaign_id, clicked, booked, called
         FROM customer WHERE id = ?'
    );
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($customer) ||
        $customer['code'] !== $result['customer_code'] ||
        $customer['first_name'] !== 'TryNow' ||
        $customer['last_name'] !== 'RollbackCheck' ||
        (int) $customer['campaign_id'] !== 7907 ||
        (int) $customer['clicked'] !== 0 ||
        (int) $customer['booked'] !== 0 ||
        (int) $customer['called'] !== 0) {
        throw new RuntimeException('Customer read-back verification failed.');
    }
    echo "PASS: Customer temporarily inserted with correct defaults.\n";

    $stmt = $pdo->prepare(
        'SELECT campaign_id, customer_id, mobile_normalized, state
         FROM try_now_sms_attempt_ledger WHERE id = ?'
    );
    $stmt->execute([$attemptId]);
    $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($ledger) ||
        (int) $ledger['customer_id'] !== $customerId ||
        (int) $ledger['campaign_id'] !== 7907 ||
        $ledger['mobile_normalized'] !== '353890000000' ||
        $ledger['state'] !== 'prepared') {
        throw new RuntimeException('Attempt ledger read-back verification failed.');
    }
    echo "PASS: Prepared SMS ledger entry temporarily inserted.\n";

    $stmt = $pdo->prepare(
        'SELECT id, event_name FROM try_now_sms_attempt_events
         WHERE attempt_id = ?'
    );
    $stmt->execute([$attemptId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($events) !== 1 || $events[0]['event_name'] !== 'prepared') {
        throw new RuntimeException('Prepared event verification failed.');
    }
    $eventId = (int) $events[0]['id'];
    echo "PASS: Prepared event temporarily recorded.\n";

    // A repeat submission inside the same transaction must not create
    // another customer or another SMS attempt.
    $duplicate = stTryNowInsertPreparedAttempt($pdo, $post);
    if (($duplicate['ok'] ?? null) !== false ||
        ($duplicate['status'] ?? '') !== 'already_registered') {
        throw new RuntimeException('Duplicate registration was not blocked.');
    }
    echo "PASS: Duplicate registration blocked.\n";

    $invalid = $post;
    $invalid['consent'] = '';
    $validation = stTryNowInsertPreparedAttempt($pdo, $invalid);
    if (($validation['ok'] ?? null) !== false ||
        ($validation['status'] ?? '') !== 'invalid') {
        throw new RuntimeException('Invalid registration was not blocked.');
    }
    echo "PASS: Invalid registration blocked.\n";

} catch (Throwable $e) {
    $failure = $e->getMessage();
} finally {
    // Never leave the test inserts committed, even if an assertion fails.
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

try {
    foreach ([
        ['customer', 'id', $customerId],
        ['try_now_sms_attempt_ledger', 'id', $attemptId],
        ['try_now_sms_attempt_events', 'id', $eventId],
    ] as $target) {
        [$table, $column, $id] = $target;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() !== 0) {
            throw new RuntimeException('Rollback verification failed for ' . $table);
        }
    }
    echo "PASS: Transaction rolled back; no test rows committed.\n";
    echo "PASS: This test makes no SMS or email calls.\n";
    echo "NOTE: InnoDB auto-increment IDs may advance after rollback.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}
