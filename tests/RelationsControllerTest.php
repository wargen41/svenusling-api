<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\RelationsController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class RelationsControllerTest extends TestCase
{
    private RelationsController $controller;

    /** @var int[] */
    private array $personIds = [];

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('DELETE FROM relations_persons');
        $connection->exec('DELETE FROM relations');

        $this->personIds = [];
        $this->controller = new RelationsController();
    }

    protected function tearDown(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('DELETE FROM relations_persons');
        $connection->exec('DELETE FROM relations');

        foreach ($this->personIds as $personId) {
            $person = $connection->prepare('DELETE FROM persons WHERE id = ?');
            $person->execute([$personId]);
        }
    }

    public function testListRelationTypesReturnsSortedData(): void
    {
        $this->insertRelation('Sibling', 'Syskon');
        $this->insertRelation('Parent', 'Förälder');

        $response = $this->controller->listRelationTypes($this->request(), new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $payload['count']);
        self::assertSame(['Parent', 'Sibling'], array_column($payload['data'], 'en'));
    }

    public function testAdminCanCreateRelationType(): void
    {
        $response = $this->controller->createRelationType(
            $this->request(['sv' => 'Syskon', 'en' => 'Sibling'], 'admin'),
            new Response()
        );
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Sibling', $this->relationEnglishName((int) $payload['id']));
    }

    public function testCreatingRelationTypeRequiresBothNames(): void
    {
        $response = $this->controller->createRelationType(
            $this->request(['en' => 'Sibling'], 'admin'),
            new Response()
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->relationCount());
    }

    public function testNonAdminCannotCreateRelationType(): void
    {
        $response = $this->controller->createRelationType(
            $this->request(['sv' => 'Syskon', 'en' => 'Sibling'], 'user'),
            new Response()
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->relationCount());
    }

    public function testAdminCanUpdateRelationType(): void
    {
        $relationId = $this->insertRelation('Sibling', 'Syskon');

        $response = $this->controller->updateRelationType(
            $this->request(['en' => 'Brother or sister'], 'admin'),
            new Response(),
            ['id' => (string) $relationId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Brother or sister', $this->relationEnglishName($relationId));
    }

    public function testAdminCanCreateAndReadPersonRelation(): void
    {
        $personId = $this->insertPerson('Ada Lovelace');
        $otherPersonId = $this->insertPerson('Charles Babbage');
        $relationId = $this->insertRelation('Colleague', 'Collega');

        $createResponse = $this->controller->createPersonRelation(
            $this->request([
                'person_id' => $personId,
                'person_2_id' => $otherPersonId,
                'relation_id' => $relationId,
                'date_1' => '1843',
            ], 'admin'),
            new Response()
        );

        $personResponse = $this->controller->getPersonRelations(
            $this->request(),
            new Response(),
            ['id' => (string) $personId]
        );
        $typeResponse = $this->controller->getRelationType(
            $this->request(),
            new Response(),
            ['id' => (string) $relationId]
        );
        $personPayload = $this->decodeJson($personResponse);
        $typePayload = $this->decodeJson($typeResponse);

        self::assertSame(201, $createResponse->getStatusCode());
        self::assertSame(
            'Charles Babbage',
            $personPayload['data']['relations'][0]['person_2_name']
        );
        self::assertSame(
            'Ada Lovelace',
            $typePayload['data']['person_pairs'][0]['person_1_name']
        );
    }

    public function testAdminCanUpdatePersonRelation(): void
    {
        $personId = $this->insertPerson('Ada Lovelace');
        $relationId = $this->insertRelation('Colleague', 'Collega');
        $this->insertPersonRelation($personId, $relationId);

        $response = $this->controller->updatePersonRelation(
            $this->request(['date_1' => '1843'], 'admin'),
            new Response(),
            [
                'person_id' => (string) $personId,
                'relation_id' => (string) $relationId,
            ]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1843', $this->personRelationDate($personId, $relationId));
    }

    public function testAdminCanDeletePersonRelation(): void
    {
        $personId = $this->insertPerson('Ada Lovelace');
        $relationId = $this->insertRelation('Colleague', 'Collega');
        $this->insertPersonRelation($personId, $relationId);

        $response = $this->controller->deletePersonRelation(
            $this->request([], 'admin'),
            new Response(),
            [
                'person_id' => (string) $personId,
                'relation_id' => (string) $relationId,
            ]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->personRelationCount($personId, $relationId));
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

    private function insertRelation(string $englishName, string $swedishName): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO relations (sv, en) VALUES (?, ?)'
        );
        $statement->execute([$swedishName, $englishName]);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private function insertPerson(string $name): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO persons (category, name) VALUES (?, ?)'
        );
        $statement->execute(['actor', $name]);

        $personId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $this->personIds[] = $personId;

        return $personId;
    }

    private function insertPersonRelation(int $personId, int $relationId): void
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO relations_persons (person_id, relation_id) VALUES (?, ?)'
        );
        $statement->execute([$personId, $relationId]);
    }

    private function relationEnglishName(int $relationId): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT en FROM relations WHERE id = ?'
        );
        $statement->execute([$relationId]);
        $name = $statement->fetchColumn();

        return $name === false ? null : $name;
    }

    private function relationCount(): int
    {
        return (int) Database::getInstance()->getConnection()
            ->query('SELECT COUNT(*) FROM relations')
            ->fetchColumn();
    }

    private function personRelationDate(int $personId, int $relationId): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT date_1 FROM relations_persons WHERE person_id = ? AND relation_id = ?'
        );
        $statement->execute([$personId, $relationId]);
        $date = $statement->fetchColumn();

        return $date === false ? null : $date;
    }

    private function personRelationCount(int $personId, int $relationId): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT COUNT(*) FROM relations_persons WHERE person_id = ? AND relation_id = ?'
        );
        $statement->execute([$personId, $relationId]);

        return (int) $statement->fetchColumn();
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
