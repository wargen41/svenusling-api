<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MovieController
{
    private $db;

    public function __construct()
    {
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (\Exception $e) {
            error_log('MovieController init error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Public: Get all movies with filtering
     */
    public function listMovies(Request $request, Response $response): Response
    {
        try {
            error_log('listMovies called');
            
            $params = $request->getQueryParams();
            $type = $params['type'] ?? null;
            $year = $params['year'] ?? null;
            $rating = $params['rating'] ?? null;
            $search = $params['search'] ?? null;
            $skip = (int)($params['skip'] ?? 0);
            $details = $params['details'] ?? null;
            if($details === 'minimal'){
                $limit = (int)($params['limit'] ?? -1);
            }else{
                $limit = min((int)($params['limit'] ?? 100), 1000);
            }


            $where = [];
            $bindings = [];

            // Filter by type (film, series, season, episode, miniseries)
            if ($type) {
                $where[] = 'type = ?';
                $bindings[] = $type;
            }

            // Filter by year
            if ($year) {
                $where[] = 'year = ?';
                $bindings[] = $year;
            }

            // Filter by rating
            if ($rating !== null) {
                $where[] = 'rating = ?';
                $bindings[] = (int)$rating;
            }

            // Search by title
            if ($search) {
                $where[] = '(title LIKE ? OR original_title LIKE ?)';
                $searchTerm = '%' . $search . '%';
                $bindings[] = $searchTerm;
                $bindings[] = $searchTerm;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $columns = 'id, title, original_title, year, type, rating, poster_image_id, added_date';
            if($details === 'minimal'){
                $columns = 'id, title, year, type, rating';
            }

            $sql = "
                SELECT $columns
                FROM movies
                $whereClause
                ORDER BY sorting_title ASC
                LIMIT ? OFFSET ?
            ";

            $stmt = $this->db->prepare($sql);
            $bindings[] = $limit;
            $bindings[] = $skip;
            $stmt->execute($bindings);
            $movies = $stmt->fetchAll();

            // Get total count
            $countSql = "SELECT COUNT(*) as count FROM movies $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute(array_slice($bindings, 0, -2));
            $countResult = $countStmt->fetch();
            $total = $countResult['count'];

            error_log('Fetched ' . count($movies) . ' movies');

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $movies,
                'pagination' => [
                    'count' => count($movies),
                    'total' => $total,
                    'skip' => $skip,
                    'limit' => $limit
                ]
            ]);
        } catch (\Exception $e) {
            error_log('Error in listMovies: ' . $e->getMessage());
            return $this->jsonResponse(
                $response,
                ['error' => 'Failed to fetch movies', 'message' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * Public: Get single movie with all related data
     */
    public function getMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;

            if (!$movieId || !is_numeric($movieId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid movie ID'], 400);
            }

            // Get movie
            $stmt = $this->db->prepare('SELECT * FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            $movie = $stmt->fetch();

            if (!$movie) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Get genres
            $stmt = $this->db->prepare('
                SELECT g.* FROM genres g
                JOIN movies_genres mg ON mg.genre_id = g.id
                WHERE mg.movie_id = ?
            ');
            $stmt->execute([$movieId]);
            $movie['genres'] = $stmt->fetchAll();

            // Get persons by category
            $stmt = $this->db->prepare('
                SELECT p.*, mp.category, mp.sequence_number, mp.role_name, mp.note
                FROM persons p
                JOIN movies_persons mp ON mp.person_id = p.id
                WHERE mp.movie_id = ?
                ORDER BY mp.category ASC, mp.sequence_number ASC
            ');
            $stmt->execute([$movieId]);
            $persons = $stmt->fetchAll();

            // Group persons by category
            $personsByCategory = [];
            foreach ($persons as $person) {
                $category = $person['category'];
                if (!isset($personsByCategory[$category])) {
                    $personsByCategory[$category] = [];
                }
                $personsByCategory[$category][] = $person;
            }
            $movie['persons'] = $personsByCategory;

            // Get trivia
            $stmt = $this->db->prepare('SELECT sv, en FROM movies_trivia WHERE movie_id = ?');
            $stmt->execute([$movieId]);
            $movie['trivia'] = $stmt->fetchAll();

            // Get quotes
            $stmt = $this->db->prepare('SELECT quote FROM movies_quotes WHERE movie_id = ?');
            $stmt->execute([$movieId]);
            $quotes = $stmt->fetchAll();
            $movie['quotes'] = array_column($quotes, 'quote');

            // Get media
            $stmt = $this->db->prepare('
                SELECT m.* FROM media m
                JOIN media_movies mm ON mm.media_id = m.id
                WHERE mm.movie_id = ?
            ');
            $stmt->execute([$movieId]);
            $movie['media'] = $stmt->fetchAll();

            // Get poster and large images if referenced
            if ($movie['poster_image_id']) {
                $stmt = $this->db->prepare('SELECT * FROM media WHERE id = ?');
                $stmt->execute([$movie['poster_image_id']]);
                $movie['poster_image'] = $stmt->fetch();
            }

            if ($movie['large_image_id']) {
                $stmt = $this->db->prepare('SELECT * FROM media WHERE id = ?');
                $stmt->execute([$movie['large_image_id']]);
                $movie['large_image'] = $stmt->fetch();
            }

            // Get series info if this is a season or episode
            if ($movie['series_id']) {
                $stmt = $this->db->prepare('SELECT id, title, type FROM movies WHERE id = ?');
                $stmt->execute([$movie['series_id']]);
                $movie['series'] = $stmt->fetch();
            }

            // Get season info if this is an episode
            if ($movie['season_id']) {
                $stmt = $this->db->prepare('SELECT id, title, sequence_number FROM movies WHERE id = ?');
                $stmt->execute([$movie['season_id']]);
                $movie['season'] = $stmt->fetch();
            }

            // Get reviews
            $stmt = $this->db->prepare('
                SELECT r.*, u.username FROM reviews r
                JOIN users u ON r.user_id = u.id
                WHERE r.movie_id = ?
                ORDER BY r.created_at DESC
            ');
            $stmt->execute([$movieId]);
            $movie['reviews'] = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $movie
            ]);

        } catch (\Exception $e) {
            error_log('Error in getMovie: ' . $e->getMessage());
            return $this->jsonResponse(
                $response,
                ['error' => 'Failed to fetch movie', 'message' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * Protected: Create movie (admin only)
     */
    public function createMovie(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');
            $userId = $request->getAttribute('user_id');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();
            error_log('Creating movie with data: ' . json_encode($data));

            $errors = $this->validateMovieInput($data);
            if (!empty($errors)) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Validation failed', 'details' => $errors],
                    422
                );
            }

            $stmt = $this->db->prepare('
                INSERT INTO movies (
                    hidden, added_date, type, series_id, season_id, 
                    sequence_number, sequence_number_2, title, original_title, 
                    sorting_title, year, year_2, rating,
                    poster_image_id, large_image_id, imdb_id, description,
                    created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['hidden'] ?? 0,
                $data['added_date'] ?? date('Y-m-d'),
                $data['type'] ?? null,
                $data['series_id'] ?? null,
                $data['season_id'] ?? null,
                $data['sequence_number'] ?? null,
                $data['sequence_number_2'] ?? null,
                $data['title'],
                $data['original_title'] ?? null,
                $data['sorting_title'] ?? $data['title'],
                $data['year'] ?? null,
                $data['year_2'] ?? null,
                $data['rating'] ?? 0,
                $data['poster_image_id'] ?? null,
                $data['large_image_id'] ?? null,
                $data['imdb_id'] ?? null,
                $data['description'] ?? null,
                $userId,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);

            $movieId = $this->db->lastInsertId();

            // Add genres if provided
            if (!empty($data['genre_ids']) && is_array($data['genre_ids'])) {
                $genreStmt = $this->db->prepare('INSERT INTO movies_genres (movie_id, genre_id) VALUES (?, ?)');
                foreach ($data['genre_ids'] as $genreId) {
                    $genreStmt->execute([$movieId, $genreId]);
                }
            }

            error_log('Movie created with ID: ' . $movieId);

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => 'Movie created',
                    'id' => (int)$movieId
                ],
                201
            );
        } catch (\Exception $e) {
            error_log('Error in createMovie: ' . $e->getMessage());
            return $this->jsonResponse(
                $response,
                ['error' => 'Failed to create movie', 'message' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * Protected: Update movie (admin only)
     */
    public function updateMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Build update query
            $updates = [];
            $bindings = [];

            $fields = [
                'hidden', 'type', 'series_id', 'season_id', 'sequence_number',
                'sequence_number_2', 'title', 'original_title', 'sorting_title',
                'year', 'year_2', 'rating', 'poster_image_id',
                'large_image_id', 'imdb_id', 'description'
            ];

            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $bindings[] = $data[$field];
                }
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $updates[] = "updated_at = ?";
            $bindings[] = date('Y-m-d H:i:s');
            $bindings[] = $movieId;

            $sql = 'UPDATE movies SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Movie updated'
            ]);

        } catch (\Exception $e) {
            error_log('Error in updateMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update movie'], 500);
        }
    }

    /**
     * Protected: Delete movie (admin only)
     */
    public function deleteMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('DELETE FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Movie deleted'
            ]);

        } catch (\Exception $e) {
            error_log('Error in deleteMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete movie'], 500);
        }
    }

    /**
     * Public: Get all media for a movie
     */
    public function getMovieMedia(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;

            if (!$movieId || !is_numeric($movieId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid movie ID'], 400);
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id, title FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            $movie = $stmt->fetch();

            if (!$movie) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Get all media for this movie
            $stmt = $this->db->prepare('
            SELECT m.*
            FROM media m
            JOIN media_movies mm ON mm.media_id = m.id
            WHERE mm.movie_id = ?
            ORDER BY m.media_type ASC, m.file_name ASC
            ');
            $stmt->execute([$movieId]);
            $media = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'movie' => $movie,
                    'media' => $media,
                    'count' => count($media)
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in getMovieMedia: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch movie media'], 500);
        }
    }

    /**
     * Protected: Add media to movie (admin only)
     */
    public function addMediaToMovie(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['movie_id']) || empty($data['media_id'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'movie_id and media_id are required'],
                    422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$data['movie_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Verify media exists
            $stmt = $this->db->prepare('SELECT id FROM media WHERE id = ?');
            $stmt->execute([$data['media_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Media not found'], 404);
            }

            // Check if media already assigned to movie
            $stmt = $this->db->prepare('
            SELECT * FROM media_movies
            WHERE movie_id = ? AND media_id = ?
            ');
            $stmt->execute([$data['movie_id'], $data['media_id']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Media already assigned to movie'],
                    409
                );
            }

            $stmt = $this->db->prepare('
            INSERT INTO media_movies (media_id, movie_id)
            VALUES (?, ?)
            ');

            $stmt->execute([$data['media_id'], $data['movie_id']]);

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Media added to movie'],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addMediaToMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add media to movie'], 500);
        }
    }

    /**
     * Protected: Add multiple media to movie (admin only)
     */
    public function addMultipleMediaToMovie(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['movie_id']) || empty($data['media_ids']) || !is_array($data['media_ids'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'movie_id and media_ids (array) are required'],
                                           422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$data['movie_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            $successful = 0;
            $failed = 0;
            $errors = [];

            $insertStmt = $this->db->prepare('
            INSERT OR IGNORE INTO media_movies (media_id, movie_id)
            VALUES (?, ?)
            ');

            foreach ($data['media_ids'] as $mediaId) {
                try {
                    // Verify media exists
                    $stmt = $this->db->prepare('SELECT id FROM media WHERE id = ?');
                    $stmt->execute([$mediaId]);
                    if (!$stmt->fetch()) {
                        $failed++;
                        $errors[] = "Media ID $mediaId not found";
                        continue;
                    }

                    $insertStmt->execute([$mediaId, $data['movie_id']]);
                    $successful++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Media ID $mediaId: " . $e->getMessage();
                }
            }

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => "Added $successful media to movie",
                    'successful' => $successful,
                    'failed' => $failed,
                    'errors' => $errors
                ],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addMultipleMediaToMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add media to movie'], 500);
        }
    }

    /**
     * Protected: Remove media from movie (admin only)
     */
    public function removeMediaFromMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['movie_id'] ?? null;
            $mediaId = $args['media_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('
            DELETE FROM media_movies
            WHERE movie_id = ? AND media_id = ?
            ');
            $stmt->execute([$movieId, $mediaId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Media not assigned to movie'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Media removed from movie']);

        } catch (\Exception $e) {
            error_log('Error in removeMediaFromMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to remove media from movie'], 500);
        }
    }

    /**
     * Protected: Replace all media for a movie (admin only)
     */
    public function replaceMovieMedia(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (!isset($data['media_ids']) || !is_array($data['media_ids'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'media_ids (array) is required'],
                                           422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Delete all existing media assignments
            $stmt = $this->db->prepare('DELETE FROM media_movies WHERE movie_id = ?');
            $stmt->execute([$movieId]);

            // Insert new media
            $insertStmt = $this->db->prepare('
            INSERT INTO media_movies (media_id, movie_id)
            VALUES (?, ?)
            ');

            foreach ($data['media_ids'] as $mediaId) {
                $insertStmt->execute([$mediaId, $movieId]);
            }

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => 'Movie media updated',
                    'count' => count($data['media_ids'])
                ]
            );

        } catch (\Exception $e) {
            error_log('Error in replaceMovieMedia: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update movie media'], 500);
        }
    }

    private function validateMovieInput($data)
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors['title'] = 'Title is required';
        } elseif (strlen($data['title']) < 2) {
            $errors['title'] = 'Title must be at least 2 characters';
        }

        if (!empty($data['year']) && (!is_numeric($data['year']) || $data['year'] < 1800 || $data['year'] > date('Y') + 5)) {
            $errors['year'] = 'Invalid year';
        }

        if (!empty($data['rating']) && (!is_numeric($data['rating']) || $data['rating'] < -10 || $data['rating'] > 100)) {
            $errors['rating'] = 'Rating must be between -10 and 100';
        }

        return $errors;
    }

    private function jsonResponse(Response $response, $data, $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
