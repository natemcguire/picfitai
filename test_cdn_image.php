<?php
require_once __DIR__ . '/bootstrap.php';

$testImageCDN = 'https://cdn.picfit.ai/generated/fit_68c9f352e6d814.52253985.png';
$testImageDirect = 'https://picfit.ai/generated/fit_68c9f352e6d814.52253985.png';
?><!DOCTYPE html>
<html>
<head>
    <title>CDN Image Test</title>
</head>
<body>
    <h1>CDN Image Test</h1>

    <h2>CDN URL (with redirect):</h2>
    <p><?= $testImageCDN ?></p>
    <img src="<?= $testImageCDN ?>" style="max-width: 300px; border: 1px solid red;" alt="CDN Image"
         onerror="this.style.border='3px solid red'; this.alt='FAILED TO LOAD';"
         onload="this.style.border='3px solid green';">

    <h2>Direct URL:</h2>
    <p><?= $testImageDirect ?></p>
    <img src="<?= $testImageDirect ?>" style="max-width: 300px; border: 1px solid blue;" alt="Direct Image"
         onerror="this.style.border='3px solid red'; this.alt='FAILED TO LOAD';"
         onload="this.style.border='3px solid green';">

    <h2>Using CDNService:</h2>
    <?php $cdnServiceUrl = CDNService::getImageUrl('/generated/fit_68c9f352e6d814.52253985.png'); ?>
    <p><?= $cdnServiceUrl ?></p>
    <img src="<?= $cdnServiceUrl ?>" style="max-width: 300px; border: 1px solid purple;" alt="CDN Service Image"
         onerror="this.style.border='3px solid red'; this.alt='FAILED TO LOAD';"
         onload="this.style.border='3px solid green';">

    <script>
    setTimeout(() => {
        console.log('Image load test complete. Check borders: Green = success, Red = failed');
    }, 3000);
    </script>
</body>
</html>