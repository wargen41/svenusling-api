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
     * Public: Get all persons for a movie (all categories)
     */
    public function getMoviePersons(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if (!$movieId || !is_numeric($movieId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid movie ID'], 400);
            }

            // Verify movie exists and check visibility
            $stmt = $this->db->prepare('SELECT id, title, hidden FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            $movie = $stmt->fetch();

            if (!$movie) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Check if movie is hidden and user is not admin
            if ($movie['hidden'] == 1 && $userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Get all persons for this movie, grouped by category
            $stmt = $this->db->prepare('
            SELECT * FROM movies_persons
            WHERE movie_id = ?
            ORDER BY category ASC, sequence_number ASC
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
                    'movie' => [
                        'id' => $movie['id'],
                        'title' => $movie['title']
                    ],
                    'persons' => $personsByCategory,
                    'count' => count($persons)
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
            $userRole = $request->getAttribute('user_role');

            if (!$movieId || !is_numeric($movieId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid movie ID'], 400);
            }

            if (!$category) {
                return $this->jsonResponse($response, ['error' => 'Category is required'], 400);
            }

            // Verify movie exists and check visibility
            $stmt = $this->db->prepare('SELECT id, title, hidden FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            $movie = $stmt->fetch();

            if (!$movie) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Check if movie is hidden and user is not admin
            if ($movie['hidden'] == 1 && $userRole !== 'admin') {
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
                    'movie' => [
                        'id' => $movie['id'],
                        'title' => $movie['title']
                    ],
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

            if (empty($data['movie_id']) || empty($data['person_name']) || empty($data['category'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'movie_id, person_name and category are required'],
                    422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$data['movie_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            if(!empty($data['person_id'])) {
                // Verify person ID exists
                $stmt = $this->db->prepare('SELECT id FROM persons WHERE id = ?');
                $stmt->execute([$data['person_id']]);
                $person = $stmt->fetch();
                if (!$person) {
                    return $this->jsonResponse($response, ['error' => 'No person found with ID ' . $data['person_id']], 404);
                }
            }

            // Check if relation already exists
            $stmt = $this->db->prepare('
                SELECT * FROM movies_persons 
                WHERE movie_id = ? AND person_name = ? AND category = ?
            ');
            $stmt->execute([$data['movie_id'], $data['person_name'], $data['category']]);
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
                $data['movie_id'] ?? null,
                $data['person_id'] ?? null,
                $data['person_name'] ?? null,
                $data['category'] ?? null,
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
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $movieId = $data['movie_id'] ?? null;
            $personName = $data['person_name'] ?? null;
            $category = $data['category'] ?? null;
            $sequenceNo = $data['sequence_number'] ?? null;

            $stmt = $this->db->prepare('
                SELECT * FROM movies_persons
                WHERE movie_id = ? AND person_name = \'?\' AND category = ? AND sequence_number = ?
            ');
            $stmt->execute([$movieId, $personName, $category, $sequenceNo]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Crew member not found'], 404);
            }

            $updates = [];
            $bindings = [];

            if (isset($data['role_name'])) {
                $updates[] = 'role_name = ?';
                $bindings[] = $data['role_name'];
            }
            if (isset($data['note'])) {
                $updates[] = 'note = ?';
                $bindings[] = $data['note'];
            }
            if (isset($data['person_id'])) {
                $updates[] = 'person_id = ?';
                $bindings[] = $data['person_id'];
            }
            $updates[] = 'person_name = ?';
            $bindings[] = $personName;

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $movieId;
            $bindings[] = $personName;
            $bindings[] = $category;
            $bindings[] = $sequenceNo;
            $sql = 'UPDATE movies_persons SET ' . implode(', ', $updates) . ' WHERE movie_id = ? AND person_name = \'?\' AND category = ? AND sequence_number = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Crew member updated']);

        } catch (\Exception $e) {
            error_log('Error in updatePersonInMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update crew member'], 500);
        }
    }

    /**
     * Protected: Remove person from movie (admin only)
     */
    public function removePersonFromMovie(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['movie_id'] ?? null;
            $category = $args['category'] ?? null;
            $sequenceNo = $args['sequence_number'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('
                DELETE FROM movies_persons 
                WHERE movie_id = ? AND category = ? AND sequence_number = ?
            ');
            $stmt->execute([$movieId, $category, $sequenceNo]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Person not found in list'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Person removed from list']);

        } catch (\Exception $e) {
            error_log('Error in removePersonFromMovie: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to remove person from list'], 500);
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
