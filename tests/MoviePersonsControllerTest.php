<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\MoviePersonsController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class MoviePersonsControllerTest extends TestCase
{
    private MoviePersonsController $controller;

    /** @var int[] */
    private array $movieIds = [];

    /** @var int[] */
    private array $personIds = [];

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $this->movieIds = [];
        $this->personIds = [];
        $this->controller = new MoviePersonsController();
    }

    protected function tearDown(): void
    {
        $connection = Database::getInstance()->getConnection();

        foreach ($this->movieIds as $movieId) {
            $crew = $connection->prepare('DELETE FROM movies_persons WHERE movie_id = ?');
            $crew->execute([$movieId]);

            $movie = $connection->prepare('DELETE FROM movies WHERE id = ?');
            $movie->execute([$movieId]);
        }

        foreach ($this->personIds as $personId) {
            $person = $connection->prepare('DELETE FROM persons WHERE id = ?');
            $person->execute([$personId]);
        }
    }

    public function testGetMoviePersonsGroupsCrewByCategoryAndSequence(): void
    {
        $movieId = $this->insertMovie('Crew test movie');
        $actorOne = $this->insertPerson('Actor One');
        $actorTwo = $this->insertPerson('Actor Two');
        $director = $this->insertPerson('Director One', 'director');
        $this->insertCrew($movieId, $actorTwo, 'actor', 2);
        $this->insertCrew($movieId, $actorOne, 'actor', 1);
        $this->insertCrew($movieId, $director, 'director', 1);

        $response = $this->controller->getMoviePersons(
            $this->request(),
            new Response(),
            ['id' => (string) $movieId]
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $payload['data']['count']);
        self::assertSame(
            ['Actor One', 'Actor Two'],
            array_column($payload['data']['persons']['actor'], 'person_name')
        );
        self::assertSame(
            ['Director One'],
            array_column($payload['data']['persons']['director'], 'person_name')
        );
    }

    public function testGetMoviePersonsByCategoryIncludesPersonDetails(): void
    {
        $movieId = $this->insertMovie('Crew test movie');
        $actorId = $this->insertPerson('Actor One');
        $directorId = $this->insertPerson('Director One', 'director');
        $this->insertCrew($movieId, $actorId, 'actor', 1);
        $this->insertCrew($movieId, $directorId, 'director', 1);

        $response = $this->controller->getMoviePersonsByCategory(
            $this->request(),
            new Response(),
            ['id' => (string) $movieId, 'category' => 'actor']
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('actor', $payload['data']['category']);
        self::assertSame(1, $payload['data']['count']);
        self::assertSame('Actor One', $payload['data']['persons'][0]['name']);
    }

    public function testAdminCanAddCrewMemberAndDuplicateIsRejected(): void
    {
        $movieId = $this->insertMovie('Crew test movie');
        $request = $this->request([
            'movie_id' => $movieId,
            'person_name' => 'Actor One',
            'category' => 'actor',
            'role_name' => 'Hero',
        ], 'admin');

        $response = $this->controller->addPersonToMovie($request, new Response());
        $duplicateResponse = $this->controller->addPersonToMovie($request, new Response());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(409, $duplicateResponse->getStatusCode());
        self::assertSame(1, $this->crewCount($movieId));
    }

    public function testNonAdminCannotAddCrewMember(): void
    {
        $movieId = $this->insertMovie('Crew test movie');

        $response = $this->controller->addPersonToMovie(
            $this->request([
                'movie_id' => $movieId,
                'person_name' => 'Actor One',
                'category' => 'actor',
            ], 'user'),
            new Response()
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->crewCount($movieId));
    }

    public function testAdminCanUpdateCrewMember(): void
    {
        $movieId = $this->insertMovie('Crew test movie');
        $sequenceNumber = 1;
        $this->insertCrew($movieId, null, 'actor', $sequenceNumber, 'Actor One', 'Hero');

        $request = $this->request([
            'movie_id' => $movieId,
            'category' => 'actor',
            'sequence_number' => $sequenceNumber,
            'person_name' => 'Actor Two',
            'role_name' => 'Villain',
        ], 'admin');
        $response = $this->controller->updatePersonInMovie(
            $request,
            new Response(),
            [
                'movie_id' => (string) $movieId,
                'category' => 'actor',
                'sequence_number' => (string) $sequenceNumber,
            ]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Actor Two', $this->crewPersonName($movieId, 'actor', $sequenceNumber));
        self::assertSame('Villain', $this->crewRole($movieId, 'actor', $sequenceNumber));
    }

    public function testAdminCanRemoveCrewMemberAndUnknownMemberReturnsNotFound(): void
    {
        $movieId = $this->insertMovie('Crew test movie');
        $sequenceNumber = 1;
        $this->insertCrew($movieId, null, 'actor', $sequenceNumber, 'Actor One');
        $args = [
            'movie_id' => (string) $movieId,
            'category' => 'actor',
            'sequence_number' => (string) $sequenceNumber,
        ];

        $response = $this->controller->removePersonFromMovie(
            $this->request([], 'admin'),
            new Response(),
            $args
        );
        $notFoundResponse = $this->controller->removePersonFromMovie(
            $this->request([], 'admin'),
            new Response(),
            $args
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(404, $notFoundResponse->getStatusCode());
        self::assertSame(0, $this->crewCount($movieId));
    }

    public function testPublicCannotReadHiddenMovieCrewButAdminCan(): void
    {
        $movieId = $this->insertMovie('Hidden crew test movie', true);
        $personId = $this->insertPerson('Actor One');
        $this->insertCrew($movieId, $personId, 'actor', 1);

        $publicResponse = $this->controller->getMoviePersons(
            $this->request(),
            new Response(),
            ['id' => (string) $movieId]
        );
        $adminResponse = $this->controller->getMoviePersons(
            $this->request([], 'admin'),
            new Response(),
            ['id' => (string) $movieId]
        );

        self::assertSame(404, $publicResponse->getStatusCode());
        self::assertSame(200, $adminResponse->getStatusCode());
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

    private function insertMovie(string $title, bool $hidden = false): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies (hidden, title, sorting_title) VALUES (?, ?, ?)'
        );
        $statement->execute([$hidden ? 1 : 0, $title, $title]);

        $movieId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $this->movieIds[] = $movieId;

        return $movieId;
    }

    private function insertPerson(string $name, string $category = 'actor'): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO persons (category, name) VALUES (?, ?)'
        );
        $statement->execute([$category, $name]);

        $personId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $this->personIds[] = $personId;

        return $personId;
    }

    private function insertCrew(
        int $movieId,
        ?int $personId,
        string $category,
        int $sequenceNumber,
        string $personName = '',
        ?string $roleName = null
    ): void {
        if ($personName === '' && $personId !== null) {
            $personName = $this->personName($personId);
        }

        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies_persons
                (movie_id, person_id, person_name, category, role_name, sequence_number)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $movieId,
            $personId,
            $personName,
            $category,
            $roleName,
            $sequenceNumber,
        ]);
    }

    private function crewCount(int $movieId): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT COUNT(*) FROM movies_persons WHERE movie_id = ?'
        );
        $statement->execute([$movieId]);

        return (int) $statement->fetchColumn();
    }

    private function crewPersonName(int $movieId, string $category, int $sequenceNumber): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT person_name FROM movies_persons
            WHERE movie_id = ? AND category = ? AND sequence_number = ?'
        );
        $statement->execute([$movieId, $category, $sequenceNumber]);
        $name = $statement->fetchColumn();

        return $name === false ? null : $name;
    }

    private function crewRole(int $movieId, string $category, int $sequenceNumber): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT role_name FROM movies_persons
            WHERE movie_id = ? AND category = ? AND sequence_number = ?'
        );
        $statement->execute([$movieId, $category, $sequenceNumber]);
        $role = $statement->fetchColumn();

        return $role === false ? null : $role;
    }

    private function personName(int $personId): string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT name FROM persons WHERE id = ?'
        );
        $statement->execute([$personId]);

        return (string) $statement->fetchColumn();
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
