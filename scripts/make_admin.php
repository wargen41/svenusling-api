<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use App\Config\Database;

// Get username from command line argument
$username = $argv[1] ?? null;

if (!$username) {
    echo "Usage: php make_admin.php <username>\n";
    echo "Example: php make_admin.php testuser\n";
    exit(1);
}

try {
    error_log("Making user '$username' an admin...");
    
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare('UPDATE users SET role = ? WHERE username = ?');
    $result = $stmt->execute(['admin', $username]);
    
    if ($stmt->rowCount() > 0) {
        echo "✓ User '$username' is now admin\n";
        error_log("✓ User '$username' promoted to admin");
    } else {
        echo "✗ User '$username' not found\n";
        error_log("✗ User '$username' not found");
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    error_log("✗ Error: " . $e->getMessage());
    exit(1);
}