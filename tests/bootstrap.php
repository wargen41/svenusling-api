<?php

$testDirectory = sys_get_temp_dir() . '/svenusling-api-tests-' . bin2hex(random_bytes(8));
define('DB_PATH', $testDirectory . '/data/database.sqlite');
define('JWT_SECRET', 'phpunit-test-signing-key-not-for-production');
define('JWT_ALGORITHM', 'HS256');

require __DIR__ . '/../vendor/autoload.php';

register_shutdown_function(static function () use ($testDirectory): void {
    $databaseFile = DB_PATH;
    if (file_exists($databaseFile)) {
        unlink($databaseFile);
    }

    $dataDirectory = dirname($databaseFile);
    if (is_dir($dataDirectory)) {
        rmdir($dataDirectory);
    }
    if (is_dir($testDirectory)) {
        rmdir($testDirectory);
    }
});
