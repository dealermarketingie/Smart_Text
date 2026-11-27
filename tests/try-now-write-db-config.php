<?php
declare(strict_types=1);

/**
 * Smart Text Try Now - Write configuration tests.
 *
 * Synthetic configuration values only.
 * No database connection, writes, SMS or email.
 *
 * PHP 7.4 compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/try-now-write-db.php';

$valid = [
    'host' => '127.0.0.1',
    'database' => 'smarttext',
    'username' => 'smarttext_try_now_writer',
    'password' => 'synthetic-test-value',
    'charset' => 'utf8mb4',
];

$cases = [
    'Expected configuration accepted' =>
        [$valid, true],

    'Localhost accepted' =>
        [array_replace($valid, ['host' => 'localhost']), true],

    'utf8mb3 accepted' =>
        [array_replace($valid, ['charset' => 'utf8mb3']), true],

    'Read-only account rejected' =>
        [array_replace($valid, [
            'username' => 'smarttext_readonly'
        ]), false],

    'Root account rejected' =>
        [array_replace($valid, [
            'username' => 'root'
        ]), false],

    'Application account rejected' =>
        [array_replace($valid, [
            'username' => 'smarttext_app'
        ]), false],

    'Wrong database rejected' =>
        [array_replace($valid, [
            'database' => 'other_database'
        ]), false],

    'Remote host rejected' =>
        [array_replace($valid, [
            'host' => 'example.com'
        ]), false],

    'Unexpected host value rejected' =>
        [array_replace($valid, [
            'host' => '127.0.0.1;port=33080'
        ]), false],

    'Unsupported charset rejected' =>
        [array_replace($valid, [
            'charset' => 'latin1'
        ]), false],

    'Empty password rejected' =>
        [array_replace($valid, [
            'password' => ''
        ]), false],

    'Missing password rejected' =>
        [array_diff_key(
            $valid,
            ['password' => true]
        ), false],

    'Invalid password type rejected' =>
        [array_replace($valid, [
            'password' => ['invalid']
        ]), false],

    'Missing username rejected' =>
        [array_diff_key(
            $valid,
            ['username' => true]
        ), false],

    'Empty username rejected' =>
        [array_replace($valid, [
            'username' => ''
        ]), false],

    'Invalid host type rejected' =>
        [array_replace($valid, [
            'host' => 123
        ]), false],
];

foreach ($cases as $name => $case) {
    [$config, $expected] = $case;

    if (stTryNowWriteConfigValid($config) !== $expected) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }

    echo "PASS: $name\n";
}

echo "PASS: All production write configuration tests.\n";
