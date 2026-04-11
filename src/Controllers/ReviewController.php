<?php
namespace App\Controllers;

use App\Config\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReviewController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Protected: Add review (authenticated users)
     */
    public function addReview(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $data = $request->getParsedBody();

            // Validate input
            $errors = $this->validateReview($data);
            if (!empty($errors)) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Validation failed', 'details' => $errors],
                    422
                );
            }

            // Check if movie exists
            $stmt = $this->db->prepare('SELECT id FROM movies WHERE id = ?');
            $stmt->execute([$data['movie_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Movie not found'],
                    404
                );
            }

            // Check if user already reviewed this movie
            $stmt = $this->db->prepare('
                SELECT id FROM reviews
                WHERE movie_id = ? AND user_id = ?
            ');
            $stmt->execute([$data['movie_id'], $userId]);
            if ($stmt->fetch()) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'You have already reviewed this movie'],
                    409
                );
            }

            // Insert review
            $stmt = $this->db->prepare('
                INSERT INTO reviews (movie_id, user_id, rating, comment)
                VALUES (?, ?, ?, ?)
            ');

            $stmt->execute([
                $data['movie_id'],
                $userId,
                $data['rating'],
                $data['comment'] ?? null
            ]);

            // Update movie average rating
            $this->updateMovieRating($data['movie_id']);

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => 'Review added',
                    'id' => (int)$this->db->lastInsertId()
                ],
                201
            );
        } catch (\Exception $e) {
            return $this->jsonResponse(
                $response,
                ['error' => 'Failed to add review'],
                500
            );
        }
    }

    /**
     * Protected: Update own review
     */
    public function updateReview(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $reviewId = $args['id'] ?? null;
            $data = $request->getParsedBody();

            // Check if review exists and belongs to user
            $stmt = $this->db->prepare('
                SELECT movie_id FROM reviews
                WHERE id = ? AND user_id = ?
            ');
            $stmt->execute([$reviewId, $userId]);
            $review = $stmt->fetch();

            if (!$review) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Review not found or you do not have permission'],
                    404
                );
            }

            // Update review
            $stmt = $this->db->prepare('
                UPDATE reviews
                SET rating = COALESCE(?, rating),
                    comment = COALESCE(?, comment),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');

            $stmt->execute([
                $data['rating'] ?? null,
                $data['comment'] ?? null,
                $reviewId
            ]);

            // Update movie rating
            $this->updateMovieRating($review['movie_id']);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Review updated'
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(
                $response,
                ['error' => 'Failed to update review'],
                500
            );
        }
    }

    /**
     * Protected: Delete own review
     */
    public function deleteReview(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $reviewId = $args['id'] ?? null;

            // Check if review exists and belongs to user
            $stmt = $this->db->prepare('
                SELECT movie_id FROM reviews
                WHERE id = ? AND user_id = ?
            ');
            $stmt->execute([$reviewId, $userId]);
            $review = $stmt->fetch();

            if (!$review) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Review not found or you do not have permission'],
                    404
                );
            }

            // Delete review
            $stmt = $this->db->prepare('DELETE FROM reviews WHERE id = ?');
            $stmt->execute([$reviewId]);

            // Update movie rating
            $this->updateMovieRating($review['movie_id']);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Review deleted'
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(
                $response,
                ['error' => 'Failed to delete review'],
                500
            );
        }
    }

    private function updateMovieRating($movieId)
    {
        $stmt = $this->db->prepare('
            SELECT AVG(rating) as avg_rating FROM reviews
            WHERE movie_id = ?
        ');
        $stmt->execute([$movieId]);
        $result = $stmt->fetch();

        $stmt = $this->db->prepare('
            UPDATE movies SET rating = ? WHERE id = ?
        ');
        $stmt->execute([$result['avg_rating'] ?? 0, $movieId]);
    }

    private function validateReview($data)
    {
        $errors = [];

        if (empty($data['movie_id'])) {
            $errors['movie_id'] = 'Movie ID is required';
        }

        if (empty($data['rating']) || !is_numeric($data['rating']) || $data['rating'] < 1 || $data['rating'] > 10) {
            $errors['rating'] = 'Rating must be between 1 and 10';
        }

        if (!empty($data['comment']) && strlen($data['comment']) > 1000) {
            $errors['comment'] = 'Comment must be less than 1000 characters';
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