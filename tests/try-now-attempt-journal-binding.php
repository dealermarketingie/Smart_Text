<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Journal binding tests.
 *
 * Synthetic identity values only.
 * No filesystem or network operations.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__
    . '/../lib/try-now-attempt-journal-binding.php';

require_once __DIR__
    . '/../lib/try-now-runtime.php';

function bindingCheck(
    string $name,
    bool $passed
): void {
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

function bindingRejects(
    callable $operation
): bool {
    try {
        $operation();
    } catch (InvalidArgumentException $e) {
        return true;
    }

    return false;
}

bindingCheck(
    'Production delivery remains disabled',
    ST_TRY_NOW_PUBLIC_LIVE_ENABLED === false &&
    ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false &&
    ST_TRY_NOW_SMS_COPY_APPROVED === false &&
    ST_TRY_NOW_SENDMODE_LIVE_ENABLED === false &&
    ST_TRY_NOW_PRODUCTION_CLEANUP_ENABLED === false &&
    stTryNowProductionDeliveryEnabled() === false
);

// Synthetic test key. Never use for live journals.
$key = str_repeat('B', 32);
$otherKey = str_repeat('C', 32);

$namespace = 'isolated-smarttext-test';

$slot = stTryNowJournalSlotId(
    $namespace,
    7907,
    123,
    $key
);

bindingCheck(
    'Slot identifier is a SHA-256 hex digest',
    preg_match(
        '/\A[0-9a-f]{64}\z/D',
        $slot
    ) === 1
);

bindingCheck(
    'Same customer and campaign produce same slot',
    $slot === stTryNowJournalSlotId(
        $namespace,
        7907,
        123,
        $key
    )
);

bindingCheck(
    'Different customer produces different slot',
    $slot !== stTryNowJournalSlotId(
        $namespace,
        7907,
        124,
        $key
    )
);

bindingCheck(
    'Different campaign produces different slot',
    $slot !== stTryNowJournalSlotId(
        $namespace,
        7908,
        123,
        $key
    )
);

bindingCheck(
    'Different environment produces different slot',
    $slot !== stTryNowJournalSlotId(
        'another-test-environment',
        7907,
        123,
        $key
    )
);

bindingCheck(
    'Different binding key produces different slot',
    $slot !== stTryNowJournalSlotId(
        $namespace,
        7907,
        123,
        $otherKey
    )
);

$filename = stTryNowJournalSlotFilename(
    $slot
);

bindingCheck(
    'Journal filename uses canonical slot identifier',
    $filename === 'slot-' . $slot . '.jsonl'
);

bindingCheck(
    'Journal filename has no path separators',
    strpos($filename, '/') === false &&
    strpos($filename, '\\') === false &&
    strpos($filename, '..') === false
);

bindingCheck(
    'Path traversal namespace rejected',
    bindingRejects(
        static function () use ($key): void {
            stTryNowJournalSlotId(
                '../httpdocs',
                7907,
                123,
                $key
            );
        }
    )
);

bindingCheck(
    'Empty namespace rejected',
    bindingRejects(
        static function () use ($key): void {
            stTryNowJournalSlotId(
                '',
                7907,
                123,
                $key
            );
        }
    )
);

bindingCheck(
    'Invalid customer ID rejected',
    bindingRejects(
        static function () use (
            $namespace,
            $key
        ): void {
            stTryNowJournalSlotId(
                $namespace,
                7907,
                0,
                $key
            );
        }
    )
);

bindingCheck(
    'Invalid campaign ID rejected',
    bindingRejects(
        static function () use (
            $namespace,
            $key
        ): void {
            stTryNowJournalSlotId(
                $namespace,
                -1,
                123,
                $key
            );
        }
    )
);

bindingCheck(
    'Short binding key rejected',
    bindingRejects(
        static function () use (
            $namespace
        ): void {
            stTryNowJournalSlotId(
                $namespace,
                7907,
                123,
                'weak-key'
            );
        }
    )
);

bindingCheck(
    'Invalid filename identifier rejected',
    bindingRejects(
        static function (): void {
            stTryNowJournalSlotFilename(
                '../another-journal'
            );
        }
    )
);

echo "PASS: All journal binding tests.\n";
