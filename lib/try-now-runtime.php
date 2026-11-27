<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Production safety configuration.
 *
 * ALL LIVE OPERATIONS DISABLED BY DEFAULT.
 *
 * Do not read these switches from POST data, cookies,
 * query parameters or HTTP headers.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-sendmode-transport.php';

// Public API must remain in simulation mode.
const ST_TRY_NOW_PUBLIC_LIVE_ENABLED = false;

// No production customer inserts.
const ST_TRY_NOW_PRODUCTION_WRITES_ENABLED = false;

// Six industry-specific SMS messages await approval.
const ST_TRY_NOW_SMS_COPY_APPROVED = false;

// Production customer deletion is not authorised.
const ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED = false;


/**
 * Pure gate evaluation for automated testing.
 * Does not activate any live operation.
 */
function stTryNowAllDeliveryGatesEnabled(
    bool $publicLive,
    bool $productionWrites,
    bool $smsCopyApproved,
    bool $sendmodeLive
): bool {
    return $publicLive
        && $productionWrites
        && $smsCopyApproved
        && $sendmodeLive;
}

function stTryNowProductionDeliveryEnabled(): bool
{
    return stTryNowAllDeliveryGatesEnabled(
        ST_TRY_NOW_PUBLIC_LIVE_ENABLED,
        ST_TRY_NOW_PRODUCTION_WRITES_ENABLED,
        ST_TRY_NOW_SMS_COPY_APPROVED,
        ST_TRY_NOW_SENDMODE_LIVE_ENABLED
    );
}

/**
 * Cleanup requires independent approval.
 */
function stTryNowCleanupGatesEnabled(
    bool $deliveryEnabled,
    bool $cleanupEnabled
): bool {
    return $deliveryEnabled && $cleanupEnabled;
}

function stTryNowProductionCleanupEnabled(): bool
{
    return stTryNowCleanupGatesEnabled(
        stTryNowProductionDeliveryEnabled(),
        ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED
    );
}
