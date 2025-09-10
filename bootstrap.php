<?php
// bootstrap.php - Application initialization
declare(strict_types=1);

// Load configuration first
require_once __DIR__ . '/config.php';

// Initialize error handling
require_once __DIR__ . '/includes/ErrorHandler.php';
ErrorHandler::init();

// Set security headers
require_once __DIR__ . '/includes/Security.php';
Security::setSecurityHeaders();

// Initialize database
require_once __DIR__ . '/includes/Database.php';

// Load core classes
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/Session.php';
require_once __DIR__ . '/includes/StripeService.php';
require_once __DIR__ . '/includes/AIService.php';

// Initialize logger
Logger::init();

// Check for missing configuration in development
if (php_sapi_name() === 'cli-server' || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost') {
    $missing = Config::isConfigured();
    if (!empty($missing)) {
        echo "⚠️  Missing configuration:\n";
        foreach ($missing as $item) {
            echo "   • $item\n";
        }
        echo "\nCopy env.example to .env and fill in your values.\n";
        echo "See README.md for setup instructions.\n\n";
    }
}

// Ensure generated images directory exists and is web-accessible
$generatedDir = __DIR__ . '/generated';
if (!is_dir($generatedDir)) {
    @mkdir($generatedDir, 0755, true);
}

// Create .htaccess for generated images
$htaccessFile = $generatedDir . '/.htaccess';
if (!file_exists($htaccessFile)) {
    file_put_contents($htaccessFile, "# Allow access to generated images\nOptions -Indexes\n");
}
