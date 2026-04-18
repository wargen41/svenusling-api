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
            'admin/login.html.twig',
            [
                'adminBaseStyles' => ADMIN_BASE_STYLES,
                'siteName' => SITE_NAME,
                'pageTitle' => 'Logga in'
            ]
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
                [
                    'error' => 'Både e-post och lösen måste vara ifyllda',
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Logga in'
                ]
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
                    [
                        'error' => 'Felaktiga inloggningsuppgifter',
                        'adminBaseStyles' => ADMIN_BASE_STYLES,
                        'siteName' => SITE_NAME,
                        'pageTitle' => 'Logga in'
                    ]
                );
            }
        } catch (\Exception $e) {
            return Twig::fromRequest($request)->render(
                $response,
                'admin/login.html.twig',
                [
                    'error' => 'Inloggning misslyckades: ' . $e->getMessage(),
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Logga in'
                ]
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
            [
                'user' => $user,
                'adminBaseStyles' => ADMIN_BASE_STYLES,
                'siteName' => SITE_NAME,
                'pageTitle' => 'Start'
            ]
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

    /**
     * Handle add genre form submission
     */
    public function handleAddGenre(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();

        try {
            $this->callApiPost('/genres', [
                'sv' => $data['sv'] ?? '',
                'en' => $data['en'] ?? '',
                'common' => isset($data['common']) ? 1 : 0
            ], $token);

            $_SESSION['message'] = 'Genre added successfully!';
            $_SESSION['message_type'] = 'success';
        } catch (\Exception $e) {
            $_SESSION['message'] = 'Error adding genre: ' . $e->getMessage();
            $_SESSION['message_type'] = 'error';
        }

        // Redirect back to genres page
        $response = $response->withStatus(302)->withHeader('Location', '/admin/genres');
        return $response;
    }

    /**
     * Handle update genre form submission
     */
    public function handleUpdateGenre(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();
        $genreId = $data['genre_id'] ?? null;

        if (!$genreId) {
            $_SESSION['message'] = 'Error: Genre ID missing';
            $_SESSION['message_type'] = 'error';
        } else {
            try {
                $this->callApiPut('/genres/' . $genreId, [
                    'sv' => $data['sv'] ?? '',
                    'en' => $data['en'] ?? '',
                    'common' => isset($data['common']) ? 1 : 0
                ], $token);

                $_SESSION['message'] = 'Genre updated successfully!';
                $_SESSION['message_type'] = 'success';
            } catch (\Exception $e) {
                $_SESSION['message'] = 'Error updating genre: ' . $e->getMessage();
                $_SESSION['message_type'] = 'error';
            }
        }

        // Redirect back to genres page
        $response = $response->withStatus(302)->withHeader('Location', '/admin/genres');
        return $response;
    }

    /**
     * Handle delete genre form submission
     */
    public function handleDeleteGenre(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();
        $genreId = $data['genre_id'] ?? null;

        if (!$genreId) {
            $_SESSION['message'] = 'Error: Genre ID missing';
            $_SESSION['message_type'] = 'error';
        } else {
            try {
                $this->callApiDelete('/genres/' . $genreId, $token);

                $_SESSION['message'] = 'Genre deleted successfully!';
                $_SESSION['message_type'] = 'success';
            } catch (\Exception $e) {
                $_SESSION['message'] = 'Error deleting genre: ' . $e->getMessage();
                $_SESSION['message_type'] = 'error';
            }
        }

        // Redirect back to genres page
        $response = $response->withStatus(302)->withHeader('Location', '/admin/genres');
        return $response;
    }

    /**
     * Update genresPage to show session messages
     */
    public function genresPage(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $_SESSION['user'] ?? null;
        $token = $_SESSION['jwt_token'] ?? null;

        // Get message from session if exists
        $message = $_SESSION['message'] ?? null;
        $messageType = $_SESSION['message_type'] ?? null;
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);

        try {
            // Fetch all genres from API
            $genres = $this->callApiGet('/genres', $token);

            return Twig::fromRequest($request)->render(
                $response,
                'admin/genres.html.twig',
                [
                    'user' => $user,
                    'genres' => $genres['data'] ?? [],
                    'message' => $message,
                    'message_type' => $messageType,
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Genrer'
                ]
            );
        } catch (\Exception $e) {
            error_log('Error in genresPage: ' . $e->getMessage());
            return Twig::fromRequest($request)->render(
                $response,
                'admin/genres.html.twig',
                [
                    'user' => $user,
                    'message' => 'Failed to load genres: ' . $e->getMessage(),
                    'message_type' => 'error',
                    'genres' => [],
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Genrer'
                ]
            );
        }
    }

    /**
     * Handle add person form submission
     */
    public function handleAddPerson(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();

        try {
            $this->callApiPost('/persons', [
                'name' => $data['name'] ?? '',
                'category' => $data['category'] ?? '',
                'birth_date' => $data['birth_date'] ?? '',
                'death_date' => $data['death_date'] ?? '',
            ], $token);

            $_SESSION['message'] = 'Person added successfully!';
            $_SESSION['message_type'] = 'success';
        } catch (\Exception $e) {
            $_SESSION['message'] = 'Error adding person: ' . $e->getMessage();
            $_SESSION['message_type'] = 'error';
        }

        // Redirect back to persons page
        $response = $response->withStatus(302)->withHeader('Location', '/admin/persons');
        return $response;
    }

    /**
     * Handle update person form submission
     */
    public function handleUpdatePerson(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();
        $personId = $data['person_id'] ?? null;

        if (!$personId) {
            $_SESSION['message'] = 'Error: Person ID missing';
            $_SESSION['message_type'] = 'error';
        } else {
            try {
                $this->callApiPut('/persons/' . $personId, [
                    'name' => $data['name'] ?? '',
                    'category' => $data['category'] ?? '',
                    'birth_date' => $data['birth_date'] ?? '',
                    'death_date' => $data['death_date'] ?? '',
                ], $token);

                $_SESSION['message'] = 'Person updated successfully!';
                $_SESSION['message_type'] = 'success';
            } catch (\Exception $e) {
                $_SESSION['message'] = 'Error updating person: ' . $e->getMessage();
                $_SESSION['message_type'] = 'error';
            }
        }

        // Redirect back to persons page
        $response = $response->withStatus(302)->withHeader('Location', '/admin/persons');
        return $response;
    }

    /**
     * Handle delete person form submission
     */
    public function handleDeletePerson(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();
        $personId = $data['person_id'] ?? null;

        if (!$personId) {
            $_SESSION['message'] = 'Error: Person ID missing';
            $_SESSION['message_type'] = 'error';
        } else {
            try {
                $this->callApiDelete('/persons/' . $personId, $token);

                $_SESSION['message'] = 'Person deleted successfully!';
                $_SESSION['message_type'] = 'success';
            } catch (\Exception $e) {
                $_SESSION['message'] = 'Error deleting person: ' . $e->getMessage();
                $_SESSION['message_type'] = 'error';
            }
        }

        // Redirect back to persons page
        $response = $response->withStatus(302)->withHeader('Location', '/admin/persons');
        return $response;
    }

    /**
     * Update personsPage to show session messages
     */
    public function personsPage(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $_SESSION['user'] ?? null;
        $token = $_SESSION['jwt_token'] ?? null;

        // Get message from session if exists
        $message = $_SESSION['message'] ?? null;
        $messageType = $_SESSION['message_type'] ?? null;
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);

        try {
            $params = $request->getQueryParams();
            $category = $params['category'] ?? null;
            if($category == 'all'){
                $category = null;
            }

            // Fetch persons from API
            $persons = $this->callApiGet("/persons?limit=-1&category=$category", $token);

            return Twig::fromRequest($request)->render(
                $response,
                'admin/persons.html.twig',
                [
                    'user' => $user,
                    'persons' => $persons['data'] ?? [],
                    'pagination' => $persons['pagination'] ?? [],
                    'param_category' => $params['category'],
                    'message' => $message,
                    'message_type' => $messageType,
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'VIP'
                ]
            );
        } catch (\Exception $e) {
            error_log('Error in personsPage: ' . $e->getMessage());
            return Twig::fromRequest($request)->render(
                $response,
                'admin/persons.html.twig',
                [
                    'user' => $user,
                    'message' => 'Failed to load persons: ' . $e->getMessage(),
                    'message_type' => 'error',
                    'persons' => [],
                    'pagination' => $persons['pagination'] ?? [],
                    'param_category' => $params['category'],
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'VIP'
                ]
            );
        }
    }

    /**
     * Helper: Call API GET
     */
    private function callApiGet($endpoint, $token = null)
    {
        $url = ADMIN_API_BASE_URL . $endpoint;
        error_log('GET ' . $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                $token ? 'Authorization: Bearer ' . $token : ''
            ],
            CURLOPT_TIMEOUT => 10
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('API error: ' . $curlError);
        }

        $response = json_decode($result, true);

        if ($httpCode >= 400) {
            throw new \Exception($response['error'] ?? 'API error');
        }

        return $response;
    }

    /**
     * Helper: Call API POST
     */
    private function callApiPost($endpoint, $data, $token = null)
    {
        $url = ADMIN_API_BASE_URL . $endpoint;
        error_log('POST ' . $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
                          CURLOPT_HTTPHEADER => [
                              'Content-Type: application/json',
                          $token ? 'Authorization: Bearer ' . $token : ''
                          ],
                          CURLOPT_TIMEOUT => 10
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('API error: ' . $curlError);
        }

        $response = json_decode($result, true);

        if ($httpCode >= 400) {
            throw new \Exception($response['error'] ?? 'API error');
        }

        return $response;
    }

    /**
     * Helper: Call API PUT
     */
    private function callApiPut($endpoint, $data, $token = null)
    {
        $url = ADMIN_API_BASE_URL . $endpoint;
        error_log('PUT ' . $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($data),
                          CURLOPT_HTTPHEADER => [
                              'Content-Type: application/json',
                          $token ? 'Authorization: Bearer ' . $token : ''
                          ],
                          CURLOPT_TIMEOUT => 10
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('API error: ' . $curlError);
        }

        $response = json_decode($result, true);

        if ($httpCode >= 400) {
            throw new \Exception($response['error'] ?? 'API error');
        }

        return $response;
    }

    /**
     * Helper: Call API DELETE
     */
    private function callApiDelete($endpoint, $token = null)
    {
        $url = ADMIN_API_BASE_URL . $endpoint;
        error_log('DELETE ' . $url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                $token ? 'Authorization: Bearer ' . $token : ''
            ],
            CURLOPT_TIMEOUT => 10
        ]);

        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('API error: ' . $curlError);
        }

        $response = json_decode($result, true);

        if ($httpCode >= 400) {
            throw new \Exception($response['error'] ?? 'API error');
        }

        return $response;
    }

    /**
     * Helper: JSON response
     */
    private function jsonResponse(Response $response, $data, $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
        ->withStatus($status)
        ->withHeader('Content-Type', 'application/json');
    }
}
