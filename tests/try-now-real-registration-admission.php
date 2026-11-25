<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Real registration admission test.
 *
 * PRIVATE PLESK CLI TEST ONLY.
 *
 * Real Redis Lua admission and real MariaDB registration.
 * The test customer is removed by exact-record cleanup.
 *
 * No production database, HTTP, SMS or email.
 * PHP 7.4 compatible.
 */

if (
    PHP_SAPI !== 'cli' ||
    count($argv) !== 2 ||
    $argv[1] !== '--isolated-test' ||
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

function realRegistrationCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function realRegistrationTestDatabase(PDO $pdo): bool
{
    if (
        $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)
        !== 'mysql'
    ) {
        return false;
    }

    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch(PDO::FETCH_ASSOC);

    return is_array($identity)
        && ($identity['db'] ?? null)
            === 'smarttext_try_now_test'
        && strpos(
            (string) ($identity['db_user'] ?? ''),
            'try_now_test@'
        ) === 0;
}

$pdo = null;
$redis = null;
$createdRecord = null;
$failed = false;
$stage = 'initialisation';

$prefix = 'stxt:try-now:rate:test:real-registration:'
    . bin2hex(random_bytes(16)) . ':';

$keys = [
    $prefix . 'ip',
    $prefix . 'mobile',
    $prefix . 'global',
];

