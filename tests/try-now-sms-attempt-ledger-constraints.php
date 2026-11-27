<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Isolated SMS ledger tests.
 *
 * PLESK CLI TESTING ONLY.
 *
 * Uses synthetic identities and rolls back all
 * reservations before completion.
 *
 * Never connects to production MariaDB.
 * Never sends SMS or modifies customer records.
 */

$expectedDirectory =
    '/var/www/vhosts/smarttext.com/try-now-check/tests';

if (
    PHP_SAPI !== 'cli' ||
    DIRECTORY_SEPARATOR !== '/' ||
    realpath(__DIR__) !== $expectedDirectory
) {
    fwrite(STDERR, "FAIL: Isolated Linux CLI required.\n");
    exit(1);
}

$root = dirname(__DIR__);

require_once $root . '/lib/try-now-runtime.php';

function ledgerTestCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function ledgerTestRow(
    int $customerId,
    string $suffix
): array {
    // Entirely synthetic values.
    $key = str_repeat('K', 32);

    return [
        'campaign_id' => 7907,
        'customer_id' => $customerId,
        'attempt_id' => bin2hex(random_bytes(16)),
        'journal_slot_id' => hash(
            'sha256',
            'synthetic-slot:' . $suffix
        ),
        'recipient_digest' => hash_hmac(
            'sha256',
            'synthetic-recipient:' . $suffix,
            $key
        ),
    ];
}

function ledgerTestInsert(
    PDO $pdo,
    array $row
): void {
    $statement = $pdo->prepare(
        'INSERT INTO try_now_sms_attempt_ledger
            (
                campaign_id,
                customer_id,
                attempt_id,
                journal_slot_id,
                recipient_digest
            )
         VALUES (?, ?, ?, ?, ?)'
    );

    $statement->execute([
        $row['campaign_id'],
        $row['customer_id'],
        $row['attempt_id'],
        $row['journal_slot_id'],
        $row['recipient_digest'],
    ]);
}

function ledgerTestExpectedError(
    string $name,
    int $expectedError,
    callable $operation
): void {
    try {
        $operation();

    } catch (PDOException $e) {
        $errorNumber = (int) (
            $e->errorInfo[1] ?? 0
        );

        if ($errorNumber !== $expectedError) {
            throw new RuntimeException(
                $name . ': unexpected database error '
                . $errorNumber,
                0,
                $e
            );
        }

        echo "PASS: $name\n";
        return;
    }

    throw new RuntimeException(
        $name . ': operation unexpectedly succeeded.'
    );
}

function ledgerTestConnection(
    array $config
): PDO {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=33080;' .
        'dbname=smarttext_try_now_test;charset=utf8mb4',
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
        'SELECT DATABASE() AS db,
                CURRENT_USER() AS db_user'
    )->fetch();

    if (
        !is_array($identity) ||
        $identity['db'] !== 'smarttext_try_now_test' ||
        strpos(
            (string) $identity['db_user'],
            'try_now_test@'
        ) !== 0
    ) {
        throw new RuntimeException(
            'Authenticated database identity mismatch.'
        );
    }

    return $pdo;
}

$pdo = null;
$concurrent = null;
$failure = null;

