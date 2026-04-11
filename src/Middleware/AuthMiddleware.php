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

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        error_log('AuthMiddleware processing request');
        
        $authHeader = $request->getHeader('Authorization');
        error_log('Authorization header: ' . json_encode($authHeader));
        
        if (empty($authHeader)) {
            error_log('✗ Missing authorization header');
            return $this->jsonResponse(
                ['error' => 'Missing authorization header'],
                401
            );
        }

        $token = str_replace('Bearer ', '', $authHeader[0]);
        error_log('Token: ' . substr($token, 0, 20) . '...');

        try {
            error_log('Decoding token with secret length: ' . strlen(JWT_SECRET));
            
            $decoded = JWT::decode(
                $token,
                new Key(JWT_SECRET, JWT_ALGORITHM)
            );
            
            error_log('✓ Token decoded successfully');
            error_log('Decoded payload: ' . json_encode($decoded));
            
            // Attach user info to request
            $request = $request
                ->withAttribute('user_id', $decoded->sub)
                ->withAttribute('user_role', $decoded->role)
                ->withAttribute('user', $decoded);
            
            error_log('User ID: ' . $decoded->sub . ', Role: ' . $decoded->role);
                
        } catch (ExpiredException $e) {
            error_log('✗ Token expired');
            return $this->jsonResponse(['error' => 'Token expired'], 401);
        } catch (SignatureInvalidException $e) {
            error_log('✗ Invalid token signature');
            return $this->jsonResponse(['error' => 'Invalid token signature'], 401);
        } catch (\Exception $e) {
            error_log('✗ Token validation error: ' . $e->getMessage());
            return $this->jsonResponse(['error' => 'Invalid token'], 401);
        }

        return $handler->handle($request);
    }

    private function jsonResponse($data, $status = 200)
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}