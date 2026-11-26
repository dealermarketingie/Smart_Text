<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Real commit/concurrency probe.
 *
 * ISOLATED PLESK CLI ONLY.
 * PHP 7.4 compatible.
 *
 * Creates and drops ONE disposable cloned table.
 * Never writes to the authoritative SMS ledger.
 * Never modifies customers or sends SMS.
 */

if (
    PHP_SAPI !== 'cli' ||
    DIRECTORY_SEPARATOR !== '/' ||
    realpath(__DIR__) !==
        '/var/www/vhosts/smarttext.com/try-now-check/tests'
) {
    fwrite(STDERR, "FAIL: Isolated CLI required.\n");
    exit(1);
}

$root = dirname(__DIR__);

require_once $root . '/lib/try-now-runtime.php';

function probeCheck(string $name, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($name);
    }

    echo "PASS: $name\n";
}

function probeConnection(array $config): PDO
{
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
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($identity) ||
        $identity['db'] !== 'smarttext_try_now_test' ||
        strpos(
            (string) $identity['db_user'],
            'try_now_test@'
        ) !== 0
    ) {
        throw new RuntimeException(
            'Unauthorised database connection.'
        );
    }

    return $pdo;
}

function probeRead(PDO $pdo, int $customerId)
{
    $statement = $pdo->prepare(
        'SELECT *
         FROM try_now_sms_ledger_commit_probe
         WHERE campaign_id = 7907
           AND customer_id = ?'
    );

    $statement->execute([$customerId]);

    return $statement->fetch(PDO::FETCH_ASSOC);
}

function probeInsertPrepared(
    PDO $pdo,
    int $customerId,
    string $label
): array {
    $unique = bin2hex(random_bytes(16));

    $reservation = [
        'campaign_id' => 7907,
        'customer_id' => $customerId,
        'attempt_id' => $unique,
        'journal_slot_id' => hash(
            'sha256',
            'synthetic-slot:' . $unique
        ),
        'recipient_digest' => hash(
            'sha256',
            'synthetic-recipient:' . $unique
        ),
    ];

    $head = hash(
        'sha256',
        'synthetic-head:' . $label . ':' . $unique
    );

    $seal = hash(
        'sha256',
        'synthetic-seal:' . $label . ':' . $unique
    );

    $insert = $pdo->prepare(
        'INSERT INTO try_now_sms_ledger_commit_probe
            (
                campaign_id,
                customer_id,
                attempt_id,
                journal_slot_id,
                recipient_digest
            )
         VALUES (?, ?, ?, ?, ?)'
    );

    $insert->execute(array_values($reservation));

    if ($insert->rowCount() !== 1) {
        throw new RuntimeException(
            'Synthetic reservation insert failed.'
        );
    }

    $update = $pdo->prepare(
        'UPDATE try_now_sms_ledger_commit_probe
         SET checkpoint_sequence = 1,
             checkpoint_revision = 1,
             checkpoint_event = ?,
             checkpoint_head_mac = ?,
             checkpoint_seal_mac = ?,
             checkpoint_updated_at = UTC_TIMESTAMP(6)
         WHERE campaign_id = ?
           AND customer_id = ?
           AND attempt_id = ?
           AND checkpoint_sequence = 0
           AND checkpoint_revision = 0'
    );

    $update->execute([
        'prepared',
        $head,
        $seal,
        7907,
        $customerId,
        $unique,
    ]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException(
            'Synthetic checkpoint initialization failed.'
        );
    }

    return [
        'reservation' => $reservation,
        'head' => $head,
        'seal' => $seal,
    ];
}

function probeAdvance(
    PDO $pdo,
    array $fixture,
    string $nextHead,
    string $nextSeal
): int {
    $reservation = $fixture['reservation'];

    $statement = $pdo->prepare(
        'UPDATE try_now_sms_ledger_commit_probe
         SET checkpoint_sequence = 2,
             checkpoint_revision = 2,
             checkpoint_event = ?,
             checkpoint_head_mac = ?,
             checkpoint_seal_mac = ?,
             checkpoint_updated_at = UTC_TIMESTAMP(6)
         WHERE campaign_id = ?
           AND customer_id = ?
           AND attempt_id = ?
           AND journal_slot_id = ?
           AND recipient_digest = ?
           AND checkpoint_sequence = 1
           AND checkpoint_revision = 1
           AND checkpoint_event = ?
           AND checkpoint_head_mac = ?
           AND checkpoint_seal_mac = ?'
    );

    $statement->execute([
        'transport_started',
        $nextHead,
        $nextSeal,
        $reservation['campaign_id'],
        $reservation['customer_id'],
        $reservation['attempt_id'],
        $reservation['journal_slot_id'],
        $reservation['recipient_digest'],
        'prepared',
        $fixture['head'],
        $fixture['seal'],
    ]);

    return $statement->rowCount();
}

