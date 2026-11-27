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
require_once __DIR__ . '/try-now-runtime.php';

/**
 * Authorise registration only for:
 *
 * 1. The isolated MariaDB database during CLI tests
 *    while production delivery is disabled.
 *
 * 2. The dedicated production writer account when
 *    every production delivery gate is enabled.
 *
 * Pure logic: no connections or database operations.
 */
function stTryNowRegistrationConnectionAllowed(
    string $database,
    string $authenticatedUser,
    bool $deliveryEnabled,
    bool $isCli
): bool {
    if (
        $database === 'smarttext_try_now_test' &&
        strpos($authenticatedUser, 'try_now_test@') === 0
    ) {
        return $isCli && !$deliveryEnabled;
    }

    if (
        $database === 'smarttext' &&
        strpos(
            $authenticatedUser,
            'smarttext_try_now_writer@'
        ) === 0
    ) {
        return $deliveryEnabled;
    }

    return false;
}

/**
 * Finalise a prepared registration transaction.
 *
 * The callbacks allow commit failures to be tested
 * without opening a real database connection.
 *
 * Only call this after:
 * - Database identity has been authorised.
 * - The customer INSERT has succeeded.
 * - The complete registration result is ready.
 *
 * Any uncertain COMMIT outcome requires review.
 * Never automatically retry or send SMS here.
 */
function stTryNowCommitPreparedRegistration(
    array $registrationResult,
    callable $commit,
    callable $inTransaction,
    callable $rollBack
): array {
    try {
        if ($commit() !== true) {
            throw new RuntimeException(
                'Registration commit not confirmed.'
            );
        }

        return $registrationResult;

    } catch (Throwable $e) {
        /*
         * A failed COMMIT acknowledgement does not
         * prove whether the transaction committed.
         *
         * Attempt rollback if the connection still
         * reports an active transaction.
         *
         * Even a successful rollback attempt cannot
         * justify an automatic retry here.
         */
        try {
            if ($inTransaction()) {
                $rollBack();
            }
        } catch (Throwable $ignored) {
            // Preserve the uncertain outcome.
        }

        return [
            'status' => 'outcome_unknown',
        ];
    }
}

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

    // Independently verify the authenticated database
    // identity before beginning any write transaction.
    $statement = $pdo->query(
        'SELECT DATABASE() AS db, CURRENT_USER() AS db_user'
    );

    $identity = $statement instanceof PDOStatement
        ? $statement->fetch(PDO::FETCH_ASSOC)
        : false;

    if (
        !is_array($identity) ||
        !is_string($identity['db'] ?? null) ||
        !is_string($identity['db_user'] ?? null) ||
        !stTryNowRegistrationConnectionAllowed(
            $identity['db'],
            $identity['db_user'],
            stTryNowProductionDeliveryEnabled(),
            PHP_SAPI === 'cli'
        )
    ) {
        throw new RuntimeException(
            'Registration connection is not authorised.'
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

         /*
         * Prepare the complete registration result
         * BEFORE attempting the database commit.
         *
         * No link generation or other potentially
         * failing processing should follow a
         * confirmed commit.
         */
        $registrationResult = [
            'status' => 'created',
            'customer_id' => $customerId,
            'code' => $code,
            'mobile' => $customer['mobile'],
            'link' => stTryNowBuildCustomerLink(
                $prepared['industry'],
                $code
            ),
        ];

        /*
         * Once COMMIT has been attempted, an exception
         * does not prove whether MariaDB committed.
         */

        return stTryNowCommitPreparedRegistration(
            $registrationResult,
            static function () use ($pdo): bool {
                return $pdo->commit();
            },
            static function () use ($pdo): bool {
                return $pdo->inTransaction();
            },
            static function () use ($pdo): bool {
                return $pdo->rollBack();
            }
        );

    } catch (Throwable $e) {
        $rollbackFailed = false;

        try {
            if ($pdo->inTransaction()) {
                if ($pdo->rollBack() !== true) {
                    $rollbackFailed = true;
                }
            }
        } catch (Throwable $ignored) {
            $rollbackFailed = true;
        }

        /*
         * An attempted commit or failed rollback
         * leaves an uncertain database outcome.
         *
         * Never report this as an ordinary failed
         * registration or automatically retry.
         */
        if ($rollbackFailed) {
            return [
                'status' => 'outcome_unknown',
            ];
        }

        // An ordinary pre-commit error with no
        // rollback failure retains its exception.
        throw $e;
    }
}
