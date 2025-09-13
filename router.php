<?php
// router.php - Router for PHP built-in development server
// This simulates .htaccess rewrite rules for local development

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove trailing slash except for root
if ($uri !== '/' && substr($uri, -1) === '/') {
    $uri = rtrim($uri, '/');
}

// Handle share URLs: /share/token -> /share.php?token=token
if (preg_match('#^/share/([a-f0-9]{32})$#', $uri, $matches)) {
    $_GET['token'] = $matches[1];
    $_SERVER['QUERY_STRING'] = 'token=' . $matches[1];
    include __DIR__ . '/share.php';
    return;
}

// Handle user photos: /user_photos/filename -> serve from user_photos directory
if (preg_match('#^/user_photos/(.+)$#', $uri, $matches)) {
    $photoPath = __DIR__ . '/user_photos/' . $matches[1];
    if (file_exists($photoPath)) {
        $mimeType = mime_content_type($photoPath);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($photoPath));
        readfile($photoPath);
        return;
    }
    http_response_code(404);
    echo "Photo not found";
    return;
}

// Handle static files
$extensions = ['js', 'css', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
$ext = pathinfo($uri, PATHINFO_EXTENSION);
if (in_array(strtolower($ext), $extensions)) {
    return false; // Let PHP built-in server handle static files
}

// Check if file exists (for direct PHP file access)
if (file_exists(__DIR__ . $uri)) {
    return false; // Let PHP built-in server handle existing files
}

// Try adding .php extension (simulate RewriteRule for removing .php extension)
$phpFile = __DIR__ . $uri . '.php';
if (file_exists($phpFile)) {
    include $phpFile;
    return;
}

// Default to index.php if nothing else matches
if ($uri === '/') {
    include __DIR__ . '/index.php';
    return;
}

// 404 for everything else
http_response_code(404);
echo "404 Not Found";