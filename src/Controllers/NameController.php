<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NameController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: Get stuff with the associated name
     */
    public function getName(Request $request, Response $response, array $args): Response
    {
        try {
            $name = $args['name'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if (!$name) {
                return $this->jsonResponse($response, ['error' => 'Invalid name'], 400);
            }

            // Work in progress
            $stmt = $this->db->prepare('
            SELECT movie_id, person_id, category, role_name
            FROM movies_persons
            WHERE person_name = ?
            GROUP BY movie_id
            ');
            $stmt->execute([$name]);
            $crew = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'crew' => $crew,
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in getName: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch name'], 500);
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
