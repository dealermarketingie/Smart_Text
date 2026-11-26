<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Protected synthetic ledger keys.
 *
 * ISOLATED PLESK CLI TESTING ONLY.
 * PHP 7.4 compatible.
 *
 * Loads keys from fixed private server configuration.
 * Public planning functions do not accept raw keys.
 *
 * No PDO, SQL, filesystem writes, HTTP or SMS.
 */

require_once __DIR__
    . '/try-now-sms-attempt-ledger-procedure-plan.php';

function stTryNowProtectedProbeResult(
    string $status
): array {
    return [
        'status' => $status,
        'evidence_ready' => false,
        'reservation' => null,
        'journal_records' => [],
        'head_seal' => null,
        'procedure_call_candidate' => false,
        'procedure' => null,
        'parameter_count' => 0,
        'requires_authoritative_cas' => true,
        'checkpoint_committed' => false,
        'review_required' => true,
        'safe_for_transport' => false,
        'automatic_send' => false,
        'automatic_retry' => false,
        'automatic_cleanup' => false,
    ];
}

/**
 * Schema validation is pure and safe to unit-test
 * using invented keys, not actual private secrets.
 */
function stTryNowProtectedProbeKeyringSchemaValid(
    $config
): bool {
    $fields = [
        'version',
        'environment',
        'namespace',
        'binding_key_hex',
        'record_key_hex',
        'seal_key_hex',
        'recipient_key_hex',
    ];

    if (
        !is_array($config) ||
        array_keys($config) !== $fields ||
        $config['version'] !== 1 ||
        $config['environment'] !==
            'isolated_probe_cli_only' ||
        $config['namespace'] !==
            'synthetic-restricted-probe-v1'
    ) {
        return false;
    }

    $values = [];

    foreach ([
        'binding_key_hex',
        'record_key_hex',
        'seal_key_hex',
        'recipient_key_hex',
    ] as $field) {
        $value = $config[$field];

        if (
            !is_string($value) ||
            preg_match(
                '/\A[0-9a-f]{64}\z/D',
                $value
            ) !== 1
        ) {
            return false;
        }

        $values[] = $value;
    }

    return count(array_unique($values)) === 4;
}

/**
 * Internal keyring loader.
 *
 * Note: PHP cannot hide secrets from arbitrary code
 * already executing as the same operating-system
 * user. This boundary prevents external callers
 * from substituting keys into the protected
 * planning interface; it does not provide process
 * isolation from compromised server-side code.
 *
 * Never echo, log or return these keys from a
 * user-facing function.
 */
