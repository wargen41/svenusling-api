<?php

namespace Tests;

use App\Config\Database;
use App\Routes;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class RoutesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Database::getInstance()->initializeTables();
    }

    public function testPublicMovieRouteAllowsAnonymousRequest(): void
    {
        $response = $this->dispatch('GET', '/movies');

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->decodeJson($response)['success']);
    }

    public function testPublicMovieRouteRejectsInvalidToken(): void
    {
        $response = $this->dispatch('GET', '/movies', 'Bearer invalid-token');

        self::assertSame(401, $response->getStatusCode());
    }

    public function testProtectedMovieRouteRequiresToken(): void
    {
        $response = $this->dispatch('GET', '/movies/hidden');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'Missing authorization header',
            $this->decodeJson($response)['error']
        );
    }

    private function dispatch(
        string $method,
        string $uri,
        ?string $authorization = null
    ): ResponseInterface {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);
        Routes::register($app);

        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }

        return $app->handle($request);
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
