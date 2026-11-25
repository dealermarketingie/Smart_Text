<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Production write connector safety tests.
 *
 * No database connections, writes, SMS or email.
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-write-db.php';

function checkWriteGate(string $name, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

// Verify that production activation remains disabled.
checkWriteGate(
    'Production write switch disabled',
    ST_TRY_NOW_PRODUCTION_WRITES_ENABLED === false
);

checkWriteGate(
    'Production delivery disabled',
    stTryNowProductionDeliveryEnabled() === false
);

checkWriteGate(
    'Production cleanup disabled',
    stTryNowProductionCleanupEnabled() === false
);

// The connector must reject access before attempting
// to load credentials or establish a PDO connection.
$blocked = false;

try {
    stTryNowProductionWritePdo();
} catch (RuntimeException $e) {
    $blocked = (
        $e->getMessage() ===
        'Production Try Now writes are disabled.'
    );
}

checkWriteGate(
    'Production write connection blocked',
    $blocked
);

// Independently verify the SMS transport safety gate.
$result = stTryNowSendmodeTransport([]);

checkWriteGate(
    'Live Sendmode transport blocked',
    ($result['status'] ?? null) === 'disabled'
    && ($result['accepted'] ?? null) === false
);

echo "PASS: All production write safety tests.\n";