function stTryNowProtectedProbeLoadKeys(): ?array
{
    if (!stTryNowLedgerRecoveryTestAllowed(true)) {
        return null;
    }

    $directory =
        '/var/www/vhosts/smarttext.com/private';

    $file = $directory .
        '/try-now-ledger-security-probe-keys.json';

    $handle = null;

    try {
        if (
            !is_dir($directory) ||
            is_link($directory) ||
            realpath($directory) !== $directory ||
            (fileperms($directory) & 0777) !== 0700 ||
            !is_file($file) ||
            is_link($file) ||
            realpath($file) !== $file
        ) {
            return null;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        $stat = fstat($handle);

        if (
            !is_array($stat) ||
            ($stat['mode'] & 0170000) !== 0100000 ||
            ($stat['mode'] & 0777) !== 0600 ||
            $stat['uid'] !== fileowner(__FILE__) ||
            $stat['gid'] !== filegroup(__FILE__) ||
            $stat['nlink'] !== 1 ||
            $stat['size'] < 200 ||
            $stat['size'] > 1024
        ) {
            return null;
        }

        $json = stream_get_contents(
            $handle,
            1025
        );

        if (
            !is_string($json) ||
            strlen($json) !== $stat['size']
        ) {
            return null;
        }

        $config = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !stTryNowProtectedProbeKeyringSchemaValid(
                $config
            )
        ) {
            return null;
        }

        $keys = [
            'namespace' => $config['namespace'],
            'binding' => hex2bin(
                $config['binding_key_hex']
            ),
            'record' => hex2bin(
                $config['record_key_hex']
            ),
            'seal' => hex2bin(
                $config['seal_key_hex']
            ),
            'recipient' => hex2bin(
                $config['recipient_key_hex']
            ),
        ];

        foreach ([
            'binding',
            'record',
            'seal',
            'recipient',
        ] as $name) {
            if (
                !is_string($keys[$name]) ||
                strlen($keys[$name]) !== 32
            ) {
                return null;
            }
        }

        return $keys;

    } catch (Throwable $e) {
        return null;

    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
}

/**
 * This stage is restricted to a reserved range
 * of synthetic customer identities and campaign
 * 7907. No real recipient is accepted.
 */
function stTryNowProtectedProbeSyntheticValid(
    int $campaignId,
    int $customerId,
    string $attemptId
): bool {
    return
        $campaignId === 7907 &&
        $customerId >= 900001000 &&
        $customerId <= 900001999 &&
        preg_match(
            '/\A[0-9a-f]{32}\z/D',
            $attemptId
        ) === 1;
}

/**
 * Derive a synthetic recipient binding internally.
 *
 * This deliberately does NOT represent an actual
 * mobile phone number and is not a production
 * recipient-binding implementation.
 */
function stTryNowProtectedProbeRecipientDigest(
    string $attemptId,
    string $recipientKey
): string {
    return hash_hmac(
        'sha256',
        "smarttext:try-now:synthetic-recipient:v1\0"
            . $attemptId,
        $recipientKey
    );
}

/**
 * Generate an authenticated PREPARED fixture.
 *
 * The caller supplies identity only, never HMAC
 * keys or a caller-selected recipient digest.
 */
function stTryNowProtectedProbePreparedEvidence(
    int $campaignId,
    int $customerId,
    string $attemptId,
    bool $allowCliTest = false
): array {
    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return stTryNowProtectedProbeResult(
            'disabled'
        );
    }

    if (!stTryNowProtectedProbeSyntheticValid(
        $campaignId,
        $customerId,
        $attemptId
    )) {
        return stTryNowProtectedProbeResult(
            'invalid_synthetic_identity'
        );
    }

    $keys = stTryNowProtectedProbeLoadKeys();

    if ($keys === null) {
        return stTryNowProtectedProbeResult(
            'protected_keyring_unavailable'
        );
    }

    try {
        $reservation = [
            'campaign_id' => $campaignId,
            'customer_id' => $customerId,
            'attempt_id' => $attemptId,
            'journal_slot_id' => stTryNowJournalSlotId(
                $keys['namespace'],
                $campaignId,
                $customerId,
                $keys['binding']
            ),
            'recipient_digest' =>
                stTryNowProtectedProbeRecipientDigest(
                    $attemptId,
                    $keys['recipient']
                ),
        ];

        $record = stTryNowBuildJournalRecord(
            $attemptId,
            $customerId,
            $campaignId,
            1,
            'prepared',
            '2026-11-26T10:00:00Z',
            str_repeat('0', 64),
            $keys['record']
        );

        $records = [$record];

        $seal = stTryNowBuildJournalHeadSeal(
            $records,
            $keys['record'],
            $keys['seal']
        );

        $verification = stTryNowVerifyJournalHeadSeal(
            $records,
            $seal,
            $keys['record'],
            $keys['seal']
        );

        if ($verification['valid'] !== true) {
            return stTryNowProtectedProbeResult(
                'invalid_authenticated_evidence'
            );
        }

        $result = stTryNowProtectedProbeResult(
            'synthetic_prepared_test_only'
        );

        $result['evidence_ready'] = true;
        $result['reservation'] = $reservation;
        $result['journal_records'] = $records;
        $result['head_seal'] = $seal;

        return $result;

    } catch (Throwable $e) {
        return stTryNowProtectedProbeResult(
            'invalid_authenticated_evidence'
        );
    }
}

/**
 * Re-authenticate evidence using the private keys.
 *
 * Returns only a sanitized procedure description,
 * never cryptographic keys or SQL parameters.
 *
 * The existing planner remains responsible for
 * journal integrity and checkpoint policy.
 */
function stTryNowProtectedProbeProcedurePlan(
    string $operation,
    array $reservation,
    array $records,
    array $seal,
    bool $allowCliTest = false
): array {
    if (!stTryNowLedgerRecoveryTestAllowed(
        $allowCliTest
    )) {
        return stTryNowProtectedProbeResult(
            'disabled'
        );
    }

    if (!stTryNowLedgerRecoveryReservationValid(
        $reservation
    )) {
        return stTryNowProtectedProbeResult(
            'invalid_reservation'
        );
    }

    if (!stTryNowProtectedProbeSyntheticValid(
        $reservation['campaign_id'],
        $reservation['customer_id'],
        $reservation['attempt_id']
    )) {
        return stTryNowProtectedProbeResult(
            'invalid_synthetic_identity'
        );
    }

    $keys = stTryNowProtectedProbeLoadKeys();

    if ($keys === null) {
        return stTryNowProtectedProbeResult(
            'protected_keyring_unavailable'
        );
    }

    $expectedRecipient =
        stTryNowProtectedProbeRecipientDigest(
            $reservation['attempt_id'],
            $keys['recipient']
        );

    if (!hash_equals(
        $expectedRecipient,
        $reservation['recipient_digest']
    )) {
        return stTryNowProtectedProbeResult(
            'recipient_binding_mismatch'
        );
    }

    $plan = stTryNowRestrictedProcedureCallPlan(
        $operation,
        $reservation,
        $records,
        $seal,
        $keys['namespace'],
        $keys['binding'],
        $keys['record'],
        $keys['seal'],
        true
    );

    if (
        $plan['procedure_call_candidate'] !== true ||
        $plan['status'] !==
            'ready_for_restricted_procedure_test_only'
    ) {
        return stTryNowProtectedProbeResult(
            'plan_' . $plan['status']
        );
    }

    $result = stTryNowProtectedProbeResult(
        'ready_for_restricted_procedure_test_only'
    );

    $result['procedure_call_candidate'] = true;
    $result['procedure'] = $plan['procedure'];
    $result['parameter_count'] =
        count($plan['parameters']);

    return $result;
}
