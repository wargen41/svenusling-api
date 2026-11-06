<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\MediaController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class MediaControllerTest extends TestCase
{
    private MediaController $controller;

    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    protected function setUp(): void
    {
        Database::getInstance()->getConnection()->exec('DELETE FROM media');
        $this->controller = new MediaController();
    }

    public function testListMediaFiltersByTypeAndPaginates(): void
    {
        $this->insertMedia('poster.jpg', 'image');
        $this->insertMedia('soundtrack.mp3', 'audio');

        $request = $this->request()->withQueryParams([
            'media_type' => 'image',
            'skip' => '0',
            'limit' => '1',
        ]);
        $response = $this->controller->listMedia($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['pagination']['count']);
        self::assertSame(1, $payload['pagination']['total']);
        self::assertSame('poster.jpg', $payload['data'][0]['file_name']);
    }

    public function testGetMediaReturnsMediaAndRelatedCollections(): void
    {
        $mediaId = $this->insertMedia('poster.jpg', 'image');

        $response = $this->controller->getMedia(
            $this->request(),
            new Response(),
            ['id' => (string) $mediaId]
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('poster.jpg', $payload['data']['file_name']);
        self::assertSame([], $payload['data']['movies']);
        self::assertSame([], $payload['data']['persons']);
    }

    public function testGetMediaRejectsInvalidId(): void
    {
        $response = $this->controller->getMedia(
            $this->request(),
            new Response(),
            ['id' => 'not-a-number']
        );

        self::assertSame(400, $response->getStatusCode());
    }

    public function testGetMediaReturnsNotFoundForUnknownId(): void
    {
        $response = $this->controller->getMedia(
            $this->request(),
            new Response(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAdminCanCreateMedia(): void
    {
        $request = $this->request([
            'file_name' => 'poster.jpg',
            'media_type' => 'image',
            'file_directory' => '/posters',
        ], 'admin');

        $response = $this->controller->createMedia($request, new Response());
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('poster.jpg', $this->mediaName((int) $payload['id']));
    }

    public function testNonAdminCannotCreateMedia(): void
    {
        $request = $this->request([
            'file_name' => 'poster.jpg',
            'media_type' => 'image',
            'file_directory' => '/posters',
        ], 'user');

        $response = $this->controller->createMedia($request, new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->mediaCount());
    }

    public function testCreatingMediaRequiresItsFileDetails(): void
    {
        $request = $this->request(['file_name' => 'poster.jpg'], 'admin');

        $response = $this->controller->createMedia($request, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->mediaCount());
    }

    public function testAdminCanUpdateMedia(): void
    {
        $mediaId = $this->insertMedia('poster.jpg', 'image');
        $request = $this->request(['caption' => 'Film poster'], 'admin');

        $response = $this->controller->updateMedia(
            $request,
            new Response(),
            ['id' => (string) $mediaId]
        );
        $caption = Database::getInstance()->getConnection()
            ->query("SELECT caption FROM media WHERE id = $mediaId")
            ->fetchColumn();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Film poster', $caption);
    }

    public function testAdminCanDeleteMedia(): void
    {
        $mediaId = $this->insertMedia('poster.jpg', 'image');
        $request = $this->request([], 'admin');

        $response = $this->controller->deleteMedia(
            $request,
            new Response(),
            ['id' => (string) $mediaId]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->mediaCount());
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

    private function insertMedia(string $fileName, string $mediaType): int
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'INSERT INTO media (file_name, media_type, file_directory) VALUES (?, ?, ?)'
        );
        $statement->execute([$fileName, $mediaType, '/test']);

        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private function mediaName(int $mediaId): ?string
    {
        $statement = Database::getInstance()->getConnection()->prepare(
            'SELECT file_name FROM media WHERE id = ?'
        );
        $statement->execute([$mediaId]);
        $name = $statement->fetchColumn();

        return $name === false ? null : $name;
    }

    private function mediaCount(): int
    {
        return (int) Database::getInstance()->getConnection()
            ->query('SELECT COUNT(*) FROM media')
            ->fetchColumn();
    }
}
