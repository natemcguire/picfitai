<?php
// warm_cdn_cache.php - Warm CloudFlare CDN cache by requesting all existing images
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "Warming CloudFlare CDN cache for existing images...\n";

// Get all generated images from database
$pdo = Database::getInstance();
$stmt = $pdo->prepare('
    SELECT result_url
    FROM generations
    WHERE status = "completed"
    AND result_url IS NOT NULL
    AND result_url != ""
    ORDER BY id DESC
');
$stmt->execute();
$generations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($generations);
$success = 0;
$errors = 0;

echo "Found {$total} images to cache...\n\n";

foreach ($generations as $i => $gen) {
    $imageUrl = $gen['result_url'];
    $cdnUrl = CDNService::getImageUrl($imageUrl);

    echo sprintf("[%d/%d] Caching: %s\n", $i + 1, $total, $cdnUrl);

    // Make HEAD request to cache the image
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $cdnUrl,
        CURLOPT_NOBODY => true, // HEAD request only
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'PicFit.ai CDN Cache Warmer',
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        $success++;
        echo "  ✅ Cached successfully\n";
    } else {
        $errors++;
        echo "  ❌ Failed (HTTP {$httpCode}): {$error}\n";
    }

    // Small delay to avoid overwhelming the server
    usleep(500000); // 0.5 seconds
}

echo "\n=== CDN Cache Warming Complete ===\n";
echo "Total images: {$total}\n";
echo "Successfully cached: {$success}\n";
echo "Errors: {$errors}\n";

if ($success > 0) {
    echo "\n🎉 CDN cache is now warmed! Images should load faster globally.\n";
}