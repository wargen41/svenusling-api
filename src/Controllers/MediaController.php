<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MediaController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: List all media
     */
    public function listMedia(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $type = $params['media_type'] ?? null;
            $skip = (int)($params['skip'] ?? 0);
            $limit = min((int)($params['limit'] ?? 10), 100);

            $where = [];
            $bindings = [];

            if ($type) {
                $where[] = 'media_type = ?';
                $bindings[] = $type;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $sql = "
                SELECT * FROM media
                $whereClause
                ORDER BY file_name ASC
                LIMIT ? OFFSET ?
            ";

            $stmt = $this->db->prepare($sql);
            $bindings[] = $limit;
            $bindings[] = $skip;
            $stmt->execute($bindings);
            $media = $stmt->fetchAll();

            // Get total count
            $countSql = "SELECT COUNT(*) as count FROM media $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute(array_slice($bindings, 0, -2));
            $countResult = $countStmt->fetch();
            $total = $countResult['count'];

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $media,
                'pagination' => [
                    'count' => count($media),
                    'total' => $total,
                    'skip' => $skip,
                    'limit' => $limit
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in listMedia: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch media'], 500);
        }
    }

    /**
     * Public: Get single media with related data
     */
    public function getMedia(Request $request, Response $response, array $args): Response
    {
        try {
            $mediaId = $args['id'] ?? null;

            if (!$mediaId || !is_numeric($mediaId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid media ID'], 400);
            }

            $stmt = $this->db->prepare('SELECT * FROM media WHERE id = ?');
            $stmt->execute([$mediaId]);
            $media = $stmt->fetch();

            if (!$media) {
                return $this->jsonResponse($response, ['error' => 'Media not found'], 404);
            }

            // Get related movies
            $stmt = $this->db->prepare('
                SELECT m.id, m.title, m.year, m.type
                FROM movies m
                JOIN media_movies mm ON mm.movie_id = m.id
                WHERE mm.media_id = ?
            ');
            $stmt->execute([$mediaId]);
            $media['movies'] = $stmt->fetchAll();

            // Get related persons
            $stmt = $this->db->prepare('
                SELECT p.id, p.name, p.category
                FROM persons p
                JOIN media_persons mp ON mp.person_id = p.id
                WHERE mp.media_id = ?
            ');
            $stmt->execute([$mediaId]);
            $media['persons'] = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $media
            ]);

        } catch (\Exception $e) {
            error_log('Error in getMedia: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch media'], 500);
        }
    }

    /**
     * Protected: Create media (admin only)
     */
    public function createMedia(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['file_name']) || empty($data['media_type']) || empty($data['file_directory'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'file_name, media_type, and file_directory are required'],
                    422
                );
            }

            $stmt = $this->db->prepare('
                INSERT INTO media (file_name, media_type, file_directory, caption, attribution)
                VALUES (?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['file_name'],
                $data['media_type'],
                $data['file_directory'],
                $data['caption'] ?? null,
                $data['attribution'] ?? null
            ]);

            $mediaId = $this->db->lastInsertId();

            return $this->jsonResponse(
                $response,
                ['success' => true, 'message' => 'Media created', 'id' => (int)$mediaId],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in createMedia: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to create media'], 500);
        }
    }

    /**
     * Protected: Update media (admin only)
     */
    public function updateMedia(Request $request, Response $response, array $args): Response
    {
        try {
            $mediaId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            $stmt = $this->db->prepare('SELECT id FROM media WHERE id = ?');
            $stmt->execute([$mediaId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Media not found'], 404);
            }

            $updates = [];
            $bindings = [];
            $fields = ['file_name', 'media_type', 'file_directory', 'caption', 'attribution'];

            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $bindings[] = $data[$field];
                }
            }

            if (empty($updates)) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $bindings[] = $mediaId;
            $sql = 'UPDATE media SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Media updated']);

        } catch (\Exception $e) {
            error_log('Error in updateMedia: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update media'], 500);
        }
    }

    /**
     * Protected: Delete media (admin only)
     */
    public function deleteMedia(Request $request, Response $response, array $args): Response
    {
        try {
            $mediaId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('DELETE FROM media WHERE id = ?');
            $stmt->execute([$mediaId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Media not found'], 404);
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Media deleted']);

        } catch (\Exception $e) {
            error_log('Error in deleteMedia: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete media'], 500);
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