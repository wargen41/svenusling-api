<?php
namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        error_log('AdminAuthMiddleware processing request');

        $token = $_SESSION['jwt_token']);

        try {
            error_log('Decoding token with secret length: ' . strlen(JWT_SECRET));
            
            $decoded = JWT::decode(
                $token,
                new Key(JWT_SECRET, JWT_ALGORITHM)
            );
            
            error_log('✓ Token decoded successfully');
            error_log('Decoded payload: ' . json_encode($decoded));

            // Confirm that user is admin
            if($decoded->role !== 'admin'){
                // If not, redirect to login page
                return $this->redirectResponse();
            }
            
            // Attach user info to request
            $request = $request
                ->withAttribute('user_id', $decoded->sub)
                ->withAttribute('user_role', $decoded->role)
                ->withAttribute('user', $decoded);
            
            error_log('User ID: ' . $decoded->sub . ', Role: ' . $decoded->role);
                
        } catch (ExpiredException $e) {
            error_log('✗ Token expired');
            return $this->redirectResponse();
        } catch (SignatureInvalidException $e) {
            error_log('✗ Invalid token signature');
            return $this->redirectResponse();
        } catch (\Exception $e) {
            error_log('✗ Token validation error: ' . $e->getMessage());
            return $this->redirectResponse();
        }

        return $handler->handle($request);
    }

    private function redirectResponse($location = '/admin/login', $status = 302)
    {
        $response = new \Slim\Psr7\Response();
        return $response
            ->withStatus($status)
            ->withHeader('Location', $location);
    }
}
