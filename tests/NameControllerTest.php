<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\NameController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class NameControllerTest extends TestCase
{
    private NameController $controller;

    /** @var int[] */
    private array $movieIds = [];

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $this->movieIds = [];
        $this->controller = new NameController();
    }

    protected function tearDown(): void
    {
        $connection = Database::getInstance()->getConnection();
        foreach ($this->movieIds as $movieId) {
            $moviePersons = $connection->prepare(
                'DELETE FROM movies_persons WHERE movie_id = ?'
            );
            $moviePersons->execute([$movieId]);

            $movieTrivia = $connection->prepare(
                'DELETE FROM movies_trivia WHERE movie_id = ?'
            );
            $movieTrivia->execute([$movieId]);

            $movie = $connection->prepare('DELETE FROM movies WHERE id = ?');
            $movie->execute([$movieId]);
        }
    }

    public function testGetNameReturnsMatchingCrewAndTrivia(): void
    {
        $movieId = $this->insertMovie('Lovelace: The Film');
        $this->insertCrew($movieId, 'Ada Lovelace');
        $this->insertTrivia($movieId, 'Ada Lovelace wrote notes', null);
        $this->insertTrivia($movieId, null, 'Ada Lovelace was a pioneer');

        $response = $this->controller->getName(
            $this->request(),
            new Response(),
            ['name' => 'Ada Lovelace']
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Ada Lovelace', $payload['data']['name']);
        self::assertSame('director', $payload['data']['crew'][0]['category']);
        self::assertSame('Lovelace: The Film', $payload['data']['crew'][0]['movie_title']);
        self::assertCount(2, $payload['data']['trivia']);
        self::assertSame('Lovelace: The Film', $payload['data']['trivia'][0]['movie_title']);
    }

    public function testGetNameReturnsEmptyCollectionsWhenNoResultsMatch(): void
    {
        $response = $this->controller->getName(
            $this->request(),
            new Response(),
            ['name' => 'No matching name']
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $payload['data']['crew']);
        self::assertSame([], $payload['data']['trivia']);
    }

    public function testGetNameHandlesApostrophesInSearchTerm(): void
    {
        $movieId = $this->insertMovie('The ONeil Story');
        $this->insertCrew($movieId, "O'Neil");
        $this->insertTrivia($movieId, null, "O'Neil's story is told in the film");

        $response = $this->controller->getName(
            $this->request(),
            new Response(),
            ['name' => "O'Neil"]
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('director', $payload['data']['crew'][0]['category']);
        self::assertSame('The ONeil Story', $payload['data']['crew'][0]['movie_title']);
        self::assertCount(1, $payload['data']['trivia']);
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/');
    }

    private function insertMovie(string $title): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies (hidden, title, sorting_title, year, type) VALUES (0, ?, ?, ?, ?)'
        );
        $statement->execute([$title, $title, '2000', 'film']);

        $movieId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $this->movieIds[] = $movieId;

        return $movieId;
    }

    private function insertCrew(int $movieId, string $personName): void
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies_persons (movie_id, person_name, category, role_name, sequence_number)
            VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$movieId, $personName, 'director', 'Director', 1]);
    }

    private function insertTrivia(int $movieId, ?string $swedish, ?string $english): void
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies_trivia (movie_id, sv, en) VALUES (?, ?, ?)'
        );
        $statement->execute([$movieId, $swedish, $english]);
    }

    private function decodeJson(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
