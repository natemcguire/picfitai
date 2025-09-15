<?php
// twitter_image.php - Dedicated image endpoint for Twitter with top-aligned cropping
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

// Create top-aligned crop for social media (1200x630 for Twitter)
$sourceImage = imagecreatefrompng($localImagePath);
if (!$sourceImage) {
    http_response_code(500);
    exit('Failed to process image');
}

$sourceWidth = imagesx($sourceImage);
$sourceHeight = imagesy($sourceImage);

// Twitter's preferred dimensions for large image cards
$targetWidth = 1200;
$targetHeight = 630;

// Calculate scaling to fit width while maintaining aspect ratio
$scale = $targetWidth / $sourceWidth;
$scaledHeight = (int)($sourceHeight * $scale);

// Create scaled image
$scaledImage = imagecreatetruecolor($targetWidth, $scaledHeight);
imagecopyresampled($scaledImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $scaledHeight, $sourceWidth, $sourceHeight);

// Create final cropped image (top-aligned)
$finalImage = imagecreatetruecolor($targetWidth, $targetHeight);

// Copy from top of scaled image
imagecopy($finalImage, $scaledImage, 0, 0, 0, 0, $targetWidth, min($targetHeight, $scaledHeight));

// If scaled image is shorter than target, fill bottom with white
if ($scaledHeight < $targetHeight) {
    $white = imagecolorallocate($finalImage, 255, 255, 255);
    imagefilledrectangle($finalImage, 0, $scaledHeight, $targetWidth, $targetHeight, $white);
}

// Set headers
header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('X-Content-Type-Options: nosniff');

// Handle OPTIONS request (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Output the processed image
imagepng($finalImage);

// Clean up memory
imagedestroy($sourceImage);
imagedestroy($scaledImage);
imagedestroy($finalImage);
?>