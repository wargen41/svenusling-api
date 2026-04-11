<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        session_start();

        // Check if user has JWT token in session
        if (!isset($_SESSION['jwt_token'])) {
            // Redirect to login
            $response = new \Slim\Psr7\Response();
            $response = $response->withStatus(302)->withHeader('Location', '/admin/login');
            return $response;
        }

        // Token exists, continue
        return $handler->handle($request);
    }
}
