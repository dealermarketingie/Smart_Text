<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Restricted probe recovery.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * Observes only the disposable security-probe ledger.
 * Uses the restricted read-only account.
 *
 * No database writes, transaction management,
 * customer changes, HTTP requests or Sendmode calls.
 *
 * Every result remains unsafe for SMS transport.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-procedure-executor.php';

function stTryNowRestrictedProbeObservationResult(
    string $status
): array {
    return [
        'status' => $status,
        'row' => null,
        'independent_reader_verified' => false,
    ];
}

/**
 * Read one checkpoint using the restricted account.
 *
 * The original writer's CONNECTION_ID must have
 * been captured from the genuine writer session.
 *
 * A separate reader session is mandatory.
 * An active reader transaction is prohibited.
 *
 * The observation is not a commit acknowledgement.
 */
function stTryNowObserveRestrictedSyntheticProbeCheckpoint(
    PDO $reader,
    array $reservation,
    int $writerConnectionId,
    bool $allowCliTest = false
): array {
    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return stTryNowRestrictedProbeObservationResult(
            'disabled'
        );
    }

    if (
        !stTryNowLedgerRecoveryReservationValid(
            $reservation
        ) ||
        $writerConnectionId <= 0
    ) {
        return stTryNowRestrictedProbeObservationResult(
            'invalid_observation_request'
        );
    }

    try {
        if (
            $reader->getAttribute(
                PDO::ATTR_DRIVER_NAME
            ) !== 'mysql' ||
            $reader->getAttribute(
                PDO::ATTR_ERRMODE
            ) !== PDO::ERRMODE_EXCEPTION ||
            $reader->inTransaction()
        ) {
            return stTryNowRestrictedProbeObservationResult(
                'untrusted_observer'
            );
        }

        $identity = $reader->query(
            'SELECT
                DATABASE() AS db,
                CURRENT_USER() AS db_user,
                CONNECTION_ID() AS session_id,
                @@port AS server_port,
                VERSION() AS server_version'
        )->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($identity) ||
            $identity['db'] !== null ||
            $identity['db_user'] !==
                'try_now_ledger_reader@172.17.0.1' ||
            (int) $identity['session_id'] <= 0 ||
            (int) $identity['session_id'] ===
                $writerConnectionId ||
            (int) $identity['server_port'] !== 3306 ||
            !is_string(
                $identity['server_version']
            ) ||
            strpos(
                $identity['server_version'],
                '11.4.'
            ) !== 0
        ) {
            return stTryNowRestrictedProbeObservationResult(
                'untrusted_observer'
            );
        }

        /*
         * Query only the disposable probe.
         *
         * Do not filter by attempt_id: an existing
         * conflicting reservation must be visible
         * to the recovery assessment.
         */
        $statement = $reader->prepare(
            'SELECT
                campaign_id,
                customer_id,
                attempt_id,
                journal_slot_id,
                recipient_digest,
                checkpoint_sequence,
                checkpoint_revision,
                checkpoint_event,
                checkpoint_head_mac,
                checkpoint_seal_mac,
                checkpoint_updated_at
             FROM smarttext_try_now_test.
                try_now_sms_ledger_security_probe
             WHERE campaign_id = ?
               AND customer_id = ?'
        );

        $statement->execute([
            $reservation['campaign_id'],
            $reservation['customer_id'],
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        $statement->closeCursor();

        if ($row === false) {
            $result =
                stTryNowRestrictedProbeObservationResult(
                    'absent'
                );

            $result[
                'independent_reader_verified'
            ] = true;

            return $result;
        }

        $result =
            stTryNowRestrictedProbeObservationResult(
                'found'
            );

        $result['row'] = $row;

        $result[
            'independent_reader_verified'
        ] = true;

        return $result;

    } catch (Throwable $e) {
        return stTryNowRestrictedProbeObservationResult(
            'read_unavailable'
        );
    }
}

/**
 * Assess a commit signal against an independently
 * observed checkpoint and authenticated journal.
 *
 * The observation is fetched internally.
 * The caller cannot inject a fabricated row.
 *
 * Commit signals are caller-reported evidence;
 * this function does not independently prove that
 * a COMMIT occurred or that it was acknowledged.
 */
function stTryNowAssessRestrictedSyntheticProbeCommit(
    PDO $reader,
    array $reservation,
    array $journalRecords,
    array $expectedSeal,
    string $namespace,
    string $bindingKey,
    string $recordKey,
    string $sealKey,
    string $commitSignal,
    int $writerConnectionId,
    bool $allowCliTest = false
): array {
    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return stTryNowLedgerRecoveryResult(
            'disabled'
        );
    }

    if (!in_array($commitSignal, [
        'not_attempted',
        'acknowledged',
        'acknowledgement_lost',
        'failed_or_unknown',
    ], true)) {
        return stTryNowLedgerRecoveryResult(
            'invalid_commit_signal'
        );
    }

    $observation =
        stTryNowObserveRestrictedSyntheticProbeCheckpoint(
            $reader,
            $reservation,
            $writerConnectionId,
            true
        );

    /*
     * An unavailable or untrusted reader never
     * produces a usable recovery assessment.
     */
    if (
        $observation[
            'independent_reader_verified'
        ] !== true
    ) {
        $result = stTryNowLedgerRecoveryResult(
            'fresh_read_unavailable_requires_review'
        );

        $result['observation_status'] =
            $observation['status'];

        $result['independent_reader_verified'] =
            false;

        return $result;
    }

    /*
     * Reuse the existing authenticated recovery
     * decision logic. Its observation is supplied
     * exclusively by our verified database read.
     */
    $result = stTryNowAssessSyntheticLedgerCommitEvidence(
        $reservation,
        $journalRecords,
        $expectedSeal,
        $namespace,
        $bindingKey,
        $recordKey,
        $sealKey,
        $commitSignal,
        [
            'status' => $observation['status'],
            'row' => $observation['row'],
        ],
        true
    );

    $result['observation_status'] =
        $observation['status'];

    $result['independent_reader_verified'] =
        true;

    return $result;
}