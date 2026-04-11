<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AwardsController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: List all awards (note: only award names without categories and no ids)
     */
    public function listAwards(Request $request, Response $response): Response
    {
        try {
            $stmt = $this->db->query('SELECT DISTINCT award FROM awards ORDER BY award ASC');
            $awards = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $awards,
                'count' => count($awards)
            ]);

        } catch (\Exception $e) {
            error_log('Error in listAwards: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch awards'], 500);
        }
    }

    /**
     * Public: List all categories (and awards); this is the one to use for a complete list
     */
    public function listCategories(Request $request, Response $response): Response
    {
        try {
            $stmt = $this->db->query('SELECT * FROM awards ORDER BY award ASC');
            $awards = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $awards,
                'count' => count($awards)
            ]);

        } catch (\Exception $e) {
            error_log('Error in listCategories: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch awards'], 500);
        }
    }

    /**
     * Public: Get award with nominees and winners
     */
    public function getAward(Request $request, Response $response, array $args): Response
    {
        try {
            $awardId = $args['id'] ?? null;

            if (!$awardId || !is_numeric($awardId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid award ID'], 400);
            }

            $stmt = $this->db->prepare('SELECT * FROM awards WHERE id = ?');
            $stmt->execute([$awardId]);
            $award = $stmt->fetch();

            if (!$award) {
                return $this->jsonResponse($response, ['error' => 'Award not found'], 404);
            }

            // Get movie nominees/winners
            $stmt = $this->db->prepare('
                SELECT am.*, m.title, m.year
                FROM awards_movies am
                LEFT JOIN movies m ON am.movie_id = m.id
                WHERE am.award_id = ?
                ORDER BY am.year DESC
            ');
            $stmt->execute([$awardId]);
            $award['movie_nominations'] = $stmt->fetchAll();

            // Get person nominees/winners
            $stmt = $this->db->prepare('
                SELECT ap.*, m.title, m.year
                FROM awards_persons ap
                LEFT JOIN movies m ON ap.movie_id = m.id
                WHERE ap.award_id = ?
                ORDER BY ap.year DESC
            ');
            $stmt->execute([$awardId]);
            $award['person_nominations'] = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $award
            ]);

        } catch (\Exception $e) {
            error_log('Error in getAward: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch award'], 500);
        }
    }

    /**
     * Protected: Create award (admin only)
     */
    public function createAward(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['award'])) {
                return $this->jsonResponse($response, ['error' => 'award field is required'], 422);
            }

            $stmt = $this->db->prepare('
                INSERT INTO awards (award, category)
                VALUES (?, ?)
            ');

            $stmt->execute([
                $data['award'],
                $data['category'] ?? null
            ]);

            $awardId = $this->db->lastInsertId();

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Award created', 'id' => (int)$awardId],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in createAward: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to create award'], 500);
        }
    }

    /**
     * Protected: Update award (admin only)
     */
    public function updateAward(Request $request, Response $response, array $args): Response
    {
        try {
            $awardId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('SELECT id FROM awards WHERE id = ?');
            $stmt->execute([$awardId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Award not found'], 404);
            }

            $updates = [];
            $bindings = [];

            if (isset($data['award'])) {
                $updates[] = 'award = ?';
                $bindings[] = $data['award'];
            }
            if (isset($data['category'])) {
                $updates[] = 'category = ?';
                $bindings[] = $data['category'];
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $awardId;
            $sql = 'UPDATE awards SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Award updated']);

        } catch (\Exception $e) {
            error_log('Error in updateAward: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update award'], 500);
        }
    }

    /**
     * Protected: Delete award (admin only)
     */
    public function deleteAward(Request $request, Response $response, array $args): Response
    {
        try {
            $awardId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('DELETE FROM awards WHERE id = ?');
            $stmt->execute([$awardId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Award not found'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Award deleted']);

        } catch (\Exception $e) {
            error_log('Error in deleteAward: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete award'], 500);
        }
    }

    /**
     * Protected: Add movie nomination/award (admin only)
     */
    public function addMovieNomination(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['award_id']) || empty($data['movie_id'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'award_id and movie_id are required'],
                    422
                );
            }

            $stmt = $this->db->prepare('
                INSERT INTO awards_movies (award_id, year, movie_id, won, note)
                VALUES (?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['award_id'],
                $data['year'] ?? null,
                $data['movie_id'],
                $data['won'] ?? 0,
                $data['note'] ?? null
            ]);

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Movie nomination added'],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addMovieNomination: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add nomination'], 500);
        }
    }

    /**
     * Protected: Add person nomination/award (admin only)
     */
    public function addPersonNomination(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['award_id']) || empty($data['person_name'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'award_id and person_name are required'],
                    422
                );
            }

            $stmt = $this->db->prepare('
                INSERT INTO awards_persons (award_id, year, movie_id, person_id, person_name, won, note)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['award_id'],
                $data['year'] ?? null,
                $data['movie_id'] ?? null,
                $data['person_id'] ?? null,
                $data['person_name'],
                $data['won'] ?? 0,
                $data['note'] ?? null
            ]);

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Person nomination added'],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addPersonNomination: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add nomination'], 500);
        }
    }

    /**
     * Protected: Update movie nomination (admin only)
     */
    public function updateMovieNomination(Request $request, Response $response, array $args): Response
    {
        try {
            $awardId = $args['award_id'] ?? null;
            $movieId = $args['movie_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('
                SELECT * FROM awards_movies 
                WHERE award_id = ? AND movie_id = ?
            ');
            $stmt->execute([$awardId, $movieId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Nomination not found'], 404);
            }

            $updates = [];
            $bindings = [];

            if (isset($data['year'])) {
                $updates[] = 'year = ?';
                $bindings[] = $data['year'];
            }
            if (isset($data['won'])) {
                $updates[] = 'won = ?';
                $bindings[] = $data['won'];
            }
            if (isset($data['note'])) {
                $updates[] = 'note = ?';
                $bindings[] = $data['note'];
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $awardId;
            $bindings[] = $movieId;
            $sql = 'UPDATE awards_movies SET ' . implode(', ', $updates) . ' WHERE award_id = ? AND movie_id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Nomination updated']);

        } catch (\Exception $e) {
            error_log('Error in updateMovieNomination: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update nomination'], 500);
        }
    }

    /**
     * Protected: Update person nomination (admin only)
     */
    public function updatePersonNomination(Request $request, Response $response, array $args): Response
    {
        try {
            $awardId = $args['award_id'] ?? null;
            $personId = $args['person_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('
                SELECT * FROM awards_persons 
                WHERE award_id = ? AND person_id = ?
            ');
            $stmt->execute([$awardId, $personId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Nomination not found'], 404);
            }

            $updates = [];
            $bindings = [];

            if (isset($data['year'])) {
                $updates[] = 'year = ?';
                $bindings[] = $data['year'];
            }
            if (isset($data['movie_id'])) {
                $updates[] = 'movie_id = ?';
                $bindings[] = $data['movie_id'];
            }
            if (isset($data['person_name'])) {
                $updates[] = 'person_name = ?';
                $bindings[] = $data['person_name'];
            }
            if (isset($data['won'])) {
                $updates[] = 'won = ?';
                $bindings[] = $data['won'];
            }
            if (isset($data['note'])) {
                $updates[] = 'note = ?';
                $bindings[] = $data['note'];
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $awardId;
            $bindings[] = $personId;
            $sql = 'UPDATE awards_persons SET ' . implode(', ', $updates) . ' WHERE award_id = ? AND person_id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Nomination updated']);

        } catch (\Exception $e) {
            error_log('Error in updatePersonNomination: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update nomination'], 500);
        }
    }

    /**
     * Protected: Delete movie nomination (admin only)
     */
    public function deleteMovieNomination(Request $request, Response $response, array $args): Response
    {
        try {
            $awardId = $args['award_id'] ?? null;
            $movieId = $args['movie_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('
                DELETE FROM awards_movies 
                WHERE award_id = ? AND movie_id = ?
            ');
            $stmt->execute([$awardId, $movieId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Nomination not found'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Nomination deleted']);

        } catch (\Exception $e) {
            error_log('Error in deleteMovieNomination: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete nomination'], 500);
        }
    }

    /**
     * Protected: Delete person nomination (admin only)
     */
    public function deletePersonNomination(Request $request, Response $response, array $args): Response
    {
        try {
            $awardId = $args['award_id'] ?? null;
            $personId = $args['person_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('
                DELETE FROM awards_persons 
                WHERE award_id = ? AND person_id = ?
            ');
            $stmt->execute([$awardId, $personId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Nomination not found'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Nomination deleted']);

        } catch (\Exception $e) {
            error_log('Error in deletePersonNomination: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete nomination'], 500);
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
