<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Transaction-based registration.
 *
 * DISABLED BY DEFAULT.
 * Not connected to the public Try Now API.
 * Requires an explicitly authorised write-enabled PDO connection.
 */

require_once __DIR__ . '/try-now-registration.php';
require_once __DIR__ . '/try-now-duplicates.php';
require_once __DIR__ . '/try-now-codes.php';

function stTryNowRegisterCustomer(
    PDO $pdo,
    array $post,
    bool $allowWrites = false
): array {
    // Safety gate: never write unless explicitly enabled.
    if (!$allowWrites) {
        return ['status' => 'disabled'];
    }

    $prepared = stTryNowPrepareRegistration($post);

    if (!$prepared['ok']) {
        return [
            'status' => 'invalid',
            'errors' => $prepared['errors'],
        ];
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException(
            'Registration requires MySQL/MariaDB.'
        );
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Registration requires its own transaction.'
        );
    }

    $customer = $prepared['customer'];
    $campaignId = (int) $customer['campaign_id'];
    $sample = stTryNowSampleForIndustry($prepared['industry']);

    if ($sample === null ||
        (int) $sample['campaign_id'] !== $campaignId) {
        return ['status' => 'invalid'];
    }

    $expectedUrl = 'https://stxt.me/' . $sample['short_id'];

    $pdo->beginTransaction();

    try {
        /*
         * Lock the campaign row.
         *
         * Other registrations using this same procedure
         * must wait until this transaction completes.
         */
        $lock = $pdo->prepare(
            'SELECT campaign_link
             FROM campaign
             WHERE id = ?
             FOR UPDATE'
        );

        $lock->execute([$campaignId]);
        $campaignUrl = $lock->fetchColumn();

        if ($campaignUrl === false ||
            rtrim((string) $campaignUrl, '/') !== $expectedUrl) {
            $pdo->rollBack();
            return ['status' => 'unavailable'];
        }

        // Duplicate check takes place while holding the lock.
        if (stTryNowDuplicateExists(
            $pdo,
            $campaignId,
            $customer['mobile']
        )) {
            $pdo->rollBack();
            return ['status' => 'duplicate'];
        }

        // Allocate a code while holding the same lock.
        $code = stTryNowGenerateUnusedCustomerCode(
            $pdo,
            $campaignId
        );

        $insert = $pdo->prepare(
            'INSERT INTO customer
                (code, first_name, last_name, mobile, campaign_id)
             VALUES (?, ?, ?, ?, ?)'
        );

        $insert->execute([
            $code,
            $customer['first_name'],
            $customer['last_name'],
            $customer['mobile'],
            $campaignId,
        ]);

        // Capture the exact record created by this transaction.
        $customerId = (int) $pdo->lastInsertId();

        if ($customerId <= 0) {
            throw new RuntimeException(
                'Unable to identify the newly registered customer.'
            );
        }

        $pdo->commit();


        return [
            'status' => 'created',
            'customer_id' => $customerId,
            'code' => $code,
            'link' => stTryNowBuildCustomerLink(
                $prepared['industry'],
                $code
            ),
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
