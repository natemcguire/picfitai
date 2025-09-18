<?php
// serve-ad.php - Secure private ad image serving
declare(strict_types=1);

require_once 'bootstrap.php';

// Check authentication
Session::requireLogin();
$user = Session::getCurrentUser();
$userId = $user['id'];

// Get requested image path
$imagePath = $_GET['path'] ?? '';
$campaignId = (int)($_GET['campaign'] ?? 0);

if (empty($imagePath) || !$campaignId) {
    http_response_code(400);
    exit('Invalid request');
}

// Validate that the user owns this campaign
$pdo = Database::getInstance();
$stmt = $pdo->prepare('
    SELECT c.id, c.user_id, ag.image_url, ag.with_text_url, ag.is_private
    FROM ad_campaigns c
    JOIN ad_generations ag ON c.id = ag.campaign_id
    WHERE c.id = ? AND (ag.image_url = ? OR ag.with_text_url = ?)
');
$stmt->execute([$campaignId, $imagePath, $imagePath]);
$adData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$adData) {
    http_response_code(404);
    exit('Ad not found');
}

// Check ownership for private ads
if ($adData['is_private'] && $adData['user_id'] !== $userId) {
    http_response_code(403);
    exit('Access denied');
}

// Construct full file path
$fullPath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;

// Validate file exists and is within allowed directory
$realPath = realpath($fullPath);
$allowedPath = realpath($_SERVER['DOCUMENT_ROOT'] . '/generated/ads/');

if (!$realPath || !$allowedPath || strpos($realPath, $allowedPath) !== 0) {
    http_response_code(404);
    exit('File not found');
}

if (!file_exists($realPath)) {
    http_response_code(404);
    exit('File not found');
}

// Get file info
$fileInfo = pathinfo($realPath);
$mimeType = match(strtolower($fileInfo['extension'] ?? '')) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    default => 'application/octet-stream'
};

// Set security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: private, max-age=3600');

// Set content headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realPath));

// Optional: Set content disposition for download
if (isset($_GET['download'])) {
    $filename = 'ad_' . $campaignId . '_' . $fileInfo['basename'];
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    header('Content-Disposition: inline');
}

// Output file
readfile($realPath);
exit;
?>