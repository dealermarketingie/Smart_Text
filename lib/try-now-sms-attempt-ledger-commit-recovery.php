<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Ledger commit recovery.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * A successful commit acknowledgement and a
 * matching independent read are separate evidence.
 *
 * No transaction control, writes, HTTP or SMS.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-advance.php';

function stTryNowLedgerRecoveryResult(
    string $status
): array {
    return [
        'status' => $status,
        'fresh_checkpoint_observed' => false,
        'commit_acknowledgement_reported' => false,
        'checkpoint_committed' => false,
        'review_required' => true,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}

function stTryNowLedgerRecoveryTestAllowed(
    bool $allowCliTest
): bool {
    return
        $allowCliTest &&
        PHP_SAPI === 'cli' &&
        DIRECTORY_SEPARATOR === '/' &&
        realpath(__DIR__) ===
            '/var/www/vhosts/smarttext.com/try-now-check/lib' &&
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
        ST_TRY_NOW_SMS_COPY_APPROVED === false &&
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
        stTryNowProductionDeliveryEnabled() === false;
}

function stTryNowLedgerRecoveryReservationValid(
    array $reservation
): bool {
    if (array_keys($reservation) !== [
        'campaign_id',
        'customer_id',
        'attempt_id',
        'journal_slot_id',
        'recipient_digest',
    ]) {
        return false;
    }

    if (
        !is_int($reservation['campaign_id']) ||
        !is_int($reservation['customer_id']) ||
        $reservation['customer_id'] <= 0 ||
        $reservation['customer_id'] > 4294967295 ||
        !in_array(
            $reservation['campaign_id'],
            array_column(stTryNowSamples(), 'campaign_id'),
            true
        )
    ) {
        return false;
    }

    foreach ([
        'attempt_id' => 32,
        'journal_slot_id' => 64,
        'recipient_digest' => 64,
    ] as $field => $length) {
        if (
            !is_string($reservation[$field]) ||
            preg_match(
                '/\A[0-9a-f]{' . $length . '}\z/',
                $reservation[$field]
            ) !== 1
        ) {
            return false;
        }
    }

    return true;
}

/**
 * Read the ledger outside the writer's transaction.
 *
 * The caller must supply a separate PDO connection
 * and the original writer's MariaDB CONNECTION_ID().
 *
 * This function rejects an active transaction and
 * an observer sharing the writer's session ID.
 *
 * It never changes database state.
 */
function stTryNowObserveSyntheticLedgerCheckpoint(
    PDO $observer,
    array $reservation,
    int $writerConnectionId,
    bool $allowCliTest = false
): array {
    $result = [
        'status' => 'disabled',
        'row' => null,
    ];

    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return $result;
    }

    if (
        !stTryNowLedgerRecoveryReservationValid(
            $reservation
        ) ||
        $writerConnectionId <= 0
    ) {
        $result['status'] = 'invalid_observation_request';
        return $result;
    }

    try {
        if (
            $observer->getAttribute(
                PDO::ATTR_DRIVER_NAME
            ) !== 'mysql' ||
            $observer->getAttribute(
                PDO::ATTR_ERRMODE
            ) !== PDO::ERRMODE_EXCEPTION ||
            $observer->inTransaction()
        ) {
            $result['status'] = 'untrusted_observer';
            return $result;
        }

        $identity = $observer->query(
            'SELECT DATABASE() AS db,
                    CURRENT_USER() AS db_user,
                    CONNECTION_ID() AS session_id'
        )->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($identity) ||
            ($identity['db'] ?? null) !==
                'smarttext_try_now_test' ||
            !is_string(
                $identity['db_user'] ?? null
            ) ||
            strpos(
                $identity['db_user'],
                'try_now_test@'
            ) !== 0 ||
            (int) $identity['session_id'] <= 0 ||
            (int) $identity['session_id'] ===
                $writerConnectionId
        ) {
            $result['status'] = 'untrusted_observer';
            return $result;
        }

        $statement = $observer->prepare(
            'SELECT campaign_id,
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
             FROM try_now_sms_attempt_ledger
             WHERE campaign_id = ?
               AND customer_id = ?'
        );

        $statement->execute([
            $reservation['campaign_id'],
            $reservation['customer_id'],
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $result['status'] = 'absent';
            return $result;
        }

        $result['status'] = 'found';
        $result['row'] = $row;

        return $result;

    } catch (Throwable $e) {
        $result['status'] = 'read_unavailable';
        return $result;
    }
}

/**
 * Check a reported observation against exact
 * expected checkpoint identity and HMAC values.
 */
