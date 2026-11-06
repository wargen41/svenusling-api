<?php

use App\Config\Database;

$testDirectory = sys_get_temp_dir() . '/svenusling-api-tests-' . bin2hex(random_bytes(8));
define('DB_PATH', $testDirectory . '/data/database.sqlite');

require __DIR__ . '/../src/Config/Database.php';

function assertTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tests = [
    'database initializes the core tables' => static function (): void {
        $databaseInstance = Database::getInstance();
        $databaseInstance->initializeTables();
        $database = $databaseInstance->getConnection();
        $tables = $database->query(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach (['movies', 'genres', 'media', 'users', 'reviews'] as $table) {
            assertTest(in_array($table, $tables, true), "Expected table '$table' to exist");
        }
    },
    'database enables foreign key constraints' => static function (): void {
        $enabled = Database::getInstance()->getConnection()
            ->query('PRAGMA foreign_keys')
            ->fetchColumn();

        assertTest((int) $enabled === 1, 'Expected SQLite foreign key constraints to be enabled');
    },
    'database initialization can be repeated' => static function (): void {
        $database = Database::getInstance();
        $database->initializeTables();
        $tableCount = $database->getConnection()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")
            ->fetchColumn();

        assertTest((int) $tableCount > 0, 'Expected tables to remain after repeated initialization');
    },
];

$passed = 0;
$failed = 0;

try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
            echo "PASS: $name\n";
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, "FAIL: $name - {$exception->getMessage()}\n");
        }
    }
} finally {
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
}

echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
