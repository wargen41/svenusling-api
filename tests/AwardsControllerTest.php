<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\AwardsController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class AwardsControllerTest extends TestCase
{
    private AwardsController $controller;

    /** @var int[] */
    private array $movieIds = [];

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('DELETE FROM awards_movies');
        $connection->exec('DELETE FROM awards_persons');
        $connection->exec('DELETE FROM awards');

        $this->movieIds = [];
        $this->controller = new AwardsController();
    }

    protected function tearDown(): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('DELETE FROM awards_movies');
        $connection->exec('DELETE FROM awards_persons');
        $connection->exec('DELETE FROM awards');

        foreach ($this->movieIds as $movieId) {
            $movie = $connection->prepare('DELETE FROM movies WHERE id = ?');
            $movie->execute([$movieId]);
        }
    }

    public function testListAwardsReturnsDistinctNames(): void
    {
        $this->insertAward('Golden Globe', 'Best actor');
        $this->insertAward('Golden Globe', 'Best picture');
        $this->insertAward('Oscars', null);

        $response = $this->controller->listAwards($this->request(), new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $payload['count']);
        self::assertSame(['Golden Globe', 'Oscars'], array_column($payload['data'], 'award'));
    }

    public function testListCategoriesReturnsAwardCategories(): void
    {
        $this->insertAward('Golden Globe', 'Best actor');
        $this->insertAward('Golden Globe', 'Best picture');

        $response = $this->controller->listCategories($this->request(), new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(2, $payload['count']);
        self::assertSame(
            ['Best actor', 'Best picture'],
            array_column($payload['data'], 'category')
        );
    }

    public function testGetAwardReturnsNotFoundForUnknownId(): void
    {
        $response = $this->controller->getAward(
            $this->request(),
            new Response(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAdminCanCreateAward(): void
    {
        $request = $this->request(['award' => 'Golden Globe', 'category' => 'Best picture'], 'admin');

        $response = $this->controller->createAward($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Golden Globe', $this->awardName((int) $payload['id']));
    }

    public function testCreatingAwardRequiresAwardName(): void
    {
        $response = $this->controller->createAward(
            $this->request(['category' => 'Best picture'], 'admin'),
            new Response()
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->awardCount());
    }

    public function testNonAdminCannotCreateAward(): void
    {
        $response = $this->controller->createAward(
            $this->request(['award' => 'Golden Globe'], 'user'),
            new Response()
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->awardCount());
    }

    public function testAdminCanUpdateAward(): void
    {
        $awardId = $this->insertAward('Golden Globe', 'Best picture');
        $request = $this->request(['category' => 'Best drama'], 'admin');

        $response = $this->controller->updateAward(
            $request,
            new Response(),
            ['id' => (string) $awardId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Best drama', $this->awardCategory($awardId));
    }

    public function testAdminCanAddMovieAndPersonNominations(): void
    {
        $awardId = $this->insertAward('Golden Globe', 'Best picture');
        $movieId = $this->insertMovie('Award test film');

        $movieResponse = $this->controller->addMovieNomination(
            $this->request([
                'award_id' => $awardId,
                'movie_id' => $movieId,
                'year' => '2025',
                'won' => 1,
            ], 'admin'),
            new Response()
        );
        $personResponse = $this->controller->addPersonNomination(
            $this->request([
                'award_id' => $awardId,
                'person_name' => 'Ada Lovelace',
                'year' => '2025',
            ], 'admin'),
            new Response()
        );

        $awardResponse = $this->controller->getAward(
            $this->request(),
            new Response(),
            ['id' => (string) $awardId]
        );
        $payload = $this->decodeJson($awardResponse);

        self::assertSame(201, $movieResponse->getStatusCode());
        self::assertSame(201, $personResponse->getStatusCode());
        self::assertSame('Award test film', $payload['data']['movie_nominations'][0]['title']);
        self::assertSame('Ada Lovelace', $payload['data']['person_nominations'][0]['person_name']);
    }

    public function testAdminCanDeleteAward(): void
    {
        $awardId = $this->insertAward('Golden Globe', 'Best picture');

        $response = $this->controller->deleteAward(
            $this->request([], 'admin'),
            new Response(),
            ['id' => (string) $awardId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->awardCount());
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

    private function insertAward(string $award, ?string $category): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO awards (award, category) VALUES (?, ?)'
        );
        $statement->execute([$award, $category]);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
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

    private function awardName(int $awardId): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT award FROM awards WHERE id = ?'
        );
        $statement->execute([$awardId]);
        $award = $statement->fetchColumn();

        return $award === false ? null : $award;
    }

    private function awardCategory(int $awardId): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT category FROM awards WHERE id = ?'
        );
        $statement->execute([$awardId]);
        $category = $statement->fetchColumn();

        return $category === false ? null : $category;
    }

    private function awardCount(): int
    {
        return (int) Database::getInstance()->getConnection()
            ->query('SELECT COUNT(*) FROM awards')
            ->fetchColumn();
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
