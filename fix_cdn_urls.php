<?php
// fix_cdn_urls.php - Convert CDN URLs back to local URLs
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "🔧 Fixing CDN URLs in database...\n\n";

try {
    $pdo = Database::getInstance();

    // Find all generations with CDN URLs
    $stmt = $pdo->prepare('
        SELECT id, result_url
        FROM generations
        WHERE result_url LIKE "https://cdn.picfit.ai/%"
    ');
    $stmt->execute();
    $cdnUrls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($cdnUrls) . " generations with CDN URLs\n\n";

    $fixed = 0;
    foreach ($cdnUrls as $row) {
        $cdnUrl = $row['result_url'];
        $localUrl = str_replace('https://cdn.picfit.ai/generated/', '/generated/', $cdnUrl);
        $localFile = __DIR__ . $localUrl;

        // Check if local file exists
        if (file_exists($localFile)) {
            // Update to local URL
            $updateStmt = $pdo->prepare('
                UPDATE generations
                SET result_url = ?
                WHERE id = ?
            ');
            $updateStmt->execute([$localUrl, $row['id']]);

            echo "✅ Fixed generation #{$row['id']}: {$localUrl}\n";
            $fixed++;
        } else {
            echo "⚠️  File not found for generation #{$row['id']}: {$localFile}\n";
        }
    }

    echo "\n🎉 Fixed {$fixed} CDN URLs!\n";
    echo "All share pages should now work correctly.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}