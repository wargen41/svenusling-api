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
        // Session is already started by AdminAuthMiddleware

        // Unset all session variables
        $_SESSION = [];

        // Destroy the session
        session_destroy();

        // Clear the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                      '',
                      time() - 42000,
                      $params["path"],
                      $params["domain"],
                      $params["secure"],
                      $params["httponly"]
            );
        }

        error_log('User logged out, session destroyed');

        $response = $response->withStatus(302)->withHeader('Location', '/admin/login');
        return $response;
    }

    /**
     * Call your API login endpoint
     */
    private function callApiLogin($email, $password)
    {
        error_log('callApiLogin called with email: ' . $email);

        $apiUrl = ADMIN_API_BASE_URL . '/auth/login';
        error_log('API URL: ' . $apiUrl);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'email' => $email,
                'password' => $password
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log('cURL errno: ' . curl_errno($ch));
        error_log('HTTP code: ' . $httpCode);

        if ($curlError) {
            error_log('cURL error: ' . $curlError);
            throw new \Exception('API connection failed: ' . $curlError);
        }

        error_log('API response body: ' . $result);

        $response = json_decode($result, true);

        if ($response === null) {
            error_log('JSON decode error: ' . json_last_error_msg());
            throw new \Exception('Invalid API response: ' . $result);
        }

        if (!isset($response['success']) || !$response['success']) {
            $errorMsg = $response['error'] ?? 'Login failed';
            error_log('Login failed. Error: ' . $errorMsg);
            throw new \Exception($errorMsg);
        }

        error_log('Login successful');
        return $response;
    }
}
