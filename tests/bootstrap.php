<?php

$testDirectory = sys_get_temp_dir() . '/svenusling-api-tests-' . bin2hex(random_bytes(8));
define('DB_PATH', $testDirectory . '/data/database.sqlite');

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
