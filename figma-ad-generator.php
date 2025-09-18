<?php
// figma-ad-generator.php - Clean AI ad generation interface
declare(strict_types=1);

require_once 'bootstrap.php';
require_once 'includes/AdGeneratorService.php';
require_once 'ad-generator/includes/FigmaExtractor.php';

// Start session but don't require login for viewing
Session::start();
$user = Session::getCurrentUser();
$userId = $user['id'] ?? null;
$isLoggedIn = Session::isLoggedIn();

// Generate CSRF token
$csrfToken = Session::generateCSRFToken();

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'extract_figma' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            $figmaUrl = $_POST['figma_url'] ?? '';
            if (empty($figmaUrl)) {
                throw new Exception('Please provide a Figma URL');
            }

            $figmaExtractor = new FigmaExtractor();
            $styleGuide = $figmaExtractor->extractFromUrl($figmaUrl);

            echo json_encode([
                'success' => true,
                'styleGuide' => $styleGuide
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'generate_ads' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!$isLoggedIn) {
                throw new Exception('Please log in to generate ads');
            }

            if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            // Create AdGeneratorService
            $adGenerator = new AdGeneratorService($userId);

            // Build style guide from form data
            $styleGuide = [
                'brand_name' => $_POST['brand_name'] ?? '',
                'product_description' => $_POST['product_description'] ?? '',
                'target_audience' => $_POST['target_audience'] ?? '',
                'campaign_goal' => $_POST['campaign_goal'] ?? '',
                'headline' => $_POST['headline'] ?? '',
                'cta_text' => $_POST['cta_text'] ?? 'SHOP NOW',
                'primary_color' => $_POST['primary_color'] ?? '#00ff00',
                'secondary_color' => $_POST['secondary_color'] ?? '#ffffff',
                'accent_color' => $_POST['accent_color'] ?? '#000000',
                'enhancement' => $_POST['enhancement'] ?? ''
            ];

            // Handle logo if provided
            if (!empty($_POST['logo'])) {
                // Logo is sent as base64 data URL (data:image/png;base64,...)
                $logoDataUrl = $_POST['logo'];
                if (preg_match('/^data:image\/([a-zA-Z0-9]+);base64,(.+)$/', $logoDataUrl, $matches)) {
                    $logoMimeType = 'image/' . $matches[1];
                    $logoBase64 = $matches[2];

                    $styleGuide['logo'] = [
                        'data' => $logoBase64,
                        'mime_type' => $logoMimeType
                    ];

                    Logger::info('Logo included in ad generation', [
                        'mime_type' => $logoMimeType,
                        'data_size_kb' => round(strlen($logoBase64) / 1024, 1)
                    ]);
                }
            }

            // Get requested size
            $size = $_POST['size'] ?? 'universal_square';
            $selectedSizes = [$size];

            // Generate ads
            $result = $adGenerator->generateAdSet($styleGuide, $selectedSizes);

            echo json_encode([
                'success' => true,
                'ads' => $result['ads'] ?? [],
                'campaign_id' => $result['campaign_id'] ?? null
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Handle save concept request
    if ($_GET['ajax'] === 'save_concept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isLoggedIn) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }

        try {
            $userId = $user['id'];
            $imageUrl = $_POST['image_url'] ?? '';
            $brandName = $_POST['brand_name'] ?? 'Untitled';
            $styleGuide = $_POST['style_guide'] ?? '{}';

            if (empty($imageUrl)) {
                throw new Exception('No image URL provided');
            }

            // Create concepts folder for user
            $conceptsFolder = "/generated/concepts/user_{$userId}";
            $fullConceptsPath = $_SERVER['DOCUMENT_ROOT'] . $conceptsFolder;

            if (!is_dir($fullConceptsPath)) {
                mkdir($fullConceptsPath, 0755, true);
                Logger::info('Created user concepts folder', [
                    'user_id' => $userId,
                    'folder_path' => $fullConceptsPath
                ]);
            }

            // Copy the image to concepts folder
            $sourceImagePath = $_SERVER['DOCUMENT_ROOT'] . $imageUrl;
            $conceptFileName = 'concept_' . uniqid() . '_' . date('Y-m-d_H-i-s') . '.png';
            $conceptImagePath = $fullConceptsPath . '/' . $conceptFileName;

            if (!file_exists($sourceImagePath)) {
                throw new Exception('Source image not found');
            }

            if (!copy($sourceImagePath, $conceptImagePath)) {
                throw new Exception('Failed to copy image to concepts folder');
            }

            // Save concept metadata to database
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('
                INSERT INTO saved_concepts (user_id, brand_name, style_guide, image_path, created_at)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ');
            $conceptRelativePath = $conceptsFolder . '/' . $conceptFileName;
            $stmt->execute([$userId, $brandName, $styleGuide, $conceptRelativePath]);

            Logger::info('Concept saved successfully', [
                'user_id' => $userId,
                'brand_name' => $brandName,
                'concept_path' => $conceptRelativePath
            ]);

            echo json_encode([
                'success' => true,
                'concept_id' => $pdo->lastInsertId(),
                'message' => 'Concept saved successfully'
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to save concept', [
                'user_id' => $userId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Handle get concepts request
    if ($_GET['ajax'] === 'get_concepts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isLoggedIn) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }

        try {
            $userId = $user['id'];
            $pdo = Database::getInstance();

            $stmt = $pdo->prepare('
                SELECT id, brand_name, style_guide, image_path, created_at
                FROM saved_concepts
                WHERE user_id = ?
                ORDER BY created_at DESC
            ');
            $stmt->execute([$userId]);
            $concepts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'concepts' => $concepts
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get concepts', [
                'user_id' => $userId ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Handle resize concept request
    if ($_GET['ajax'] === 'resize_concept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isLoggedIn) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }

        try {
            $userId = $user['id'];
            $sourceImage = $_POST['source_image'] ?? '';
            $targetSize = $_POST['size'] ?? 'universal_square';

            if (empty($sourceImage)) {
                throw new Exception('No source image provided');
            }

            // Load AdGeneratorService to get size dimensions
            require_once __DIR__ . '/includes/AdGeneratorService.php';
            $adGenerator = new AdGeneratorService($userId);
            $availableSizes = $adGenerator->getAvailableAdSizes();

            if (!isset($availableSizes[$targetSize])) {
                throw new Exception('Invalid target size: ' . $targetSize);
            }

            $targetDimensions = $availableSizes[$targetSize];
            $targetWidth = $targetDimensions['width'];
            $targetHeight = $targetDimensions['height'];

            // Get style guide data if available
            $styleGuide = [];
            if (!empty($_POST['brand_name'])) {
                $styleGuide = [
                    'brand_name' => $_POST['brand_name'],
                    'product_description' => $_POST['product_description'] ?? '',
                    'target_audience' => $_POST['target_audience'] ?? '',
                    'campaign_goal' => $_POST['campaign_goal'] ?? '',
                    'headline' => $_POST['headline'] ?? '',
                    'cta_text' => $_POST['cta_text'] ?? '',
                    'primary_color' => $_POST['primary_color'] ?? '#00ff00',
                    'secondary_color' => $_POST['secondary_color'] ?? '#ffffff',
                    'accent_color' => $_POST['accent_color'] ?? '#000000',
                    'enhancement' => $_POST['enhancement'] ?? ''
                ];
            }

            // First: Ask Gemini to intelligently reformat the concept
            $reformattedImageUrl = reformatConceptWithGemini(
                $sourceImage,
                $targetWidth,
                $targetHeight,
                $userId,
                $targetSize,
                $styleGuide
            );

            // Second: Ensure exact dimensions with smart cropping
            $finalImageUrl = ensureExactDimensions(
                $reformattedImageUrl,
                $targetWidth,
                $targetHeight,
                $userId,
                $targetSize
            );

            Logger::info('Concept resized successfully', [
                'user_id' => $userId,
                'source_image' => $sourceImage,
                'target_size' => $targetSize,
                'target_dimensions' => "{$targetWidth}x{$targetHeight}",
                'result_url' => $finalImageUrl
            ]);

            echo json_encode([
                'success' => true,
                'ads' => [[
                    'id' => uniqid(),
                    'type' => $targetSize,
                    'name' => $targetDimensions['name'],
                    'dimensions' => "{$targetWidth}x{$targetHeight}",
                    'image_url' => $finalImageUrl
                ]]
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to resize concept', [
                'user_id' => $userId ?? 'unknown',
                'error' => $e->getMessage(),
                'source_image' => $_POST['source_image'] ?? 'none',
                'target_size' => $_POST['size'] ?? 'none'
            ]);

            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }
}

// Check if user is logged in helper
function isUserLoggedIn() {
    global $isLoggedIn;
    return $isLoggedIn;
}

/**
 * First pass: Ask Gemini to intelligently reformat the concept for new dimensions
 */
function reformatConceptWithGemini(string $sourceImageUrl, int $targetWidth, int $targetHeight, int $userId, string $targetSize, array $styleGuide = []): string {
    require_once __DIR__ . '/includes/AdGeneratorService.php';

    // Build a reformatting prompt
    $aspectRatio = $targetWidth > $targetHeight ? 'landscape' : ($targetWidth < $targetHeight ? 'portrait' : 'square');

    $prompt = "You are reformatting an existing advertising concept for a different aspect ratio and size. ";
    $prompt .= "ORIGINAL CONCEPT: The uploaded image shows an existing ad design. ";
    $prompt .= "TARGET FORMAT: {$aspectRatio} orientation ({$targetWidth}x{$targetHeight}px).\n\n";

    $prompt .= "TASK: Intelligently reformat this ad concept to work optimally in the new dimensions. ";
    $prompt .= "This is NOT creating a new concept - you are adapting the EXISTING design elements.\n\n";

    $prompt .= "REFORMATTING GUIDELINES:\n";
    $prompt .= "- Keep the same brand identity, colors, and overall visual style\n";
    $prompt .= "- Preserve all existing text elements (headlines, CTAs, body text)\n";
    $prompt .= "- Reposition and resize elements to work better in the new aspect ratio\n";
    $prompt .= "- Adjust composition for the new format (e.g., vertical layouts for stories, horizontal for banners)\n";
    $prompt .= "- Maintain visual hierarchy and brand consistency\n";
    $prompt .= "- Optimize for the new platform format while keeping the core concept intact\n";
    $prompt .= "- You may reference and adapt elements from the existing design as needed\n\n";

    // Add style guide context if available
    if (!empty($styleGuide['brand_name'])) {
        $prompt .= "BRAND: {$styleGuide['brand_name']}\n";
    }

    if (!empty($styleGuide['headline'])) {
        $prompt .= "HEADLINE: \"{$styleGuide['headline']}\" - Ensure this text is prominently displayed\n";
    }

    if (!empty($styleGuide['cta_text'])) {
        $prompt .= "CTA: \"{$styleGuide['cta_text']}\" - Keep this as a visible action button\n";
    }

    // Platform-specific guidance
    $platformGuidance = match($targetSize) {
        'instagram_story', 'facebook_story', 'snapchat_ad', 'universal_portrait' =>
            'VERTICAL FORMAT: Adapt for vertical storytelling. Stack elements vertically, use full height effectively.',
        'google_banner', 'google_rectangle', 'twitter_header' =>
            'BANNER FORMAT: Optimize for horizontal banner layout. Arrange elements side-by-side or in compact rows.',
        'youtube_thumbnail' =>
            'THUMBNAIL FORMAT: Make text large and readable at small sizes. Bold, clear composition.',
        default =>
            'BALANCED FORMAT: Maintain good visual balance and readability.'
    };

    $prompt .= "\n{$platformGuidance}\n\n";
    $prompt .= "OUTPUT: A reformatted version of the original concept that works perfectly in the new dimensions while preserving the core design and messaging.";

    try {
        $adGenerator = new AdGeneratorService($userId);

        // Convert source image to base64 for Gemini
        $sourceImagePath = $_SERVER['DOCUMENT_ROOT'] . $sourceImageUrl;
        $imageData = file_get_contents($sourceImagePath);
        $base64Image = base64_encode($imageData);
        $mimeType = mime_content_type($sourceImagePath);

        // Call Gemini with the image and reformatting prompt
        $reformattedImages = $adGenerator->callGeminiWithImage($prompt, $base64Image, $mimeType, $targetWidth, $targetHeight);

        Logger::info('Concept reformatted with Gemini', [
            'source_image' => $sourceImageUrl,
            'target_dimensions' => "{$targetWidth}x{$targetHeight}",
            'target_size' => $targetSize,
            'prompt_length' => strlen($prompt),
            'num_variations' => count($reformattedImages)
        ]);

        // For now, return the first one (we could later build a gallery selector)
        return $reformattedImages[0] ?? null;

    } catch (Exception $e) {
        Logger::warning('Gemini reformatting failed, falling back to smart crop', [
            'error' => $e->getMessage(),
            'source_image' => $sourceImageUrl
        ]);

        // Fallback to smart cropping if Gemini fails
        return smartCropImage($sourceImageUrl, $targetWidth, $targetHeight, $userId, $targetSize);
    }
}

/**
 * Second pass: Ensure the image is exactly the right dimensions
 */
function ensureExactDimensions(string $sourceImageUrl, int $targetWidth, int $targetHeight, int $userId, string $targetSize): string {
    $sourceImagePath = $_SERVER['DOCUMENT_ROOT'] . $sourceImageUrl;

    if (!file_exists($sourceImagePath)) {
        throw new Exception('Reformatted image not found: ' . $sourceImagePath);
    }

    $imageInfo = getimagesize($sourceImagePath);
    if (!$imageInfo) {
        throw new Exception('Invalid reformatted image');
    }

    $currentWidth = $imageInfo[0];
    $currentHeight = $imageInfo[1];

    // If already exact dimensions, return as-is
    if ($currentWidth === $targetWidth && $currentHeight === $targetHeight) {
        Logger::info('Image already exact dimensions', [
            'image' => $sourceImageUrl,
            'dimensions' => "{$currentWidth}x{$currentHeight}"
        ]);
        return $sourceImageUrl;
    }

    // Load source image
    switch ($imageInfo['mime']) {
        case 'image/jpeg':
            $sourceImage = imagecreatefromjpeg($sourceImagePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($sourceImagePath);
            break;
        case 'image/webp':
            $sourceImage = imagecreatefromwebp($sourceImagePath);
            break;
        default:
            throw new Exception('Unsupported image format: ' . $imageInfo['mime']);
    }

    // Create target image with exact dimensions
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

    // Preserve transparency for PNG
    if ($imageInfo['mime'] === 'image/png') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
        imagefill($targetImage, 0, 0, $transparent);
    }

    // Smart resize/crop to exact dimensions
    $sourceRatio = $currentWidth / $currentHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($sourceRatio > $targetRatio) {
        // Source is wider - crop sides
        $cropHeight = $currentHeight;
        $cropWidth = $currentHeight * $targetRatio;
        $cropX = ($currentWidth - $cropWidth) / 2;
        $cropY = 0;
    } else {
        // Source is taller - crop top/bottom, bias toward top third
        $cropWidth = $currentWidth;
        $cropHeight = $currentWidth / $targetRatio;
        $cropX = 0;
        $cropY = max(0, ($currentHeight - $cropHeight) / 3);
    }

    // Copy and resize to exact dimensions
    imagecopyresampled(
        $targetImage, $sourceImage,
        0, 0, (int)$cropX, (int)$cropY,
        $targetWidth, $targetHeight,
        (int)$cropWidth, (int)$cropHeight
    );

    // Save final image
    $userFolder = "/generated/ads/user_{$userId}";
    $fullUserPath = $_SERVER['DOCUMENT_ROOT'] . $userFolder;

    if (!is_dir($fullUserPath)) {
        mkdir($fullUserPath, 0755, true);
    }

    $filename = 'final_' . $targetSize . '_' . uniqid() . '.jpg';
    $relativePath = $userFolder . '/' . $filename;
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . $relativePath;

    if (!imagejpeg($targetImage, $fullPath, 92)) {
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
        throw new Exception('Failed to save final image');
    }

    // Cleanup
    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    Logger::info('Final dimensions ensured', [
        'source_dimensions' => "{$currentWidth}x{$currentHeight}",
        'target_dimensions' => "{$targetWidth}x{$targetHeight}",
        'crop_area' => "{$cropX},{$cropY} {$cropWidth}x{$cropHeight}",
        'output_path' => $relativePath
    ]);

    return $relativePath;
}

/**
 * Fallback smart cropping function (used if Gemini fails)
 */
function smartCropImage(string $sourceImageUrl, int $targetWidth, int $targetHeight, int $userId, string $targetSize): string {
    $sourceImagePath = $_SERVER['DOCUMENT_ROOT'] . $sourceImageUrl;

    if (!file_exists($sourceImagePath)) {
        throw new Exception('Source image not found: ' . $sourceImagePath);
    }

    $imageInfo = getimagesize($sourceImagePath);
    if (!$imageInfo) {
        throw new Exception('Invalid source image');
    }

    // Load and process image (same logic as before)
    switch ($imageInfo['mime']) {
        case 'image/jpeg':
            $sourceImage = imagecreatefromjpeg($sourceImagePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($sourceImagePath);
            break;
        case 'image/webp':
            $sourceImage = imagecreatefromwebp($sourceImagePath);
            break;
        default:
            throw new Exception('Unsupported image format: ' . $imageInfo['mime']);
    }

    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);

    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

    if ($imageInfo['mime'] === 'image/png') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
        imagefill($targetImage, 0, 0, $transparent);
    }

    // Smart crop
    $sourceRatio = $sourceWidth / $sourceHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($sourceRatio > $targetRatio) {
        $cropHeight = $sourceHeight;
        $cropWidth = $sourceHeight * $targetRatio;
        $cropX = ($sourceWidth - $cropWidth) / 2;
        $cropY = 0;
    } else {
        $cropWidth = $sourceWidth;
        $cropHeight = $sourceWidth / $targetRatio;
        $cropX = 0;
        $cropY = max(0, ($sourceHeight - $cropHeight) / 3);
    }

    imagecopyresampled(
        $targetImage, $sourceImage,
        0, 0, (int)$cropX, (int)$cropY,
        $targetWidth, $targetHeight,
        (int)$cropWidth, (int)$cropHeight
    );

    $userFolder = "/generated/ads/user_{$userId}";
    $fullUserPath = $_SERVER['DOCUMENT_ROOT'] . $userFolder;

    if (!is_dir($fullUserPath)) {
        mkdir($fullUserPath, 0755, true);
    }

    $filename = 'cropped_' . $targetSize . '_' . uniqid() . '.jpg';
    $relativePath = $userFolder . '/' . $filename;
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . $relativePath;

    if (!imagejpeg($targetImage, $fullPath, 92)) {
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
        throw new Exception('Failed to save cropped image');
    }

    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    Logger::info('Smart crop fallback used', [
        'source_dimensions' => "{$sourceWidth}x{$sourceHeight}",
        'target_dimensions' => "{$targetWidth}x{$targetHeight}",
        'output_path' => $relativePath
    ]);

    return $relativePath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✨ Ad Generator 3000 ✨</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=VT323&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'VT323', 'Courier New', monospace;
            background: #000;
            color: #00ff00;
            min-height: 100vh;
            line-height: 1.4;
        }

        .navbar {
            background: #001100;
            border-bottom: 2px solid #00ff00;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-brand {
            font-size: 20px;
            font-weight: bold;
            color: #00ff00;
        }

        .navbar-nav {
            display: flex;
            gap: 20px;
            align-items: center;
            font-size: 14px;
        }

        .navbar-link {
            color: #00ff00;
            text-decoration: none;
            padding: 5px 10px;
            border: 1px solid transparent;
            transition: all 0.3s;
        }

        .navbar-link:hover {
            border-color: #00ff00;
            background: rgba(0, 255, 0, 0.1);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .title {
            font-size: 48px;
            margin-bottom: 10px;
            text-shadow: 0 0 10px #00ff00;
        }

        .subtitle {
            font-size: 20px;
            color: #00cc00;
            margin-bottom: 20px;
        }

        .ascii-art {
            font-size: 14px;
            color: #004400;
            margin: 20px 0;
        }

        /* Progress Steps */
        .steps {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 15px;
            border: 2px solid #003300;
            background: #001100;
            min-width: 120px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .step.active {
            border-color: #00ff00;
            background: #002200;
            box-shadow: 0 0 15px rgba(0, 255, 0, 0.3);
        }

        .step.completed {
            border-color: #00cc00;
            background: #001100;
        }

        .step-number {
            font-size: 24px;
            font-weight: bold;
        }

        .step-label {
            font-size: 12px;
            text-align: center;
        }

        /* Window Containers */
        .window {
            background: #001100;
            border: 2px solid #00ff00;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 255, 0, 0.2);
        }

        .window.hidden {
            display: none;
        }

        .window-header {
            background: #00ff00;
            color: #000;
            padding: 12px 20px;
            font-size: 16px;
            font-weight: bold;
        }

        .window-body {
            padding: 30px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 25px;
        }

        .label {
            display: block;
            color: #00ff00;
            font-size: 16px;
            margin-bottom: 8px;
            font-weight: bold;
        }

        .input, .textarea {
            width: 100%;
            background: #000;
            border: 2px solid #00ff00;
            color: #00ff00;
            padding: 12px;
            font-family: inherit;
            font-size: 16px;
            resize: vertical;
        }

        .input:focus, .textarea:focus {
            outline: none;
            border-color: #00cc00;
            box-shadow: 0 0 5px rgba(0, 255, 0, 0.5);
        }

        .textarea {
            min-height: 100px;
        }

        /* Buttons */
        .btn {
            background: #00ff00;
            color: #000;
            border: none;
            padding: 15px 30px;
            font-family: inherit;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
        }

        .btn:hover {
            background: #00cc00;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 255, 0, 0.4);
        }

        .btn:disabled {
            background: #003300;
            color: #006600;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: #003300;
            color: #00ff00;
            border: 2px solid #00ff00;
        }

        .btn-secondary:hover {
            background: rgba(0, 255, 0, 0.1);
        }

        /* Concept Generation Step - 2x Larger */
        #concept-step .window-body {
            min-height: 600px;
            padding: 40px;
        }

        .concept-container {
            display: flex;
            gap: 30px;
            height: 500px;
        }

        .concept-left {
            flex: 0 0 300px;
        }

        .concept-center {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #003300;
            background: #000;
            position: relative;
        }

        .concept-placeholder {
            text-align: center;
            color: #666;
        }

        .concept-image {
            max-width: 100%;
            max-height: 100%;
            border-radius: 10px;
        }

        /* Concepts Gallery */
        .concepts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .concept-card {
            background: #1a1a1a;
            border: 2px solid #333;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .concept-card:hover {
            border-color: #00ff00;
            transform: translateY(-2px);
        }

        .concept-card.selected {
            border-color: #00ff00;
            background: #002200;
        }

        .concept-card img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 10px;
            background: #111;
            transition: opacity 0.3s ease;
        }

        .concept-card img[data-loading="true"] {
            opacity: 0.5;
        }

        .concept-card-title {
            font-size: 14px;
            color: #00ff00;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .concept-card-date {
            font-size: 12px;
            color: #666;
        }

        .current-concept-card {
            border-color: #ff6b35;
            background: #2a1a0a;
        }

        .current-concept-card:hover {
            border-color: #ff6b35;
        }

        .current-concept-card.selected {
            background: #332200;
        }

        /* Logo Upload */
        .logo-upload-area {
            border: 2px dashed #333;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .logo-upload-area:hover {
            border-color: #00ff00;
            background: rgba(0, 255, 0, 0.05);
        }

        .logo-upload-prompt {
            cursor: pointer;
            color: #666;
        }

        .logo-preview {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            background: #1a1a1a;
            border-radius: 5px;
        }

        .logo-preview img {
            max-width: 80px;
            max-height: 80px;
            object-fit: contain;
            border-radius: 4px;
            border: 1px solid #333;
        }

        .logo-actions {
            display: flex;
            gap: 10px;
        }

        /* Universal Loading Bar */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.3);
            z-index: 10000;
            display: none;
            justify-content: center;
            align-items: center;
        }

        .loading-modal {
            background: #000;
            border: 2px solid #00ff00;
            border-radius: 8px;
            padding: 25px 30px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            position: relative;
        }

        .loading-title {
            color: #00ff00;
            font-size: 16px;
            margin-bottom: 15px;
            font-weight: bold;
        }

        .loading-bar-container {
            background: #111;
            border: 1px solid #333;
            border-radius: 10px;
            height: 8px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .loading-bar {
            background: linear-gradient(90deg, #00ff00, #00cc00);
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 10px;
        }

        .loading-message {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .loading-dismiss {
            color: #888;
            font-size: 12px;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .loading-dismiss:hover {
            color: #00ff00;
        }

        /* Export Grid */
        .export-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .export-card {
            border: 2px solid #003300;
            background: #001100;
            padding: 20px;
            text-align: center;
        }

        .export-card.generating {
            border-color: #00ff00;
            background: #002200;
        }

        .size-label {
            font-size: 18px;
            margin-bottom: 10px;
            color: #00ff00;
        }

        .size-dimensions {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }

        /* Utility Classes */
        .text-center {
            text-align: center;
        }

        .mb-20 {
            margin-bottom: 20px;
        }

        .mt-20 {
            margin-top: 20px;
        }

        .hidden {
            display: none !important;
        }

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Color Selection Grid */
        .color-square {
            width: 50px;
            height: 50px;
            border: 3px solid #003300;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 8px;
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .color-square:hover {
            transform: scale(1.1);
            border-color: #00ff00;
            box-shadow: 0 4px 15px rgba(0, 255, 0, 0.3);
        }

        .color-square.selected-primary {
            border-color: #00ff00;
            border-width: 4px;
            transform: scale(1.05);
        }

        .color-square.selected-primary::after {
            content: "1";
            position: absolute;
            top: -8px;
            right: -8px;
            background: #00ff00;
            color: #000;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }

        .color-square.selected-secondary {
            border-color: #00ccff;
            border-width: 4px;
            transform: scale(1.05);
        }

        .color-square.selected-secondary::after {
            content: "2";
            position: absolute;
            top: -8px;
            right: -8px;
            background: #00ccff;
            color: #000;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }

        .color-square.selected-accent {
            border-color: #ffcc00;
            border-width: 4px;
            transform: scale(1.05);
        }

        .color-square.selected-accent::after {
            content: "3";
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ffcc00;
            color: #000;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }

        /* Reference Image Preview */
        .reference-preview-item {
            position: relative;
            width: 120px;
            height: 120px;
            border: 2px solid #003300;
            border-radius: 8px;
            overflow: hidden;
            background: #001100;
        }

        .reference-preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .reference-remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #ff0033;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reference-remove-btn:hover {
            background: #ff3355;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .title {
                font-size: 32px;
            }

            .steps {
                gap: 10px;
            }

            .step {
                min-width: 80px;
                padding: 10px;
            }

            .concept-container {
                flex-direction: column;
                height: auto;
            }

            .concept-left {
                flex: none;
            }

            .concept-center {
                min-height: 300px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="navbar-brand">🎨 PicFit.ai</div>
        <div class="navbar-nav">
            <?php if ($isLoggedIn): ?>
                <span class="navbar-user"><?php echo htmlspecialchars($user['email']); ?></span>
                <a href="/dashboard" class="navbar-link">Dashboard</a>
                <a href="/ad-dashboard" class="navbar-link">Ads</a>
                <a href="/dashboard" class="navbar-link">Pics</a>
                <?php if ($user['email'] === 'nate.mcguire@gmail.com'): ?>
                    <a href="/file-browser.php" class="navbar-link" style="color: #ff6b35;">🗂️ Files</a>
                <?php endif; ?>
            <?php else: ?>
                <span class="navbar-user">Guest</span>
                <a href="/auth/login.php" class="navbar-link">Login</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1 class="title">✨ AD GENERATOR 3000 ✨</h1>
            <p class="subtitle">&gt;&gt; TURN IDEAS INTO ADS &lt;&lt;</p>
            <div class="ascii-art">
                ╔══════════════════════════════════════╗<br>
                ║  AI-POWERED MARKETING MACHINE v3.0   ║<br>
                ╚══════════════════════════════════════╝
            </div>
        </div>

        <!-- Progress Steps -->
        <div class="steps">
            <div class="step active" id="step-1" onclick="goToStep(1)">
                <div class="step-number">01</div>
                <div class="step-label">BRAND INFO</div>
            </div>
            <div class="step" id="step-2" onclick="goToStep(2)">
                <div class="step-number">02</div>
                <div class="step-label">FIGMA</div>
            </div>
            <div class="step" id="step-3" onclick="goToStep(3)">
                <div class="step-number">03</div>
                <div class="step-label">STYLE</div>
            </div>
            <div class="step" id="step-4" onclick="goToStep(4)">
                <div class="step-number">04</div>
                <div class="step-label">GENERATE</div>
            </div>
            <div class="step" id="step-5" onclick="goToStep(5)">
                <div class="step-number">05</div>
                <div class="step-label">EXPORT</div>
            </div>
        </div>

        <!-- Step 1: Brand Information -->
        <div class="window" id="brand-step">
            <div class="window-header">▶ BRAND INFORMATION</div>
            <div class="window-body">
                <div class="form-group">
                    <label class="label">Brand Name:</label>
                    <input type="text" id="brand-name" class="input" placeholder="Your Company Name" required>
                </div>

                <div class="form-group">
                    <label class="label">Brand Logo (Optional):</label>
                    <div class="logo-upload-area" id="logo-upload-area">
                        <input type="file" id="logo-upload" accept="image/*" style="display: none;" onchange="handleLogoUpload(event)">
                        <div class="logo-upload-prompt" onclick="document.getElementById('logo-upload').click()">
                            <div style="font-size: 48px; margin-bottom: 10px;">📁</div>
                            <div>Click to upload your logo</div>
                            <div style="font-size: 12px; color: #666; margin-top: 5px;">PNG, JPG, SVG (Max 5MB)</div>
                        </div>
                        <div id="logo-preview" class="logo-preview hidden">
                            <img id="logo-preview-img" src="" alt="Logo Preview">
                            <div class="logo-actions">
                                <button type="button" class="btn btn-secondary" onclick="removeLogo()" style="font-size: 12px; padding: 5px 10px;">Remove</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Product/Service Description:</label>
                    <textarea id="product-description" class="textarea" placeholder="What are you promoting? Be specific about your product or service..."></textarea>
                </div>

                <div class="form-group">
                    <label class="label">Target Audience:</label>
                    <input type="text" id="target-audience" class="input" placeholder="Young professionals, fitness enthusiasts, etc.">
                </div>

                <div class="form-group">
                    <label class="label">Campaign Goal:</label>
                    <input type="text" id="campaign-goal" class="input" placeholder="Drive sales, increase awareness, generate leads, etc.">
                </div>

                <div class="text-center mt-20">
                    <button class="btn" onclick="continueToFigma()">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Figma Extraction (Optional) -->
        <div class="window hidden" id="figma-step">
            <div class="window-header">▶ FIGMA EXTRACTION (OPTIONAL)</div>
            <div class="window-body">
                <div class="form-group">
                    <label class="label">Figma Design URL:</label>
                    <input type="text" id="figma-url" class="input" placeholder="https://www.figma.com/file/...">
                    <div style="color: #666; font-size: 14px; margin-top: 8px;">
                        We'll extract colors and fonts from your Figma design
                    </div>
                </div>

                <div class="text-center mt-20">
                    <button class="btn" onclick="extractFigma()" id="extract-btn">
                        🎨 EXTRACT STYLES
                    </button>
                    <button class="btn btn-secondary" onclick="skipFigma()" style="margin-left: 15px;">
                        SKIP FIGMA
                    </button>
                </div>

                <div id="figma-loading" class="hidden text-center mt-20">
                    <div style="color: #00ff00;">🤖 EXTRACTING STYLES...</div>
                </div>

                <div id="figma-results" class="hidden mt-20">
                    <div style="color: #00ff00; margin-bottom: 15px;">✅ Styles extracted successfully!</div>
                    <button class="btn" onclick="continueToStyle()">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 3: Style Customization -->
        <div class="window hidden" id="style-step">
            <div class="window-header">▶ STYLE CUSTOMIZATION</div>
            <div class="window-body">
                <!-- Figma Color Selection Area -->
                <div id="figma-colors-section" class="hidden" style="margin-bottom: 30px;">
                    <div class="label">Select Colors from Figma Design:</div>
                    <div style="color: #666; font-size: 14px; margin-bottom: 15px;" id="color-selection-instruction">
                        Click colors to select: Primary → Secondary → Accent
                    </div>
                    <div id="figma-color-grid" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                        <!-- Colors will be populated here -->
                    </div>
                </div>

                <!-- Selected Colors Display -->
                <div class="form-group">
                    <label class="label">Primary Color:</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" id="primary-color" value="#00ff00" style="width: 60px; height: 40px;">
                        <span id="primary-color-text" style="color: #00ff00;">#00ff00</span>
                        <div id="primary-selection-status" style="color: #666; font-size: 12px;"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Secondary Color:</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" id="secondary-color" value="#ffffff" style="width: 60px; height: 40px;">
                        <span id="secondary-color-text" style="color: #ffffff;">#ffffff</span>
                        <div id="secondary-selection-status" style="color: #666; font-size: 12px;"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Accent Color:</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" id="accent-color" value="#000000" style="width: 60px; height: 40px;">
                        <span id="accent-color-text" style="color: #000000;">#000000</span>
                        <div id="accent-selection-status" style="color: #666; font-size: 12px;"></div>
                    </div>
                </div>

                <!-- Reference Images Upload -->
                <div class="form-group">
                    <label class="label">Reference Images (Optional):</label>
                    <div style="color: #666; font-size: 14px; margin-bottom: 10px;">
                        Upload screenshots or example ads for style inspiration
                    </div>
                    <input type="file" id="reference-images" class="input" accept="image/*" multiple
                           style="padding: 8px; background: #000; border: 2px dashed #00ff00;">
                    <div id="reference-preview" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;">
                        <!-- Preview images will appear here -->
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Headline:</label>
                    <input type="text" id="headline" class="input" placeholder="Your Main Headline Here">
                </div>

                <div class="form-group">
                    <label class="label">Call-to-Action:</label>
                    <input type="text" id="cta-text" class="input" placeholder="SHOP NOW" value="SHOP NOW">
                </div>

                <div class="text-center mt-20">
                    <button class="btn" onclick="continueToGenerate()">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 4: Concept Generation (2x Larger) -->
        <div class="window hidden" id="concept-step">
            <div class="window-header">▶ CONCEPT GENERATION</div>
            <div class="window-body">
                <div class="concept-container">
                    <!-- Left Panel -->
                    <div class="concept-left">
                        <div class="form-group">
                            <label class="label">Creative Direction:</label>
                            <textarea id="enhancement" class="textarea" placeholder="Describe your vision... modern, playful, professional, etc."></textarea>
                        </div>

                        <button class="btn" onclick="generateConcept()" id="generate-btn" style="width: 100%;">
                            <?php if ($isLoggedIn): ?>
                                🎨 GENERATE CONCEPT
                            <?php else: ?>
                                🔒 LOGIN TO GENERATE
                            <?php endif; ?>
                        </button>

                        <button class="btn btn-secondary mt-20" onclick="regenerateConcept()" id="regenerate-btn" style="width: 100%;" disabled>
                            🔄 REGENERATE
                        </button>

                        <button class="btn mt-10" onclick="saveForLater()" id="save-concept-btn" style="width: 100%; background: #4CAF50;" disabled>
                            💾 SAVE FOR LATER
                        </button>
                    </div>

                    <!-- Center Display -->
                    <div class="concept-center" id="concept-display">
                        <div class="concept-placeholder">
                            <div style="font-size: 48px; margin-bottom: 20px;">🎨</div>
                            <div style="font-size: 20px; margin-bottom: 10px;">CONCEPT PREVIEW</div>
                            <div style="font-size: 14px;">Click generate to create your ad concept</div>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-20">
                    <button class="btn" onclick="continueToExport()" id="continue-export-btn" style="display: none;">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 5: Export Multiple Sizes -->
        <div class="window hidden" id="export-step">
            <div class="window-header">▶ EXPORT MULTIPLE SIZES</div>
            <div class="window-body">
                <!-- Concepts Gallery Section -->
                <div id="concepts-gallery-section">
                    <div class="text-center mb-20">
                        <div style="font-size: 20px; margin-bottom: 10px;">📁 Select a concept to export:</div>
                        <div style="font-size: 14px; color: #666;">Choose from your saved concepts or use the current one</div>
                    </div>

                    <div id="concepts-gallery-loading" class="text-center" style="padding: 40px;">
                        <div style="font-size: 16px; color: #00ff00;">⏳ Loading your concepts...</div>
                    </div>

                    <div id="concepts-gallery" class="concepts-grid" style="display: none;">
                        <!-- Concepts will be loaded here -->
                    </div>
                </div>

                <!-- Size Selection Section (hidden initially) -->
                <div id="size-selection-section" class="hidden">
                    <div class="text-center mb-20">
                        <div style="font-size: 20px; margin-bottom: 10px;">📐 Export selected concept in different sizes:</div>
                        <div id="selected-concept-info" style="font-size: 14px; color: #666; margin-bottom: 15px;"></div>

                        <!-- Selected Concept Preview -->
                        <div id="selected-concept-preview" style="margin-bottom: 20px;">
                            <!-- Preview will be shown here -->
                        </div>
                    </div>

                <div class="export-grid">
                    <!-- Universal Formats -->
                    <div class="export-card" id="export-universal_square">
                        <div class="size-label">Universal Square</div>
                        <div class="size-dimensions">1080 × 1080</div>
                        <button class="btn" onclick="exportSize('universal_square')" style="width: 100%;">
                            ⬜ EXPORT SQUARE
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-universal_portrait">
                        <div class="size-label">Universal Portrait</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('universal_portrait')" style="width: 100%;">
                            📱 EXPORT PORTRAIT
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-universal_landscape">
                        <div class="size-label">Universal Landscape</div>
                        <div class="size-dimensions">1920 × 1080</div>
                        <button class="btn" onclick="exportSize('universal_landscape')" style="width: 100%;">
                            🖥️ EXPORT LANDSCAPE
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- Facebook/Meta -->
                    <div class="export-card" id="export-facebook_feed">
                        <div class="size-label">Facebook Feed</div>
                        <div class="size-dimensions">1200 × 630</div>
                        <button class="btn" onclick="exportSize('facebook_feed')" style="width: 100%;">
                            📘 EXPORT FACEBOOK
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-facebook_square">
                        <div class="size-label">Facebook Square</div>
                        <div class="size-dimensions">1080 × 1080</div>
                        <button class="btn" onclick="exportSize('facebook_square')" style="width: 100%;">
                            📘 FB SQUARE
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-facebook_story">
                        <div class="size-label">Facebook Story</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('facebook_story')" style="width: 100%;">
                            📘 FB STORY
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- Instagram -->
                    <div class="export-card" id="export-instagram_feed">
                        <div class="size-label">Instagram Feed</div>
                        <div class="size-dimensions">1080 × 1080</div>
                        <button class="btn" onclick="exportSize('instagram_feed')" style="width: 100%;">
                            📸 IG FEED
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-instagram_story">
                        <div class="size-label">Instagram Story</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('instagram_story')" style="width: 100%;">
                            📸 IG STORY
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-instagram_reel">
                        <div class="size-label">Instagram Reel</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('instagram_reel')" style="width: 100%;">
                            📸 IG REEL
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-instagram_portrait">
                        <div class="size-label">Instagram Portrait</div>
                        <div class="size-dimensions">1080 × 1350</div>
                        <button class="btn" onclick="exportSize('instagram_portrait')" style="width: 100%;">
                            📸 IG PORTRAIT
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- Twitter/X -->
                    <div class="export-card" id="export-twitter_post">
                        <div class="size-label">Twitter Post</div>
                        <div class="size-dimensions">1200 × 675</div>
                        <button class="btn" onclick="exportSize('twitter_post')" style="width: 100%;">
                            🐦 X POST
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-twitter_square">
                        <div class="size-label">Twitter Square</div>
                        <div class="size-dimensions">1080 × 1080</div>
                        <button class="btn" onclick="exportSize('twitter_square')" style="width: 100%;">
                            🐦 X SQUARE
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-twitter_header">
                        <div class="size-label">Twitter Header</div>
                        <div class="size-dimensions">1500 × 500</div>
                        <button class="btn" onclick="exportSize('twitter_header')" style="width: 100%;">
                            🐦 X HEADER
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- LinkedIn -->
                    <div class="export-card" id="export-linkedin_feed">
                        <div class="size-label">LinkedIn Feed</div>
                        <div class="size-dimensions">1200 × 627</div>
                        <button class="btn" onclick="exportSize('linkedin_feed')" style="width: 100%;">
                            💼 LI FEED
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-linkedin_square">
                        <div class="size-label">LinkedIn Square</div>
                        <div class="size-dimensions">1080 × 1080</div>
                        <button class="btn" onclick="exportSize('linkedin_square')" style="width: 100%;">
                            💼 LI SQUARE
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-linkedin_story">
                        <div class="size-label">LinkedIn Story</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('linkedin_story')" style="width: 100%;">
                            💼 LI STORY
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- TikTok -->
                    <div class="export-card" id="export-tiktok_video">
                        <div class="size-label">TikTok Video</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('tiktok_video')" style="width: 100%;">
                            🎵 TIKTOK
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- YouTube -->
                    <div class="export-card" id="export-youtube_thumbnail">
                        <div class="size-label">YouTube Thumbnail</div>
                        <div class="size-dimensions">1280 × 720</div>
                        <button class="btn" onclick="exportSize('youtube_thumbnail')" style="width: 100%;">
                            📺 YT THUMB
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-youtube_short">
                        <div class="size-label">YouTube Short</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('youtube_short')" style="width: 100%;">
                            📺 YT SHORT
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- Pinterest -->
                    <div class="export-card" id="export-pinterest_pin">
                        <div class="size-label">Pinterest Pin</div>
                        <div class="size-dimensions">1000 × 1500</div>
                        <button class="btn" onclick="exportSize('pinterest_pin')" style="width: 100%;">
                            📌 PINTEREST
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-pinterest_square">
                        <div class="size-label">Pinterest Square</div>
                        <div class="size-dimensions">1080 × 1080</div>
                        <button class="btn" onclick="exportSize('pinterest_square')" style="width: 100%;">
                            📌 PIN SQUARE
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- Snapchat -->
                    <div class="export-card" id="export-snapchat_ad">
                        <div class="size-label">Snapchat Ad</div>
                        <div class="size-dimensions">1080 × 1920</div>
                        <button class="btn" onclick="exportSize('snapchat_ad')" style="width: 100%;">
                            👻 SNAPCHAT
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <!-- Google Ads -->
                    <div class="export-card" id="export-google_banner">
                        <div class="size-label">Google Banner</div>
                        <div class="size-dimensions">728 × 90</div>
                        <button class="btn" onclick="exportSize('google_banner')" style="width: 100%;">
                            🔍 BANNER
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-google_rectangle">
                        <div class="size-label">Google Rectangle</div>
                        <div class="size-dimensions">300 × 250</div>
                        <button class="btn" onclick="exportSize('google_rectangle')" style="width: 100%;">
                            🔍 RECTANGLE
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-google_skyscraper">
                        <div class="size-label">Google Skyscraper</div>
                        <div class="size-dimensions">160 × 600</div>
                        <button class="btn" onclick="exportSize('google_skyscraper')" style="width: 100%;">
                            🔍 SKYSCRAPER
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>

                    <div class="export-card" id="export-google_large_rectangle">
                        <div class="size-label">Google Large Rectangle</div>
                        <div class="size-dimensions">336 × 280</div>
                        <button class="btn" onclick="exportSize('google_large_rectangle')" style="width: 100%;">
                            🔍 LARGE RECT
                        </button>
                        <div class="export-result" style="margin-top: 15px;"></div>
                    </div>
                </div>

                </div>

                <div class="text-center mt-20">
                    <button class="btn btn-secondary" onclick="backFromSizeSelection()" id="back-to-concepts-btn" style="display: none;">
                        &lt;&lt; BACK TO CONCEPTS
                    </button>
                    <button class="btn btn-secondary" onclick="goToStep(4)" id="back-to-concept-btn">
                        &lt;&lt; BACK TO CONCEPT
                    </button>
                </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Universal Loading Bar Modal -->
    <div class="loading-overlay" id="universal-loading">
        <div class="loading-modal">
            <div class="loading-title" id="loading-title">Processing...</div>
            <div class="loading-bar-container">
                <div class="loading-bar" id="loading-bar"></div>
            </div>
            <div class="loading-message" id="loading-message">Please wait while we process your request</div>
            <div class="loading-dismiss" onclick="dismissLoading()">You can dismiss this</div>
        </div>
    </div>

    <script>
        // Global variables
        let currentStep = 1;
        let styleGuide = {};
        let generatedConcept = null;
        let logoFile = null;
        let referenceImages = [];
        let selectedColors = {};
        let colorSelectionStep = 'primary'; // primary, secondary, accent, complete

        // Helper function to check if user is logged in
        function isUserLoggedIn() {
            return <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        }

        // Universal Loading Bar Functions
        let loadingInterval = null;
        let currentProgress = 0;

        function showLoading(title = 'Processing...', message = 'Please wait while we process your request') {
            document.getElementById('loading-title').textContent = title;
            document.getElementById('loading-message').textContent = message;
            document.getElementById('loading-bar').style.width = '0%';
            document.getElementById('universal-loading').style.display = 'flex';

            currentProgress = 0;
            // Start automatic progress animation
            loadingInterval = setInterval(() => {
                if (currentProgress < 90) {
                    currentProgress += Math.random() * 15;
                    currentProgress = Math.min(currentProgress, 90);
                    document.getElementById('loading-bar').style.width = currentProgress + '%';
                }
            }, 500);

            console.log('⏳ Loading started:', title);
        }

        function updateLoading(progress, message = null) {
            if (message) {
                document.getElementById('loading-message').textContent = message;
            }
            currentProgress = Math.max(currentProgress, progress);
            document.getElementById('loading-bar').style.width = currentProgress + '%';
        }

        function hideLoading() {
            if (loadingInterval) {
                clearInterval(loadingInterval);
                loadingInterval = null;
            }

            // Complete the progress bar
            document.getElementById('loading-bar').style.width = '100%';

            // Hide after a brief moment
            setTimeout(() => {
                document.getElementById('universal-loading').style.display = 'none';
                currentProgress = 0;
            }, 300);

            console.log('✅ Loading completed');
        }

        function dismissLoading() {
            hideLoading();
            playBeep(400, 100); // Dismiss beep
            console.log('❌ Loading dismissed by user');
        }

        // Logo upload functions
        function handleLogoUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            // Validate file size (5MB max)
            if (file.size > 5 * 1024 * 1024) {
                alert('Logo file must be under 5MB');
                return;
            }

            // Validate file type
            if (!file.type.startsWith('image/')) {
                alert('Please upload an image file (PNG, JPG, SVG)');
                return;
            }

            logoFile = file;

            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logo-preview-img').src = e.target.result;
                document.querySelector('.logo-upload-prompt').style.display = 'none';
                document.getElementById('logo-preview').classList.remove('hidden');

                // Store in style guide
                styleGuide.logo = {
                    file: file,
                    dataUrl: e.target.result,
                    name: file.name
                };

                playBeep(600, 100); // Success beep
                console.log('✅ Logo uploaded:', file.name);
            };
            reader.readAsDataURL(file);
        }

        function removeLogo() {
            logoFile = null;
            styleGuide.logo = null;
            document.getElementById('logo-upload').value = '';
            document.getElementById('logo-preview-img').src = '';
            document.querySelector('.logo-upload-prompt').style.display = 'block';
            document.getElementById('logo-preview').classList.add('hidden');

            playBeep(400, 100); // Remove beep
            console.log('🗑️ Logo removed');
        }

        // Step Navigation
        function goToStep(stepNumber) {
            console.log(`🔄 Navigating to step ${stepNumber}`);

            // Hide all steps
            document.querySelectorAll('.window').forEach(w => w.classList.add('hidden'));
            document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));

            // Show target step
            currentStep = stepNumber;
            document.getElementById(`step-${stepNumber}`).classList.add('active');

            switch(stepNumber) {
                case 1:
                    document.getElementById('brand-step').classList.remove('hidden');
                    console.log('📝 Step 1: Brand Info');
                    break;
                case 2:
                    document.getElementById('figma-step').classList.remove('hidden');
                    console.log('🎨 Step 2: Figma Extraction');
                    break;
                case 3:
                    document.getElementById('style-step').classList.remove('hidden');
                    console.log('⚙️ Step 3: Style Customization');
                    populateStylesFromExtraction();
                    break;
                case 4:
                    document.getElementById('concept-step').classList.remove('hidden');
                    console.log('🚀 Step 4: Concept Generation');
                    break;
                case 5:
                    document.getElementById('export-step').classList.remove('hidden');
                    console.log('📤 Step 5: Export Multiple Sizes');
                    break;
            }
        }

        // Step 1: Brand Info Functions
        function continueToFigma() {
            // Collect brand info
            styleGuide.brand_name = document.getElementById('brand-name').value;
            styleGuide.product_description = document.getElementById('product-description').value;
            styleGuide.target_audience = document.getElementById('target-audience').value;
            styleGuide.campaign_goal = document.getElementById('campaign-goal').value;

            if (!styleGuide.brand_name.trim()) {
                alert('Please enter your brand name');
                return;
            }

            console.log('💾 Brand info collected:', styleGuide);
            goToStep(2);
        }

        // Step 2: Figma Functions
        function extractFigma() {
            const figmaUrl = document.getElementById('figma-url').value;
            if (!figmaUrl.trim()) {
                alert('Please enter a Figma URL');
                return;
            }

            console.log('🎨 Extracting Figma styles from:', figmaUrl);

            // Show universal loading bar
            showLoading('🎨 Extracting Figma Styles', 'Analyzing your Figma design for colors and components...');

            document.getElementById('extract-btn').disabled = true;
            document.getElementById('figma-loading').classList.remove('hidden');

            fetch('/figma-ad-generator?ajax=extract_figma', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `figma_url=${encodeURIComponent(figmaUrl)}&csrf_token=${encodeURIComponent('<?= $csrfToken ?>')}`
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('figma-loading').classList.add('hidden');
                document.getElementById('extract-btn').disabled = false;

                if (data.success) {
                    console.log('✅ Figma extraction successful:', data.styleGuide);

                    // Merge extracted styles with existing style guide
                    styleGuide = { ...styleGuide, ...data.styleGuide };

                    document.getElementById('figma-results').classList.remove('hidden');
                } else {
                    alert('Figma extraction failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('❌ Figma extraction error:', error);
                document.getElementById('figma-loading').classList.add('hidden');
                document.getElementById('extract-btn').disabled = false;
                alert('Network error occurred');
            });
        }

        function skipFigma() {
            console.log('⏭️ Skipping Figma extraction');
            goToStep(3);
        }

        function continueToStyle() {
            goToStep(3);
            populateStylesFromExtraction();
        }

        // Color selection state
        let colorSelectionStep = 'primary'; // 'primary', 'secondary', 'accent'
        let selectedColors = {
            primary: null,
            secondary: null,
            accent: null
        };

        // Sound functions
        function playBeep(frequency = 800, duration = 100) {
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);

                oscillator.frequency.value = frequency;
                oscillator.type = 'sine';

                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + duration / 1000);

                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + duration / 1000);
            } catch (e) {
                console.log('🔇 Audio not available');
            }
        }

        // Populate styles from extracted Figma data
        function populateStylesFromExtraction() {
            if (styleGuide && styleGuide.colors && styleGuide.colors.length > 0) {
                console.log('🎨 Displaying Figma color grid:', styleGuide.colors);

                // Show the color selection section
                document.getElementById('figma-colors-section').classList.remove('hidden');

                // Create color grid
                const colorGrid = document.getElementById('figma-color-grid');
                colorGrid.innerHTML = '';

                styleGuide.colors.forEach((color, index) => {
                    const colorSquare = document.createElement('div');
                    colorSquare.className = 'color-square';
                    colorSquare.style.backgroundColor = color;
                    colorSquare.title = color;
                    colorSquare.dataset.color = color;
                    colorSquare.dataset.index = index;

                    colorSquare.addEventListener('click', function() {
                        selectBrandColor(this, color);
                    });

                    colorGrid.appendChild(colorSquare);
                });

                // Reset selection state
                colorSelectionStep = 'primary';
                selectedColors = { primary: null, secondary: null, accent: null };
                updateSelectionInstruction();
            } else {
                // Hide color selection section if no colors
                document.getElementById('figma-colors-section').classList.add('hidden');
            }

            // Update other fields if extracted
            if (styleGuide) {
                if (styleGuide.headline && !document.getElementById('headline').value) {
                    document.getElementById('headline').value = styleGuide.headline;
                    console.log('📝 Populated headline:', styleGuide.headline);
                }

                if (styleGuide.cta_text && !document.getElementById('cta-text').value) {
                    document.getElementById('cta-text').value = styleGuide.cta_text;
                    console.log('📝 Populated CTA:', styleGuide.cta_text);
                }
            }
        }

        // Handle color selection
        function selectBrandColor(element, color) {
            console.log(`🎨 Selecting ${colorSelectionStep} color:`, color);

            // Play beep sound
            switch(colorSelectionStep) {
                case 'primary':
                    playBeep(800, 150); // High beep
                    break;
                case 'secondary':
                    playBeep(600, 150); // Medium beep
                    break;
                case 'accent':
                    playBeep(400, 150); // Low beep
                    break;
            }

            // Clear previous selections of this type
            document.querySelectorAll('.color-square').forEach(square => {
                square.classList.remove(`selected-${colorSelectionStep}`);
            });

            // Mark this color as selected
            element.classList.add(`selected-${colorSelectionStep}`);
            selectedColors[colorSelectionStep] = color;

            // Update the color input and display
            document.getElementById(`${colorSelectionStep}-color`).value = color;
            document.getElementById(`${colorSelectionStep}-color-text`).textContent = color;
            document.getElementById(`${colorSelectionStep}-color-text`).style.color = color;
            document.getElementById(`${colorSelectionStep}-selection-status`).textContent = '✓ Selected';

            // Move to next selection step
            switch(colorSelectionStep) {
                case 'primary':
                    colorSelectionStep = 'secondary';
                    break;
                case 'secondary':
                    colorSelectionStep = 'accent';
                    break;
                case 'accent':
                    colorSelectionStep = 'complete';
                    break;
            }

            updateSelectionInstruction();
        }

        // Update instruction text
        function updateSelectionInstruction() {
            const instruction = document.getElementById('color-selection-instruction');

            switch(colorSelectionStep) {
                case 'primary':
                    instruction.textContent = '👆 Select PRIMARY color (1/3)';
                    instruction.style.color = '#00ff00';
                    break;
                case 'secondary':
                    instruction.textContent = '👆 Select SECONDARY color (2/3)';
                    instruction.style.color = '#00ccff';
                    break;
                case 'accent':
                    instruction.textContent = '👆 Select ACCENT color (3/3)';
                    instruction.style.color = '#ffcc00';
                    break;
                case 'complete':
                    instruction.textContent = '✅ All colors selected! Click any color to change selection.';
                    instruction.style.color = '#00ff00';
                    // Reset to allow re-selection
                    colorSelectionStep = 'primary';
                    break;
            }
        }

        // Reference image handling
        let referenceImages = [];

        function setupImageUpload() {
            const imageInput = document.getElementById('reference-images');
            if (imageInput) {
                imageInput.addEventListener('change', function(e) {
                    handleReferenceImages(e.target.files);
                });
            }
        }

        function handleReferenceImages(files) {
            Array.from(files).forEach(file => {
                if (file.type.startsWith('image/')) {
                    addReferenceImage(file);
                }
            });
        }

        function addReferenceImage(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const imageData = {
                    file: file,
                    dataUrl: e.target.result,
                    name: file.name
                };

                referenceImages.push(imageData);
                displayReferencePreview(imageData, referenceImages.length - 1);

                console.log('📸 Added reference image:', file.name);
                playBeep(500, 100); // Medium beep for upload
            };
            reader.readAsDataURL(file);
        }

        function displayReferencePreview(imageData, index) {
            const previewContainer = document.getElementById('reference-preview');

            const previewItem = document.createElement('div');
            previewItem.className = 'reference-preview-item';
            previewItem.dataset.index = index;

            previewItem.innerHTML = `
                <img src="${imageData.dataUrl}" alt="${imageData.name}" class="reference-preview-img">
                <button class="reference-remove-btn" onclick="removeReferenceImage(${index})" title="Remove image">×</button>
            `;

            previewContainer.appendChild(previewItem);
        }

        function removeReferenceImage(index) {
            // Remove from array
            referenceImages.splice(index, 1);

            // Rebuild preview (simple approach)
            const previewContainer = document.getElementById('reference-preview');
            previewContainer.innerHTML = '';

            // Re-display all remaining images with updated indices
            referenceImages.forEach((imageData, newIndex) => {
                displayReferencePreview(imageData, newIndex);
            });

            console.log('🗑️ Removed reference image, remaining:', referenceImages.length);
            playBeep(300, 80); // Low beep for removal
        }

        // Step 3: Style Functions
        function continueToGenerate() {
            // Collect style info
            styleGuide.primary_color = document.getElementById('primary-color').value;
            styleGuide.secondary_color = document.getElementById('secondary-color').value;
            styleGuide.accent_color = document.getElementById('accent-color').value;
            styleGuide.headline = document.getElementById('headline').value;
            styleGuide.cta_text = document.getElementById('cta-text').value;

            // Include reference images
            styleGuide.reference_images = referenceImages.map(img => ({
                name: img.name,
                dataUrl: img.dataUrl
            }));

            console.log('🎨 Style info collected:', styleGuide);
            console.log('📸 Reference images:', referenceImages.length);
            goToStep(4);
        }

        // Build prompt preview (replicates backend buildAdPrompt logic)
        function buildPromptPreview(styleGuide, adType, width, height) {
            const aspectRatio = width > height ? 'landscape' : (width < height ? 'portrait' : 'square');

            let prompt = "You are creating an ORIGINAL advertising concept based on brand guidelines. ";
            prompt += `Format: ${aspectRatio} orientation (${width}x${height}px) for digital marketing.\n\n`;

            prompt += "IMPORTANT: Create something NEW and INNOVATIVE that follows the brand guidelines but doesn't copy existing materials. ";

            // Add brand/company context
            if (styleGuide.brand_name) {
                prompt += `Brand: ${styleGuide.brand_name}. `;
            }

            if (styleGuide.product_description) {
                prompt += `Product/Service: ${styleGuide.product_description}. `;
            }

            // Add target audience
            if (styleGuide.target_audience) {
                prompt += `Target audience: ${styleGuide.target_audience}. `;
            }

            // Add strategic campaign goal
            if (styleGuide.campaign_goal) {
                const goalContextMap = {
                    'awareness': 'GOAL: Maximum brand recall. Create memorable, distinctive visuals that stick in minds. Use bold, unexpected creative that breaks through the noise.',
                    'sales': 'GOAL: Drive immediate purchases. Create urgency, showcase value, demonstrate benefits. Make the product irresistible and the action clear.',
                    'leads': 'GOAL: Capture qualified interest. Build intrigue, offer value exchange, create curiosity gaps that compel information sharing.',
                    'traffic': 'GOAL: Generate clicks. Create visual tension that demands resolution, tease content that viewers must see, use pattern interrupts.',
                    'engagement': 'GOAL: Spark interaction. Design for shareability, create conversation starters, tap into emotions and cultural moments.'
                };
                const goalContext = goalContextMap[styleGuide.campaign_goal] || 'GOAL: Achieve marketing objectives through compelling creative.';
                prompt += `\n${goalContext}\n`;
            }

            // Add copy/messaging if provided
            if (styleGuide.ad_copy) {
                prompt += `Key messaging: ${styleGuide.ad_copy}. `;
            }

            // Add headline and CTA as visual elements
            if (styleGuide.headline) {
                prompt += `HEADLINE: "${styleGuide.headline}" - Make this the main attention-grabbing text element, prominently displayed. `;
            }

            if (styleGuide.cta_text) {
                prompt += `CALL-TO-ACTION: "${styleGuide.cta_text}" - Create this as a visible, clickable button or prominent text element that stands out and encourages action. `;
            }

            // Add visual style from Figma or defaults
            if (styleGuide.primary_color) {
                prompt += `Primary brand color: ${styleGuide.primary_color}. `;
            }

            if (styleGuide.primary_font) {
                prompt += `Typography should be clean and modern, similar to ${styleGuide.primary_font}. `;
            }

            // Add logo information
            if (styleGuide.logo) {
                prompt += `BRAND LOGO: Include the uploaded brand logo prominently in the design. Position it appropriately for brand recognition. `;
            }

            // Platform-specific creative strategies
            const platformGuidanceMap = {
                'instagram_story': 'PLATFORM: Stories - Full-screen immersive experience. Use vertical real estate dramatically. Create thumb-stopping moment in first 2 seconds. Layer depth with foreground/background. Safe zones for UI elements.',
                'facebook_story': 'PLATFORM: Stories - Full-screen immersive experience. Use vertical real estate dramatically. Create thumb-stopping moment in first 2 seconds. Layer depth with foreground/background. Safe zones for UI elements.',
                'snapchat_ad': 'PLATFORM: Stories - Full-screen immersive experience. Use vertical real estate dramatically. Create thumb-stopping moment in first 2 seconds. Layer depth with foreground/background. Safe zones for UI elements.',
                'instagram_feed': 'PLATFORM: Social Feed Square - Compete in infinite scroll. Use high contrast, bold focal points. Create visual patterns that halt scrolling. Mobile-first composition. Make it work as a tiny thumbnail AND full screen.',
                'facebook_square': 'PLATFORM: Social Feed Square - Compete in infinite scroll. Use high contrast, bold focal points. Create visual patterns that halt scrolling. Mobile-first composition. Make it work as a tiny thumbnail AND full screen.',
                'facebook_feed': 'PLATFORM: Facebook Feed - Stand out in cluttered timeline. Use emotional triggers, faces perform well. Create curiosity gaps. Optimize for both desktop and mobile viewing.',
                'youtube_thumbnail': 'PLATFORM: YouTube - Maximize click-through. Use faces with strong emotions, high contrast text, create intrigue without clickbait. Consider how it looks at multiple sizes.',
                'google_banner': 'PLATFORM: Display Network - Instant clarity is key. 3-second rule: message must be understood immediately. Strong visual hierarchy. Works at 50% size.',
                'google_rectangle': 'PLATFORM: Display Network - Instant clarity is key. 3-second rule: message must be understood immediately. Strong visual hierarchy. Works at 50% size.',
                'pinterest_pin': 'PLATFORM: Pinterest - Aspirational and actionable. Vertical format for maximum real estate. Step-by-step visual appeal. Save-worthy content.',
                'linkedin_feed': 'PLATFORM: LinkedIn - Professional but not boring. Data visualization, industry insights. Thought leadership visual. B2B decision-maker appeal.',
                'twitter_post': 'PLATFORM: Twitter/X - Speed of consumption. Bold, simple, meme-aware. Text-image harmony. Conversation starter.'
            };
            const platformGuidance = platformGuidanceMap[adType] || 'PLATFORM: Multi-channel - Flexible creative that works across contexts. Strong brand recognition. Clear focal point.';

            prompt += `\n${platformGuidance}\n`;

            // Creative excellence requirements
            prompt += "\nCREATIVE EXCELLENCE:\n";
            prompt += "- Apply advanced advertising psychology and visual hierarchy\n";
            prompt += "- Use the brand guidelines as DNA, not a template\n";
            prompt += "- Consider scroll behavior, attention patterns, and platform algorithms\n";
            prompt += "- Balance creativity with conversion principles\n";

            if (styleGuide.has_shadows) {
                prompt += "- Include subtle shadows and depth for premium feel\n";
            }

            // Final mandate
            prompt += "\nFINAL OUTPUT: A breakthrough ad that would win creative awards while delivering measurable business results. ";
            prompt += "Something the brand's CMO would proudly present to the board. ";
            prompt += "An ad that competitors will wish they had created first.";

            return prompt;
        }

        // Step 4: Concept Generation
        function generateConcept() {
            if (!isUserLoggedIn()) {
                window.location.href = '/auth/login.php';
                return;
            }

            console.log('🖼️ Generating concept...');

            const enhancement = document.getElementById('enhancement').value;
            styleGuide.enhancement = enhancement;

            // Build debug prompt for display
            const debugPrompt = buildPromptPreview(styleGuide, 'universal_square', 1080, 1080);

            // Show universal loading bar
            showLoading('🎨 Generating Ad Concept', 'Creating your personalized ad design with AI...');

            // Show loading with debug prompt
            document.getElementById('concept-display').innerHTML = `
                <div class="concept-placeholder">
                    <div style="font-size: 24px; margin-bottom: 20px;">🤖</div>
                    <div style="font-size: 16px; margin-bottom: 10px; color: #00ff00;">GENERATING...</div>
                    <div style="font-size: 12px; color: #666;">Creating your concept</div>

                    <!-- Debug Prompt Display -->
                    <div style="margin-top: 20px; padding: 15px; background: rgba(0,0,0,0.3); border: 1px solid #333; border-radius: 5px; text-align: left;">
                        <div style="font-size: 12px; color: #00ff00; margin-bottom: 10px;">🔍 DEBUG: Prompt being sent to Gemini AI:</div>
                        <div style="font-size: 10px; color: #ccc; font-family: monospace; white-space: pre-wrap; max-height: 200px; overflow-y: auto;">${debugPrompt}</div>
                    </div>
                </div>
            `;

            document.getElementById('generate-btn').disabled = true;

            // Prepare form data
            const formData = new URLSearchParams();
            formData.append('brand_name', styleGuide.brand_name || '');
            formData.append('product_description', styleGuide.product_description || '');
            formData.append('target_audience', styleGuide.target_audience || '');
            formData.append('campaign_goal', styleGuide.campaign_goal || '');
            formData.append('headline', styleGuide.headline || '');
            formData.append('cta_text', styleGuide.cta_text || 'SHOP NOW');
            formData.append('primary_color', styleGuide.primary_color || '#00ff00');
            formData.append('secondary_color', styleGuide.secondary_color || '#ffffff');
            formData.append('accent_color', styleGuide.accent_color || '#000000');
            formData.append('enhancement', enhancement);
            formData.append('size', 'universal_square');

            // Include logo if uploaded
            if (styleGuide.logo && styleGuide.logo.dataUrl) {
                formData.append('logo', styleGuide.logo.dataUrl);
            }

            formData.append('csrf_token', '<?= $csrfToken ?>');

            console.log('📤 Sending ad generation request...');

            fetch('/figma-ad-generator?ajax=generate_ads', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                console.log('✨ Generation response:', data);

                // Hide universal loading bar
                hideLoading();

                document.getElementById('generate-btn').disabled = false;

                if (data.success && data.ads && data.ads.length > 0) {
                    const ad = data.ads[0];
                    generatedConcept = ad;

                    document.getElementById('concept-display').innerHTML = `
                        <img src="${ad.image_url}" alt="Generated Concept" class="concept-image">
                    `;

                    document.getElementById('regenerate-btn').disabled = false;
                    document.getElementById('save-concept-btn').disabled = false;
                    document.getElementById('continue-export-btn').style.display = 'inline-block';

                    console.log('✅ Concept generated successfully');
                } else {
                    document.getElementById('concept-display').innerHTML = `
                        <div class="concept-placeholder">
                            <div style="font-size: 48px; margin-bottom: 20px;">⚠️</div>
                            <div style="font-size: 16px; margin-bottom: 10px; color: #ff6600;">GENERATION FAILED</div>
                            <div style="font-size: 12px; color: #ccc;">${data.error || 'Unknown error'}</div>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('❌ Generation error:', error);

                // Hide universal loading bar
                hideLoading();

                document.getElementById('generate-btn').disabled = false;

                document.getElementById('concept-display').innerHTML = `
                    <div class="concept-placeholder">
                        <div style="font-size: 48px; margin-bottom: 20px;">❌</div>
                        <div style="font-size: 16px; margin-bottom: 10px; color: #ff0000;">NETWORK ERROR</div>
                        <div style="font-size: 12px; color: #ccc;">Please try again</div>
                    </div>
                `;
            });
        }

        function regenerateConcept() {
            console.log('🔄 Regenerating concept...');
            generateConcept();
        }

        function saveForLater() {
            if (!generatedConcept || !generatedConcept.image_url) {
                alert('No concept to save!');
                return;
            }

            console.log('💾 Saving concept for later...');

            const formData = new URLSearchParams();
            formData.append('action', 'save_concept');
            formData.append('image_url', generatedConcept.image_url);
            formData.append('brand_name', styleGuide.brand_name || 'Untitled');
            formData.append('style_guide', JSON.stringify(styleGuide));
            formData.append('csrf_token', '<?= $csrfToken ?>');

            document.getElementById('save-concept-btn').disabled = true;
            document.getElementById('save-concept-btn').textContent = '💾 SAVING...';

            fetch('/figma-ad-generator?ajax=save_concept', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('save-concept-btn').disabled = false;

                if (data.success) {
                    document.getElementById('save-concept-btn').textContent = '✅ SAVED';
                    document.getElementById('save-concept-btn').style.background = '#00cc00';
                    playBeep(600, 200); // Success beep

                    setTimeout(() => {
                        document.getElementById('save-concept-btn').textContent = '💾 SAVE FOR LATER';
                        document.getElementById('save-concept-btn').style.background = '#4CAF50';
                    }, 2000);
                } else {
                    document.getElementById('save-concept-btn').textContent = '❌ FAILED';
                    alert('Failed to save concept: ' + (data.error || 'Unknown error'));

                    setTimeout(() => {
                        document.getElementById('save-concept-btn').textContent = '💾 SAVE FOR LATER';
                    }, 2000);
                }
            })
            .catch(error => {
                console.error('❌ Save concept error:', error);
                document.getElementById('save-concept-btn').disabled = false;
                document.getElementById('save-concept-btn').textContent = '❌ ERROR';
                alert('Network error occurred while saving');

                setTimeout(() => {
                    document.getElementById('save-concept-btn').textContent = '💾 SAVE FOR LATER';
                }, 2000);
            });
        }

        function continueToExport() {
            goToStep(5);
            loadConceptsGallery();
        }

        // Concepts Gallery Functions
        let selectedConceptForExport = null;

        function loadConceptsGallery() {
            console.log('📁 Loading concepts gallery...');

            // Show universal loading bar
            showLoading('📁 Loading Concepts', 'Fetching your saved concepts...');

            document.getElementById('concepts-gallery-loading').style.display = 'block';
            document.getElementById('concepts-gallery').style.display = 'none';

            fetch('/figma-ad-generator?ajax=get_concepts', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `csrf_token=${encodeURIComponent('<?= $csrfToken ?>')}`
            })
            .then(response => response.json())
            .then(data => {
                // Hide universal loading bar
                hideLoading();

                document.getElementById('concepts-gallery-loading').style.display = 'none';
                document.getElementById('concepts-gallery').style.display = 'grid';

                if (data.success) {
                    renderConceptsGallery(data.concepts);
                } else {
                    document.getElementById('concepts-gallery').innerHTML = `
                        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #666;">
                            <div style="font-size: 48px; margin-bottom: 20px;">📁</div>
                            <div>No saved concepts found</div>
                            <div style="font-size: 12px; margin-top: 10px;">Generate and save some concepts first!</div>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('❌ Load concepts error:', error);

                // Hide universal loading bar
                hideLoading();

                document.getElementById('concepts-gallery-loading').style.display = 'none';
                document.getElementById('concepts-gallery').innerHTML = `
                    <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #ff6600;">
                        <div style="font-size: 48px; margin-bottom: 20px;">⚠️</div>
                        <div>Failed to load concepts</div>
                    </div>
                `;
            });
        }

        // Store concepts data globally to avoid JSON in onclick
        let conceptsData = new Map();

        function renderConceptsGallery(concepts) {
            const gallery = document.getElementById('concepts-gallery');
            let html = '';

            console.log(`🖼️ Rendering ${concepts.length} concepts in gallery`);

            // Update loading progress
            updateLoading(50, `Rendering ${concepts.length} concepts...`);

            // Clear previous data
            conceptsData.clear();

            // Add current concept if available
            if (generatedConcept && generatedConcept.image_url) {
                conceptsData.set('current', generatedConcept);
                html += `
                    <div class="concept-card current-concept-card" onclick="selectConceptForExport('current')" data-concept-id="current">
                        <div class="concept-card-placeholder" style="height: 120px; background: #111; border-radius: 5px; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; color: #666; font-size: 12px;">Loading...</div>
                        <img data-loading="true" data-src="${generatedConcept.image_url}" alt="Current Concept" style="display: none;" onload="this.style.display='block'; this.previousElementSibling.style.display='none'; this.style.opacity='1'; this.removeAttribute('data-loading');" onerror="this.style.opacity='0.3';">
                        <div class="concept-card-title">🔥 CURRENT CONCEPT</div>
                        <div class="concept-card-date">Just Generated</div>
                    </div>
                `;
            }

            // Add saved concepts with lazy loading and placeholders
            concepts.forEach((concept, index) => {
                conceptsData.set(concept.id.toString(), concept);
                const date = new Date(concept.created_at).toLocaleDateString();
                html += `
                    <div class="concept-card" onclick="selectConceptForExport('${concept.id}')" data-concept-id="${concept.id}">
                        <div class="concept-card-placeholder" style="height: 120px; background: #111; border-radius: 5px; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; color: #666; font-size: 12px;">Loading...</div>
                        <img data-loading="true" data-src="${concept.image_path}" alt="${concept.brand_name || 'Untitled'}" style="display: none;" onload="this.style.display='block'; this.previousElementSibling.style.display='none'; this.style.opacity='1'; this.removeAttribute('data-loading');" onerror="this.style.opacity='0.3'; this.previousElementSibling.textContent='Failed';">
                        <div class="concept-card-title">${concept.brand_name || 'Untitled'}</div>
                        <div class="concept-card-date">${date}</div>
                    </div>
                `;
            });

            gallery.innerHTML = html;

            // Update loading progress
            updateLoading(80, 'Loading concept images...');

            // Implement lazy loading for images
            lazyLoadConceptImages();
        }

        function lazyLoadConceptImages() {
            const images = document.querySelectorAll('.concept-card img[data-src]');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                });
            });

            images.forEach(img => imageObserver.observe(img));
        }

        function selectConceptForExport(conceptId) {
            console.log('🎯 Selected concept for export:', conceptId);

            // Get concept data from stored map
            const conceptData = conceptsData.get(conceptId.toString());
            if (!conceptData) {
                console.error('❌ Concept data not found for ID:', conceptId);
                return;
            }

            // Remove previous selection
            document.querySelectorAll('.concept-card').forEach(card => {
                card.classList.remove('selected');
            });

            // Mark new selection
            document.querySelector(`[data-concept-id="${conceptId}"]`).classList.add('selected');

            // Store selected concept
            selectedConceptForExport = conceptData;

            // Show concept info
            const brandName = conceptData.brand_name || 'Current Concept';
            document.getElementById('selected-concept-info').textContent = `Selected: ${brandName}`;

            // Show concept preview
            document.getElementById('selected-concept-preview').innerHTML = `
                <img src="${conceptData.image_url || conceptData.image_path}" alt="Selected Concept" style="max-width: 200px; max-height: 200px; border: 2px solid #00ff00; border-radius: 8px;">
            `;

            // Hide gallery, show size selection
            document.getElementById('concepts-gallery-section').classList.add('hidden');
            document.getElementById('size-selection-section').classList.remove('hidden');
            document.getElementById('back-to-concepts-btn').style.display = 'inline-block';
            document.getElementById('back-to-concept-btn').style.display = 'none';

            playBeep(600, 150); // Selection beep
        }

        function backFromSizeSelection() {
            console.log('⬅️ Back to concepts gallery');

            // Show gallery, hide size selection
            document.getElementById('concepts-gallery-section').classList.remove('hidden');
            document.getElementById('size-selection-section').classList.add('hidden');
            document.getElementById('back-to-concepts-btn').style.display = 'none';
            document.getElementById('back-to-concept-btn').style.display = 'inline-block';

            // Clear selection
            selectedConceptForExport = null;
            document.querySelectorAll('.concept-card').forEach(card => {
                card.classList.remove('selected');
            });
        }

        // Step 5: Export Functions
        function exportSize(size) {
            if (!selectedConceptForExport) {
                alert('Please select a concept first!');
                return;
            }

            console.log(`📤 Exporting size: ${size}`);

            const card = document.getElementById(`export-${size}`);
            const button = card.querySelector('button');
            const result = card.querySelector('.export-result');

            card.classList.add('generating');
            button.disabled = true;
            button.textContent = '⏳ GENERATING...';

            // Get style guide from selected concept
            let conceptStyleGuide = styleGuide; // Default to current style guide
            if (selectedConceptForExport.style_guide) {
                try {
                    conceptStyleGuide = JSON.parse(selectedConceptForExport.style_guide);
                } catch (e) {
                    console.warn('Failed to parse concept style guide, using current:', e);
                }
            }

            // Prepare form data with the specific size
            const formData = new URLSearchParams();
            formData.append('brand_name', conceptStyleGuide.brand_name || '');
            formData.append('product_description', conceptStyleGuide.product_description || '');
            formData.append('target_audience', conceptStyleGuide.target_audience || '');
            formData.append('campaign_goal', conceptStyleGuide.campaign_goal || '');
            formData.append('headline', conceptStyleGuide.headline || '');
            formData.append('cta_text', conceptStyleGuide.cta_text || 'SHOP NOW');
            formData.append('primary_color', conceptStyleGuide.primary_color || '#00ff00');
            formData.append('secondary_color', conceptStyleGuide.secondary_color || '#ffffff');
            formData.append('accent_color', conceptStyleGuide.accent_color || '#000000');
            formData.append('enhancement', conceptStyleGuide.enhancement || '');
            formData.append('size', size);
            formData.append('source_image', selectedConceptForExport.image_url || selectedConceptForExport.image_path);
            formData.append('csrf_token', '<?= $csrfToken ?>');

            fetch('/figma-ad-generator?ajax=resize_concept', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                card.classList.remove('generating');
                button.disabled = false;

                if (data.success && data.ads && data.ads.length > 0) {
                    const ad = data.ads[0];
                    button.textContent = '✅ GENERATED';
                    button.style.background = '#00cc00';

                    result.innerHTML = `
                        <a href="${ad.image_url}" target="_blank" class="btn btn-secondary" style="font-size: 12px; padding: 8px 15px;">
                            💾 DOWNLOAD
                        </a>
                    `;

                    console.log(`✅ ${size} export successful`);
                } else {
                    button.textContent = '❌ FAILED';
                    button.style.background = '#cc0000';
                    result.innerHTML = '<div style="color: #ff6600; font-size: 12px;">Export failed</div>';
                }
            })
            .catch(error => {
                console.error(`❌ Export error for ${size}:`, error);
                card.classList.remove('generating');
                button.disabled = false;
                button.textContent = '❌ ERROR';
                button.style.background = '#cc0000';
                result.innerHTML = '<div style="color: #ff0000; font-size: 12px;">Network error</div>';
            });
        }

        // Color picker updates and image upload setup
        document.addEventListener('DOMContentLoaded', function() {
            // Setup color picker updates
            ['primary', 'secondary', 'accent'].forEach(colorType => {
                const colorInput = document.getElementById(`${colorType}-color`);
                const colorText = document.getElementById(`${colorType}-color-text`);

                if (colorInput && colorText) {
                    colorInput.addEventListener('input', function() {
                        colorText.textContent = this.value;
                        colorText.style.color = this.value;
                    });
                }
            });

            // Setup image upload
            setupImageUpload();
        });
    </script>
</body>
</html>