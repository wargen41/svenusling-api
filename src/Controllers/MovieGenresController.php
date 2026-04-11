<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MovieGenresController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: Get all genres for a movie
     */
    public function getMovieGenres(Request $request, Response $response, array $args): Response
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

            // Get all genres for this movie
            $stmt = $this->db->prepare('
                SELECT g.id, g.common, g.sv, g.en
                FROM genres g
                JOIN movies_genres mg ON mg.genre_id = g.id
                WHERE mg.movie_id = ?
                ORDER BY g.en ASC
            ');
            $stmt->execute([$movieId]);
            $genres = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'movie' => $movie,
                    'genres' => $genres,
                    'count' => count($genres)
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in getMovieGenres: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch movie genres'], 500);
        }
    }

    /**
     * Protected: Add genre to movie (admin only)
     */
    public function addGenreToMovie(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['movie_id']) || empty($data['genre_id'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'movie_id and genre_id are required'],
                    422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$data['movie_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Verify genre exists
            $stmt = $this->db->prepare('SELECT id FROM genres WHERE id = ?');
            $stmt->execute([$data['genre_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Genre not found'], 404);
            }

            // Check if genre already assigned to movie
            $stmt = $this->db->prepare('
                SELECT * FROM movies_genres 
                WHERE movie_id = ? AND genre_id = ?
            ');
            $stmt->execute([$data['movie_id'], $data['genre_id']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Genre already assigned to movie'],
                    409
                );
            }

            $stmt = $this->db->prepare('
                INSERT INTO movies_genres (movie_id, genre_id)
                VALUES (?, ?)
            ');

            $stmt->execute([$data['movie_id'], $data['genre_id']]);

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Genre added to movie'],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addGenreToMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add genre to movie'], 500);
        }
    }

    /**
     * Protected: Add multiple genres to movie (admin only)
     */
    public function addGenresToMovie(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['movie_id']) || empty($data['genre_ids']) || !is_array($data['genre_ids'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'movie_id and genre_ids (array) are required'],
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
                INSERT OR IGNORE INTO movies_genres (movie_id, genre_id)
                VALUES (?, ?)
            ');

            foreach ($data['genre_ids'] as $genreId) {
                try {
                    // Verify genre exists
                    $stmt = $this->db->prepare('SELECT id FROM genres WHERE id = ?');
                    $stmt->execute([$genreId]);
                    if (!$stmt->fetch()) {
                        $failed++;
                        $errors[] = "Genre ID $genreId not found";
                        continue;
                    }

                    $insertStmt->execute([$data['movie_id'], $genreId]);
                    $successful++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Genre ID $genreId: " . $e->getMessage();
                }
            }

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => "Added $successful genres to movie",
                    'successful' => $successful,
                    'failed' => $failed,
                    'errors' => $errors
                ],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addGenresToMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add genres to movie'], 500);
        }
    }

    /**
     * Protected: Remove genre from movie (admin only)
     */
    public function removeGenreFromMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['movie_id'] ?? null;
            $genreId = $args['genre_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('
                DELETE FROM movies_genres 
                WHERE movie_id = ? AND genre_id = ?
            ');
            $stmt->execute([$movieId, $genreId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Genre not assigned to movie'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Genre removed from movie']);

        } catch (\Exception $e) {
            error_log('Error in removeGenreFromMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to remove genre from movie'], 500);
        }
    }

    /**
     * Protected: Replace all genres for a movie (admin only)
     */
    public function replaceMovieGenres(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (!isset($data['genre_ids']) || !is_array($data['genre_ids'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'genre_ids (array) is required'],
                    422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Delete all existing genre assignments
            $stmt = $this->db->prepare('DELETE FROM movies_genres WHERE movie_id = ?');
            $stmt->execute([$movieId]);

            // Insert new genres
            $insertStmt = $this->db->prepare('
                INSERT INTO movies_genres (movie_id, genre_id)
                VALUES (?, ?)
            ');

            foreach ($data['genre_ids'] as $genreId) {
                $insertStmt->execute([$movieId, $genreId]);
            }

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => 'Movie genres updated',
                    'count' => count($data['genre_ids'])
                ]
            );

        } catch (\Exception $e) {
            error_log('Error in replaceMovieGenres: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update movie genres'], 500);
        }
    }

    private function jsonResponse(Response $response, $data, $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}