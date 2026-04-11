<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SeriesController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Public: Get full series with all seasons
     */
    public function getSeries(Request $request, Response $response, array $args): Response
    {
        try {
            $seriesId = $args['id'] ?? null;

            if (!$seriesId || !is_numeric($seriesId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid series ID'], 400);
            }

            // Get series
            $stmt = $this->db->prepare('SELECT * FROM movies WHERE id = ? AND type IN ("series", "miniseries")');
            $stmt->execute([$seriesId]);
            $series = $stmt->fetch();

            if (!$series) {
                return $this->jsonResponse($response, ['error' => 'Series not found'], 404);
            }

            // Get all seasons for this series
            $stmt = $this->db->prepare('
                SELECT * FROM movies
                WHERE series_id = ? AND type = "season"
                ORDER BY sequence_number ASC
            ');
            $stmt->execute([$seriesId]);
            $seasons = $stmt->fetchAll();

            // For each season, get episodes
            foreach ($seasons as &$season) {
                $stmt = $this->db->prepare('
                    SELECT * FROM movies
                    WHERE season_id = ?
                    ORDER BY sequence_number ASC
                ');
                $stmt->execute([$season['id']]);
                $season['episodes'] = $stmt->fetchAll();
            }

            $series['seasons'] = $seasons;

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $series
            ]);

        } catch (\Exception $e) {
            error_log('Error in getSeries: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch series'], 500);
        }
    }

    /**
     * Public: Get season with episodes
     */
    public function getSeason(Request $request, Response $response, array $args): Response
    {
        try {
            $seasonId = $args['id'] ?? null;

            if (!$seasonId || !is_numeric($seasonId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid season ID'], 400);
            }

            // Get season
            $stmt = $this->db->prepare('SELECT * FROM movies WHERE id = ? AND type = "season"');
            $stmt->execute([$seasonId]);
            $season = $stmt->fetch();

            if (!$season) {
                return $this->jsonResponse($response, ['error' => 'Season not found'], 404);
            }

            // Get series info
            if ($season['series_id']) {
                $stmt = $this->db->prepare('SELECT id, title, type FROM movies WHERE id = ?');
                $stmt->execute([$season['series_id']]);
                $season['series'] = $stmt->fetch();
            }

            // Get episodes
            $stmt = $this->db->prepare('
                SELECT * FROM movies
                WHERE season_id = ?
                ORDER BY sequence_number ASC
            ');
            $stmt->execute([$seasonId]);
            $season['episodes'] = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $season
            ]);

        } catch (\Exception $e) {
            error_log('Error in getSeason: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch season'], 500);
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