<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Live admission outcomes.
 *
 * Pure response decision logic.
 * No Redis, database, HTTP or SMS operations.
 *
 * PHP 7.4 compatible.
 */

function stTryNowAdmissionUnavailableOutcome(): array
{
    return [
        'proceed' => false,
        'http_status' => 503,
        'retry_after' => null,
        'message' => 'Try Now is temporarily unavailable.',
    ];
}

/**
 * Convert an admission-guard result into a safe decision.
 *
 * Only an explicitly allowed result may proceed.
 * All unexpected states fail closed.
 */
function stTryNowAdmissionOutcome(array $admission): array
{
    if (
        ($admission['status'] ?? null) === 'allowed' &&
        ($admission['allowed'] ?? null) === true &&
        ($admission['retry_after'] ?? null) === 0
    ) {
        return [
            'proceed' => true,
            'http_status' => null,
            'retry_after' => null,
            'message' => null,
        ];
    }

    $retryAfter = $admission['retry_after'] ?? null;

    if (
        ($admission['status'] ?? null) === 'limited' &&
        ($admission['allowed'] ?? null) === false &&
        is_int($retryAfter) &&
        $retryAfter >= 1 &&
        $retryAfter <= 86400
    ) {
        return [
            'proceed' => false,
            'http_status' => 429,
            'retry_after' => $retryAfter,
            'message' => 'Too many requests. Please try again later.',
        ];
    }

    return stTryNowAdmissionUnavailableOutcome();
}

/**
 * Execute an admission check safely.
 *
 * Any exception (including Redis failures)
 * produces HTTP 503 and blocks further processing.
 *
 * The callback will later invoke the real guard.
 * Tests can supply synthetic outcomes instead.
 */
function stTryNowEvaluateLiveAdmission(callable $checker): array
{
    try {
        $result = $checker();

        if (!is_array($result)) {
            return stTryNowAdmissionUnavailableOutcome();
        }

        return stTryNowAdmissionOutcome($result);

    } catch (Throwable $e) {
        return stTryNowAdmissionUnavailableOutcome();
    }
}
