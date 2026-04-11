<?php
namespace App\Config;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        error_log('Database::__construct() called');
        
        try {
            $dbDir = dirname(DB_PATH);
            
            if (!is_dir($dbDir)) {
                error_log('Database directory does not exist, creating...');
                if (!mkdir($dbDir, 0755, true)) {
                    throw new \Exception("Could not create database directory: $dbDir");
                }
                error_log("✓ Created database directory: $dbDir");
            } else {
                error_log('Database directory exists');
            }
            
            if (!is_writable($dbDir)) {
                throw new \Exception("Database directory is not writable: $dbDir");
            }
            
            error_log('Database directory is writable');
            error_log('Connecting to database: ' . DB_PATH);
            
            $this->connection = new PDO(
                'sqlite:' . DB_PATH,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            
            error_log('✓ PDO connection created');
            
            if (file_exists(DB_PATH)) {
                error_log('✓ Database file confirmed to exist: ' . DB_PATH);
                $size = filesize(DB_PATH);
                error_log('  File size: ' . $size . ' bytes');
            } else {
                error_log('✗ Database file NOT found after PDO creation');
            }
            
            $this->connection->exec('PRAGMA foreign_keys = ON');
            error_log('✓ Foreign keys enabled');
            
        } catch (PDOException $e) {
            error_log('✗ PDOException in Database constructor: ' . $e->getMessage());
            error_log('Code: ' . $e->getCode());
            throw new \Exception('Database connection failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            error_log('✗ Exception in Database constructor: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance()
    {
        error_log('Database::getInstance() called');
        
        if (self::$instance === null) {
            error_log('Creating new Database instance...');
            self::$instance = new self();
            error_log('✓ Database instance created');
        } else {
            error_log('Returning existing Database instance');
        }
        
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function initializeTables()
    {
        error_log('Database::initializeTables() called');
        
        try {
            $tables = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
            $existingTables = array_column($tables, 'name');
            error_log('Found tables: ' . json_encode($existingTables));
            
            if (in_array('movies', $existingTables)) {
                error_log('✓ Tables already exist, skipping creation');
                return;
            }
            
            error_log('Creating tables...');

            // Media table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS media (
                    id INTEGER PRIMARY KEY,
                    file_name TEXT NOT NULL,
                    media_type TEXT NOT NULL,
                    file_directory TEXT NOT NULL,
                    caption TEXT,
                    attribution TEXT
                )
            ');
            error_log('✓ Media table created');

            // Persons table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS persons (
                    id INTEGER PRIMARY KEY,
                    category TEXT NOT NULL,
                    name TEXT NOT NULL,
                    birth_date TEXT,
                    death_date TEXT,
                    poster_image_id INTEGER,
                    FOREIGN KEY(poster_image_id) REFERENCES media(id)
                )
            ');
            error_log('✓ Persons table created');

            // Genres table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS genres (
                    id INTEGER PRIMARY KEY,
                    common INTEGER NOT NULL,
                    sv TEXT NOT NULL,
                    en TEXT NOT NULL
                )
            ');
            error_log('✓ Genres table created');

            // Movies table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS movies (
                    id INTEGER PRIMARY KEY,
                    hidden INTEGER NOT NULL,
                    added_date TEXT,
                    type TEXT,
                    series_id INTEGER,
                    season_id INTEGER,
                    sequence_number INTEGER,
                    sequence_number_2 INTEGER,
                    title TEXT NOT NULL,
                    original_title TEXT,
                    sorting_title TEXT NOT NULL,
                    year TEXT,
                    year_2 TEXT,
                    rating INTEGER NOT NULL,
                    poster_image_id INTEGER,
                    large_image_id INTEGER,
                    imdb_id TEXT,
                    description TEXT,
                    created_by INTEGER,
                    created_at TEXT,
                    updated_at TEXT,
                    FOREIGN KEY(series_id) REFERENCES movies(id),
                    FOREIGN KEY(season_id) REFERENCES movies(id),
                    FOREIGN KEY(poster_image_id) REFERENCES media(id),
                    FOREIGN KEY(large_image_id) REFERENCES media(id)
                )
            ');
            error_log('✓ Movies table created');

            // Movies genres junction table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS movies_genres (
                    movie_id INTEGER NOT NULL,
                    genre_id INTEGER NOT NULL,
                    PRIMARY KEY (movie_id, genre_id),
                    FOREIGN KEY(movie_id) REFERENCES movies(id),
                    FOREIGN KEY(genre_id) REFERENCES genres(id)
                )
            ');
            error_log('✓ Movies genres table created');

            // Movies persons junction table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS movies_persons (
                    movie_id INTEGER NOT NULL,
                    person_id INTEGER NOT NULL,
                    person_name TEXT NOT NULL,
                    category TEXT NOT NULL,
                    role_name TEXT,
                    note TEXT,
                    sequence_number INTEGER NOT NULL,
                    FOREIGN KEY(movie_id) REFERENCES movies(id),
                    FOREIGN KEY(person_id) REFERENCES persons(id)
                )
            ');
            error_log('✓ Movies persons table created');

            // Movies trivia table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS movies_trivia (
                    movie_id INTEGER NOT NULL,
                    sv TEXT,
                    en TEXT,
                    FOREIGN KEY(movie_id) REFERENCES movies(id)
                )
            ');
            error_log('✓ Movies trivia table created');

            // Persons trivia table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS persons_trivia (
                    person_id INTEGER NOT NULL,
                    sv TEXT,
                    en TEXT,
                    FOREIGN KEY(person_id) REFERENCES persons(id)
                )
            ');
            error_log('✓ Persons trivia table created');

            // Media persons junction table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS media_persons (
                    media_id INTEGER NOT NULL,
                    person_id INTEGER NOT NULL,
                    FOREIGN KEY(media_id) REFERENCES media(id),
                    FOREIGN KEY(person_id) REFERENCES persons(id)
                )
            ');
            error_log('✓ Media persons table created');

            // Media movies junction table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS media_movies (
                    media_id INTEGER NOT NULL,
                    movie_id INTEGER NOT NULL,
                    FOREIGN KEY(media_id) REFERENCES media(id),
                    FOREIGN KEY(movie_id) REFERENCES movies(id)
                )
            ');
            error_log('✓ Media movies table created');

            // Movies quotes table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS movies_quotes (
                    movie_id INTEGER NOT NULL,
                    quote TEXT NOT NULL,
                    FOREIGN KEY(movie_id) REFERENCES movies(id)
                )
            ');
            error_log('✓ Movies quotes table created');

            // Awards table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS awards (
                    id INTEGER PRIMARY KEY,
                    award TEXT NOT NULL,
                    category TEXT,
                    UNIQUE (award, category)
                )
            ');
            error_log('✓ Awards table created');

            // Awards persons junction table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS awards_persons (
                    award_id INTEGER NOT NULL,
                    year TEXT,
                    movie_id INTEGER,
                    person_id INTEGER,
                    person_name TEXT NOT NULL,
                    won INTEGER NOT NULL,
                    note TEXT,
                    FOREIGN KEY(award_id) REFERENCES awards(id),
                    FOREIGN KEY(movie_id) REFERENCES movies(id),
                    FOREIGN KEY(person_id) REFERENCES persons(id)
                )
            ');
            error_log('✓ Awards persons table created');

            // Awards movies junction table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS awards_movies (
                    award_id INTEGER NOT NULL,
                    year TEXT,
                    movie_id INTEGER,
                    won INTEGER NOT NULL,
                    note TEXT,
                    FOREIGN KEY(award_id) REFERENCES awards(id),
                    FOREIGN KEY(movie_id) REFERENCES movies(id)
                )
            ');
            error_log('✓ Awards movies table created');

            // Relations table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS relations (
                    id INTEGER PRIMARY KEY,
                    sv TEXT,
                    en TEXT
                )
            ');
            error_log('✓ Relations table created');

            // Relations persons junction table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS relations_persons (
                    person_id INTEGER NOT NULL,
                    person_2_id INTEGER,
                    person_2_name TEXT,
                    relation_id INTEGER NOT NULL,
                    date_1 TEXT,
                    date_2 TEXT,
                    FOREIGN KEY(person_id) REFERENCES persons(id),
                    FOREIGN KEY(person_2_id) REFERENCES persons(id),
                    FOREIGN KEY(relation_id) REFERENCES relations(id)
                )
            ');
            error_log('✓ Relations persons table created');

            // Users table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY,
                    username TEXT UNIQUE NOT NULL,
                    email TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    role TEXT DEFAULT "user",
                    created_at TEXT,
                    updated_at TEXT
                )
            ');
            error_log('✓ Users table created');

            // Reviews table
            $this->connection->exec('
                CREATE TABLE IF NOT EXISTS reviews (
                    id INTEGER PRIMARY KEY,
                    movie_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    rating INTEGER NOT NULL,
                    comment TEXT,
                    created_at TEXT,
                    updated_at TEXT,
                    FOREIGN KEY(movie_id) REFERENCES movies(id),
                    FOREIGN KEY(user_id) REFERENCES users(id)
                )
            ');
            error_log('✓ Reviews table created');
            
            error_log('✓ All tables created successfully');
            
        } catch (PDOException $e) {
            error_log('✗ PDOException in initializeTables: ' . $e->getMessage());
            throw new \Exception('Table creation failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            error_log('✗ Exception in initializeTables: ' . $e->getMessage());
            throw $e;
        }
    }
}
