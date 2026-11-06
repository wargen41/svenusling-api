<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\MovieGenresController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class MovieGenresControllerTest extends TestCase
{
    private MovieGenresController $controller;

    /** @var int[] */
    private array $movieIds = [];

    /** @var int[] */
    private array $genreIds = [];

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $this->movieIds = [];
        $this->genreIds = [];
        $this->controller = new MovieGenresController();
    }

    protected function tearDown(): void
    {
        $connection = Database::getInstance()->getConnection();

        foreach ($this->movieIds as $movieId) {
            $assignments = $connection->prepare(
                'DELETE FROM movies_genres WHERE movie_id = ?'
            );
            $assignments->execute([$movieId]);

            $movie = $connection->prepare('DELETE FROM movies WHERE id = ?');
            $movie->execute([$movieId]);
        }

        foreach ($this->genreIds as $genreId) {
            $genre = $connection->prepare('DELETE FROM genres WHERE id = ?');
            $genre->execute([$genreId]);
        }
    }

    public function testGetMovieGenresReturnsSortedGenres(): void
    {
        $movieId = $this->insertMovie('Genre test movie');
        $dramaId = $this->insertGenre('Drama');
        $comedyId = $this->insertGenre('Comedy');
        $this->assignGenre($movieId, $dramaId);
        $this->assignGenre($movieId, $comedyId);

        $response = $this->controller->getMovieGenres(
            $this->request(),
            new Response(),
            ['id' => (string) $movieId]
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $payload['data']['count']);
        self::assertSame(
            ['Comedy', 'Drama'],
            array_column($payload['data']['genres'], 'en')
        );
    }

    public function testAdminCanAddGenreAndDuplicateAssignmentIsRejected(): void
    {
        $movieId = $this->insertMovie('Genre test movie');
        $genreId = $this->insertGenre('Comedy');
        $request = $this->request([
            'movie_id' => $movieId,
            'genre_id' => $genreId,
        ], 'admin');

        $response = $this->controller->addGenreToMovie($request, new Response());
        $duplicateResponse = $this->controller->addGenreToMovie($request, new Response());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(409, $duplicateResponse->getStatusCode());
        self::assertSame(1, $this->assignmentCount($movieId));
    }

    public function testNonAdminCannotAddGenreToMovie(): void
    {
        $movieId = $this->insertMovie('Genre test movie');
        $genreId = $this->insertGenre('Comedy');

        $response = $this->controller->addGenreToMovie(
            $this->request([
                'movie_id' => $movieId,
                'genre_id' => $genreId,
            ], 'user'),
            new Response()
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->assignmentCount($movieId));
    }

    public function testBulkAddReportsUnknownGenreIds(): void
    {
        $movieId = $this->insertMovie('Genre test movie');
        $genreId = $this->insertGenre('Comedy');

        $response = $this->controller->addGenresToMovie(
            $this->request([
                'movie_id' => $movieId,
                'genre_ids' => [$genreId, 999],
            ], 'admin'),
            new Response()
        );
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $payload['successful']);
        self::assertSame(1, $payload['failed']);
        self::assertSame(['Genre ID 999 not found'], $payload['errors']);
        self::assertSame(1, $this->assignmentCount($movieId));
    }

    public function testAdminCanReplaceMovieGenres(): void
    {
        $movieId = $this->insertMovie('Genre test movie');
        $oldGenreId = $this->insertGenre('Drama');
        $newGenreId = $this->insertGenre('Comedy');
        $this->assignGenre($movieId, $oldGenreId);

        $response = $this->controller->replaceMovieGenres(
            $this->request(['genre_ids' => [$newGenreId]], 'admin'),
            new Response(),
            ['id' => (string) $movieId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->assignmentCount($movieId));
        self::assertSame([$newGenreId], $this->assignedGenreIds($movieId));
    }

    public function testAdminCanRemoveMovieGenreAndUnknownAssignmentReturnsNotFound(): void
    {
        $movieId = $this->insertMovie('Genre test movie');
        $genreId = $this->insertGenre('Comedy');
        $this->assignGenre($movieId, $genreId);

        $args = ['movie_id' => (string) $movieId, 'genre_id' => (string) $genreId];
        $response = $this->controller->removeGenreFromMovie(
            $this->request([], 'admin'),
            new Response(),
            $args
        );
        $notFoundResponse = $this->controller->removeGenreFromMovie(
            $this->request([], 'admin'),
            new Response(),
            $args
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(404, $notFoundResponse->getStatusCode());
        self::assertSame(0, $this->assignmentCount($movieId));
    }

    public function testGetMovieGenresRejectsInvalidAndUnknownMovieIds(): void
    {
        $invalidResponse = $this->controller->getMovieGenres(
            $this->request(),
            new Response(),
            ['id' => 'not-a-number']
        );
        $notFoundResponse = $this->controller->getMovieGenres(
            $this->request(),
            new Response(),
            ['id' => '-1']
        );

        self::assertSame(400, $invalidResponse->getStatusCode());
        self::assertSame(404, $notFoundResponse->getStatusCode());
    }

    private function request(array $body = [], ?string $role = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/');
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        if ($role !== null) {
            $request = $request->withAttribute('user_role', $role);
        }

        return $request;
    }

    private function insertMovie(string $title): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies (hidden, title, sorting_title) VALUES (0, ?, ?)'
        );
        $statement->execute([$title, $title]);

        $movieId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $this->movieIds[] = $movieId;

        return $movieId;
    }

    private function insertGenre(string $englishName): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO genres (common, sv, en) VALUES (0, ?, ?)'
        );
        $statement->execute([$englishName, $englishName]);

        $genreId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $this->genreIds[] = $genreId;

        return $genreId;
    }

    private function assignGenre(int $movieId, int $genreId): void
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies_genres (movie_id, genre_id) VALUES (?, ?)'
        );
        $statement->execute([$movieId, $genreId]);
    }

    private function assignmentCount(int $movieId): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT COUNT(*) FROM movies_genres WHERE movie_id = ?'
        );
        $statement->execute([$movieId]);

        return (int) $statement->fetchColumn();
    }

    private function assignedGenreIds(int $movieId): array
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT genre_id FROM movies_genres WHERE movie_id = ?'
        );
        $statement->execute([$movieId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