$writer = null;
$observer = null;
$failure = null;
$probeCreated = false;
$probeDropped = false;

try {
    probeCheck(
        'Production delivery and cleanup disabled',
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false
    );

    $configFile = $root . '/db-test.php';

    if (!is_file($configFile) || is_link($configFile)) {
        throw new RuntimeException(
            'Private test database config missing.'
        );
    }

    $config = require $configFile;

    probeCheck(
        'Isolated MariaDB configuration verified',
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

    $writer = probeConnection($config);
    $observer = probeConnection($config);

    $writerId = (int) $writer->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    $observerId = (int) $observer->query(
        'SELECT CONNECTION_ID()'
    )->fetchColumn();

    probeCheck(
        'Independent authenticated sessions established',
        $writerId > 0 &&
        $observerId > 0 &&
        $writerId !== $observerId
    );

    probeCheck(
        'Authoritative attempt ledger is empty',
        (int) $writer->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    $exists = $writer->query(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME =
               'try_now_sms_ledger_commit_probe'"
    )->fetchColumn();

    if ((int) $exists !== 0) {
        throw new RuntimeException(
            'An existing probe table needs inspection.'
        );
    }

    /*
     * Deliberate isolated DDL.
     *
     * Clone the structure only, not the data.
     * The original ledger is never modified.
     */
    $writer->exec(
        'CREATE TABLE try_now_sms_ledger_commit_probe
         LIKE try_now_sms_attempt_ledger'
    );

    $probeCreated = true;

    probeCheck(
        'Disposable ledger clone created',
        (int) $writer->query(
            'SELECT COUNT(*)
             FROM try_now_sms_ledger_commit_probe'
        )->fetchColumn() === 0
    );

    $engine = $writer->query(
        "SELECT ENGINE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME =
               'try_now_sms_ledger_commit_probe'"
    )->fetchColumn();

    probeCheck(
        'Disposable ledger clone uses InnoDB',
        $engine === 'InnoDB'
    );

    /*
     * TEST 1: Actual successful COMMIT.
     */
    $writer->beginTransaction();

    $first = probeInsertPrepared(
        $writer,
        900000601,
        'confirmed-commit'
    );

    probeCheck(
        'Uncommitted fixture invisible to observer',
        probeRead($observer, 900000601) === false
    );

    probeCheck(
        'First MariaDB COMMIT acknowledged',
        $writer->commit() === true
    );

    $observed = probeRead($observer, 900000601);

    probeCheck(
        'Committed checkpoint visible independently',
        is_array($observed) &&
        (int) $observed['checkpoint_sequence'] === 1 &&
        $observed['checkpoint_head_mac'] ===
            $first['head'] &&
        $observed['checkpoint_seal_mac'] ===
            $first['seal']
    );

    /*
     * TEST 2: Simulated lost acknowledgement.
     *
     * This is a deliberate exception AFTER a
     * successful real COMMIT. It does not
     * simulate a network-level commit failure.
     */
    $writer->beginTransaction();

    $second = probeInsertPrepared(
        $writer,
        900000602,
        'lost-acknowledgement'
    );

    $lostAcknowledgementInjected = false;

    try {
        if ($writer->commit() !== true) {
            throw new RuntimeException(
                'Commit acknowledgement missing.'
            );
        }

        throw new RuntimeException(
            'SYNTHETIC_LOST_ACK_AFTER_COMMIT'
        );

    } catch (RuntimeException $e) {
        if (
            $e->getMessage() !==
                'SYNTHETIC_LOST_ACK_AFTER_COMMIT'
        ) {
            throw $e;
        }

        $lostAcknowledgementInjected = true;
    }

    probeCheck(
        'Post-commit acknowledgement failure injected',
        $lostAcknowledgementInjected
    );

    $observedSecond = probeRead(
        $observer,
        900000602
    );

    probeCheck(
        'Committed fixture survives simulated lost acknowledgement',
        is_array($observedSecond) &&
        (int) $observedSecond[
            'checkpoint_sequence'
        ] === 1 &&
        $observedSecond['checkpoint_seal_mac'] ===
            $second['seal']
    );

    /*
     * TEST 3: Competing conditional updates.
     */
    $nextHead = hash(
        'sha256',
        'synthetic-next-head:' . $first['head']
    );

    $nextSeal = hash(
        'sha256',
        'synthetic-next-seal:' . $first['seal']
    );

    $observer->exec(
        'SET SESSION innodb_lock_wait_timeout = 2'
    );

    $writer->beginTransaction();

    probeCheck(
        'First writer advances checkpoint conditionally',
        probeAdvance(
            $writer,
            $first,
            $nextHead,
            $nextSeal
        ) === 1
    );

    $observer->beginTransaction();

    $lockTimeout = false;

    try {
        probeAdvance(
            $observer,
            $first,
            $nextHead,
            $nextSeal
        );

    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1205) {
            throw $e;
        }

        $lockTimeout = true;
    }

    probeCheck(
        'Competing checkpoint writer cannot take row lock',
        $lockTimeout
    );

    if ($observer->inTransaction()) {
        $observer->rollBack();
    }

    probeCheck(
        'First checkpoint update commits successfully',
        $writer->commit() === true
    );

    $after = probeRead($observer, 900000601);

    probeCheck(
        'Committed winner has checkpoint sequence 2',
        is_array($after) &&
        (int) $after['checkpoint_sequence'] === 2 &&
        (int) $after['checkpoint_revision'] === 2 &&
        $after['checkpoint_event'] ===
            'transport_started' &&
        $after['checkpoint_head_mac'] === $nextHead &&
        $after['checkpoint_seal_mac'] === $nextSeal
    );

    $observer->beginTransaction();

    $staleRows = probeAdvance(
        $observer,
        $first,
        $nextHead,
        $nextSeal
    );

    probeCheck(
        'Stale competing checkpoint update affects zero rows',
        $staleRows === 0
    );

    $observer->rollBack();

    /*
     * TEST 4: An uncommitted reservation rolls back.
     */
    $writer->beginTransaction();

    probeInsertPrepared(
        $writer,
        900000603,
        'explicit-rollback'
    );

    $writer->rollBack();

    probeCheck(
        'Rolled-back reservation absent independently',
        probeRead($observer, 900000603) === false
    );

    probeCheck(
        'Only two committed synthetic fixtures exist',
        (int) $writer->query(
            'SELECT COUNT(*)
             FROM try_now_sms_ledger_commit_probe'
        )->fetchColumn() === 2
    );

    probeCheck(
        'Authoritative attempt ledger remains untouched',
        (int) $writer->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

    /*
     * Remove ONLY the disposable probe.
     *
     * Never DROP, DELETE or TRUNCATE the real
     * try_now_sms_attempt_ledger table.
     */
    if (
        $writer->inTransaction() ||
        $observer->inTransaction()
    ) {
        throw new RuntimeException(
            'Cannot clean probe during an active transaction.'
        );
    }

    $writer->exec(
        'DROP TABLE try_now_sms_ledger_commit_probe'
    );

    $probeDropped = true;

    probeCheck(
        'Disposable probe table removed',
        (int) $writer->query(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME =
                   'try_now_sms_ledger_commit_probe'"
        )->fetchColumn() === 0
    );

    probeCheck(
        'Original attempt ledger still empty',
        (int) $writer->query(
            'SELECT COUNT(*)
             FROM try_now_sms_attempt_ledger'
        )->fetchColumn() === 0
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    foreach ([$observer, $writer] as $connection) {
        if (
            $connection instanceof PDO &&
            $connection->inTransaction()
        ) {
            try {
                $connection->rollBack();
            } catch (Throwable $ignored) {
                // Preserve the original failure.
            }
        }
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . $failure->getMessage() . "\n"
    );

    if ($probeCreated && !$probeDropped) {
        fwrite(
            STDERR,
            "REVIEW: Disposable probe table retained.\n"
        );
    }

    exit(1);
}

echo "PASS: All real-commit and competing-writer tests.\n";
echo "PASS: Step 428 completed.\n";
