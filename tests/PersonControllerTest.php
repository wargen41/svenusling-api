<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\PersonController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class PersonControllerTest extends TestCase
{
    private PersonController $controller;

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('DELETE FROM awards_persons');
        $connection->exec('DELETE FROM media_persons');
        $connection->exec('DELETE FROM movies_persons');
        $connection->exec('DELETE FROM relations_persons');
        $connection->exec('DELETE FROM persons_trivia');
        $connection->exec('UPDATE movies SET created_by = NULL');
        $connection->exec('DELETE FROM persons');

        $this->controller = new PersonController();
    }

    public function testListPersonsFiltersByCategoryAndSearch(): void
    {
        $this->insertPerson('Ada Lovelace', 'director');
        $this->insertPerson('Buster Keaton', 'actor');

        $request = $this->request()->withQueryParams([
            'category' => 'director',
            'search' => 'Ada',
            'details' => 'minimal',
            'limit' => '1',
        ]);
        $response = $this->controller->listPersons($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['pagination']['total']);
        self::assertSame('Ada Lovelace', $payload['data'][0]['name']);
        self::assertArrayNotHasKey('birth_date', $payload['data'][0]);
    }

    public function testGetPersonReturnsPersonAndRelatedCollections(): void
    {
        $personId = $this->insertPerson('Ada Lovelace', 'director');

        $response = $this->controller->getPerson(
            $this->request(),
            new Response(),
            ['id' => (string) $personId]
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Ada Lovelace', $payload['data']['name']);
        self::assertSame([], $payload['data']['filmography']);
        self::assertSame([], $payload['data']['trivia']);
        self::assertSame([], $payload['data']['relations']);
    }

    public function testGetPersonRejectsInvalidId(): void
    {
        $response = $this->controller->getPerson(
            $this->request(),
            new Response(),
            ['id' => 'not-a-number']
        );

        self::assertSame(400, $response->getStatusCode());
    }

    public function testGetPersonReturnsNotFoundForUnknownId(): void
    {
        $response = $this->controller->getPerson(
            $this->request(),
            new Response(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAdminCanCreatePerson(): void
    {
        $request = $this->request([
            'name' => 'Ada Lovelace',
            'category' => 'director',
            'birth_date' => '1815-12-10',
        ], 'admin');

        $response = $this->controller->createPerson($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Ada Lovelace', $this->personName((int) $payload['id']));
    }

    public function testNonAdminCannotCreatePerson(): void
    {
        $request = $this->request([
            'name' => 'Ada Lovelace',
            'category' => 'director',
        ], 'user');

        $response = $this->controller->createPerson($request, new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->personCount());
    }

    public function testCreatingPersonRequiresNameAndCategory(): void
    {
        $request = $this->request(['name' => 'Ada Lovelace'], 'admin');

        $response = $this->controller->createPerson($request, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->personCount());
    }

    public function testAdminCanUpdatePerson(): void
    {
        $personId = $this->insertPerson('Ada Lovelace', 'director');
        $request = $this->request(['name' => 'Augusta Ada King'], 'admin');

        $response = $this->controller->updatePerson(
            $request,
            new Response(),
            ['id' => (string) $personId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Augusta Ada King', $this->personName($personId));
    }

    public function testAdminCanDeletePerson(): void
    {
        $personId = $this->insertPerson('Ada Lovelace', 'director');
        $request = $this->request([], 'admin');

        $response = $this->controller->deletePerson(
            $request,
            new Response(),
            ['id' => (string) $personId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->personCount());
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

    private function insertPerson(string $name, string $category): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO persons (category, name) VALUES (?, ?)'
        );
        $statement->execute([$category, $name]);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private function personName(int $personId): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT name FROM persons WHERE id = ?'
        );
        $statement->execute([$personId]);
        $name = $statement->fetchColumn();

        return $name === false ? null : $name;
    }

    private function personCount(): int
    {
        return (int) Database::getInstance()->getConnection()
            ->query('SELECT COUNT(*) FROM persons')
            ->fetchColumn();
    }
}
