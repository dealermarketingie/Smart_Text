<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Restricted ledger procedure tests.
 *
 * ISOLATED PLESK CLI ONLY.
 *
 * Uses existing private test credentials.
 * Uses synthetic reservation identities.
 * Every database write remains uncommitted.
 * All synthetic changes are rolled back.
 *
 * No production database, HTTP or Sendmode access.
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

function securityCheck(string $label, bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }

    echo "PASS: $label\n";
}

function securityExpectError(
    string $label,
    array $expectedCodes,
    callable $operation
): void {
    try {
        $operation();
    } catch (PDOException $e) {
        $number = (int) ($e->errorInfo[1] ?? 0);

        if (!in_array($number, $expectedCodes, true)) {
            throw new RuntimeException(
                $label . ': unexpected database error ' . $number,
                0,
                $e
            );
        }

        securityCheck($label, true);
        return;
    }

    throw new RuntimeException(
        $label . ': operation unexpectedly succeeded'
    );
}

function securityConnection(array $config, string $role): PDO
{
    if (!in_array($role, ['executor', 'reader'], true)) {
        throw new RuntimeException('Invalid connection role');
    }

    $account = $config[$role] ?? null;
    $expectedUser = 'try_now_ledger_' . $role;

    if (
        !is_array($account) ||
        ($account['username'] ?? null) !== $expectedUser ||
        !is_string($account['password'] ?? null) ||
        $account['password'] === ''
    ) {
        throw new RuntimeException(
            'Invalid private connection configuration'
        );
    }

    // No default database: only explicitly qualified objects.
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=33080;charset=utf8mb4',
        $account['username'],
        $account['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    $identity = $pdo->query(
        'SELECT CURRENT_USER()'
    )->fetchColumn();

    securityCheck(
        ucfirst($role) . ' authenticated independently',
        $identity === $expectedUser . '@172.17.0.1'
    );

    return $pdo;
}

function securityCall(
    PDO $pdo,
    string $procedure,
    array $arguments
): void {
    if (!in_array($procedure, [
        'try_now_ledger_probe_reserve',
        'try_now_ledger_probe_initialize',
        'try_now_ledger_probe_advance',
    ], true)) {
        throw new RuntimeException('Unapproved procedure');
    }

    $placeholders = implode(
        ', ',
        array_fill(0, count($arguments), '?')
    );

    $statement = $pdo->prepare(
        'CALL smarttext_try_now_test.' .
        $procedure . '(' . $placeholders . ')'
    );

    $statement->execute($arguments);
    $statement->closeCursor();
}

function securityFixture(int $customerId): array
{
    $nonce = bin2hex(random_bytes(16));

    $fixture = [
        'campaign' => 7907,
        'customer' => $customerId,
        'attempt' => $nonce,
        'slot' => hash('sha256', 'synthetic-slot:' . $nonce),
        'recipient' => hash(
            'sha256',
            'synthetic-recipient:' . $nonce
        ),
    ];

    for ($i = 1; $i <= 4; $i++) {
        $fixture['h' . $i] = hash(
            'sha256',
            'synthetic-head-' . $i . ':' . $nonce
        );
        $fixture['s' . $i] = hash(
            'sha256',
            'synthetic-seal-' . $i . ':' . $nonce
        );
    }

    return $fixture;
}

function securityReserve(PDO $pdo, array $fixture): void
{
    securityCall(
        $pdo,
        'try_now_ledger_probe_reserve',
        [
            $fixture['campaign'],
            $fixture['customer'],
            $fixture['attempt'],
            $fixture['slot'],
            $fixture['recipient'],
        ]
    );
}

function securityInitialize(PDO $pdo, array $fixture): void
{
    securityCall(
        $pdo,
        'try_now_ledger_probe_initialize',
        [
            $fixture['campaign'],
            $fixture['customer'],
            $fixture['attempt'],
            $fixture['slot'],
            $fixture['recipient'],
            $fixture['h1'],
            $fixture['s1'],
        ]
    );
}

function securityAdvance(
    PDO $pdo,
    array $fixture,
    int $previousSequence,
    string $previousEvent,
    string $nextEvent,
    ?string $overridePreviousHead = null,
    ?string $overrideNextHead = null,
    ?string $overrideNextSeal = null
): void {
    $nextSequence = $previousSequence + 1;

    securityCall(
        $pdo,
        'try_now_ledger_probe_advance',
        [
            $fixture['campaign'],
            $fixture['customer'],
            $fixture['attempt'],
            $fixture['slot'],
            $fixture['recipient'],

            $previousSequence,
            $previousEvent,
            $overridePreviousHead ??
                $fixture['h' . $previousSequence],
            $fixture['s' . $previousSequence],

            $nextEvent,
            $overrideNextHead ??
                $fixture['h' . $nextSequence],
            $overrideNextSeal ??
                $fixture['s' . $nextSequence],
        ]
    );
}

