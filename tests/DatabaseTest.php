<?php

namespace Tests;

use App\Config\Database;
use PDOException;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    private static Database $database;

    public static function setUpBeforeClass(): void
    {
        self::$database = Database::getInstance();
        self::$database->initializeTables();
    }

    public function testInitializationCreatesCoreTables(): void
    {
        $tables = self::$database->getConnection()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach (['movies', 'genres', 'media', 'users', 'reviews'] as $table) {
            self::assertContains($table, $tables);
        }
    }

    public function testForeignKeyConstraintsAreEnabled(): void
    {
        $enabled = self::$database->getConnection()
            ->query('PRAGMA foreign_keys')
            ->fetchColumn();

        self::assertSame(1, (int) $enabled);
    }

    public function testForeignKeyConstraintsRejectInvalidReferences(): void
    {
        $this->expectException(PDOException::class);

        self::$database->getConnection()->exec(
            'INSERT INTO movies_genres (movie_id, genre_id) VALUES (999, 999)'
        );
    }

    public function testInitializationCanBeRepeated(): void
    {
        $connection = self::$database->getConnection();
        $tableCountBefore = $connection
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")
            ->fetchColumn();

        self::$database->initializeTables();

        $tableCountAfter = $connection
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")
            ->fetchColumn();

        self::assertSame((int) $tableCountBefore, (int) $tableCountAfter);
    }
}