function stTryNowLedgerRecoveryRowMatches(
    array $row,
    array $reservation,
    array $seal
): bool {
    $numbers = [
        'campaign_id' => $reservation['campaign_id'],
        'customer_id' => $reservation['customer_id'],
        'checkpoint_sequence' => $seal['record_count'],
        'checkpoint_revision' => $seal['record_count'],
    ];

    foreach ($numbers as $field => $expected) {
        if (!array_key_exists($field, $row)) {
            return false;
        }

        $actual = $row[$field];

        if (
            !is_int($actual) &&
            !(
                is_string($actual) &&
                ctype_digit($actual)
            )
        ) {
            return false;
        }

        if ((int) $actual !== $expected) {
            return false;
        }
    }

    $strings = [
        'attempt_id' => $reservation['attempt_id'],
        'journal_slot_id' =>
            $reservation['journal_slot_id'],
        'recipient_digest' =>
            $reservation['recipient_digest'],
        'checkpoint_event' => $seal['head_event'],
        'checkpoint_head_mac' => $seal['head_mac'],
        'checkpoint_seal_mac' => $seal['seal_mac'],
    ];

    foreach ($strings as $field => $expected) {
        if (
            !isset($row[$field]) ||
            !is_string($row[$field]) ||
            !hash_equals($expected, $row[$field])
        ) {
            return false;
        }
    }

    return
        isset($row['checkpoint_updated_at']) &&
        is_string($row['checkpoint_updated_at']) &&
        $row['checkpoint_updated_at'] !== '';
}

/**
 * Assess a COMMIT report and separate observation.
 *
 * Commit signals:
 * - not_attempted
 * - acknowledged
 * - acknowledgement_lost
 * - failed_or_unknown
 *
 * For safety, even an acknowledged and observed
 * checkpoint never authorises SMS here.
 *
 * This is test-only decision logic. The caller is
 * responsible for supplying trustworthy commit
 * evidence and an actual fresh-session observation.
 */
function stTryNowAssessSyntheticLedgerCommitEvidence(
    array $reservation,
    array $journalRecords,
    array $expectedSeal,
    string $namespace,
    string $bindingKey,
    string $recordKey,
    string $sealKey,
    string $commitSignal,
    array $observation,
    bool $allowCliTest = false
): array {
    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return stTryNowLedgerRecoveryResult('disabled');
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

    try {
        if (
            !stTryNowLedgerRecoveryReservationValid(
                $reservation
            ) ||
            strlen($bindingKey) < 32 ||
            strlen($recordKey) < 32 ||
            strlen($sealKey) < 32 ||
            hash_equals($bindingKey, $recordKey) ||
            hash_equals($bindingKey, $sealKey) ||
            hash_equals($recordKey, $sealKey) ||
            count($journalRecords) < 1 ||
            count($journalRecords) > 3
        ) {
            return stTryNowLedgerRecoveryResult(
                'invalid_expected_evidence'
            );
        }

        $slot = stTryNowJournalSlotId(
            $namespace,
            $reservation['campaign_id'],
            $reservation['customer_id'],
            $bindingKey
        );

        if (!hash_equals(
            $slot,
            $reservation['journal_slot_id']
        )) {
            return stTryNowLedgerRecoveryResult(
                'invalid_expected_evidence'
            );
        }

        $verification = stTryNowVerifyJournalHeadSeal(
            $journalRecords,
            $expectedSeal,
            $recordKey,
            $sealKey
        );

        if (
            $verification['valid'] !== true ||
            $journalRecords[0]['attempt_id'] !==
                $reservation['attempt_id'] ||
            $journalRecords[0]['customer_id'] !==
                $reservation['customer_id'] ||
            $journalRecords[0]['campaign_id'] !==
                $reservation['campaign_id']
        ) {
            return stTryNowLedgerRecoveryResult(
                'invalid_expected_evidence'
            );
        }

    } catch (Throwable $e) {
        return stTryNowLedgerRecoveryResult(
            'invalid_expected_evidence'
        );
    }

    $observationStatus =
        $observation['status'] ?? 'read_unavailable';

    if ($commitSignal === 'not_attempted') {
        return stTryNowLedgerRecoveryResult(
            $observationStatus === 'found'
                ? 'unexpected_evidence_requires_review'
                : 'commit_not_attempted_test_only'
        );
    }

    if ($observationStatus === 'absent') {
        return stTryNowLedgerRecoveryResult(
            $commitSignal === 'acknowledged'
                ? 'acknowledged_but_absent_requires_review'
                : 'commit_outcome_unknown_requires_review'
        );
    }

    if (
        $observationStatus !== 'found' ||
        !is_array($observation['row'] ?? null)
    ) {
        return stTryNowLedgerRecoveryResult(
            'fresh_read_unavailable_requires_review'
        );
    }

    if (!stTryNowLedgerRecoveryRowMatches(
        $observation['row'],
        $reservation,
        $expectedSeal
    )) {
        return stTryNowLedgerRecoveryResult(
            'persisted_checkpoint_conflict_requires_review'
        );
    }

    if ($commitSignal === 'acknowledged') {
        $result = stTryNowLedgerRecoveryResult(
            'acknowledged_and_observed_test_only'
        );

        $result['commit_acknowledgement_reported'] =
            true;

    } elseif (
        $commitSignal === 'acknowledgement_lost'
    ) {
        $result = stTryNowLedgerRecoveryResult(
            'observed_after_lost_ack_requires_review'
        );

    } else {
        $result = stTryNowLedgerRecoveryResult(
            'observed_after_commit_error_requires_review'
        );
    }

    $result['fresh_checkpoint_observed'] = true;

    return $result;
}
