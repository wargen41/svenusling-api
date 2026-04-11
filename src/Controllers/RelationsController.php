<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RelationsController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: List all relation types
     */
    public function listRelationTypes(Request $request, Response $response): Response
    {
        try {
            $stmt = $this->db->query('SELECT * FROM relations ORDER BY en ASC');
            $relations = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $relations,
                'count' => count($relations)
            ]);

        } catch (\Exception $e) {
            error_log('Error in listRelationTypes: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch relation types'], 500);
        }
    }

    /**
     * Public: Get relation type with all person pairs
     */
    public function getRelationType(Request $request, Response $response, array $args): Response
    {
        try {
            $relationId = $args['id'] ?? null;

            if (!$relationId || !is_numeric($relationId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid relation ID'], 400);
            }

            $stmt = $this->db->prepare('SELECT * FROM relations WHERE id = ?');
            $stmt->execute([$relationId]);
            $relation = $stmt->fetch();

            if (!$relation) {
                return $this->jsonResponse($response, ['error' => 'Relation type not found'], 404);
            }

            // Get all person pairs with this relation
            $stmt = $this->db->prepare('
                SELECT rp.*, p1.name as person_1_name
                FROM relations_persons rp
                JOIN persons p1 ON rp.person_id = p1.id
                WHERE rp.relation_id = ?
                ORDER BY p1.name ASC
            ');
            $stmt->execute([$relationId]);
            $relation['person_pairs'] = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $relation
            ]);

        } catch (\Exception $e) {
            error_log('Error in getRelationType: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch relation type'], 500);
        }
    }

    /**
     * Public: Get all relations for a person
     */
    public function getPersonRelations(Request $request, Response $response, array $args): Response
    {
        try {
            $personId = $args['id'] ?? null;

            if (!$personId || !is_numeric($personId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid person ID'], 400);
            }

            // Verify person exists
            $stmt = $this->db->prepare('SELECT id, name FROM persons WHERE id = ?');
            $stmt->execute([$personId]);
            $person = $stmt->fetch();

            if (!$person) {
                return $this->jsonResponse($response, ['error' => 'Person not found'], 404);
            }

            // Get all relations for this person
            $stmt = $this->db->prepare('
                SELECT rp.*, r.sv, r.en, p2.id as person_2_id, p2.name as person_2_name
                FROM relations_persons rp
                JOIN relations r ON rp.relation_id = r.id
                LEFT JOIN persons p2 ON rp.person_2_id = p2.id
                WHERE rp.person_id = ?
                ORDER BY r.en ASC
            ');
            $stmt->execute([$personId]);
            $relations = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'person' => $person,
                    'relations' => $relations
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in getPersonRelations: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch person relations'], 500);
        }
    }

    /**
     * Protected: Create relation type (admin only)
     */
    public function createRelationType(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['en']) || empty($data['sv'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'en and sv fields are required'],
                    422
                );
            }

            $stmt = $this->db->prepare('
                INSERT INTO relations (sv, en)
                VALUES (?, ?)
            ');

            $stmt->execute([
                $data['sv'],
                $data['en']
            ]);

            $relationId = $this->db->lastInsertId();

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Relation type created', 'id' => (int)$relationId],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in createRelationType: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to create relation type'], 500);
        }
    }

    /**
     * Protected: Update relation type (admin only)
     */
    public function updateRelationType(Request $request, Response $response, array $args): Response
    {
        try {
            $relationId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('SELECT id FROM relations WHERE id = ?');
            $stmt->execute([$relationId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Relation type not found'], 404);
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

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $relationId;
            $sql = 'UPDATE relations SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Relation type updated']);

        } catch (\Exception $e) {
            error_log('Error in updateRelationType: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update relation type'], 500);
        }
    }

    /**
     * Protected: Delete relation type (admin only)
     */
    public function deleteRelationType(Request $request, Response $response, array $args): Response
    {
        try {
            $relationId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('DELETE FROM relations WHERE id = ?');
            $stmt->execute([$relationId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Relation type not found'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Relation type deleted']);

        } catch (\Exception $e) {
            error_log('Error in deleteRelationType: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete relation type'], 500);
        }
    }

    /**
     * Protected: Create person relation (admin only)
     */
    public function createPersonRelation(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['person_id']) || empty($data['relation_id'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'person_id and relation_id are required'],
                    422
                );
            }

            // Verify person exists
            $stmt = $this->db->prepare('SELECT id FROM persons WHERE id = ?');
            $stmt->execute([$data['person_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Person not found'], 404);
            }

            // Verify relation type exists
            $stmt = $this->db->prepare('SELECT id FROM relations WHERE id = ?');
            $stmt->execute([$data['relation_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Relation type not found'], 404);
            }

            // Verify second person exists if provided
            if (!empty($data['person_2_id'])) {
                $stmt = $this->db->prepare('SELECT id FROM persons WHERE id = ?');
                $stmt->execute([$data['person_2_id']]);
                if (!$stmt->fetch()) {
                    return $this->jsonResponse($response, ['error' => 'Second person not found'], 404);
                }
            }

            $stmt = $this->db->prepare('
                INSERT INTO relations_persons (person_id, person_2_id, person_2_name, relation_id, date_1, date_2)
                VALUES (?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['person_id'],
                $data['person_2_id'] ?? null,
                $data['person_2_name'] ?? null,
                $data['relation_id'],
                $data['date_1'] ?? null,
                $data['date_2'] ?? null
            ]);

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Person relation created'],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in createPersonRelation: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to create person relation'], 500);
        }
    }

    /**
     * Protected: Update person relation (admin only)
     */
    public function updatePersonRelation(Request $request, Response $response, array $args): Response
    {
        try {
            $personId = $args['person_id'] ?? null;
            $relationId = $args['relation_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('
                SELECT * FROM relations_persons 
                WHERE person_id = ? AND relation_id = ?
            ');
            $stmt->execute([$personId, $relationId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Person relation not found'], 404);
            }

            $updates = [];
            $bindings = [];

            if (isset($data['person_2_id'])) {
                $updates[] = 'person_2_id = ?';
                $bindings[] = $data['person_2_id'];
            }
            if (isset($data['person_2_name'])) {
                $updates[] = 'person_2_name = ?';
                $bindings[] = $data['person_2_name'];
            }
            if (isset($data['date_1'])) {
                $updates[] = 'date_1 = ?';
                $bindings[] = $data['date_1'];
            }
            if (isset($data['date_2'])) {
                $updates[] = 'date_2 = ?';
                $bindings[] = $data['date_2'];
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $personId;
            $bindings[] = $relationId;
            $sql = 'UPDATE relations_persons SET ' . implode(', ', $updates) . ' WHERE person_id = ? AND relation_id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Person relation updated']);

        } catch (\Exception $e) {
            error_log('Error in updatePersonRelation: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update person relation'], 500);
        }
    }

    /**
     * Protected: Delete person relation (admin only)
     */
    public function deletePersonRelation(Request $request, Response $response, array $args): Response
    {
        try {
            $personId = $args['person_id'] ?? null;
            $relationId = $args['relation_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('
                DELETE FROM relations_persons 
                WHERE person_id = ? AND relation_id = ?
            ');
            $stmt->execute([$personId, $relationId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Person relation not found'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Person relation deleted']);

        } catch (\Exception $e) {
            error_log('Error in deletePersonRelation: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete person relation'], 500);
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