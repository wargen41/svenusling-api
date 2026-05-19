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

            $stmt = $this->db->prepare('
            SELECT mp.person_id, mp.category, mp.role_name, mp.note, mp.movie_id, m.title AS movie_title, m.year AS movie_year
            FROM movies_persons mp
            JOIN movies m ON mp.movie_id = m.id
            WHERE person_name = ?
            ');
            $stmt->execute([$name]);
            $crew = $stmt->fetchAll();

            $stmt = $this->db->prepare('
            SELECT mt.*, m.title AS movie_title, m.year AS movie_year
            FROM movies_trivia mt
            JOIN movies m ON mt.movie_id = m.id
            WHERE (mt.sv LIKE ? OR mt.en LIKE ?)
            ');
            $stmt->execute([$name, $name]);
            $trivia = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'name' => $name,
                    'crew' => $crew,
                    'trivia' => $trivia,
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
