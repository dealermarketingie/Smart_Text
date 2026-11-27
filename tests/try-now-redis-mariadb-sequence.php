<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Redis/MariaDB sequencing test.
 *
 * PRIVATE PLESK CLI TEST ONLY.
 *
 * Uses:
 * - Dedicated authenticated Redis
 * - Random, temporary Redis test keys
 * - Isolated MariaDB test database
 * - Rolled-back customer INSERT
 *
 * No production data, live SMS or email.
 * PHP 7.4 compatible.
 */

if (
    PHP_SAPI !== 'cli' ||
    ($argv[1] ?? '') !== '--isolated-test' ||
    realpath(__DIR__) !==
        '/var/www/vhosts/smarttext.com/try-now-check/tests'
) {
    fwrite(STDERR, "FAIL: Private CLI test only.\n");
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-registration-sequence.php';

require_once __DIR__
    . '/../lib/try-now-transaction.php';

function combinedCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

$pdo = null;
$redis = null;
$failed = false;
$stage = 'initialisation';

$prefix = 'stxt:try-now:rate:test:db-sequence:'
    . bin2hex(random_bytes(16)) . ':';

$keys = [
    $prefix . 'ip',
    $prefix . 'mobile',
    $prefix . 'global',
];

try {
    // All production delivery gates must remain disabled.
    combinedCheck(
        'Production delivery remains disabled',
        stTryNowProductionDeliveryEnabled() === false
    );

    $stage = 'database connection';

    $config = require __DIR__ . '/../db-test.php';

    if (
        !is_array($config) ||
        ($config['host'] ?? null) !== '127.0.0.1' ||
        (int) ($config['port'] ?? 0) !== 33080 ||
        ($config['database'] ?? null) !==
            'smarttext_try_now_test' ||
        ($config['username'] ?? null) !== 'try_now_test'
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

    combinedCheck(
        'Isolated MariaDB identity verified',
        is_array($identity) &&
        ($identity['db'] ?? null) ===
            'smarttext_try_now_test' &&
        strpos(
            (string) ($identity['db_user'] ?? ''),
            'try_now_test@'
        ) === 0
    );

    $campaigns = (int) $pdo->query(
        'SELECT COUNT(*) FROM campaign'
    )->fetchColumn();

    $initialCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    combinedCheck(
        'Expected isolated database baseline',
        $campaigns === 6 && $initialCount === 4
    );

    $stage = 'Redis admission';

    $redis = stTryNowRedisConnect();

    combinedCheck(
        'Dedicated Redis connected',
        $redis instanceof Redis
    );

    // Test-only limits: one successful request.
    // All test keys expire automatically after 120 seconds.
    $arguments = array_merge(
        $keys,
        [1, 1, 1, 120, 120, 120]
    );

    $first = $redis->eval(
        stTryNowRateLimitScript(),
        $arguments,
        3
    );

    combinedCheck(
        'Redis permits first test request',
        $first === [1, 0, 0]
    );

    $admitted = stTryNowRegistrationAdmissionResult(
        stTryNowAdmissionOutcome([
            'status' => 'allowed',
            'allowed' => true,
            'retry_after' => 0,
        ])
    );

    $stage = 'rolled-back registration';

    // Use a synthetic, randomly generated mobile.
    $mobile = '087' . (string) random_int(
        1000000,
        9999999
    );

    $post = [
        'industry' => 'automotive',
        'firstName' => 'Redis',
        'lastNameCompany' => 'Integration Test',
        'email' => 'integration@example.com',
        'mobile' => $mobile,
        'consent' => 'on',
        'website' => '',
    ];

    $prepared = stTryNowPrepareRegistration($post);

    combinedCheck(
        'Synthetic customer details valid',
        $prepared['ok'] === true
    );

    $registrationCalls = 0;
    $smsPreparationCalls = 0;

    $register = static function () use (
        $pdo,
        $prepared,
        &$registrationCalls
    ): array {
        $registrationCalls++;

        $customer = $prepared['customer'];

        $pdo->beginTransaction();

        try {
            // Match the production procedure's campaign lock.
            $lock = $pdo->prepare(
                'SELECT campaign_link
                 FROM campaign
                 WHERE id = ?
                 FOR UPDATE'
            );

            $lock->execute([
                $customer['campaign_id'],
            ]);

            $campaignLink = $lock->fetchColumn();
            $lock->closeCursor();

            if (
                $campaignLink !==
                    'https://stxt.me/LJfjMU'
            ) {
                throw new RuntimeException(
                    'Unexpected test campaign.'
                );
            }

            if (stTryNowDuplicateExists(
                $pdo,
                (int) $customer['campaign_id'],
                $customer['mobile']
            )) {
                throw new RuntimeException(
                    'Synthetic mobile already exists.'
                );
            }

            $code = stTryNowGenerateUnusedCustomerCode(
                $pdo,
                (int) $customer['campaign_id']
            );

            $insert = $pdo->prepare(
                'INSERT INTO customer
                    (code, first_name, last_name,
                     mobile, campaign_id)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $insert->execute([
                $code,
                $customer['first_name'],
                $customer['last_name'],
                $customer['mobile'],
                $customer['campaign_id'],
            ]);

            $id = (int) $pdo->lastInsertId();

            if ($id <= 0) {
                throw new RuntimeException(
                    'Test insertion failed.'
                );
            }

            $verify = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM customer
                 WHERE id = ?'
            );
            $verify->execute([$id]);

            if ((int) $verify->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'Inserted test row not visible.'
                );
            }

            // Report synthetic success to the sequencing
            // helper, but never commit this test record.
            return [
                'status' => 'created',
                'customer_id' => $id,
            ];

        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    };

    $prepareSms = static function (
        array $registration
    ) use (&$smsPreparationCalls): bool {
        $smsPreparationCalls++;

        // Synthetic preparation acknowledgement only.
        // No Sendmode transport or HTTP request.
        return (
            ($registration['customer_id'] ?? 0) > 0
        );
    };

    $success = stTryNowRunSyntheticRegistrationSequence(
        $admitted,
        $register,
        $prepareSms,
        true
    );

    combinedCheck(
        'Allowed request executes registration before SMS preparation',
        $success['status'] === 'synthetic_prepared' &&
        $registrationCalls === 1 &&
        $smsPreparationCalls === 1 &&
        $success['sms_sent'] === false
    );

    combinedCheck(
        'Successful test registration was rolled back',
        !$pdo->inTransaction() &&
        (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === $initialCount
    );

    $stage = 'rate-limit rejection';

    // The same temporary Redis counters are now exhausted.
    $second = $redis->eval(
        stTryNowRateLimitScript(),
        $arguments,
        3
    );

    combinedCheck(
        'Redis rejects second test request',
        is_array($second) &&
        count($second) === 3 &&
        $second[0] === 0 &&
        $second[1] === 1 &&
        $second[2] > 0
    );

    $limited = stTryNowRegistrationAdmissionResult(
        stTryNowAdmissionOutcome([
            'status' => 'limited',
            'allowed' => false,
            'retry_after' => (int) $second[2],
        ])
    );

    $blocked = stTryNowRunSyntheticRegistrationSequence(
        $limited,
        $register,
        $prepareSms,
        true
    );

    combinedCheck(
        'HTTP 429 blocks registration and SMS callbacks',
        $limited['http_failure']['status'] === 429 &&
        $blocked['status'] === 'blocked' &&
        $registrationCalls === 1 &&
        $smsPreparationCalls === 1
    );

    $stage = 'Redis failure handling';

    // Synthetic failure; do not stop the shared Redis container.
    $failureOutcome = stTryNowEvaluateLiveAdmission(
        static function (): array {
            throw new RuntimeException(
                'Synthetic Redis failure'
            );
        }
    );

    $unavailable =
        stTryNowRegistrationAdmissionResult(
            $failureOutcome
        );

    $blocked = stTryNowRunSyntheticRegistrationSequence(
        $unavailable,
        $register,
        $prepareSms,
        true
    );

    combinedCheck(
        'HTTP 503 blocks registration and SMS callbacks',
        $unavailable['http_failure']['status'] === 503 &&
        $blocked['status'] === 'blocked' &&
        $registrationCalls === 1 &&
        $smsPreparationCalls === 1
    );

    combinedCheck(
        'Rejected requests do not increment test counters',
        (int) $redis->get($keys[0]) === 1 &&
        (int) $redis->get($keys[1]) === 1 &&
        (int) $redis->get($keys[2]) === 1
    );

    combinedCheck(
        'Final MariaDB customer count unchanged',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === $initialCount
    );

    echo "PASS: Combined Redis/MariaDB test completed.\n";

} catch (Throwable $e) {
    $failed = true;

    fwrite(
        STDERR,
        "FAIL during $stage: " .
        $e->getMessage() . "\n"
    );

} finally {
    // Never leave a test database transaction open.
    if (
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    // Remove only this run's random Redis test keys.
    if ($redis instanceof Redis) {
        try {
            foreach ($keys as $key) {
                $redis->del($key);
            }

            foreach ($keys as $key) {
                if ($redis->exists($key) !== 0) {
                    throw new RuntimeException(
                        'Temporary Redis key remains.'
                    );
                }
            }

            echo "PASS: Temporary Redis keys removed.\n";

        } catch (Throwable $e) {
            $failed = true;
            fwrite(
                STDERR,
                "FAIL: Temporary Redis cleanup.\n"
            );

        } finally {
            $redis->close();
        }
    }
}

exit($failed ? 1 : 0);
