<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\GenreController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class GenreControllerTest extends TestCase
{
    private GenreController $controller;

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('DELETE FROM movies_genres');
        $connection->exec('DELETE FROM genres');

        $this->controller = new GenreController();
    }

    public function testListGenresReturnsDataAndCount(): void
    {
        $this->insertGenre('Comedy', 'Komedi');
        $this->insertGenre('Drama', 'Drama');

        $response = $this->controller->listGenres($this->request(), new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $payload['count']);
        self::assertSame(['Comedy', 'Drama'], array_column($payload['data'], 'en'));
    }

    public function testAdminCanCreateGenre(): void
    {
        $request = $this->request(
            ['sv' => 'Komedi', 'en' => 'Comedy', 'common' => 1],
            'admin'
        );

        $response = $this->controller->createGenre($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('Comedy', $this->genreName((int) $payload['id']));
    }

    public function testNonAdminCannotCreateGenre(): void
    {
        $request = $this->request(
            ['sv' => 'Komedi', 'en' => 'Comedy'],
            'user'
        );

        $response = $this->controller->createGenre($request, new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->genreCount());
    }

    public function testCreatingGenreRequiresSwedishAndEnglishNames(): void
    {
        $request = $this->request(['sv' => 'Komedi'], 'admin');

        $response = $this->controller->createGenre($request, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->genreCount());
    }

    public function testGetGenreRejectsInvalidId(): void
    {
        $response = $this->controller->getGenre(
            $this->request(),
            new Response(),
            ['id' => 'not-a-number']
        );

        self::assertSame(400, $response->getStatusCode());
    }

    public function testGetGenreReturnsNotFoundForUnknownId(): void
    {
        $response = $this->controller->getGenre(
            $this->request(),
            new Response(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAdminCanUpdateGenre(): void
    {
        $genreId = $this->insertGenre('Comedy', 'Komedi');
        $request = $this->request(['en' => 'Comedy films'], 'admin');

        $response = $this->controller->updateGenre(
            $request,
            new Response(),
            ['id' => (string) $genreId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Comedy films', $this->genreName($genreId));
    }

    public function testNonAdminCannotUpdateGenre(): void
    {
        $genreId = $this->insertGenre('Comedy', 'Komedi');
        $request = $this->request(['en' => 'Comedy films'], 'user');

        $response = $this->controller->updateGenre(
            $request,
            new Response(),
            ['id' => (string) $genreId]
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Comedy', $this->genreName($genreId));
    }

    public function testUpdatingGenreRequiresAtLeastOneField(): void
    {
        $genreId = $this->insertGenre('Comedy', 'Komedi');
        $request = $this->request([], 'admin');

        $response = $this->controller->updateGenre(
            $request,
            new Response(),
            ['id' => (string) $genreId]
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Comedy', $this->genreName($genreId));
    }

    public function testUpdatingUnknownGenreReturnsNotFound(): void
    {
        $request = $this->request(['en' => 'Comedy films'], 'admin');

        $response = $this->controller->updateGenre(
            $request,
            new Response(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAdminCanDeleteGenre(): void
    {
        $genreId = $this->insertGenre('Comedy', 'Komedi');
        $request = $this->request([], 'admin');

        $response = $this->controller->deleteGenre(
            $request,
            new Response(),
            ['id' => (string) $genreId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->genreCount());
    }

    public function testNonAdminCannotDeleteGenre(): void
    {
        $genreId = $this->insertGenre('Comedy', 'Komedi');
        $request = $this->request([], 'user');

        $response = $this->controller->deleteGenre(
            $request,
            new Response(),
            ['id' => (string) $genreId]
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Comedy', $this->genreName($genreId));
    }

    public function testDeletingUnknownGenreReturnsNotFound(): void
    {
        $request = $this->request([], 'admin');

        $response = $this->controller->deleteGenre(
            $request,
            new Response(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function request(array $body = [], ?string $role = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        if ($role !== null) {
            $request = $request->withAttribute('user_role', $role);
        }

        return $request;
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function insertGenre(string $englishName, string $swedishName): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO genres (common, sv, en) VALUES (0, ?, ?)'
        );
        $statement->execute([$swedishName, $englishName]);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private function genreName(int $genreId): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT en FROM genres WHERE id = ?'
        );
        $statement->execute([$genreId]);
        $name = $statement->fetchColumn();

        return $name === false ? null : $name;
    }

    private function genreCount(): int
    {
        return (int) Database::getInstance()->getConnection()
            ->query('SELECT COUNT(*) FROM genres')
            ->fetchColumn();
    }
}
