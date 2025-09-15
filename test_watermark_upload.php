<?php
require_once 'bootstrap.php';

$message = '';
$error = '';
$watermarkedImage = null;
$method = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_image'])) {
    try {
        if ($_FILES['test_image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload failed with error code: ' . $_FILES['test_image']['error']);
        }

        $uploadedFile = $_FILES['test_image'];
        $mimeType = $uploadedFile['type'];

        // Validate it's an image
        if (!in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'])) {
            throw new Exception('Invalid file type. Only JPEG, PNG, and WebP images are allowed.');
        }

        // Read the uploaded image
        $imageData = file_get_contents($uploadedFile['tmp_name']);
        if ($imageData === false) {
            throw new Exception('Failed to read uploaded file');
        }

        // Get original size
        $originalSize = strlen($imageData);

        // Apply watermark
        $aiService = new AIService();

        // Force method if specified
        $forceMethod = $_POST['force_method'] ?? 'auto';

        if ($forceMethod === 'imagick' && extension_loaded('imagick')) {
            // Force Imagick
            try {
                $watermarkedData = $aiService->addWatermarkImagick($imageData, $mimeType);
                $method = 'Imagick (forced)';
            } catch (Exception $e) {
                $error = 'Imagick failed: ' . $e->getMessage();
                $watermarkedData = $aiService->addWatermarkGD($imageData, $mimeType);
                $method = 'GD (fallback after Imagick failure)';
            }
        } elseif ($forceMethod === 'gd') {
            // Force GD
            $watermarkedData = $aiService->addWatermarkGD($imageData, $mimeType);
            $method = 'GD (forced)';
        } else {
            // Auto - use default logic
            $watermarkedData = $aiService->addWatermark($imageData, $mimeType);
            $method = extension_loaded('imagick') ? 'Imagick (auto)' : 'GD (auto)';
        }

        $watermarkedSize = strlen($watermarkedData);

        // Save watermarked image
        $filename = 'test_watermark_' . uniqid() . '.' . pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
        $filepath = 'generated/' . $filename;

        if (file_put_contents($filepath, $watermarkedData) === false) {
            throw new Exception('Failed to save watermarked image');
        }

        $watermarkedImage = '/' . $filepath;

        $message = sprintf(
            "✅ Watermark applied successfully!\n" .
            "Method: %s\n" .
            "Original size: %s\n" .
            "Watermarked size: %s\n" .
            "Size change: %s",
            $method,
            number_format($originalSize) . ' bytes',
            number_format($watermarkedSize) . ' bytes',
            ($watermarkedSize > $originalSize ? '+' : '') . number_format($watermarkedSize - $originalSize) . ' bytes'
        );

    } catch (Exception $e) {
        $error = '❌ Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watermark Test - PicFit.ai</title>
    <link rel="stylesheet" href="/public/styles.css">
    <style>
        .test-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .upload-form {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        .form-group input[type="file"] {
            display: block;
            width: 100%;
            padding: 10px;
            border: 2px dashed #ddd;
            border-radius: 6px;
            background: white;
            cursor: pointer;
        }
        .form-group input[type="file"]:hover {
            border-color: #007bff;
        }
        .radio-group {
            display: flex;
            gap: 20px;
            margin: 10px 0;
        }
        .radio-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        .radio-group input[type="radio"] {
            margin-right: 5px;
        }
        .submit-btn {
            background: #007bff;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .submit-btn:hover {
            background: #0056b3;
        }
        .result-box {
            margin: 20px 0;
            padding: 15px;
            border-radius: 6px;
            white-space: pre-wrap;
            font-family: monospace;
        }
        .success-box {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error-box {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .image-preview {
            margin: 20px 0;
            text-align: center;
        }
        .image-preview img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .info-box {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }
        .info-box h3 {
            margin-top: 0;
            color: #1565c0;
        }
        .info-box ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .debug-info {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'includes/nav.php'; ?>

    <div class="test-container">
        <h1>🎨 Watermark Test Tool</h1>

        <div class="info-box">
            <h3>ℹ️ Test Information</h3>
            <ul>
                <li><strong>Imagick:</strong> <?= extension_loaded('imagick') ? '✅ Available' : '❌ Not available' ?></li>
                <li><strong>GD:</strong> <?= function_exists('imagecreatetruecolor') ? '✅ Available' : '❌ Not available' ?></li>
                <li><strong>Font file:</strong> <?= file_exists(__DIR__ . '/public/fonts/Inter-Bold.ttf') ? '✅ Found' : '❌ Not found' ?></li>
                <li><strong>Font readable:</strong> <?= is_readable(__DIR__ . '/public/fonts/Inter-Bold.ttf') ? '✅ Yes' : '❌ No' ?></li>
                <li><strong>Font path:</strong> <?= realpath(__DIR__ . '/public/fonts/Inter-Bold.ttf') ?: 'Cannot resolve' ?></li>
            </ul>
        </div>

        <div class="upload-form">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="test_image">Upload Image to Watermark:</label>
                    <input type="file" id="test_image" name="test_image" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label>Watermark Method:</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="force_method" value="auto" checked>
                            Auto (Imagick → GD)
                        </label>
                        <label>
                            <input type="radio" name="force_method" value="imagick" <?= !extension_loaded('imagick') ? 'disabled' : '' ?>>
                            Force Imagick
                        </label>
                        <label>
                            <input type="radio" name="force_method" value="gd">
                            Force GD
                        </label>
                    </div>
                </div>

                <button type="submit" class="submit-btn">Apply Watermark</button>
            </form>
        </div>

        <?php if ($message): ?>
            <div class="result-box success-box"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="result-box error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($watermarkedImage): ?>
            <div class="image-preview">
                <h2>Watermarked Result:</h2>
                <img src="<?= htmlspecialchars($watermarkedImage) ?>" alt="Watermarked image">
                <div class="debug-info">
                    <strong>File:</strong> <?= htmlspecialchars($watermarkedImage) ?><br>
                    <strong>Method used:</strong> <?= htmlspecialchars($method) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="info-box" style="margin-top: 40px;">
            <h3>🔍 Debug Information</h3>
            <div class="debug-info">
                <?php
                // Check Imagick fonts
                if (extension_loaded('imagick')) {
                    echo "<strong>Imagick Version:</strong> " . phpversion('imagick') . "<br>";
                    $imagick = new Imagick();
                    $formats = $imagick->queryFormats();
                    echo "<strong>Imagick Formats:</strong> " . (in_array('JPEG', $formats) ? 'JPEG ✓' : 'JPEG ✗') . ', ';
                    echo (in_array('PNG', $formats) ? 'PNG ✓' : 'PNG ✗') . ', ';
                    echo (in_array('WEBP', $formats) ? 'WEBP ✓' : 'WEBP ✗') . "<br>";

                    // Try to get font list
                    try {
                        $fontList = $imagick->queryFonts();
                        echo "<strong>Available fonts:</strong> " . count($fontList) . " fonts<br>";
                        echo "<details><summary>Show first 10 fonts</summary><pre>";
                        print_r(array_slice($fontList, 0, 10));
                        echo "</pre></details>";
                    } catch (Exception $e) {
                        echo "<strong>Font query failed:</strong> " . $e->getMessage() . "<br>";
                    }
                }

                // Check GD info
                if (function_exists('gd_info')) {
                    $gdInfo = gd_info();
                    echo "<br><strong>GD Version:</strong> " . $gdInfo['GD Version'] . "<br>";
                    echo "<strong>FreeType:</strong> " . ($gdInfo['FreeType Support'] ? 'Yes' : 'No') . "<br>";
                    echo "<strong>JPEG:</strong> " . ($gdInfo['JPEG Support'] ? 'Yes' : 'No') . "<br>";
                    echo "<strong>PNG:</strong> " . ($gdInfo['PNG Support'] ? 'Yes' : 'No') . "<br>";
                    echo "<strong>WebP:</strong> " . ($gdInfo['WebP Support'] ?? false ? 'Yes' : 'No') . "<br>";
                }
                ?>
            </div>
        </div>
    </div>
</body>
</html>