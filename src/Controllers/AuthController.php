<?php
namespace App\Controllers;

use App\Config\Database;
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    private $db;

    public function __construct()
    {
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (\Exception $e) {
            error_log('AuthController init error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Public: Register new user
     */
    public function register(Request $request, Response $response): Response
    {
        try {
            error_log('register() called');
            
            $data = $request->getParsedBody();
            error_log('Parsed body: ' . json_encode($data));

            // Validate input
            $errors = $this->validateRegister($data);
            if (!empty($errors)) {
                error_log('Validation errors: ' . json_encode($errors));
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Validation failed', 'details' => $errors],
                    422
                );
            }

            error_log('Validation passed, checking if user exists...');
            
            // Check if user exists
            $stmt = $this->db->prepare('
                SELECT id FROM users WHERE email = ? OR username = ?
            ');
            $stmt->execute([$data['email'], $data['username']]);
            if ($stmt->fetch()) {
                error_log('User already exists');
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Email or username already exists'],
                    409
                );
            }

            error_log('User does not exist, hashing password...');
            
            // Hash password
            $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
            error_log('Password hashed successfully');

            error_log('Inserting new user...');
            
            // Insert user
            $stmt = $this->db->prepare('
                INSERT INTO users (username, email, password_hash, role)
                VALUES (?, ?, ?, ?)
            ');

            $result = $stmt->execute([
                $data['username'],
                $data['email'],
                $passwordHash,
                'user'
            ]);
            
            error_log('Insert result: ' . ($result ? 'true' : 'false'));
            
            $userId = $this->db->lastInsertId();
            error_log('New user ID: ' . $userId);

            error_log('Generating JWT token...');
            
            // Generate token
            $token = $this->generateToken($userId, 'user');
            error_log('Token generated successfully');

            return $this->jsonResponse(
                $response,
                [
                    'success' => true,
                    'message' => 'User registered',
                    'token' => $token,
                    'user' => [
                        'id' => (int)$userId,
                        'username' => $data['username'],
                        'email' => $data['email']
                    ]
                ],
                201
            );
        } catch (\PDOException $e) {
            error_log('✗ PDO Error in register: ' . $e->getMessage());
            error_log('Code: ' . $e->getCode());
            error_log('Trace: ' . $e->getTraceAsString());
            return $this->jsonResponse(
                $response,
                [
                    'error' => 'Database error',
                    'message' => $e->getMessage()
                ],
                500
            );
        } catch (\Exception $e) {
            error_log('✗ Error in register: ' . $e->getMessage());
            error_log('File: ' . $e->getFile());
            error_log('Line: ' . $e->getLine());
            error_log('Trace: ' . $e->getTraceAsString());
            return $this->jsonResponse(
                $response,
                [
                    'error' => 'Registration failed',
                    'message' => $e->getMessage()
                ],
                500
            );
        }
    }

    /**
     * Public: Login user
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            error_log('login() called');
            
            $data = $request->getParsedBody();
            error_log('Parsed body: ' . json_encode($data));

            if (empty($data['email']) || empty($data['password'])) {
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Email and password are required'],
                    400
                );
            }

            error_log('Getting user by email: ' . $data['email']);
            
            // Get user
            $stmt = $this->db->prepare('
                SELECT id, username, email, password_hash, role
                FROM users WHERE email = ?
            ');
            $stmt->execute([$data['email']]);
            $user = $stmt->fetch();

            if (!$user) {
                error_log('User not found');
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Invalid credentials'],
                    401
                );
            }

            error_log('User found, verifying password...');
            
            if (!password_verify($data['password'], $user['password_hash'])) {
                error_log('Password verification failed');
                return $this->jsonResponse(
                    $response,
                    ['error' => 'Invalid credentials'],
                    401
                );
            }

            error_log('Password verified, generating token...');
            
            // Generate token
            $token = $this->generateToken($user['id'], $user['role']);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Login successful',
                'token' => $token,
                'user' => [
                    'id' => (int)$user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]);
        } catch (\Exception $e) {
            error_log('✗ Error in login: ' . $e->getMessage());
            error_log('Trace: ' . $e->getTraceAsString());
            return $this->jsonResponse(
                $response,
                ['error' => 'Login failed'],
                500
            );
        }
    }

    private function generateToken($userId, $role)
    {
        error_log('generateToken called with userId: ' . $userId . ', role: ' . $role);
        
        $issuedAt = time();
        $payload = [
            'iat' => $issuedAt,
            'exp' => $issuedAt + JWT_EXPIRATION,
            'sub' => $userId,
            'role' => $role
        ];

        try {
            $token = JWT::encode($payload, JWT_SECRET, JWT_ALGORITHM);
            error_log('Token encoded successfully');
            return $token;
        } catch (\Exception $e) {
            error_log('✗ Error encoding token: ' . $e->getMessage());
            throw $e;
        }
    }

    private function validateRegister($data)
    {
        error_log('validateRegister called with data: ' . json_encode($data));
        
        $errors = [];

        if (empty($data['username'])) {
            $errors['username'] = 'Username is required';
        } elseif (strlen($data['username']) < 3) {
            $errors['username'] = 'Username must be at least 3 characters';
        }

        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        error_log('Validation errors: ' . json_encode($errors));
        
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