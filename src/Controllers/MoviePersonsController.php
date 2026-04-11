<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MoviePersonsController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: Get all persons for a movie
     */
    public function getMoviePersons(Request $request, Response $response, array $args): Response
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

            // Get all persons for this movie
            $stmt = $this->db->prepare('
                SELECT mp.*, p.id as person_id, p.name, p.birth_date, p.death_date, p.poster_image_id
                FROM movies_persons mp
                JOIN persons p ON mp.person_id = p.id
                WHERE mp.movie_id = ?
                ORDER BY mp.category ASC, mp.sequence_number ASC
            ');
            $stmt->execute([$movieId]);
            $persons = $stmt->fetchAll();

            // Group by category
            $personsByCategory = [];
            foreach ($persons as $person) {
                $category = $person['category'];
                if (!isset($personsByCategory[$category])) {
                    $personsByCategory[$category] = [];
                }
                $personsByCategory[$category][] = $person;
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'movie' => $movie,
                    'persons' => $personsByCategory
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in getMoviePersons: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch movie persons'], 500);
        }
    }

    /**
     * Public: Get persons by category for a movie
     */
    public function getMoviePersonsByCategory(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;
            $category = $args['category'] ?? null;

            if (!$movieId || !is_numeric($movieId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid movie ID'], 400);
            }

            if (!$category) {
                return $this->jsonResponse($response, ['error' => 'Category is required'], 400);
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id, title FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            $movie = $stmt->fetch();

            if (!$movie) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Get persons by category
            $stmt = $this->db->prepare('
                SELECT mp.*, p.id as person_id, p.name, p.birth_date, p.death_date, p.poster_image_id
                FROM movies_persons mp
                JOIN persons p ON mp.person_id = p.id
                WHERE mp.movie_id = ? AND mp.category = ?
                ORDER BY mp.sequence_number ASC
            ');
            $stmt->execute([$movieId, $category]);
            $persons = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'movie' => $movie,
                    'category' => $category,
                    'persons' => $persons,
                    'count' => count($persons)
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in getMoviePersonsByCategory: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch movie persons'], 500);
        }
    }

    /**
     * Protected: Add person to movie (admin only)
     */
    public function addPersonToMovie(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['movie_id']) || empty($data['person_id']) || empty($data['category'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'movie_id, person_id, and category are required'],
                    422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$data['movie_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Verify person exists
            $stmt = $this->db->prepare('SELECT id, name FROM persons WHERE id = ?');
            $stmt->execute([$data['person_id']]);
            $person = $stmt->fetch();
            if (!$person) {
                return $this->jsonResponse($response, ['error' => 'Person not found'], 404);
            }

            // Check if relation already exists
            $stmt = $this->db->prepare('
                SELECT * FROM movies_persons 
                WHERE movie_id = ? AND person_id = ? AND category = ?
            ');
            $stmt->execute([$data['movie_id'], $data['person_id'], $data['category']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Person already added to movie in this category'],
                    409
                );
            }

            $stmt = $this->db->prepare('
                INSERT INTO movies_persons (movie_id, person_id, person_name, category, role_name, note, sequence_number)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['movie_id'],
                $data['person_id'],
                $person['name'],
                $data['category'],
                $data['role_name'] ?? null,
                $data['note'] ?? null,
                $data['sequence_number'] ?? 0
            ]);

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Person added to movie'],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addPersonToMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add person to movie'], 500);
        }
    }

    /**
     * Protected: Update person in movie (admin only)
     */
    public function updatePersonInMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['movie_id'] ?? null;
            $personId = $args['person_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('
                SELECT * FROM movies_persons 
                WHERE movie_id = ? AND person_id = ?
            ');
            $stmt->execute([$movieId, $personId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Person not found in movie'], 404);
            }

            $updates = [];
            $bindings = [];

            if (isset($data['category'])) {
                $updates[] = 'category = ?';
                $bindings[] = $data['category'];
            }
            if (isset($data['sequence_number'])) {
                $updates[] = 'sequence_number = ?';
                $bindings[] = $data['sequence_number'];
            }
            if (isset($data['role_name'])) {
                $updates[] = 'role_name = ?';
                $bindings[] = $data['role_name'];
            }
            if (isset($data['note'])) {
                $updates[] = 'note = ?';
                $bindings[] = $data['note'];
            }
            if (isset($data['person_name'])) {
                $updates[] = 'person_name = ?';
                $bindings[] = $data['person_name'];
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $movieId;
            $bindings[] = $personId;
            $sql = 'UPDATE movies_persons SET ' . implode(', ', $updates) . ' WHERE movie_id = ? AND person_id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Person updated in movie']);

        } catch (\Exception $e) {
            error_log('Error in updatePersonInMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update person in movie'], 500);
        }
    }

    /**
     * Protected: Remove person from movie (admin only)
     */
    public function removePersonFromMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['movie_id'] ?? null;
            $personId = $args['person_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('
                DELETE FROM movies_persons 
                WHERE movie_id = ? AND person_id = ?
            ');
            $stmt->execute([$movieId, $personId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Person not found in movie'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Person removed from movie']);

        } catch (\Exception $e) {
            error_log('Error in removePersonFromMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to remove person from movie'], 500);
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
