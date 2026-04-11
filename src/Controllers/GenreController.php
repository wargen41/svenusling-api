<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GenreController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: List all genres
     */
    public function listGenres(Request $request, Response $response): Response
    {
        try {
            $stmt = $this->db->query('SELECT * FROM genres ORDER BY en ASC');
            $genres = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $genres,
                'count' => count($genres)
            ]);

        } catch (\Exception $e) {
            error_log('Error in listGenres: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch genres'], 500);
        }
    }

    /**
     * Public: Get genre with movies
     */
    public function getGenre(Request $request, Response $response, array $args): Response
    {
        try {
            $genreId = $args['id'] ?? null;

            if (!$genreId || !is_numeric($genreId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid genre ID'], 400);
            }

            $stmt = $this->db->prepare('SELECT * FROM genres WHERE id = ?');
            $stmt->execute([$genreId]);
            $genre = $stmt->fetch();

            if (!$genre) {
                return $this->jsonResponse($response, ['error' => 'Genre not found'], 404);
            }

            // Get movies with this genre
            $stmt = $this->db->prepare('
                SELECT m.id, m.title, m.year, m.type, m.rating
                FROM movies m
                JOIN movies_genres mg ON mg.movie_id = m.id
                WHERE mg.genre_id = ?
                ORDER BY m.year DESC
            ');
            $stmt->execute([$genreId]);
            $genre['movies'] = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $genre
            ]);

        } catch (\Exception $e) {
            error_log('Error in getGenre: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch genre'], 500);
        }
    }

    /**
     * Protected: Create genre (admin only)
     */
    public function createGenre(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['en']) || empty($data['sv'])) {
                return $this->jsonResponse($response, ['error' => 'en and sv are required'], 422);
            }

            $stmt = $this->db->prepare('
                INSERT INTO genres (common, sv, en)
                VALUES (?, ?, ?)
            ');

            $stmt->execute([
                $data['common'] ?? 0,
                $data['sv'],
                $data['en']
            ]);

            $genreId = $this->db->lastInsertId();

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Genre created', 'id' => (int)$genreId],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in createGenre: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to create genre'], 500);
        }
    }

    /**
     * Protected: Update genre (admin only)
     */
    public function updateGenre(Request $request, Response $response, array $args): Response
    {
        try {
            $genreId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('SELECT id FROM genres WHERE id = ?');
            $stmt->execute([$genreId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Genre not found'], 404);
            }

            $updates = [];
            $bindings = [];

            if (isset($data['sv'])) {
                $updates[] = 'sv = ?';
                $bindings[] = $data['sv'];
            }
            if (isset($data['en'])) {
                $updates[] = 'en = ?';
                $bindings[] = $data['en'];
            }
            if (isset($data['common'])) {
                $updates[] = 'common = ?';
                $bindings[] = $data['common'];
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $genreId;
            $sql = 'UPDATE genres SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Genre updated']);

        } catch (\Exception $e) {
            error_log('Error in updateGenre: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update genre'], 500);
        }
    }

    /**
     * Protected: Delete genre (admin only)
     */
    public function deleteGenre(Request $request, Response $response, array $args): Response
    {
        try {
            $genreId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('DELETE FROM genres WHERE id = ?');
            $stmt->execute([$genreId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Genre not found'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Genre deleted']);

        } catch (\Exception $e) {
            error_log('Error in deleteGenre: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete genre'], 500);
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