try {
    realRegistrationCheck(
        'Production delivery disabled',
        !stTryNowProductionDeliveryEnabled()
    );

    realRegistrationCheck(
        'Live SMS transport disabled',
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false
    );

    $stage = 'database verification';

    $config = require __DIR__ . '/../db-test.php';

    if (
        !is_array($config) ||
        ($config['host'] ?? null) !== '127.0.0.1' ||
        (int) ($config['port'] ?? 0) !== 33080 ||
        ($config['database'] ?? null)
            !== 'smarttext_try_now_test' ||
        ($config['username'] ?? null)
            !== 'try_now_test'
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
            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    realRegistrationCheck(
        'Isolated database identity verified',
        realRegistrationTestDatabase($pdo)
    );

    $baseline = (int) $pdo->query(
        'SELECT COUNT(*) FROM customer'
    )->fetchColumn();

    realRegistrationCheck(
        'Initial customer count is four',
        $baseline === 4
    );

    $campaignCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM campaign'
    )->fetchColumn();

    realRegistrationCheck(
        'Six isolated campaigns verified',
        $campaignCount === 6
    );

    // Choose a synthetic mobile not already present.
    $mobile = null;

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = '087'
            . random_int(1000000, 9999999);

        if (!stTryNowDuplicateExists(
            $pdo,
            7907,
            $candidate
        )) {
            $mobile = $candidate;
            break;
        }
    }

    if ($mobile === null) {
        throw new RuntimeException(
            'Could not allocate synthetic mobile.'
        );
    }

    $post = [
        'industry' => 'automotive',
        'firstName' => 'Redis',
        'lastNameCompany' => 'Admission Test',
        'email' => 'admission@example.com',
        'mobile' => $mobile,
        'consent' => 'on',
        'website' => '',
    ];

    $prepared = stTryNowPrepareRegistration($post);

    realRegistrationCheck(
        'Synthetic registration details valid',
        $prepared['ok'] === true
    );

    // The registration function must remain
    // disabled unless explicitly authorised.
    $disabled = stTryNowRegisterCustomer(
        $pdo,
        $post,
        false
    );

    realRegistrationCheck(
        'Registration disabled by default',
        $disabled['status'] === 'disabled'
        && !$pdo->inTransaction()
    );

    $stage = 'Redis admission';

    $redis = stTryNowRedisConnect();

    realRegistrationCheck(
        'Dedicated Redis connected',
        $redis instanceof Redis
    );

    // Test-only limits: two admissions.
    // Temporary keys expire after 120 seconds.
    $arguments = array_merge(
        $keys,
        [2, 2, 2, 120, 120, 120]
    );

    $first = $redis->eval(
        stTryNowRateLimitScript(),
        $arguments,
        3
    );

    realRegistrationCheck(
        'First request admitted by Redis',
        $first === [1, 0, 0]
    );

    $admitted = stTryNowRegistrationAdmissionResult(
        stTryNowAdmissionOutcome([
            'status' => 'allowed',
            'allowed' => true,
            'retry_after' => 0,
        ])
    );

    $registrationCalls = 0;
    $smsPreparationCalls = 0;
    $lastRegistrationStatus = null;

    // This callback invokes the ACTUAL registration
    // function, not a synthetic INSERT.
    $register = static function () use (
        $pdo,
        $post,
        $prepared,
        &$createdRecord,
        &$registrationCalls,
        &$lastRegistrationStatus
    ): array {
        $registrationCalls++;

        $registration = stTryNowRegisterCustomer(
            $pdo,
            $post,
            true
        );

        $lastRegistrationStatus =
            $registration['status'] ?? null;

        // Retain exact identifiers immediately
        // for test-record cleanup.
        if ($lastRegistrationStatus === 'created') {
            $createdRecord = [
                'id' => (int) $registration['customer_id'],
                'campaign_id' =>
                    (int) $prepared['customer']['campaign_id'],
                'code' => (string) $registration['code'],
                'mobile' =>
                    $prepared['customer']['mobile'],
            ];
        }

        return $registration;
    };

    // No real SMS preparation or Sendmode call.
    $prepareSms = static function (
        array $registration
    ) use (&$smsPreparationCalls): bool {
        $smsPreparationCalls++;

        return preg_match(
            '~^https://stxt\.me/LJfjMU/'
            . '[A-Za-z][A-Za-z0-9]{5}7907$~D',
            (string) ($registration['link'] ?? '')
        ) === 1;
    };

    $stage = 'real customer registration';

    $success = stTryNowRunSyntheticRegistrationSequence(
        $admitted,
        $register,
        $prepareSms,
        true
    );

    realRegistrationCheck(
        'Real registration executed after admission',
        $success['status'] === 'synthetic_prepared'
        && $lastRegistrationStatus === 'created'
        && $registrationCalls === 1
        && $smsPreparationCalls === 1
        && $createdRecord !== null
    );

    realRegistrationCheck(
        'Actual customer transaction committed',
        !$pdo->inTransaction()
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === $baseline + 1
    );

    realRegistrationCheck(
        'No SMS transmitted',
        $success['sms_sent'] === false
        && $success['automatic_retry'] === false
    );

    $stage = 'duplicate protection';

    // Second request is admitted, but must not
    // create a duplicate customer.
    $second = $redis->eval(
        stTryNowRateLimitScript(),
        $arguments,
        3
    );

    realRegistrationCheck(
        'Second request admitted by Redis',
        $second === [1, 0, 0]
    );

    $duplicate = stTryNowRunSyntheticRegistrationSequence(
        $admitted,
        $register,
        $prepareSms,
        true
    );

    realRegistrationCheck(
        'Actual duplicate registration rejected',
        $lastRegistrationStatus === 'duplicate'
        && $duplicate['status'] === 'registration_failed'
        && $registrationCalls === 2
        && $smsPreparationCalls === 1
    );

    realRegistrationCheck(
        'Duplicate attempt creates no new customer',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM customer'
        )->fetchColumn() === $baseline + 1
    );

    $stage = 'rate-limit rejection';

    $third = $redis->eval(
        stTryNowRateLimitScript(),
        $arguments,
        3
    );

    realRegistrationCheck(
        'Third request blocked by Redis',
        is_array($third)
        && count($third) === 3
        && $third[0] === 0
        && $third[1] === 1
        && $third[2] > 0
    );

    $limited = stTryNowRegistrationAdmissionResult(
        stTryNowAdmissionOutcome([
            'status' => 'limited',
            'allowed' => false,
            'retry_after' => (int) $third[2],
        ])
    );

    $blocked = stTryNowRunSyntheticRegistrationSequence(
        $limited,
        $register,
        $prepareSms,
        true
    );

    realRegistrationCheck(
        'HTTP 429 prevents actual registration',
        $limited['http_failure']['status'] === 429
        && $blocked['status'] === 'blocked'
        && $registrationCalls === 2
        && $smsPreparationCalls === 1
    );

    $stage = 'Redis failure protection';

    $unavailable = stTryNowRegistrationAdmissionResult(
        stTryNowEvaluateLiveAdmission(
            static function (): array {
                throw new RuntimeException(
                    'Synthetic Redis failure'
                );
            }
        )
    );

    $blocked = stTryNowRunSyntheticRegistrationSequence(
        $unavailable,
        $register,
        $prepareSms,
        true
    );

    realRegistrationCheck(
        'HTTP 503 prevents actual registration',
        $unavailable['http_failure']['status'] === 503
        && $blocked['status'] === 'blocked'
        && $registrationCalls === 2
        && $smsPreparationCalls === 1
    );

    realRegistrationCheck(
        'Rejected Redis request leaves counters unchanged',
        (int) $redis->get($keys[0]) === 2
        && (int) $redis->get($keys[1]) === 2
        && (int) $redis->get($keys[2]) === 2
    );

    echo "PASS: Real registration admission test completed.\n";

} catch (Throwable $e) {
    $failed = true;

    fwrite(
        STDERR,
        "FAIL during $stage: " . $e->getMessage() . "\n"
    );

} finally {
    // Ensure no transaction remains open.
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * Remove ONLY the exact record created by this test.
     *
     * This is not Sendmode rejection cleanup.
     * It is isolated test-data housekeeping.
     */
    if ($createdRecord !== null && $pdo instanceof PDO) {
        try {
            if (!realRegistrationTestDatabase($pdo)) {
                throw new RuntimeException(
                    'Unsafe cleanup database identity.'
                );
            }

            $pdo->beginTransaction();

            $delete = $pdo->prepare(
                'DELETE FROM customer
                 WHERE id = ?
                   AND campaign_id = ?
                   AND BINARY code = BINARY ?
                   AND BINARY mobile = BINARY ?
                   AND first_name = ?
                   AND last_name = ?
                   AND clicked = 0
                   AND booked = 0
                   AND called = 0
                   AND date_added >=
                       CURRENT_TIMESTAMP - INTERVAL 10 MINUTE
                   AND date_added <= CURRENT_TIMESTAMP'
            );

            $delete->execute([
                $createdRecord['id'],
                $createdRecord['campaign_id'],
                $createdRecord['code'],
                $createdRecord['mobile'],
                'Redis',
                'Admission Test',
            ]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    'Exact test-record cleanup failed.'
                );
            }

            $pdo->commit();

            echo "PASS: Exact synthetic customer removed.\n";

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $failed = true;

            fwrite(
                STDERR,
                "FAIL: Test customer cleanup requires inspection.\n"
            );
        }
    }

    if ($pdo instanceof PDO) {
        try {
            if (!realRegistrationTestDatabase($pdo)) {
                throw new RuntimeException(
                    'Unexpected database identity.'
                );
            }

            $finalCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM customer'
            )->fetchColumn();

            realRegistrationCheck(
                'Final customer count restored to four',
                $finalCount === 4
            );

        } catch (Throwable $e) {
            $failed = true;
            fwrite(
                STDERR,
                "FAIL: Final database count verification.\n"
            );
        }
    }

    // Remove only temporary, randomly named Redis keys.
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
