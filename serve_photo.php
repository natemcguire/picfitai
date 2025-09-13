<?php
// serve_photo.php - Secure photo serving
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/UserPhotoService.php';

Session::start();

if (!Session::isLoggedIn()) {
    http_response_code(403);
    exit('Forbidden');
}

$user = Session::getCurrentUser();
$filename = $_GET['file'] ?? '';

if (empty($filename)) {
    http_response_code(404);
    exit('Not Found');
}

// Sanitize filename
$filename = basename($filename);
$filePath = UserPhotoService::getPhotoPath($filename);

// Check if file exists
if (!file_exists($filePath)) {
    http_response_code(404);
    exit('Not Found');
}

// Check if user owns this photo
$pdo = Database::getInstance();
$stmt = $pdo->prepare('SELECT user_id FROM user_photos WHERE filename = ?');
$stmt->execute([$filename]);
$photoOwnerId = $stmt->fetchColumn();

if (!$photoOwnerId || $photoOwnerId != $user['id']) {
    http_response_code(403);
    exit('Forbidden');
}

// Get file info
$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($fileInfo, $filePath);
finfo_close($fileInfo);

// Set headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($filePath)) . ' GMT');

// Output file
readfile($filePath);