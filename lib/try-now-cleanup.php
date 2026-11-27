<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Guarded customer cleanup.
 *
 * TEST DATABASE ONLY.
 * Disabled by default.
 * Never deletes after an accepted or unknown SMS outcome.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/try-now-delivery-policy.php';
require_once __DIR__ . '/try-now-support.php';

function stTryNowGuardedCleanup(
    PDO $pdo,
    int $customerId,
    int $campaignId,
    string $code,
    string $mobile,
    array $sendmodeResult,
    bool $allowDeletion = false
): array {
    // First safety gate: deletion must be explicitly enabled.
    if (!$allowDeletion) {
        return ['status' => 'disabled', 'deleted' => false];
    }

    // Only an explicit provider rejection qualifies.
    $decision = stTryNowDeliveryDecision($sendmodeResult);

    if (
        $decision['status'] !== 'rejected' ||
        $decision['customer_action'] !== 'guarded_cleanup' ||
        !isset($sendmodeResult['provider_code']) ||
        !is_int($sendmodeResult['provider_code']) ||
        $sendmodeResult['provider_code'] <= 0
    ) {
        return ['status' => 'not_eligible', 'deleted' => false];
    }

    // Validate the exact customer identifiers.
    if (
        $customerId <= 0 ||
        !in_array(
            $campaignId,
            array_column(stTryNowSamples(), 'campaign_id'),
            true
        ) ||
        !preg_match('/^[A-Za-z][A-Za-z0-9]{5}$/D', $code) ||
        stTryNowNormalizeMobile($mobile) !== $mobile
    ) {
        throw new InvalidArgumentException(
            'Invalid cleanup identifiers.'
        );
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException(
            'Cleanup requires MySQL/MariaDB.'
        );
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Cleanup requires its own transaction.'
        );
    }

    // Second safety gate: permit deletion in our test DB only.
    $identity = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !$identity ||
        $identity['db'] !== 'smarttext_try_now_test' ||
        strpos($identity['db_user'], 'try_now_test@') !== 0
    ) {
        return ['status' => 'disabled', 'deleted' => false];
    }

    $pdo->beginTransaction();

    try {
        // Use the same campaign lock as customer registration.
        $lock = $pdo->prepare(
            'SELECT id FROM campaign
             WHERE id = ?
             FOR UPDATE'
        );

        $lock->execute([$campaignId]);
        $campaignExists = $lock->fetchColumn();
        $lock->closeCursor();

        if ($campaignExists === false) {
            $pdo->rollBack();
            return [
                'status' => 'not_eligible',
                'deleted' => false,
            ];
        }

        /*
         * Delete only the exact, recent, unengaged record.
         * A mismatch in ANY condition prevents deletion.
         */
        $delete = $pdo->prepare(
            'DELETE FROM customer
             WHERE id = ?
               AND campaign_id = ?
               AND BINARY code = BINARY ?
               AND BINARY mobile = BINARY ?
               AND clicked = 0
               AND booked = 0
               AND called = 0
               AND date_added >=
                   CURRENT_TIMESTAMP - INTERVAL 5 MINUTE
               AND date_added <= CURRENT_TIMESTAMP'
        );

        $delete->execute([
            $customerId,
            $campaignId,
            $code,
            $mobile,
        ]);

        $deleted = $delete->rowCount() === 1;

        $pdo->commit();

        return [
            'status' => $deleted ? 'deleted' : 'not_eligible',
            'deleted' => $deleted,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