$executor = null;
$reader = null;
$failure = null;

$table =
    'smarttext_try_now_test.' .
    'try_now_sms_ledger_security_probe';

$authoritative =
    'smarttext_try_now_test.' .
    'try_now_sms_attempt_ledger';

try {
    securityCheck(
        'Production operations remain disabled',
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false
    );

    $configFile = $root . '/db-ledger-security-probe.php';

    securityCheck(
        'Private configuration has safe permissions',
        is_file($configFile) &&
        !is_link($configFile) &&
        (fileperms($configFile) & 0777) === 0600
    );

    $config = require $configFile;

    securityCheck(
        'Isolated database configuration verified',
        is_array($config) &&
        ($config['host'] ?? null) === '127.0.0.1' &&
        ($config['port'] ?? null) === 33080 &&
        ($config['database'] ?? null) ===
            'smarttext_try_now_test'
    );

    $executor = securityConnection($config, 'executor');
    $reader = securityConnection($config, 'reader');

    securityCheck(
        'Disposable probe initially empty',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    /*
     * Direct permissions must be denied.
     *
     * Write probes intentionally affect zero rows
     * even if a permission is unexpectedly granted.
     */
    securityExpectError(
        'Executor direct SELECT denied',
        [1044, 1142, 1143],
        static function () use ($executor, $table): void {
            $executor->query(
                'SELECT COUNT(*) FROM ' . $table
            );
        }
    );

    securityExpectError(
        'Executor direct INSERT denied',
        [1044, 1142, 1143],
        static function () use ($executor, $table): void {
            $executor->exec(
                'INSERT INTO ' . $table . '
                 (
                     campaign_id,
                     customer_id,
                     attempt_id,
                     journal_slot_id,
                     recipient_digest
                 )
                 SELECT
                     7907,
                     900000799,
                     REPEAT("a", 32),
                     REPEAT("b", 64),
                     REPEAT("c", 64)
                 WHERE 1 = 0'
            );
        }
    );

    securityExpectError(
        'Executor direct UPDATE denied',
        [1044, 1142, 1143],
        static function () use ($executor, $table): void {
            $executor->exec(
                'UPDATE ' . $table . '
                 SET checkpoint_sequence = checkpoint_sequence
                 WHERE 1 = 0'
            );
        }
    );

    securityExpectError(
        'Executor direct DELETE denied',
        [1044, 1142, 1143],
        static function () use ($executor, $table): void {
            $executor->exec(
                'DELETE FROM ' . $table . '
                 WHERE 1 = 0'
            );
        }
    );

    securityExpectError(
        'Executor cannot read authoritative ledger',
        [1044, 1142, 1143],
        static function () use (
            $executor,
            $authoritative
        ): void {
            $executor->query(
                'SELECT COUNT(*) FROM ' . $authoritative
            );
        }
    );

    securityExpectError(
        'Reader cannot update disposable probe',
        [1044, 1142, 1143],
        static function () use ($reader, $table): void {
            $reader->exec(
                'UPDATE ' . $table . '
                 SET checkpoint_sequence = checkpoint_sequence
                 WHERE 1 = 0'
            );
        }
    );

    securityExpectError(
        'Reader cannot access authoritative ledger',
        [1044, 1142, 1143],
        static function () use (
            $reader,
            $authoritative
        ): void {
            $reader->query(
                'SELECT COUNT(*) FROM ' . $authoritative
            );
        }
    );

    securityExpectError(
        'Reader cannot execute reservation procedure',
        [1370, 1044],
        static function () use ($reader): void {
            securityCall(
                $reader,
                'try_now_ledger_probe_reserve',
                [
                    7907,
                    900000799,
                    str_repeat('a', 32),
                    str_repeat('b', 64),
                    str_repeat('c', 64),
                ]
            );
        }
    );

    /*
     * Every following write stays inside the
     * executor's uncommitted transaction.
     */
    securityCheck(
        'Executor transaction started',
        $executor->beginTransaction()
    );

    $normal = securityFixture(900000751);

    $invalid = $normal;
    $invalid['attempt'] = str_repeat('A', 32);

    securityExpectError(
        'Uppercase attempt rejected',
        [1644],
        static function () use ($executor, $invalid): void {
            securityReserve($executor, $invalid);
        }
    );

    securityReserve($executor, $normal);

    securityCheck(
        'Valid synthetic reservation accepted',
        $executor->inTransaction()
    );

    securityExpectError(
        'Duplicate reservation rejected',
        [1062],
        static function () use ($executor, $normal): void {
            securityReserve($executor, $normal);
        }
    );

    $wrongRecipient = $normal;
    $wrongRecipient['recipient'] = str_repeat('f', 64);

    securityExpectError(
        'Changed recipient binding rejected',
        [1644],
        static function () use (
            $executor,
            $wrongRecipient
        ): void {
            securityInitialize(
                $executor,
                $wrongRecipient
            );
        }
    );

    securityInitialize($executor, $normal);

    securityCheck(
        'Prepared checkpoint initialized',
        $executor->inTransaction()
    );

    securityExpectError(
        'Repeated initialization rejected',
        [1644],
        static function () use ($executor, $normal): void {
            securityInitialize($executor, $normal);
        }
    );

    securityExpectError(
        'Skipped transport checkpoint rejected',
        [1644],
        static function () use ($executor, $normal): void {
            securityAdvance(
                $executor,
                $normal,
                1,
                'prepared',
                'provider_accepted'
            );
        }
    );

    securityExpectError(
        'Unchanged checkpoint HMACs rejected',
        [1644],
        static function () use ($executor, $normal): void {
            securityAdvance(
                $executor,
                $normal,
                1,
                'prepared',
                'transport_started',
                null,
                $normal['h1'],
                $normal['s1']
            );
        }
    );

    securityExpectError(
        'Incorrect previous HMAC rejected',
        [1644],
        static function () use ($executor, $normal): void {
            securityAdvance(
                $executor,
                $normal,
                1,
                'prepared',
                'transport_started',
                str_repeat('0', 64)
            );
        }
    );

    securityAdvance(
        $executor,
        $normal,
        1,
        'prepared',
        'transport_started'
    );

    securityCheck(
        'Prepared-to-transport transition accepted',
        $executor->inTransaction()
    );

    securityExpectError(
        'Stale prepared checkpoint rejected',
        [1644],
        static function () use ($executor, $normal): void {
            securityAdvance(
                $executor,
                $normal,
                1,
                'prepared',
                'transport_started'
            );
        }
    );

    securityAdvance(
        $executor,
        $normal,
        2,
        'transport_started',
        'provider_unknown'
    );

    securityCheck(
        'Provider-unknown checkpoint accepted',
        $executor->inTransaction()
    );

    securityExpectError(
        'Terminal provider checkpoint cannot advance',
        [1644],
        static function () use ($executor, $normal): void {
            securityAdvance(
                $executor,
                $normal,
                3,
                'provider_unknown',
                'provider_accepted'
            );
        }
    );

    /*
     * Independent cancellation pathway.
     */
    $cancelled = securityFixture(900000752);

    securityReserve($executor, $cancelled);
    securityInitialize($executor, $cancelled);

    securityAdvance(
        $executor,
        $cancelled,
        1,
        'prepared',
        'cancelled_before_transport'
    );

    securityCheck(
        'Pre-transport cancellation accepted',
        $executor->inTransaction()
    );

    securityExpectError(
        'Cancelled attempt cannot advance',
        [1644],
        static function () use (
            $executor,
            $cancelled
        ): void {
            securityAdvance(
                $executor,
                $cancelled,
                2,
                'cancelled_before_transport',
                'provider_accepted'
            );
        }
    );

    securityCheck(
        'Independent reader cannot see uncommitted rows',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

    securityCheck(
        'Transaction remains under caller control',
        $executor->inTransaction()
    );

} catch (Throwable $e) {
    $failure = $e;

} finally {
    /*
     * Never commit test reservations.
     */
    if (
        $executor instanceof PDO &&
        $executor->inTransaction()
    ) {
        try {
            $executor->rollBack();
        } catch (Throwable $e) {
            if ($failure === null) {
                $failure = $e;
            }
        }
    }
}

try {
    if (!$reader instanceof PDO) {
        throw new RuntimeException(
            'Independent reader unavailable'
        );
    }

    securityCheck(
        'All synthetic reservations rolled back',
        (int) $reader->query(
            'SELECT COUNT(*) FROM ' . $table
        )->fetchColumn() === 0
    );

} catch (Throwable $e) {
    if ($failure === null) {
        $failure = $e;
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . $failure->getMessage() . "\n"
    );
    exit(1);
}

echo "PASS: All restricted ledger security tests.\n";
echo "PASS: Step 451 completed.\n";
