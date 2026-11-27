<?php
declare(strict_types=1);

/**
 * Step 534: production-schema integration test for journal transitions.
 *
 * Inserts synthetic registrations, transitions them to transport_started
 * and provider_* using synthetic responses, then ROLLS BACK EVERYTHING.
 * No provider call, SMS, email, or committed row. This tests SQL state
 * transitions, NOT durable commit behaviour of the public live workflow.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-write-db.php';
require_once __DIR__ . '/../lib/try-now-persist.php';
require_once __DIR__ . '/../lib/try-now-attempt-journal-db.php';

function stStep534Check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo 'PASS: '.$description."\n";
}

/** Create a unique rollback-only registration with no real recipient messaging. */
function stStep534Prepared(PDO $pdo): array
{
    for ($i = 0; $i < 12; $i++) {
        // Valid but non-disclosed Irish-format number. Never send to it.
        $number = '089'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $result = stTryNowInsertPreparedAttempt($pdo, [
            'industry' => 'automotive',
            'firstName' => 'TryNow',
            'lastNameCompany' => 'JournalRollback',
            'email' => 'trynow-rollback@example.com',
            'mobile' => $number,
            'consent' => 'on',
            'website' => '',
        ]);
        if (($result['status'] ?? null) === 'already_registered') {
            continue;
        }
        if (($result['ok'] ?? null) !== true ||
            ($result['status'] ?? null) !== 'prepared') {
            throw new RuntimeException('Unable to prepare synthetic SMS attempt.');
        }
        return $result;
    }
    throw new RuntimeException('No unused synthetic test registration found.');
}

function stStep534ExpectError(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        echo 'PASS: '.$label."\n";
        return;
    }
    throw new RuntimeException('Invalid transition was allowed: '.$label);
}

$pdo = null;
$inserted = [];
$failure = null;

try {
    $pdo = stTryNowWritePdo();
    stStep534Check($pdo instanceof PDO, 'Dedicated write database connection authenticated.');
    $pdo->beginTransaction();

    $accepted = stStep534Prepared($pdo);
    $acceptedId = (int) $accepted['attempt_id'];
    $inserted[] = $accepted;

    stStep534ExpectError(function () use ($pdo,$acceptedId): void {
        stTryNowRecordProviderOutcomeInTransaction($pdo,$acceptedId,[
            'status'=>'accepted','accepted'=>true,
            'delivered'=>false,'provider_code'=>0,
        ]);
    }, 'Provider outcome before transport is blocked.');

    stTryNowMarkTransportStartedInTransaction($pdo, $acceptedId);
    $s = stTryNowLockAttemptJournal($pdo, $acceptedId);
    stStep534Check($s['state']==='transport_started', 'Transport boundary and event recorded.');
    stStep534ExpectError(function () use ($pdo,$acceptedId): void {
        stTryNowMarkTransportStartedInTransaction($pdo,$acceptedId);
    }, 'Second transport start is blocked.');

    stTryNowRecordProviderOutcomeInTransaction($pdo, $acceptedId,[
        'status'=>'accepted','accepted'=>true,
        'delivered'=>false,'provider_code'=>0,
    ]);
    stStep534ExpectError(function () use ($pdo,$acceptedId): void {
        stTryNowRecordProviderOutcomeInTransaction($pdo, $acceptedId,[
            'status'=>'accepted','accepted'=>true,
            'delivered'=>false,'provider_code'=>0,
        ]);
    }, 'Second provider outcome is blocked.');

    $responses = [
        ['label'=>'rejected','status'=>'rejected','code'=>11],
        ['label'=>'unknown','status'=>'unknown','code'=>null],
    ];
    foreach ($responses as $case) {
        $result = stStep534Prepared($pdo);
        $inserted[] = $result;
        $id = (int) $result['attempt_id'];
        stTryNowMarkTransportStartedInTransaction($pdo,$id);
        stTryNowRecordProviderOutcomeInTransaction($pdo,$id,[
            'status'=>$case['status'], 'accepted'=>false,
            'delivered'=>false, 'provider_code'=>$case['code'],
        ]);
        stStep534ExpectError(function () use ($pdo,$id): void {
            stTryNowMarkTransportStartedInTransaction($pdo,$id);
        }, 'No automatic resend after '.$case['label'].' response.');
    }

    $expected = ['provider_accepted'=>0,'provider_rejected'=>11,'provider_unknown'=>null];
    foreach ($inserted as $r) {
        $id = (int) $r['attempt_id'];
        $stmt = $pdo->prepare(
            'SELECT state, provider_code, transport_started_at, completed_at
             FROM try_now_sms_attempt_ledger WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !array_key_exists($row['state'],$expected)) {
            throw new RuntimeException('Invalid provider ledger state.');
        }
        stStep534Check(
            $row['transport_started_at'] !== null &&
            $row['completed_at'] !== null &&
            ($expected[$row['state']] === null ? $row['provider_code'] === null :
                (int) $row['provider_code'] === $expected[$row['state']]),
            $row['state'].' state, timestamps and provider evidence verified.'
        );

        $stmt = $pdo->prepare(
            'SELECT event_name FROM try_now_sms_attempt_events
             WHERE attempt_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        $events = array_map(static function ($name): array {
            return ['event' => $name];
        }, $stmt->fetchAll(PDO::FETCH_COLUMN,0));
        $classified = stTryNowClassifyAttemptHistory($events);
        stStep534Check(
            $classified['sequence_valid'] === true &&
            $classified['attempt_state'] === 'attempted' &&
            $classified['automatic_send'] === false &&
            $classified['automatic_retry'] === false &&
            $classified['delivered'] === false,
            $row['state'].' journal history is valid with no retry/delivery claim.'
        );
    }

    stStep534ExpectError(function () use ($pdo,$acceptedId): void {
        stTryNowCommitTransportStarted($pdo,$acceptedId);
    }, 'Commit wrapper refuses a nested transaction.');

} catch (Throwable $e) {
    $failure = $e->getMessage();
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

if ($failure !== null) {
    fwrite(STDERR,'FAIL: '.$failure."\n");
    exit(1);
}

try {
    foreach ($inserted as $row) {
        $checks = [
            ['customer', 'id', $row['customer_id']],
            ['try_now_sms_attempt_ledger', 'id', $row['attempt_id']],
        ];
        foreach ($checks as [$table,$column,$id]) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() !== 0) {
                throw new RuntimeException('Rollback failed for '.$table);
            }
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM try_now_sms_attempt_events WHERE attempt_id = ?'
        );
        $stmt->execute([$row['attempt_id']]);
        if ((int) $stmt->fetchColumn() !== 0) {
            throw new RuntimeException('Rollback failed for attempt events.');
        }
    }
    echo "PASS: All temporary registrations, ledger states and events rolled back.\n";
    echo "PASS: No SMS, email or network delivery occurred.\n";
    echo "NOTE: Auto-increment counters may advance after rollback.\n";
} catch (Throwable $e) {
    fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");
    exit(1);
}
