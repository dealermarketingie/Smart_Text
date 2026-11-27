<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Concurrent registration test.
 *
 * ISOLATED MARIADB ONLY.
 * No production access, SMS or email.
 *
 * Run only once against the prepared test database.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-transaction.php';

function concurrencyPdo(): PDO
{
    $config = require __DIR__ . '/../db-test.php';

    if (
        $config['host'] !== '127.0.0.1' ||
        (int) $config['port'] !== 33080 ||
        $config['database'] !== 'smarttext_try_now_test' ||
        $config['username'] !== 'try_now_test'
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

    if (
        $identity['db'] !== 'smarttext_try_now_test' ||
        strpos($identity['db_user'], 'try_now_test@') !== 0
    ) {
        throw new RuntimeException('Database identity mismatch.');
    }

    return $pdo;
}

/**
 * Worker: attempt to register the same synthetic customer.
 */
if (($argv[1] ?? '') === '--worker') {
    try {
        $pdo = concurrencyPdo();

        $input = [
            'industry' => 'healthcare',
            'firstName' => 'Concurrency',
            'lastNameCompany' => 'Test',
            'email' => 'concurrency@example.com',
            'mobile' => '0860000002',
            'consent' => 'on',
            'website' => '',
        ];

        // Signal that this worker is ready to register.
        echo "READY\n";
        flush();

        $result = stTryNowRegisterCustomer(
            $pdo,
            $input,
            true
        );

        $status = $result['status'] ?? '';

        if (!in_array($status, ['created', 'duplicate'], true)) {
            throw new RuntimeException('Unexpected registration status.');
        }

        echo $status . "\n";
        exit(0);

    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL: Worker registration failed.\n");
        exit(1);
    }
}

if (count($argv) !== 1) {
    fwrite(STDERR, "FAIL: Invalid test arguments.\n");
    exit(1);
}

$pdo = null;
$workers = [];
$passed = false;

try {
    $pdo = concurrencyPdo();

    echo "PASS: Isolated MariaDB connection verified.\n";

    $before = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    if ($before !== 1) {
        throw new RuntimeException(
            'Expected exactly one existing test customer.'
        );
    }

    if (stTryNowDuplicateExists($pdo, 7908, '0860000002')) {
        throw new RuntimeException(
            'Concurrency test mobile already exists.'
        );
    }

    echo "PASS: Initial customer count is 1.\n";

    // Hold the healthcare campaign row lock.
    $pdo->beginTransaction();

    $lock = $pdo->prepare(
        'SELECT campaign_link
         FROM campaign
         WHERE id = ?
         FOR UPDATE'
    );

    $lock->execute([7908]);
    $campaignUrl = $lock->fetchColumn();
    $lock->closeCursor();

    if ($campaignUrl !== 'https://stxt.me/mQ66my') {
        throw new RuntimeException(
            'Unexpected campaign configuration.'
        );
    }

    echo "PASS: Campaign row locked.\n";

    $php = '/opt/plesk/php/8.4/bin/php';

    // Start two independent registration processes.
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];

        $process = proc_open(
            [$php, __FILE__, '--worker'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start worker.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $workers[] = [
            'process' => $process,
            'pipes' => $pipes,
            'output' => '',
            'error' => '',
        ];
    }

    // Wait until both workers are connected and ready.
    $deadline = microtime(true) + 10;

    while (true) {
        $ready = 0;

        foreach ($workers as $i => $worker) {
            $workers[$i]['output'] .=
                stream_get_contents($worker['pipes'][1]);

            $workers[$i]['error'] .=
                stream_get_contents($worker['pipes'][2]);

            if (strpos($workers[$i]['output'], "READY\n") !== false) {
                $ready++;
            } else {
                $state = proc_get_status($worker['process']);

                if (!$state['running']) {
                    throw new RuntimeException(
                        'Worker stopped before becoming ready.'
                    );
                }
            }
        }

        if ($ready === 2) {
            break;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker readiness timeout.');
        }

        usleep(25000);
    }

    echo "PASS: Both registration processes started.\n";

    // Allow workers time to reach the locked row.
    usleep(500000);

    foreach ($workers as $i => $worker) {
        $workers[$i]['output'] .=
            stream_get_contents($worker['pipes'][1]);

        $state = proc_get_status($worker['process']);

        if (
            !$state['running'] ||
            strpos($workers[$i]['output'], "created\n") !== false ||
            strpos($workers[$i]['output'], "duplicate\n") !== false
        ) {
            throw new RuntimeException(
                'A worker completed before the lock was released.'
            );
        }
    }

    echo "PASS: Both registrations blocked by campaign lock.\n";

    // Release the lock so the workers can proceed.
    $pdo->commit();

    echo "PASS: Campaign lock released.\n";

    $statuses = [];

    foreach ($workers as $i => $worker) {
        stream_set_blocking($worker['pipes'][1], true);
        stream_set_blocking($worker['pipes'][2], true);

        $output = $workers[$i]['output']
            . stream_get_contents($worker['pipes'][1]);

        $error = $workers[$i]['error']
            . stream_get_contents($worker['pipes'][2]);

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);

        $exitCode = proc_close($worker['process']);
        $workers[$i]['process'] = null;

        $status = trim(str_replace("READY\n", '', $output));

        if (
            $exitCode !== 0 ||
            $error !== '' ||
            !in_array($status, ['created', 'duplicate'], true)
        ) {
            throw new RuntimeException(
                'Worker returned an unexpected result.'
            );
        }

        $statuses[] = $status;
    }

    sort($statuses);

    if ($statuses !== ['created', 'duplicate']) {
        throw new RuntimeException(
            'Expected one created and one duplicate result.'
        );
    }

    echo "PASS: One registration created.\n";
    echo "PASS: Second registration rejected as duplicate.\n";

    $after = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    if (
        $after !== 2 ||
        !stTryNowDuplicateExists($pdo, 7908, '0860000002')
    ) {
        throw new RuntimeException(
            'Final customer records are incorrect.'
        );
    }

    echo "PASS: Customer count increased from 1 to 2.\n";
    echo "PASS: Duplicate record not created.\n";

    $passed = true;

} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");

} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Clean up any workers if the test exits early.
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
        }

        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
    }
}

if ($passed) {
    echo "PASS: Concurrent registration test completed.\n";
}

exit($passed ? 0 : 1);
