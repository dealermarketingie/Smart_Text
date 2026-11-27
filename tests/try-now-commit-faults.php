<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - COMMIT fault injection.
 *
 * Synthetic callbacks only.
 * No MariaDB, Redis, HTTP or SMS operations.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-transaction.php';

if (
    stTryNowProductionDeliveryEnabled() !== false ||
    ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED !== false
) {
    fwrite(STDERR, "FAIL: Production gates enabled.\n");
    exit(1);
}

function commitCheck(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

$registration = [
    'status' => 'created',
    'customer_id' => 123,
    'code' => 'Ab1234',
    'mobile' => '0871234567',
    'link' => 'https://stxt.me/LJfjMU/Ab12347907',
];

/*
 * Each scenario describes:
 *
 * Name, commit mode, rollback mode,
 * transaction-state probe failure,
 * expected result, expected rollback calls.
 */
$cases = [
    [
        'Confirmed COMMIT returns registered customer',
        'ok', 'ok', false, 'created', 0,
    ],
    [
        'COMMIT returning false requires review',
        'false', 'ok', false, 'outcome_unknown', 1,
    ],
    [
        'COMMIT exception requires review',
        'throws', 'ok', false, 'outcome_unknown', 1,
    ],
    [
        'Exception after server COMMIT requires review',
        'after', 'ok', false, 'outcome_unknown', 0,
    ],
    [
        'Rollback exception preserves uncertainty',
        'throws', 'throws', false, 'outcome_unknown', 1,
    ],
    [
        'Rollback returning false preserves uncertainty',
        'throws', 'false', false, 'outcome_unknown', 1,
    ],
    [
        'Transaction-state failure preserves uncertainty',
        'throws', 'ok', true, 'outcome_unknown', 0,
    ],
];

foreach ($cases as $case) {
    [
        $name,
        $commitMode,
        $rollbackMode,
        $probeThrows,
        $expectedStatus,
        $expectedRollbacks,
    ] = $case;

    $transactionOpen = true;
    $commitCalls = 0;
    $rollbackCalls = 0;

    $result = stTryNowCommitPreparedRegistration(
        $registration,

        static function () use (
            $commitMode,
            &$transactionOpen,
            &$commitCalls
        ): bool {
            ++$commitCalls;

            if ($commitMode === 'ok') {
                $transactionOpen = false;
                return true;
            }

            if ($commitMode === 'false') {
                return false;
            }

            if ($commitMode === 'after') {
                // Simulate a successful server commit
                // followed by a lost acknowledgement.
                $transactionOpen = false;
            }

            throw new RuntimeException(
                'Synthetic COMMIT failure'
            );
        },

        static function () use (
            &$transactionOpen,
            $probeThrows
        ): bool {
            if ($probeThrows) {
                throw new RuntimeException(
                    'Synthetic transaction-state failure'
                );
            }

            return $transactionOpen;
        },

        static function () use (
            $rollbackMode,
            &$transactionOpen,
            &$rollbackCalls
        ): bool {
            ++$rollbackCalls;

            if ($rollbackMode === 'throws') {
                throw new RuntimeException(
                    'Synthetic rollback failure'
                );
            }

            if ($rollbackMode === 'false') {
                return false;
            }

            $transactionOpen = false;
            return true;
        }
    );

    $correctResult = $expectedStatus === 'created'
        ? $result === $registration
        : $result === ['status' => 'outcome_unknown'];

    commitCheck(
        $name,
        $correctResult &&
        $commitCalls === 1 &&
        $rollbackCalls === $expectedRollbacks
    );
}

echo "PASS: All COMMIT fault-injection tests.\n";
