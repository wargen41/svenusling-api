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

use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

// Log startup
error_log('Environment: ' . ENVIRONMENT);
error_log('Database path: ' . DB_PATH);

try {
    $database = Database::getInstance();
    
    $database->initializeTables();
    
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

// Create Slim app
$app = AppFactory::create();

// Add BodyParsingMiddleware to parse JSON request bodies
// This is CRITICAL for POST requests with JSON data
$app->addBodyParsingMiddleware();

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

// Add CORS middleware
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});

// Add Twig middleware
$twig = Twig::create(__DIR__ . '/../views', ['cache' => false, 'debug' => true, 'strict_variables' => true, 'autoescape' => 'name']);
$app->add(TwigMiddleware::create($app, $twig));

// Handle preflight requests
// $app->options('/{routes:.+}', function ($request, $response) {
//     return $response;
// });
// Handle OPTIONS preflight requests for all routes
$app->options('/{routes:.*}', function ($request, $response) {
    error_log('OPTIONS request handled for: ' . $request->getUri()->getPath());
    return $response;
});

// Register routes
try {
    Routes::register($app);
    error_log('✓ Routes registered successfully');
} catch (\Exception $e) {
    error_log('✗ Route registration failed: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    throw $e;
}

// Run app
$app->run();
