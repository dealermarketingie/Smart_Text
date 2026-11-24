<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Isolated workflow integration.
 *
 * CLI TESTING ONLY.
 * Uses the isolated MariaDB database and simulated
 * Sendmode outcomes.
 *
 * No HTTP requests, live SMS or email.
 * Production access is explicitly blocked.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-transaction.php';
require_once __DIR__ . '/try-now-sms.php';
require_once __DIR__ . '/try-now-sendmode.php';
require_once __DIR__ . '/try-now-cleanup.php';

function stTryNowRunIsolatedWorkflow(
    PDO $pdo,
    array $post,
    array $simulatedSendmodeResult,
    bool $allowTestWrites = false
): array {
    // Never run from the public website.
    if (!$allowTestWrites || PHP_SAPI !== 'cli') {
        return [
            'status' => 'disabled',
            'sms_sent' => false,
        ];
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException(
            'Isolated workflow requires MariaDB.'
        );
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Workflow requires no active transaction.'
        );
    }

    // Reject anything except our dedicated test database.
    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !$identity ||
        $identity['db'] !== 'smarttext_try_now_test' ||
        strpos($identity['db_user'], 'try_now_test@') !== 0
    ) {
        return [
            'status' => 'disabled',
            'sms_sent' => false,
        ];
    }

    $decision = stTryNowDeliveryDecision(
        $simulatedSendmodeResult
    );

    // Disabled delivery must stop BEFORE customer insertion.
    if ($decision['status'] === 'disabled') {
        return [
            'status' => 'disabled',
            'sms_sent' => false,
        ];
    }

    // Validate before any database write.
    $prepared = stTryNowPrepareRegistration($post);

    if (!$prepared['ok']) {
        return [
            'status' => 'invalid',
            'errors' => $prepared['errors'],
            'sms_sent' => false,
        ];
    }

    // Register using our previously tested transaction.
    $registration = stTryNowRegisterCustomer(
        $pdo,
        $post,
        true
    );

    if ($registration['status'] !== 'created') {
        return [
            'status' => $registration['status'],
            'sms_sent' => false,
        ];
    }

    $customerId = (int) $registration['customer_id'];
    $code = $registration['code'];
    $customer = $prepared['customer'];

    try {
        // Prepare personalised SMS for the actual new code.
        $sms = stTryNowPrepareSms(
            $prepared['industry'],
            $customer['mobile'],
            $code
        );

        // Verify Sendmode request construction.
        // This does NOT transmit the request.
        stTryNowBuildSendmodeRequest($sms);

    } catch (Throwable $e) {
        // Customer already exists. Do not delete or retry.
        return [
            'status' => 'review_required',
            'customer_id' => $customerId,
            'sms_sent' => false,
            'automatic_retry' => false,
        ];
    }

    // Simulated acceptance, NOT a real SMS.
    if ($decision['status'] === 'accepted') {
        return [
            'status' => 'simulated_accepted',
            'customer_id' => $customerId,
            'sms_sent' => false,
            'automatic_retry' => false,
        ];
    }

    // Simulated explicit rejection.
    if ($decision['status'] === 'rejected') {
        $cleanup = stTryNowGuardedCleanup(
            $pdo,
            $customerId,
            (int) $customer['campaign_id'],
            $code,
            $customer['mobile'],
            $simulatedSendmodeResult,
            true
        );

        return [
            'status' => $cleanup['deleted']
                ? 'simulated_rejected_cleaned'
                : 'review_required',
            'customer_id' => $customerId,
            'customer_deleted' => $cleanup['deleted'],
            'sms_sent' => false,
            'automatic_retry' => false,
        ];
    }

    // Unknown outcomes always preserve the customer.
    return [
        'status' => 'review_required',
        'customer_id' => $customerId,
        'sms_sent' => false,
        'automatic_retry' => false,
    ];
}
