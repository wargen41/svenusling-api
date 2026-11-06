<?php

namespace Tests;

use App\Middleware\AuthMiddleware;
use App\Middleware\PublicAuthMiddleware;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class AuthMiddlewareTest extends TestCase
{
    public function testProtectedMiddlewareRejectsMissingToken(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new AuthMiddleware())->process($this->request(), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'Missing authorization header',
            $this->decodeJson($response)['error']
        );
    }

    public function testProtectedMiddlewareRejectsInvalidToken(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new AuthMiddleware())->process(
            $this->request($this->token('another-phpunit-signing-key-not-for-production')),
            $handler
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Invalid token signature', $this->decodeJson($response)['error']);
    }

    public function testProtectedMiddlewareRejectsExpiredToken(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new AuthMiddleware())->process(
            $this->request($this->token(JWT_SECRET, time() - 60)),
            $handler
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Token expired', $this->decodeJson($response)['error']);
    }

    public function testProtectedMiddlewarePassesUserAttributesForValidToken(): void
    {
        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $request) use ($expectedResponse): ResponseInterface {
                self::assertSame('42', $request->getAttribute('user_id'));
                self::assertSame('admin', $request->getAttribute('user_role'));
                self::assertSame('42', $request->getAttribute('user')->sub);

                return $expectedResponse;
            });

        $response = (new AuthMiddleware())->process(
            $this->request($this->token(JWT_SECRET)),
            $handler
        );

        self::assertSame($expectedResponse, $response);
    }

    public function testPublicMiddlewareAllowsAnonymousRequest(): void
    {
        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $request) use ($expectedResponse): ResponseInterface {
                self::assertNull($request->getAttribute('user_id'));

                return $expectedResponse;
            });

        $response = (new PublicAuthMiddleware())->process($this->request(), $handler);

        self::assertSame($expectedResponse, $response);
    }

    public function testPublicMiddlewareRejectsInvalidToken(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new PublicAuthMiddleware())->process(
            $this->request($this->token('another-phpunit-signing-key-not-for-production')),
            $handler
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Invalid token signature', $this->decodeJson($response)['error']);
    }

    public function testPublicMiddlewarePassesUserAttributesForValidToken(): void
    {
        $expectedResponse = new Response();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $request) use ($expectedResponse): ResponseInterface {
                self::assertSame('42', $request->getAttribute('user_id'));
                self::assertSame('admin', $request->getAttribute('user_role'));

                return $expectedResponse;
            });

        $response = (new PublicAuthMiddleware())->process(
            $this->request($this->token(JWT_SECRET)),
            $handler
        );

        self::assertSame($expectedResponse, $response);
    }

    private function request(?string $token = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $request;
    }

    private function token(string $secret, ?int $expiresAt = null): string
    {
        $payload = [
            'sub' => '42',
            'role' => 'admin',
            'iat' => time(),
            'exp' => $expiresAt ?? time() + 3600,
        ];

        return JWT::encode($payload, $secret, JWT_ALGORITHM);
    }

    private function decodeJson(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
