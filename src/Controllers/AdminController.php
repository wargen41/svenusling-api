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
                'message' => 'Inloggad som ' . $user['username'],
                'message_type' => 'success',
                'adminBaseStyles' => ADMIN_BASE_STYLES,
                'siteName' => SITE_NAME,
                'pageTitle' => 'Start',
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
                'sv' => $data['sv'] ?? null,
                'en' => $data['en'] ?? null,
                'common' => isset($data['common']) ? 1 : 0
            ], $token);

            $_SESSION['message'] = 'Genre "' . $data['sv'] . '" tillagd';
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
                    'sv' => $data['sv'] ?? null,
                    'en' => $data['en'] ?? null,
                    'common' => isset($data['common']) ? 1 : 0
                ], $token);

                $_SESSION['message'] = 'Genre "' . $data['sv'] . '" uppdaterad';
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

                $_SESSION['message'] = 'Genre raderad';
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
     * Genres page (protected)
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
                'name' => $data['name'] ?? null,
                'category' => $data['category'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'death_date' => $data['death_date'] ?? null,
            ], $token);

            $_SESSION['message'] = '"' . $data['name'] . '" tillagd';
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
                    'name' => $data['name'] ?? null,
                    'category' => $data['category'] ?? null,
                    'birth_date' => $data['birth_date'] ?? null,
                    'death_date' => $data['death_date'] ?? null,
                ], $token);

                $_SESSION['message'] = '"' . $data['name'] . '" uppdaterad';
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

                $_SESSION['message'] = 'Person raderad';
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
     * Persons page (protected)
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
            $limit = $params['limit'] ?? '-1';
            $search = $params['search'] ?? null;
            $category = $params['category'] ?? null;
            if($category == 'all'){
                $category = null;
            }else if($category == null){
                $limit = 0; // Visa inget genom att ange limit 0
            }

            // Fetch persons from API
            $persons = $this->callApiGet("/persons?limit=$limit&category=$category&search=$search", $token);

            return Twig::fromRequest($request)->render(
                $response,
                'admin/persons.html.twig',
                [
                    'user' => $user,
                    'persons' => $persons['data'] ?? [],
                    'pagination' => $persons['pagination'] ?? [],
                    'params' => $params,
                    'message' => $message,
                    'message_type' => $messageType,
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Personer'
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
                    'params' => $params,
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Personer'
                ]
            );
        }
    }

    /**
     * Handle add movie form submission
     */
    public function handleAddMovie(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();
        $form_redirect = $data['form_redirect'] ?? '/admin';

        try {
            $this->callApiPost('/movies', [
                'hidden' => $data['hidden'] ?? '1',
                'added_date' => $data['added_date'] ?? null,
                'type' => $data['type'] ?? null,
                'genre_ids' => $data['genre_ids'] ?? [],
                'series_id' => $data['series_id'] ?? null,
                'season_id' => $data['season_id'] ?? null,
                'sequence_number' => $data['sequence_number'] ?? null,
                'sequence_number_2' => $data['sequence_number_2'] ?? null,
                'title' => $data['title'] ?? null,
                'original_title' => $data['original_title'] ?? null,
                'sorting_title' => $data['sorting_title'] ?? $data['title'],
                'year' => $data['year'] ?? null,
                'year_2' => $data['year_2'] ?? null,
                'rating' => $data['rating'] ?? '0', // Detta ska ändras sen
                'poster_image_id' => $data['poster_image_id'] ?? null,
                'large_image_id' => $data['large_image_id'] ?? null,
                'imdb_id' => $data['imdb_id'] ?? null,
                'description' => $data['description'] ?? null,
            ], $token);

            $_SESSION['message'] = '"' . $data['title'] . '" tillagd';
            $_SESSION['message_type'] = 'success';
        } catch (\Exception $e) {
            $_SESSION['message'] = 'Error adding movie: ' . $e->getMessage();
            $_SESSION['message_type'] = 'error';
        }

        // Redirect
        $response = $response->withStatus(302)->withHeader('Location', $form_redirect);
        return $response;
    }

    /**
     * Handle update movie form submission
     */
    public function handleUpdateMovie(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();
        $form_redirect = $data['form_redirect'] ?? '/admin';
        $movieId = $data['movie_id'] ?? null;

        if (!$movieId) {
            $_SESSION['message'] = 'Error: Movie ID missing';
            $_SESSION['message_type'] = 'error';
        } else {
            try {
                $this->callApiPut('/movies/' . $movieId, [
                    'hidden' => $data['hidden'] ?? '1',
                    'added_date' => $data['added_date'] ?? null,
                    'type' => $data['type'] ?? null,
                    'genre_ids' => $data['genre_ids'] ?? [],
                    'series_id' => $data['series_id'] ?? null,
                    'season_id' => $data['season_id'] ?? null,
                    'sequence_number' => $data['sequence_number'] ?? null,
                    'sequence_number_2' => $data['sequence_number_2'] ?? null,
                    'title' => $data['title'] ?? null,
                    'original_title' => $data['original_title'] ?? null,
                    'sorting_title' => $data['sorting_title'] ?? $data['title'],
                    'year' => $data['year'] ?? null,
                    'year_2' => $data['year_2'] ?? null,
                    'rating' => $data['rating'] ?? '0', // Detta ska ändras sen
                    'poster_image_id' => $data['poster_image_id'] ?? null,
                    'large_image_id' => $data['large_image_id'] ?? null,
                    'imdb_id' => $data['imdb_id'] ?? null,
                    'description' => $data['description'] ?? null,
                ], $token);

                $_SESSION['message'] = '"' . $data['title'] . '" uppdaterad';
                $_SESSION['message_type'] = 'success';
            } catch (\Exception $e) {
                $_SESSION['message'] = 'Error updating movie: ' . $e->getMessage();
                $_SESSION['message_type'] = 'error';
            }
        }

        // Redirect
        $response = $response->withStatus(302)->withHeader('Location', $form_redirect);
        return $response;
    }

    /**
     * Handle delete movie form submission
     */
    public function handleDeleteMovie(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['jwt_token'] ?? null;
        $data = $request->getParsedBody();
        $form_redirect = $data['form_redirect'] ?? '/admin';
        $movieId = $data['movie_id'] ?? null;

        if (!$movieId) {
            $_SESSION['message'] = 'Error: Movie ID missing';
            $_SESSION['message_type'] = 'error';
        } else {
            try {
                $this->callApiDelete('/movies/' . $movieId, $token);

                $_SESSION['message'] = 'Raderades';
                $_SESSION['message_type'] = 'success';
            } catch (\Exception $e) {
                $_SESSION['message'] = 'Error deleting movie: ' . $e->getMessage();
                $_SESSION['message_type'] = 'error';
            }
        }

        // Redirect back to movies page
        $response = $response->withStatus(302)->withHeader('Location', $form_redirect);
        return $response;
    }

    /**
     * Films page (protected)
     */
    public function filmsPage(Request $request, Response $response): Response
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
            $limit = $params['limit'] ?? '-1';
            $search = $params['search'] ?? null;
            $type = $params['type'] ?? null;
            $year = $params['year'] ?? null;
            $rating = $params['rating'] ?? null;
            /* Att kunna välja Alla funkar inte när jag har sidor uppdelade mellan filmer och serier
            if($type == 'all'){
                $type = null;
            }else */if($type == null){
                $limit = 0; // Visa inget genom att ange limit 0
            }

            // Fetch movies from API
            $movies = $this->callApiGet("/movies?limit=$limit&type=$type&year=$year&search=$search", $token);

            return Twig::fromRequest($request)->render(
                $response,
                'admin/films.html.twig',
                [
                    'user' => $user,
                    'movies' => $movies['data'] ?? [],
                    'pagination' => $movies['pagination'] ?? [],
                    'params' => $params,
                    'message' => $message,
                    'message_type' => $messageType,
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Filmer'
                ]
            );
        } catch (\Exception $e) {
            error_log('Error in moviesPage: ' . $e->getMessage());
            return Twig::fromRequest($request)->render(
                $response,
                'admin/films.html.twig',
                [
                    'user' => $user,
                    'message' => 'Failed to load movies: ' . $e->getMessage(),
                    'message_type' => 'error',
                    'movies' => [],
                    'pagination' => $movies['pagination'] ?? [],
                    'params' => $params,
                    'adminBaseStyles' => ADMIN_BASE_STYLES,
                    'siteName' => SITE_NAME,
                    'pageTitle' => 'Filmer'
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
