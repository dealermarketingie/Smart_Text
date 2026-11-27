<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - SMS delivery outcome policy.
 *
 * Pure decision logic only.
 * No database writes, customer deletion, HTTP requests
 * or SMS delivery.
 *
 * PHP 7.4 compatible.
 */

function stTryNowDeliveryDecision(array $result): array
{
    $status = $result['status'] ?? null;
    $accepted = $result['accepted'] ?? null;
    $delivered = $result['delivered'] ?? null;

    if (
        $status === 'accepted' &&
        $accepted === true &&
        $delivered === false
    ) {
        return [
            'status' => 'accepted',
            'customer_action' => 'keep',
            'automatic_retry' => false,
        ];
    }

    if (
        $status === 'rejected' &&
        $accepted === false &&
        $delivered === false
    ) {
        return [
            'status' => 'rejected',
            'customer_action' => 'guarded_cleanup',
            'automatic_retry' => false,
        ];
    }

    if (
        $status === 'disabled' &&
        $accepted === false &&
        $delivered === false
    ) {
        return [
            'status' => 'disabled',
            'customer_action' => 'stop_before_registration',
            'automatic_retry' => false,
        ];
    }

    // Timeouts, malformed responses and inconsistent
    // provider results must never trigger automatic retries.
    return [
        'status' => 'unknown',
        'customer_action' => 'keep_for_review',
        'automatic_retry' => false,
    ];
}
