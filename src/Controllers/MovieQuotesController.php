<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MovieQuotesController
{
    private $db;

    public function __construct()
    {
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (\Exception $e) {
            error_log('MovieQuotesController init error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Public: Get all quotes for a movie
     */
    public function getMovieQuotes(Request $request, Response $response, array $args): Response
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

            // Get all quotes for this movie
            $stmt = $this->db->prepare('
                SELECT id, quote, added_date
                FROM movies_quotes
                WHERE movie_id = ?
                ORDER BY added_date ASC
            ');
            $stmt->execute([$movieId]);
            $quotes = $stmt->fetchAll();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'movie' => $movie,
                    'quotes' => $quotes,
                    'count' => count($quotes)
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Error in getMovieQuotes: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch movie quotes'], 500);
        }
    }

    /**
     * Public: Get a single quote by ID
     */
    public function getQuote(Request $request, Response $response, array $args): Response
    {
        try {
            $quoteId = $args['quote_id'] ?? null;

            if (!$quoteId || !is_numeric($quoteId)) {
                return $this->jsonResponse($response, ['error' => 'Invalid quote ID'], 400);
            }

            $stmt = $this->db->prepare('SELECT * FROM movies_quotes WHERE id = ?');
            $stmt->execute([$quoteId]);
            $quote = $stmt->fetch();

            if (!$quote) {
                return $this->jsonResponse($response, ['error' => 'Quote not found'], 404);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $quote
            ]);

        } catch (\Exception $e) {
            error_log('Error in getQuote: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to fetch quote'], 500);
        }
    }

    /**
     * Protected: Add quote to movie (admin only)
     */
    public function addQuote(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            // Validate input
            $errors = $this->validateQuoteInput($data);
            if (!empty($errors)) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Validation failed', 'details' => $errors],
                    422
                );
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$data['movie_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            // Insert quote
            $stmt = $this->db->prepare('
                INSERT INTO movies_quotes (movie_id, quote, added_date)
                VALUES (?, ?, ?)
            ');

            $stmt->execute([
                $data['movie_id'],
                $data['quote'],
                $data['added_date'] ?? date('Y-m-d')
            ]);

            $quoteId = $this->db->lastInsertId();

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => 'Quote added',
                    'id' => (int)$quoteId
                ],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addQuote: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add quote'], 500);
        }
    }

    /**
     * Protected: Add multiple quotes to movie (admin only)
     */
    public function addMultipleQuotes(Request $request, Response $response): Response
    {
        try {
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['movie_id']) || empty($data['quotes']) || !is_array($data['quotes'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'movie_id and quotes (array) are required'],
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
                INSERT INTO movies_quotes (movie_id, quote, added_date)
                VALUES (?, ?, ?)
            ');

            foreach ($data['quotes'] as $quoteText) {
                try {
                    if (empty($quoteText)) {
                        $failed++;
                        $errors[] = 'Empty quote text';
                        continue;
                    }

                    $insertStmt->execute([
                        $data['movie_id'],
                        $quoteText,
                        date('Y-m-d')
                    ]);
                    $successful++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = $e->getMessage();
                }
            }

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => "Added $successful quotes",
                    'successful' => $successful,
                    'failed' => $failed,
                    'errors' => $errors
                ],
                201
            );

        } catch (\Exception $e) {
            error_log('Error in addMultipleQuotes: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to add quotes'], 500);
        }
    }

    /**
     * Protected: Update quote (admin only)
     */
    public function updateQuote(Request $request, Response $response, array $args): Response
    {
        try {
            $quoteId = $args['quote_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();

            // Verify quote exists
            $stmt = $this->db->prepare('SELECT id FROM movies_quotes WHERE id = ?');
            $stmt->execute([$quoteId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Quote not found'], 404);
            }

            // Update quote
            $stmt = $this->db->prepare('
                UPDATE movies_quotes
                SET quote = ?
                WHERE id = ?
            ');

            $stmt->execute([$data['quote'], $quoteId]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Quote updated'
            ]);

        } catch (\Exception $e) {
            error_log('Error in updateQuote: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to update quote'], 500);
        }
    }

    /**
     * Protected: Delete quote (admin only)
     */
    public function deleteQuote(Request $request, Response $response, array $args): Response
    {
        try {
            $quoteId = $args['quote_id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            $stmt = $this->db->prepare('DELETE FROM movies_quotes WHERE id = ?');
            $stmt->execute([$quoteId]);

            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse($response, ['error' => 'Quote not found'], 404);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Quote deleted'
            ]);

        } catch (\Exception $e) {
            error_log('Error in deleteQuote: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete quote'], 500);
        }
    }

    /**
     * Protected: Delete all quotes for a movie (admin only)
     */
    public function deleteAllMovieQuotes(Request $request, Response $response, array $args): Response
    {
        try {
            $movieId = $args['id'] ?? null;
            $userRole = $request->getAttribute('user_role');

            if ($userRole !== 'admin') {
                return $this->jsonResponse($response, ['error' => 'Admin access required'], 403);
            }

            // Verify movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$movieId]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, ['error' => 'Movie not found'], 404);
            }

            $stmt = $this->db->prepare('DELETE FROM movies_quotes WHERE movie_id = ?');
            $stmt->execute([$movieId]);

            $deletedCount = $stmt->rowCount();

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => "Deleted $deletedCount quotes",
                'count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            error_log('Error in deleteAllMovieQuotes: ' . $e->getMessage());
            return $this->jsonResponse($response, ['error' => 'Failed to delete quotes'], 500);
        }
    }

    private function validateQuoteInput($data)
    {
        $errors = [];

        if (empty($data['movie_id'])) {
            $errors['movie_id'] = 'Movie ID is required';
        }

        if (empty($data['quote'])) {
            $errors['quote'] = 'Quote is required';
        } elseif (strlen($data['quote']) < 2) {
            $errors['quote'] = 'Quote must be at least 2 characters';
        } elseif (strlen($data['quote']) > 1000) {
            $errors['quote'] = 'Quote must be less than 1000 characters';
        }

        return $errors;
    }

    private function jsonResponse(Response $response, $data, $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}