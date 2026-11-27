<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - durable SMS attempt transitions.
 *
 * This file never calls Sendmode, creates a customer, sends email, or retries
 * an SMS. The two stTryNowCommit* functions own and commit their transactions.
 * A future caller must obtain a SUCCESSFUL RETURN from
 * stTryNowCommitTransportStarted() BEFORE invoking Sendmode. If it throws,
 * do not send, even if the database may have committed.
 *
 * The *InTransaction variants are ONLY for an already-open DB transaction
 * (including rollback-only tests); they do not commit and MUST NOT be used
 * immediately before a real SMS without a successful explicit commit.
 *
 * Requires the two Step 532 InnoDB tables and the Step 533 write account.
 * PHP 7.4+ compatible.
 */
require_once __DIR__ . '/try-now-attempt-evidence.php';

/** Inspect a locked attempt and verify its ordered, durable event history. */
function stTryNowLockAttemptJournal(PDO $pdo, int $attemptId): array
{
    if ($attemptId < 1 || !$pdo->inTransaction()) {
        throw new RuntimeException('SMS attempt requires an active transaction.');
    }

    // The account has UPDATE privileges on the ledger (not on events).
    // Locking the ledger serialises all state changes for this attempt.
    $stmt = $pdo->prepare(
        'SELECT id, state FROM try_now_sms_attempt_ledger
         WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($attempt)) {
        throw new RuntimeException('Unknown SMS attempt.');
    }

    $stmt = $pdo->prepare(
        'SELECT event_name FROM try_now_sms_attempt_events
         WHERE attempt_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$attemptId]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $expected = [
        'prepared' => ['prepared'],
        'cancelled_before_transport' => ['prepared', 'cancelled_before_transport'],
        'transport_started' => ['prepared', 'transport_started'],
        'provider_accepted' => ['prepared', 'transport_started', 'provider_accepted'],
        'provider_rejected' => ['prepared', 'transport_started', 'provider_rejected'],
        'provider_unknown' => ['prepared', 'transport_started', 'provider_unknown'],
    ];

    $state = $attempt['state'] ?? null;
    if (!is_string($state) ||
        !isset($expected[$state]) ||
        $names !== $expected[$state]) {
        throw new RuntimeException('SMS attempt journal is inconsistent.');
    }

    $events = [];
    foreach ($names as $name) {
        $events[] = ['event' => $name];
    }
    $evidence = stTryNowClassifyAttemptHistory($events);
    if (($evidence['sequence_valid'] ?? false) !== true) {
        throw new RuntimeException('SMS attempt evidence is invalid.');
    }

    return $attempt;
}

/**
 * Record the irrevocable transport boundary inside a caller-owned transaction.
 * Do not call Sendmode until THIS TRANSACTION HAS BEEN COMMITTED successfully.
 */
function stTryNowMarkTransportStartedInTransaction(PDO $pdo, int $attemptId): void
{
    $attempt = stTryNowLockAttemptJournal($pdo, $attemptId);
    if ($attempt['state'] !== 'prepared') {
        throw new RuntimeException('SMS transport may only start once.');
    }

    $stmt = $pdo->prepare(
        "UPDATE try_now_sms_attempt_ledger
         SET state = 'transport_started',
             transport_started_at = CURRENT_TIMESTAMP(6)
         WHERE id = ? AND state = 'prepared'"
    );
    $stmt->execute([$attemptId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('SMS transport transition was not recorded.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO try_now_sms_attempt_events (attempt_id, event_name)
         VALUES (?, 'transport_started')"
    );
    $stmt->execute([$attemptId]);
}

/**
 * Map an actual Sendmode transport result into the allowed durable outcome.
 * Accepted = provider accepted for processing; NOT delivery to the handset.
 * No automatic retries are allowed for ANY outcome, especially unknown.
 */
function stTryNowJournalProviderResult(array $result): array
{
    if (!isset($result['status']) ||
        !is_string($result['status']) ||
        !array_key_exists('accepted', $result) ||
        !is_bool($result['accepted']) ||
        !array_key_exists('delivered', $result) ||
        $result['delivered'] !== false ||
        !array_key_exists('provider_code', $result)) {
        throw new InvalidArgumentException('Invalid SMS provider result.');
    }

    $code = $result['provider_code'];
    if ($code !== null && (!is_int($code) || $code < 0 || $code > 2147483647)) {
        throw new InvalidArgumentException('Invalid SMS provider code.');
    }

    if ($result['status'] === 'accepted' &&
        $result['accepted'] === true && $code === 0) {
        return ['state' => 'provider_accepted', 'event' => 'provider_accepted', 'code' => 0];
    }
    if ($result['status'] === 'rejected' &&
        $result['accepted'] === false && is_int($code) && $code > 0) {
        return ['state' => 'provider_rejected', 'event' => 'provider_rejected', 'code' => $code];
    }
    if ($result['status'] === 'unknown' && $result['accepted'] === false) {
        return ['state' => 'provider_unknown', 'event' => 'provider_unknown', 'code' => $code];
    }

    throw new InvalidArgumentException('Conflicting SMS provider result.');
}

/** Record exactly one provider outcome inside a caller-owned transaction. */
function stTryNowRecordProviderOutcomeInTransaction(
    PDO $pdo,
    int $attemptId,
    array $result
): void {
    $outcome = stTryNowJournalProviderResult($result);
    $attempt = stTryNowLockAttemptJournal($pdo, $attemptId);
    if ($attempt['state'] !== 'transport_started') {
        throw new RuntimeException('No pending SMS transport to complete.');
    }

    $stmt = $pdo->prepare(
        'UPDATE try_now_sms_attempt_ledger
         SET state = ?, provider_code = ?,
             completed_at = CURRENT_TIMESTAMP(6)
         WHERE id = ? AND state = ?'
    );
    $stmt->execute([
        $outcome['state'],
        $outcome['code'],
        $attemptId,
        'transport_started',
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('SMS provider result was not recorded.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO try_now_sms_attempt_events (attempt_id, event_name)
         VALUES (?, ?)'
    );
    $stmt->execute([$attemptId, $outcome['event']]);
}

/**
 * LIVE-WORKFLOW BOUNDARY: return only after 'transport_started' is committed.
 * On any error, the caller MUST NOT invoke Sendmode or retry automatically.
 */
function stTryNowCommitTransportStarted(PDO $pdo, int $attemptId): void
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Transport boundary needs a fresh transaction.');
    }
    $pdo->beginTransaction();
    try {
        stTryNowMarkTransportStartedInTransaction($pdo, $attemptId);
        if (!$pdo->commit()) {
            throw new RuntimeException('SMS transport transaction did not commit.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Persist provider evidence in its own committed transaction. A failure here
 * does NOT mean Sendmode was never contacted. Leave transport_started as
 * potentially attempted; do not resend automatically.
 */
function stTryNowCommitProviderOutcome(PDO $pdo, int $attemptId, array $result): void
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Provider outcome needs a fresh transaction.');
    }
    $pdo->beginTransaction();
    try {
        stTryNowRecordProviderOutcomeInTransaction($pdo, $attemptId, $result);
        if (!$pdo->commit()) {
            throw new RuntimeException('SMS provider outcome did not commit.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
