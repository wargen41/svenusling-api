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
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        error_log('Checking for JWT token in session');

        // Check if user has JWT token in session
        if (!isset($_SESSION['jwt_token']) || empty($_SESSION['jwt_token'])) {
            error_log('No JWT token in session, redirecting to login');
            $response = new \Slim\Psr7\Response();
            $response = $response->withStatus(302)->withHeader('Location', '/admin/login');
            return $response;
        }

        error_log('Valid JWT token found, allowing request');
        return $handler->handle($request);
    }
}
