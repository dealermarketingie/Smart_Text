<?php
declare(strict_types=1);

/**
 * Controlled Try Now SMS delivery workflow.
 * NEVER call from a public request unless ALL runtime gates are enabled,
 * validation and Redis admission have succeeded.
 *
 * This file does not enable live sending: see try-now-runtime.php and
 * try-now-sendmode-transport.php, both hard-disabled in the current branch.
 *
 * Attempt order:
 *   1. In one transaction, insert customer, reserved ledger and 'prepared'.
 *   2. Commit the transaction.
 *   3. Commit 'transport_started' BEFORE attempting any Sendmode request.
 *   4. Call Sendmode once, and commit the response evidence.
 * No automatic retries, deletions or updates to existing customers.
 *
 * PHP 7.4+ compatible.
 */
require_once __DIR__ . '/try-now-live-response.php';
require_once __DIR__ . '/try-now-write-db.php';
require_once __DIR__ . '/try-now-persist.php';
require_once __DIR__ . '/try-now-sms.php';
require_once __DIR__ . '/try-now-sendmode.php';
require_once __DIR__ . '/try-now-sendmode-transport.php';
require_once __DIR__ . '/try-now-attempt-journal-db.php';
require_once __DIR__ . '/try-now-runtime.php';

/** Private configuration outside the web document root. */
function stTryNowLoadSendmodeConfig(): array
{
    // Works in /httpdocs/lib as well as /try-now-check/lib.
    $path = dirname(__DIR__, 2) . '/private/try-now-sendmode.php';
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        throw new RuntimeException('Sendmode configuration unavailable.');
    }
    $config = require $path;
    if (!is_array($config) ||
        !is_string($config['access_key'] ?? null) ||
        $config['access_key'] === '' ||
        preg_match('/[\r\n]/', $config['access_key']) ||
        !is_string($config['sender_id'] ?? null) ||
        !preg_match('/^[A-Za-z0-9 ]{1,11}$/D', $config['sender_id']) ||
        trim($config['sender_id']) === '') {
        throw new RuntimeException('Invalid Sendmode configuration.');
    }
    return [
        'access_key' => $config['access_key'],
        'sender_id' => $config['sender_id'],
    ];
}

/** No phone numbers, names, URLs, credentials or provider bodies in logs. */
function stTryNowDeliveryLog(string $stage, Throwable $error, int $attemptId = 0): void
{
    $sqlstate = ($error instanceof PDOException) ? (string) $error->getCode() : 'n/a';
    error_log(sprintf(
        'Try Now SMS stage=%s attempt_id=%d exception=%s sqlstate=%s',
        $stage,
        $attemptId,
        get_class($error),
        $sqlstate
    ));
}

/**
 * Run only AFTER a validated live POST has passed its Redis admission check.
 * Returns a safe HTTP response descriptor (never calls header()/exit).
 */
function stTryNowExecuteLiveRequest(array $post): array
{
    if (!stTryNowProductionDeliveryEnabled()) {
        return stTryNowLiveResponse('unavailable');
    }

    // Preflight provider credentials before creating ANY records.
    try {
        $sendmodeConfig = stTryNowLoadSendmodeConfig();
        $pdo = stTryNowWritePdo();
    } catch (Throwable $e) {
        stTryNowDeliveryLog('preflight', $e);
        return stTryNowLiveResponse('unavailable');
    }

    $attemptId = 0;
    try {
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Unable to start registration transaction.');
        }

        $prepared = stTryNowInsertPreparedAttempt($pdo, $post);
        if (($prepared['ok'] ?? null) !== true) {
            $pdo->rollBack();
            $status = $prepared['status'] ?? null;
            if ($status === 'invalid') {
                return stTryNowLiveResponse('invalid', $prepared['errors'] ?? []);
            }
            if ($status === 'already_registered') {
                return stTryNowLiveResponse('already_requested');
            }
            return stTryNowLiveResponse('unavailable');
        }

        $attemptId = (int) $prepared['attempt_id'];
        $industry = (string) ($post['industry'] ?? '');

        // Assemble and verify the exact message BEFORE committing registration.
        $sms = stTryNowPrepareSms(
            $industry,
            (string) $prepared['mobile_normalized'],
            (string) $prepared['customer_code'],
            (string) $post['firstName']
        );
        $payload = $sms['payload'] ?? [];
        if (($payload['recipients'][0] ?? null) !== $prepared['mobile_normalized'] ||
            !is_string($payload['messagetext'] ?? null) ||
            strpos($payload['messagetext'], $prepared['sample_link']) === false) {
            throw new RuntimeException('SMS preparation mismatch.');
        }
        $sendmodeRequest = stTryNowBuildSendmodeRequest($sms);

        if (!$pdo->commit()) {
            throw new RuntimeException('Registration transaction did not commit.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $ignored) {
                // Do not retry even when a commit outcome is uncertain.
            }
        }
        stTryNowDeliveryLog('registration', $e, $attemptId);
        return stTryNowLiveResponse('unavailable');
    }

    // Critical durable boundary. Never send without successful return.
    try {
        stTryNowCommitTransportStarted($pdo, $attemptId);
    } catch (Throwable $e) {
        stTryNowDeliveryLog('transport_boundary', $e, $attemptId);
        return stTryNowLiveResponse('unavailable');
    }

    // ONE provider call. Failure may mean the SMS was submitted; never retry.
    try {
        $providerResult = stTryNowSendmodeTransport(
            $sendmodeRequest,
            $sendmodeConfig['access_key'],
            $sendmodeConfig['sender_id']
        );
    } catch (Throwable $e) {
        stTryNowDeliveryLog('provider_call', $e, $attemptId);
        $providerResult = [
            'status' => 'unknown',
            'accepted' => false,
            'delivered' => false,
            'provider_code' => null,
        ];
    }
    unset($sendmodeConfig);

    try {
        // Includes response structure validation; unexpected results fail closed.
        stTryNowCommitProviderOutcome($pdo, $attemptId, $providerResult);
    } catch (Throwable $e) {
        stTryNowDeliveryLog('provider_journal', $e, $attemptId);
        return stTryNowLiveResponse('provider_unknown');
    }

    if (($providerResult['status'] ?? null) === 'accepted' &&
        ($providerResult['accepted'] ?? null) === true) {
        return stTryNowLiveResponse('accepted');
    }
    if (($providerResult['status'] ?? null) === 'rejected') {
        return stTryNowLiveResponse('provider_rejected');
    }
    return stTryNowLiveResponse('provider_unknown');
}
