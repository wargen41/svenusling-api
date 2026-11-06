<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\MovieController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class MovieControllerTest extends TestCase
{
    private MovieController $controller;

    /** @var int[] */
    private array $movieIds = [];

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $this->movieIds = [];
        $this->controller = new MovieController();
    }

    protected function tearDown(): void
    {
        $connection = Database::getInstance()->getConnection();

        foreach ($this->movieIds as $movieId) {
            foreach ([
                'reviews',
                'media_movies',
                'movies_persons',
                'movies_quotes',
                'movies_trivia',
                'movies_genres',
                'awards_movies',
                'awards_persons',
            ] as $table) {
                $statement = $connection->prepare("DELETE FROM $table WHERE movie_id = ?");
                $statement->execute([$movieId]);
            }

            $movie = $connection->prepare('DELETE FROM movies WHERE id = ?');
            $movie->execute([$movieId]);
        }
    }

    public function testListMoviesAppliesFiltersAndHidesHiddenMoviesFromPublicUsers(): void
    {
        $this->insertMovie('Visible Adventure', false, 'film', '2020');
        $this->insertMovie('Hidden Adventure', true, 'film', '2020');
        $this->insertMovie('Visible Drama', false, 'film', '2020');

        $request = $this->request()->withQueryParams([
            'search' => 'Adventure',
            'type' => 'film',
            'year' => '2020',
            'details' => 'minimal',
        ]);
        $response = $this->controller->listMovies($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['pagination']['total']);
        self::assertSame('Visible Adventure', $payload['data'][0]['title']);
    }

    public function testAdminCanListHiddenMovies(): void
    {
        $this->insertMovie('Visible Adventure', false, 'film', '2020');
        $this->insertMovie('Hidden Adventure', true, 'film', '2020');

        $request = $this->request()->withAttribute('user_role', 'admin')->withQueryParams([
            'search' => 'Adventure',
            'type' => 'film',
            'year' => '2020',
            'details' => 'minimal',
        ]);
        $response = $this->controller->listMovies($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(2, $payload['pagination']['total']);
        self::assertSame(
            ['Hidden Adventure', 'Visible Adventure'],
            array_column($payload['data'], 'title')
        );
    }

    public function testGetMovieReturnsEmptyRelatedCollections(): void
    {
        $movieId = $this->insertMovie('Visible Adventure', false, 'film', '2020');

        $response = $this->controller->getMovie(
            $this->request(),
            new Response(),
            ['id' => (string) $movieId]
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Visible Adventure', $payload['data']['title']);
        self::assertSame([], $payload['data']['genres']);
        self::assertSame([], $payload['data']['persons']);
        self::assertSame([], $payload['data']['trivia']);
        self::assertSame([], $payload['data']['quotes']);
        self::assertSame([], $payload['data']['media']);
        self::assertSame([], $payload['data']['reviews']);
    }

    public function testGetMovieHidesHiddenMoviesFromPublicUsers(): void
    {
        $movieId = $this->insertMovie('Hidden Adventure', true, 'film', '2020');

        $response = $this->controller->getMovie(
            $this->request(),
            new Response(),
            ['id' => (string) $movieId]
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAdminCanGetHiddenMovie(): void
    {
        $movieId = $this->insertMovie('Hidden Adventure', true, 'film', '2020');
        $request = $this->request()->withAttribute('user_role', 'admin');

        $response = $this->controller->getMovie(
            $request,
            new Response(),
            ['id' => (string) $movieId]
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hidden Adventure', $payload['data']['title']);
    }

    public function testGetMovieRejectsInvalidId(): void
    {
        $response = $this->controller->getMovie(
            $this->request(),
            new Response(),
            ['id' => 'not-a-number']
        );

        self::assertSame(400, $response->getStatusCode());
    }

    public function testGetMovieReturnsNotFoundForUnknownId(): void
    {
        $response = $this->controller->getMovie(
            $this->request(),
            new Response(),
            ['id' => '-1']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/');
    }

    private function insertMovie(
        string $title,
        bool $hidden,
        string $type,
        string $year
    ): int {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies (hidden, title, sorting_title, type, year, rating)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$hidden ? 1 : 0, $title, $title, $type, $year, 75]);

        $movieId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $this->movieIds[] = $movieId;

        return $movieId;
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
