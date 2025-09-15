<?php
// whatsapp_image.php - Dedicated image endpoint for WhatsApp with optimal formatting
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$shareToken = $_GET['token'] ?? '';
$shareToken = basename($shareToken);

if (empty($shareToken)) {
    http_response_code(404);
    exit('Not found');
}

// Get the generation
$pdo = Database::getInstance();
$stmt = $pdo->prepare('
    SELECT result_url
    FROM generations
    WHERE share_token = ? AND status = "completed" AND is_public = 1
');
$stmt->execute([$shareToken]);
$generation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$generation) {
    http_response_code(404);
    exit('Not found');
}

// Get the local image path
$imageUrl = $generation['result_url'];
$localImagePath = __DIR__ . '/../' . ltrim($imageUrl, '/');

if (!file_exists($localImagePath)) {
    http_response_code(404);
    exit('Image not found');
}

// Create WhatsApp-optimized image (1200x1200 square, top-aligned)
$sourceImage = imagecreatefrompng($localImagePath);
if (!$sourceImage) {
    http_response_code(500);
    exit('Failed to process image');
}

$sourceWidth = imagesx($sourceImage);
$sourceHeight = imagesy($sourceImage);

// WhatsApp works best with square images
$targetSize = 1200;

// Calculate how to fit the image while showing the top portion
$scale = $targetSize / $sourceWidth;
$scaledHeight = (int)($sourceHeight * $scale);

// Create scaled image
$scaledImage = imagecreatetruecolor($targetSize, $scaledHeight);
imagecopyresampled($scaledImage, $sourceImage, 0, 0, 0, 0, $targetSize, $scaledHeight, $sourceWidth, $sourceHeight);

// Create final square image (top-aligned)
$finalImage = imagecreatetruecolor($targetSize, $targetSize);

// Fill with white background first
$white = imagecolorallocate($finalImage, 255, 255, 255);
imagefill($finalImage, 0, 0, $white);

// Copy from top of scaled image
$copyHeight = min($targetSize, $scaledHeight);
imagecopy($finalImage, $scaledImage, 0, 0, 0, 0, $targetSize, $copyHeight);

// Set WhatsApp-friendly headers
header('Content-Type: image/jpeg'); // WhatsApp prefers JPEG
header('Cache-Control: public, max-age=86400'); // Shorter cache for WhatsApp
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex'); // Don't index these processed images

// Handle OPTIONS request (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Output as JPEG for better WhatsApp compatibility
imagejpeg($finalImage, null, 90); // 90% quality

// Clean up memory
imagedestroy($sourceImage);
imagedestroy($scaledImage);
imagedestroy($finalImage);
?>