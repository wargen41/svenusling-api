<?php
// Database configuration
$projectRoot = __DIR__;
define('DB_PATH', $projectRoot . '/movies.db');

// JWT configuration - with debugging
$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || $jwtSecret === '') {
    $jwtSecret = 'your-secret-key-change-in-production';
    error_log('⚠️  Using default JWT_SECRET (not from environment)');
}

define('JWT_SECRET', $jwtSecret);
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRATION', 3600); // 1 hour

// Log config on startup
error_log('Config loaded:');
error_log('  JWT_SECRET type: ' . gettype(JWT_SECRET));
error_log('  JWT_SECRET length: ' . strlen(JWT_SECRET));
error_log('  JWT_ALGORITHM: ' . JWT_ALGORITHM);
error_log('  JWT_EXPIRATION: ' . JWT_EXPIRATION);

// API configuration
define('API_VERSION', '1.0.0');
define('ENVIRONMENT', getenv('ENVIRONMENT') ?? 'development');

// CORS allowed origins
define('ALLOWED_ORIGINS', [
    'http://localhost:3000',
    'http://localhost:8000',
    'https://yourdomain.com'
]);

return [
    'db_path' => DB_PATH,
    'jwt_secret' => JWT_SECRET,
    'jwt_algorithm' => JWT_ALGORITHM,
    'jwt_expiration' => JWT_EXPIRATION,
];