try {
    ledgerTestCheck(
        'Production operations remain disabled',
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false
    );

    $configFile = $root . '/db-test.php';

    if (
        !is_file($configFile) ||
        is_link($configFile)
    ) {
        throw new RuntimeException(
            'Private test configuration unavailable.'
        );
    }

    $config = require $configFile;

    ledgerTestCheck(
        'Isolated database configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        (int) ($config['port'] ?? 0) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test' &&
        ($config['username'] ?? null) ===
            'try_now_test' &&
        is_string($config['password'] ?? null) &&
        $config['password'] !== ''
    );

    $pdo = ledgerTestConnection($config);

    ledgerTestCheck(
        'Authenticated isolated database connection',
        $pdo instanceof PDO
    );

    $engine = $pdo->query(
        "SELECT ENGINE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'try_now_sms_attempt_ledger'"
    )->fetchColumn();

    ledgerTestCheck(
        'Ledger uses InnoDB',
        $engine === 'InnoDB'
    );

    $count = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM try_now_sms_attempt_ledger'
    )->fetchColumn();

    // Do not touch a ledger containing existing evidence.
    ledgerTestCheck(
        'Ledger is initially empty',
        $count === 0
    );

    $pdo->beginTransaction();

    $original = ledgerTestRow(
        900000101,
        'original'
    );

    ledgerTestInsert($pdo, $original);

    ledgerTestCheck(
        'Synthetic reservation inserted',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 1
    );

    $statement = $pdo->prepare(
        'SELECT checkpoint_sequence,
                checkpoint_revision,
                checkpoint_event,
                checkpoint_head_mac,
                checkpoint_seal_mac
         FROM try_now_sms_attempt_ledger
         WHERE campaign_id = ?
           AND customer_id = ?'
    );

    $statement->execute([
        7907,
        900000101,
    ]);

    $stored = $statement->fetch();

    ledgerTestCheck(
        'New reservation starts without a checkpoint',
        is_array($stored) &&
        (int) $stored['checkpoint_sequence'] === 0 &&
        (int) $stored['checkpoint_revision'] === 0 &&
        $stored['checkpoint_event'] === null &&
        $stored['checkpoint_head_mac'] === null &&
        $stored['checkpoint_seal_mac'] === null
    );

    // Same customer, but otherwise different identity.
    $duplicateCustomer = ledgerTestRow(
        900000101,
        'duplicate-customer'
    );

    ledgerTestExpectedError(
        'Duplicate customer reservation rejected',
        1062,
        static function () use (
            $pdo,
            $duplicateCustomer
        ): void {
            ledgerTestInsert(
                $pdo,
                $duplicateCustomer
            );
        }
    );

    // Different customer but identical attempt ID.
    $duplicateAttempt = ledgerTestRow(
        900000102,
        'duplicate-attempt'
    );

    $duplicateAttempt['attempt_id'] =
        $original['attempt_id'];

    ledgerTestExpectedError(
        'Duplicate attempt identifier rejected',
        1062,
        static function () use (
            $pdo,
            $duplicateAttempt
        ): void {
            ledgerTestInsert(
                $pdo,
                $duplicateAttempt
            );
        }
    );

    // Different customer and attempt; same journal slot.
    $duplicateSlot = ledgerTestRow(
        900000103,
        'duplicate-slot'
    );

    $duplicateSlot['journal_slot_id'] =
        $original['journal_slot_id'];

    ledgerTestExpectedError(
        'Duplicate journal slot rejected',
        1062,
        static function () use (
            $pdo,
            $duplicateSlot
        ): void {
            ledgerTestInsert(
                $pdo,
                $duplicateSlot
            );
        }
    );

    // Different customer, attempt and slot; same recipient.
    $duplicateRecipient = ledgerTestRow(
        900000104,
        'duplicate-recipient'
    );

    $duplicateRecipient['recipient_digest'] =
        $original['recipient_digest'];

    ledgerTestExpectedError(
        'Duplicate recipient reservation rejected',
        1062,
        static function () use (
            $pdo,
            $duplicateRecipient
        ): void {
            ledgerTestInsert(
                $pdo,
                $duplicateRecipient
            );
        }
    );

    $invalidAttempt = ledgerTestRow(
        900000105,
        'invalid-attempt'
    );

    $invalidAttempt['attempt_id'] =
        'g' . str_repeat('0', 31);

    ledgerTestExpectedError(
        'Malformed attempt identifier rejected',
        4025,
        static function () use (
            $pdo,
            $invalidAttempt
        ): void {
            ledgerTestInsert(
                $pdo,
                $invalidAttempt
            );
        }
    );

    $invalidSlot = ledgerTestRow(
        900000106,
        'invalid-slot'
    );

    $invalidSlot['journal_slot_id'] =
        'g' . str_repeat('0', 63);

    ledgerTestExpectedError(
        'Malformed journal slot rejected',
        4025,
        static function () use (
            $pdo,
            $invalidSlot
        ): void {
            ledgerTestInsert(
                $pdo,
                $invalidSlot
            );
        }
    );

    $invalidRecipient = ledgerTestRow(
        900000107,
        'invalid-recipient'
    );

    $invalidRecipient['recipient_digest'] =
        'g' . str_repeat('0', 63);

    ledgerTestExpectedError(
        'Malformed recipient digest rejected',
        4025,
        static function () use (
            $pdo,
            $invalidRecipient
        ): void {
            ledgerTestInsert(
                $pdo,
                $invalidRecipient
            );
        }
    );

    ledgerTestExpectedError(
        'Incomplete checkpoint state rejected',
        4025,
        static function () use ($pdo): void {
            $pdo->exec(
                "UPDATE try_now_sms_attempt_ledger
                 SET checkpoint_sequence = 2,
                     checkpoint_revision = 2,
                     checkpoint_event = 'transport_started'
                 WHERE campaign_id = 7907
                   AND customer_id = 900000101"
            );
        }
    );

    ledgerTestExpectedError(
        'NULL checkpoint event rejected',
        4025,
        static function () use ($pdo): void {
            $pdo->exec(
                "UPDATE try_now_sms_attempt_ledger
                 SET checkpoint_sequence = 1,
                     checkpoint_revision = 1,
                     checkpoint_event = NULL,
                     checkpoint_head_mac = REPEAT('a', 64),
                     checkpoint_seal_mac = REPEAT('b', 64),
                     checkpoint_updated_at = UTC_TIMESTAMP(6)
                 WHERE campaign_id = 7907
                   AND customer_id = 900000101"
            );
        }
    );

    ledgerTestCheck(
        'Rejected writes preserve original reservation',
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 1
    );

    /*
     * A second independent database connection
     * attempts to reserve the same customer while
     * the first transaction has not committed.
     *
     * The second INSERT must not succeed.
     */
    $concurrent = ledgerTestConnection($config);

    // Session-only setting; no server changes.
    $concurrent->exec(
        'SET SESSION innodb_lock_wait_timeout = 2'
    );

    $concurrent->beginTransaction();

    $competing = ledgerTestRow(
        900000101,
        'concurrent'
    );

    ledgerTestExpectedError(
        'Concurrent reservation blocked by InnoDB',
        1205,
        static function () use (
            $concurrent,
            $competing
        ): void {
            ledgerTestInsert(
                $concurrent,
                $competing
            );
        }
    );

    ledgerTestCheck(
        'Original uncommitted reservation remains',
        $pdo->inTransaction() &&
        (int) $pdo->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 1
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * Always roll back both test transactions.
     * Never commit synthetic attempt evidence.
     */
    foreach ([$concurrent, $pdo] as $connection) {
        if (
            $connection instanceof PDO &&
            $connection->inTransaction()
        ) {
            try {
                $connection->rollBack();
            } catch (Throwable $e) {
                if ($failure === null) {
                    $failure = $e;
                }
            }
        }
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . $failure->getMessage() . "\n"
    );
    exit(1);
}

try {
    $remaining = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM try_now_sms_attempt_ledger'
    )->fetchColumn();

    ledgerTestCheck(
        'All synthetic reservations rolled back',
        $remaining === 0
    );

    echo "PASS: All isolated ledger constraint tests.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        'FAIL: ' . $e->getMessage() . "\n"
    );
    exit(1);
}
