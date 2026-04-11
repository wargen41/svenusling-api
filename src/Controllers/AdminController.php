<?php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AdminController
{
    private $view;

    public function __construct()
    {
        // You'll need to inject Twig, or access it from the app
    }

    /**
     * Show login page
     */
    public function loginPage(Request $request, Response $response): Response
    {
        // Render login template
        return Twig::fromRequest($request)->render(
            $response,
            'admin/login.html.twig'
        );
    }

    /**
     * Handle login form submission
     */
    public function handleLogin(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data['email']) || empty($data['password'])) {
            return Twig::fromRequest($request)->render(
                $response,
                'admin/login.html.twig',
                ['error' => 'Email and password are required']
            );
        }

        try {
            // Call your API login endpoint
            $loginResponse = $this->callApiLogin($data['email'], $data['password']);

            if (isset($loginResponse['token']) && isset($loginResponse['user'])) {
                // Store token in session
                session_start();
                $_SESSION['jwt_token'] = $loginResponse['token'];
                $_SESSION['user'] = $loginResponse['user'];

                // Redirect to dashboard
                $response = $response->withStatus(302)->withHeader('Location', '/admin');
                return $response;
            } else {
                return Twig::fromRequest($request)->render(
                    $response,
                    'admin/login.html.twig',
                    ['error' => 'Invalid credentials']
                );
            }
        } catch (\Exception $e) {
            return Twig::fromRequest($request)->render(
                $response,
                'admin/login.html.twig',
                ['error' => 'Login failed: ' . $e->getMessage()]
            );
        }
    }

    /**
     * Dashboard page (protected)
     */
    public function dashboard(Request $request, Response $response): Response
    {
        session_start();
        $user = $_SESSION['user'] ?? null;

        return Twig::fromRequest($request)->render(
            $response,
            'admin/dashboard.html.twig',
            ['user' => $user]
        );
    }

    /**
     * Logout
     */
    public function logout(Request $request, Response $response): Response
    {
        session_start();
        session_destroy();

        $response = $response->withStatus(302)->withHeader('Location', '/admin/login');
        return $response;
    }

    /**
     * Call your API login endpoint
     */
    private function callApiLogin($email, $password)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://localhost:8000/auth/login', // Adjust to your API URL
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $email,
                'password' => $password
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ]
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($result, true);

        if (!isset($response['success']) || !$response['success']) {
            throw new \Exception($response['error'] ?? 'Login failed');
        }

        return $response;
    }
}
