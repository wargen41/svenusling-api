<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\ReviewController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class ReviewControllerTest extends TestCase
{
    private ReviewController $controller;
    private int $movieId;
    private int $userId;
    private int $otherUserId;

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        $this->controller = new ReviewController();
        $this->userId = $this->insertUser();
        $this->otherUserId = $this->insertUser();
        $this->movieId = $this->insertMovie();
    }

    protected function tearDown(): void
    {
        $connection = Database::getInstance()->getConnection();

        $reviews = $connection->prepare('DELETE FROM reviews WHERE movie_id = ?');
        $reviews->execute([$this->movieId]);

        $movie = $connection->prepare('DELETE FROM movies WHERE id = ?');
        $movie->execute([$this->movieId]);

        $users = $connection->prepare('DELETE FROM users WHERE id IN (?, ?)');
        $users->execute([$this->userId, $this->otherUserId]);
    }

    public function testAddingReviewStoresItAndUpdatesMovieRating(): void
    {
        $request = $this->request([
            'movie_id' => $this->movieId,
            'rating' => 80,
            'comment' => 'A thoughtful film',
        ], $this->userId);

        $response = $this->controller->addReview($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(80, $this->reviewRating((int) $payload['id']));
        self::assertSame(80.0, $this->movieRating());
    }

    public function testAddingReviewRejectsOutOfRangeRating(): void
    {
        $request = $this->request([
            'movie_id' => $this->movieId,
            'rating' => 101,
        ], $this->userId);

        $response = $this->controller->addReview($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Validation failed', $payload['error']);
        self::assertSame(0, $this->reviewCount());
    }

    public function testAddingReviewForUnknownMovieReturnsNotFound(): void
    {
        $request = $this->request([
            'movie_id' => 999,
            'rating' => 80,
        ], $this->userId);

        $response = $this->controller->addReview($request, new Response());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->reviewCount());
    }

    public function testUserCannotReviewSameMovieTwice(): void
    {
        $this->insertReview($this->userId, 60);
        $request = $this->request([
            'movie_id' => $this->movieId,
            'rating' => 80,
        ], $this->userId);

        $response = $this->controller->addReview($request, new Response());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $this->reviewCount());
    }

    public function testUserCanUpdateOwnReviewAndMovieRating(): void
    {
        $reviewId = $this->insertReview($this->userId, 60);
        $request = $this->request([
            'rating' => 90,
            'comment' => 'Revised opinion',
        ], $this->userId);

        $response = $this->controller->updateReview(
            $request,
            new Response(),
            ['id' => (string) $reviewId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(90, $this->reviewRating($reviewId));
        self::assertSame(90.0, $this->movieRating());
    }

    public function testUserCannotUpdateAnotherUsersReview(): void
    {
        $reviewId = $this->insertReview($this->otherUserId, 60);
        $request = $this->request(['rating' => 90], $this->userId);

        $response = $this->controller->updateReview(
            $request,
            new Response(),
            ['id' => (string) $reviewId]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(60, $this->reviewRating($reviewId));
    }

    public function testUserCanDeleteOwnReviewAndResetsMovieRating(): void
    {
        $reviewId = $this->insertReview($this->userId, 80);

        $response = $this->controller->deleteReview(
            $this->request([], $this->userId),
            new Response(),
            ['id' => (string) $reviewId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->reviewCount());
        self::assertSame(0.0, $this->movieRating());
    }

    public function testUserCannotDeleteAnotherUsersReview(): void
    {
        $reviewId = $this->insertReview($this->otherUserId, 60);

        $response = $this->controller->deleteReview(
            $this->request([], $this->userId),
            new Response(),
            ['id' => (string) $reviewId]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(60, $this->reviewRating($reviewId));
    }

    private function request(array $body, int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/reviews')
            ->withParsedBody($body)
            ->withAttribute('user_id', $userId);
    }

    private function insertUser(): int
    {
        $email = bin2hex(random_bytes(8)) . '@example.test';
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)'
        );
        $statement->execute(['test-user', $email, 'test-password-hash']);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private function insertMovie(): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO movies (hidden, title, sorting_title) VALUES (0, ?, ?)'
        );
        $statement->execute(['Review test movie', 'Review test movie']);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private function insertReview(int $userId, int $rating): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO reviews (movie_id, user_id, rating, comment) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$this->movieId, $userId, $rating, 'Test review']);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private function reviewRating(int $reviewId): ?int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT rating FROM reviews WHERE id = ?'
        );
        $statement->execute([$reviewId]);
        $rating = $statement->fetchColumn();

        return $rating === false ? null : (int) $rating;
    }

    private function reviewCount(): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT COUNT(*) FROM reviews WHERE movie_id = ?'
        );
        $statement->execute([$this->movieId]);

        return (int) $statement->fetchColumn();
    }

    private function movieRating(): float
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT rating FROM movies WHERE id = ?'
        );
        $statement->execute([$this->movieId]);
        $rating = $statement->fetchColumn();

        return (float) $rating;
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
