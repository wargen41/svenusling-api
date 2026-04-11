<?php
// Enable error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use Slim\Factory\AppFactory;
use App\Config\Database;
use App\Routes;

// Log startup
error_log('=== APPLICATION STARTUP ===');
error_log('Environment: ' . ENVIRONMENT);
error_log('Database path: ' . DB_PATH);

try {
    error_log('Step 1: Getting database instance...');
    $database = Database::getInstance();
    error_log('Step 2: Database instance obtained');
    
    error_log('Step 3: Initializing tables...');
    $database->initializeTables();
    error_log('Step 4: Tables initialized');
    
    if (file_exists(DB_PATH)) {
        error_log('✓ Database file exists: ' . DB_PATH);
    } else {
        error_log('✗ Database file NOT found: ' . DB_PATH);
    }
    
} catch (\Exception $e) {
    error_log('✗ FATAL ERROR during database initialization');
    error_log('Error message: ' . $e->getMessage());
    error_log('Error file: ' . $e->getFile());
    error_log('Error line: ' . $e->getLine());
    error_log('Error trace: ' . $e->getTraceAsString());
    
    // Return error response
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'Database initialization failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => ENVIRONMENT === 'development' ? $e->getTraceAsString() : null
    ]);
    exit(1);
}

error_log('Step 5: Creating Slim app...');

// Create Slim app
$app = AppFactory::create();

error_log('Step 6: Adding middleware...');

// Add BodyParsingMiddleware to parse JSON request bodies
// This is CRITICAL for POST requests with JSON data
$app->addBodyParsingMiddleware();

error_log('Step 7: Adding error middleware...');

// Add error handling middleware with detailed errors in development
$errorMiddleware = $app->addErrorMiddleware(
    ENVIRONMENT === 'development', // displayErrorDetails
    true,  // logErrors
    true   // logErrorDetails
);

// Custom error handler to log exceptions
$errorMiddleware->setDefaultErrorHandler(function ($request, $exception, $displayErrorDetails) {
    error_log('✗ Request error: ' . $exception->getMessage());
    error_log('File: ' . $exception->getFile());
    error_log('Line: ' . $exception->getLine());
    
    $payload = [
        'error' => $exception->getMessage(),
    ];
    
    if ($displayErrorDetails) {
        $payload['file'] = $exception->getFile();
        $payload['line'] = $exception->getLine();
        $payload['trace'] = $exception->getTraceAsString();
    }
    
    $response = new \Slim\Psr7\Response();
    $response->getBody()->write(json_encode($payload));
    
    return $response
        ->withStatus($exception->getCode() ?: 500)
        ->withHeader('Content-Type', 'application/json');
});

error_log('Step 8: Adding CORS middleware...');

// Add CORS middleware
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});

error_log('Step 9: Adding preflight handler...');

// Handle preflight requests
$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

error_log('Step 10: Registering routes...');

// Register routes
try {
    Routes::register($app);
    error_log('✓ Routes registered successfully');
} catch (\Exception $e) {
    error_log('✗ Route registration failed: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    throw $e;
}

error_log('Step 11: Running application...');
error_log('=== APPLICATION READY ===');

// Run app
$app->run();