<?php
// Start output buffering early to prevent any unwanted output
ob_start();

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/BackgroundJobService.php';
require_once __DIR__ . '/includes/UserPhotoService.php';

Session::start();

// Handle AJAX requests for non-authenticated users
if (isset($_POST['ajax']) && $_POST['ajax'] === '1' && !Session::isLoggedIn()) {
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated', 'redirect' => '/auth/login.php']);
    exit;
}

if (!Session::isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

$user = Session::getCurrentUser();
$credits = $user['credits_remaining'] ?? 0;

// Get user's stored photos
$userPhotos = UserPhotoService::getUserPhotos($user['id']);
foreach ($userPhotos as &$photo) {
    $photo['url'] = UserPhotoService::getPhotoUrl($photo['filename']);
}

// Get outfit collections and options with caching
$collections = [];
$outfitOptions = [];
$outfitsDir = __DIR__ . '/images/outfits/';

// Define featured collections
$featuredCollections = [
    'theemmys' => [
        'name' => 'The Emmy\'s',
        'icon' => '⭐',
        'featured' => true,
        'description' => 'Red carpet looks from the Emmy Awards'
    ]
];

// Cache key for outfit data
$cacheKey = 'outfit_collections_v2';
$cacheFile = __DIR__ . '/cache/' . $cacheKey . '.json';
$cacheEnabled = Config::get('outfit_cache_enabled', true);
$cacheMaxAge = 3600; // 1 hour

// Try to load from cache first
$outfitData = null;
if ($cacheEnabled && file_exists($cacheFile)) {
    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge < $cacheMaxAge) {
        $cachedData = file_get_contents($cacheFile);
        if ($cachedData !== false) {
            $outfitData = json_decode($cachedData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $collections = $outfitData['collections'] ?? [];
                $outfitOptions = $outfitData['outfitOptions'] ?? [];
            }
        }
    }
}

// Build outfit data if not cached or cache invalid
if ($outfitData === null) {
    // Ensure cache directory exists
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    // Optimized: Single directory scan for regular outfits
    if (is_dir($outfitsDir)) {
        $iterator = new DirectoryIterator($outfitsDir);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->isDir()) continue;

            $extension = strtolower($fileInfo->getExtension());
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = $fileInfo->getFilename();
                $outfitOptions[] = [
                    'filename' => $filename,
                    'url' => '/images/outfits/' . $filename,
                    'path' => $fileInfo->getPathname(),
                    'name' => ucfirst(pathinfo($filename, PATHINFO_FILENAME)),
                    'collection' => null
                ];
            }
        }
    }

    // Optimized: Process collections with single scan each
    foreach ($featuredCollections as $collectionId => $collectionInfo) {
        $collectionDir = $outfitsDir . $collectionId . '/';
        if (is_dir($collectionDir)) {
            $collectionOutfits = [];
            $iterator = new DirectoryIterator($collectionDir);

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) continue;

                $extension = strtolower($fileInfo->getExtension());
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $filename = $fileInfo->getFilename();
                    $collectionOutfits[] = [
                        'filename' => $filename,
                        'url' => '/images/outfits/' . $collectionId . '/' . $filename,
                        'path' => $fileInfo->getPathname(),
                        'name' => ucfirst(pathinfo($filename, PATHINFO_FILENAME)),
                        'collection' => $collectionId
                    ];
                }
            }

            if (!empty($collectionOutfits)) {
                $collections[$collectionId] = array_merge($collectionInfo, [
                    'id' => $collectionId,
                    'outfits' => $collectionOutfits
                ]);
            }
        }
    }

    // Cache the results
    if ($cacheEnabled) {
        $cacheData = [
            'collections' => $collections,
            'outfitOptions' => $outfitOptions,
            'cached_at' => time()
        ];
        @file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));
    }
}

$error = '';
$success = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set content type to JSON for AJAX handling
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        if (ob_get_level()) {
            ob_clean();
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
    }

    try {
        if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        // Get privacy setting
        $isPublic = !isset($_POST['make_private']) || $_POST['make_private'] !== '1';
        $creditCost = $isPublic ? 0.5 : 1.0;

        if ($credits < $creditCost) {
            throw new Exception('Insufficient credits. Please purchase more credits to continue.');
        }

        // Rate limiting check
        $pdo = Database::getInstance();
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

        // Check user rate limit (based on tier)
        $userTier = $credits >= 50 ? 'premium' : ($credits >= 10 ? 'paid' : 'free');
        $userLimit = match($userTier) {
            'premium' => 20,  // 20 per hour for premium users
            'paid' => 10,     // 10 per hour for paid users
            'free' => 3       // 3 per hour for free users
        };

        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM generations
            WHERE user_id = ?
            AND created_at > datetime("now", "-1 hour")
        ');
        $stmt->execute([$user['id']]);
        $recentGenerations = (int)$stmt->fetchColumn();

        if ($recentGenerations >= $userLimit) {
            throw new Exception("Rate limit exceeded. You can generate up to {$userLimit} images per hour. Please try again later.");
        }

        // Check IP rate limit (anti-abuse) - simplified approach
        if ($ipAddress) {
            $ipKey = 'ip_' . md5($ipAddress);
            $currentHour = time() - (time() % 3600); // Round to current hour

            // Check current requests for this IP in this hour
            $stmt = $pdo->prepare('
                SELECT requests FROM rate_limits
                WHERE id = ? AND window_start = ?
            ');
            $stmt->execute([$ipKey, $currentHour]);
            $currentRequests = (int)$stmt->fetchColumn();

            if ($currentRequests >= 30) {
                throw new Exception("Too many requests from this network. Please try again later.");
            }

            // Track this request
            $pdo->prepare('
                INSERT OR REPLACE INTO rate_limits (id, requests, window_start, created_at)
                VALUES (?, ? + 1, ?, CURRENT_TIMESTAMP)
            ')->execute([$ipKey, $currentRequests, $currentHour]);
        }

        // Handle person photo selection
        $standingPhotos = [];
        $personSource = $_POST['person_source'] ?? '';

        if ($personSource === 'stored' && !empty($_POST['stored_photo_id'])) {
            // Use stored photo
            $storedPhotoId = (int) $_POST['stored_photo_id'];
            $storedPhoto = UserPhotoService::getPhotoById($storedPhotoId, $user['id']);

            if (!$storedPhoto) {
                throw new Exception('Selected photo not found');
            }

            $standingPhotos[] = [
                'tmp_name' => $storedPhoto['file_path'],
                'type' => $storedPhoto['mime_type'],
                'size' => $storedPhoto['file_size'],
                'name' => $storedPhoto['original_name']
            ];

        } elseif ($personSource === 'upload' && isset($_FILES['person_upload'])) {
            // Use uploaded photo
            if ($_FILES['person_upload']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Failed to upload person photo');
            }

            $standingPhotos[] = $_FILES['person_upload'];

            // Save if requested
            if (isset($_POST['save_photo']) && $_POST['save_photo'] === '1') {
                try {
                    $setPrimary = UserPhotoService::getUserPhotoCount($user['id']) === 0;
                    $photoResult = UserPhotoService::uploadUserPhoto($user['id'], $_FILES['person_upload'], $setPrimary);
                    // Update path for processing
                    $standingPhotos[0]['tmp_name'] = UserPhotoService::getPhotoPath($photoResult['filename']);
                } catch (Exception $e) {
                    Logger::error('Failed to save user photo', ['error' => $e->getMessage()]);
                }
            }
        } else {
            throw new Exception('Please select or upload a photo of yourself');
        }

        // Handle outfit selection
        $outfitPhoto = [];
        $outfitSource = $_POST['outfit_source'] ?? '';

        if ($outfitSource === 'default' && !empty($_POST['default_outfit_path'])) {
            // Use default outfit
            $defaultOutfitPath = $_POST['default_outfit_path'];
            if (!file_exists($defaultOutfitPath)) {
                throw new Exception('Selected outfit not found');
            }

            $outfitPhoto = [
                'tmp_name' => $defaultOutfitPath,
                'type' => mime_content_type($defaultOutfitPath),
                'size' => filesize($defaultOutfitPath),
                'name' => basename($defaultOutfitPath)
            ];

        } elseif ($outfitSource === 'upload' && isset($_FILES['outfit_upload'])) {
            // Use uploaded outfit
            if ($_FILES['outfit_upload']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Failed to upload outfit photo');
            }

            $outfitPhoto = $_FILES['outfit_upload'];
        } else {
            throw new Exception('Please select or upload an outfit');
        }

        // DIRECT PROCESSING ONLY - No more background jobs!
        try {
            // Set execution time for processing
            set_time_limit(60);

            // Process immediately
            $aiService = new AIService();
            $result = $aiService->generateFit($user['id'], $standingPhotos, $outfitPhoto, $isPublic);

            $costText = $isPublic ? '0.5 credits' : '1 credit';
            $privacyText = $isPublic ? 'public' : 'private';
            $success = "Your {$privacyText} photo is ready! ({$costText} used)";

            // For AJAX requests, return immediate result
            if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                echo json_encode([
                    'success' => true,
                    'message' => $success,
                    'result_url' => $result['result_url'],
                    'share_url' => $result['share_url'] ?? null,
                    'is_public' => $isPublic,
                    'credit_cost' => $creditCost,
                    'processing_time' => $result['processing_time'] ?? 0
                ]);
                exit;
            }

        } catch (Exception $e) {
            throw $e; // No fallback - direct processing only
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
        Logger::error('Generation error', ['error' => $error, 'user_id' => $user['id']]);

        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            echo json_encode([
                'success' => false,
                'error' => $error
            ]);
            exit;
        }
    }
}

$csrfToken = Session::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Your Fit - <?= Config::get('app_name') ?></title>

    <?php
    // Preload first 4 outfit images for faster rendering
    if (!empty($outfitOptions)) {
        $preloadCount = min(4, count($outfitOptions));
        for ($i = 0; $i < $preloadCount; $i++) {
            echo '<link rel="preload" as="image" href="' . htmlspecialchars($outfitOptions[$i]['url']) . '">' . "\n    ";
        }
    }
    ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #ffeef8 0%, #ffe0f7 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .page-header {
            background: linear-gradient(45deg, #ff6b9d, #4ecdc4);
            color: white;
            padding: 25px 30px;
            text-align: center;
        }

        .page-header h1 {
            font-size: 2em;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .page-header p {
            font-size: 1.1em;
            opacity: 0.95;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.3;
            font-weight: 400;
        }

        .form-container {
            padding: 40px 30px;
        }

        /* New Selection System Styles */
        .selection-section {
            margin-bottom: 40px;
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            border: 2px solid #e9ecef;
        }

        .selection-section h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.3em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .selection-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: white;
            padding: 5px;
            border-radius: 10px;
        }

        .tab-button {
            flex: 1;
            padding: 12px 20px;
            border: 3px solid #667eea;
            background: transparent;
            color: #6c757d;
            font-size: 1em;
            font-weight: 500;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .tab-button.active {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: 3px solid #4a5cb8;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Gallery Grid */
        .selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .selection-item {
            position: relative;
            cursor: pointer;
        }

        .selection-item input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .selection-item label {
            display: block;
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            border: 3px solid transparent;
            transition: all 0.3s ease;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            cursor: pointer;
        }

        .selection-item input[type="radio"]:checked + label {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .selection-item input[type="radio"]:checked + label::after {
            content: '✓';
            position: absolute;
            top: 8px;
            right: 8px;
            background: #667eea;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
            z-index: 10;
        }

        .selection-item img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            object-position: center -10px;
            display: block;
        }

        .selection-name {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px;
            font-size: 0.8em;
            text-overflow: ellipsis;
            white-space: nowrap;
            overflow: hidden;
        }

        /* Upload Area */
        .upload-area {
            border: 3px dashed #dee2e6;
            border-radius: 10px;
            padding: 40px 20px;
            text-align: center;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .upload-area:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .upload-area.has-file {
            border-color: #27ae60;
            background: #f0fff4;
        }

        .upload-area input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            cursor: pointer;
        }

        .upload-icon {
            font-size: 3em;
            margin-bottom: 10px;
            color: #adb5bd;
        }

        .upload-text {
            color: #495057;
            font-size: 1.1em;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .upload-hint {
            color: #6c757d;
            font-size: 0.9em;
        }

        .upload-preview {
            margin-top: 15px;
            display: none;
        }

        .upload-preview img {
            max-width: 150px;
            max-height: 150px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* Privacy Options */
        .privacy-option {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            margin-bottom: 10px;
        }

        .privacy-option:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .privacy-option input[type="radio"] {
            margin-right: 15px;
            margin-top: 2px;
            transform: scale(1.2);
        }

        .privacy-option:has(input[type="radio"]:checked) {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .option-content {
            flex: 1;
        }

        .option-content strong {
            display: block;
            margin-bottom: 5px;
            font-size: 1.1em;
        }

        .option-content p {
            margin: 0;
            color: #6c757d;
            font-size: 0.9em;
            line-height: 1.4;
        }

        /* Fixed Bottom Section */
        .bottom-section {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e9ecef;
            padding: 20px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
            z-index: 1000;
        }

        /* Credits Summary in main content */
        .credits-summary {
            margin-top: 25px;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            border: 2px solid #e9ecef;
        }

        .credits-summary h4 {
            margin: 0 0 15px 0;
            color: #2c3e50;
            font-size: 1.1em;
            text-align: center;
        }

        .credits-summary table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
        }

        .credits-summary td {
            padding: 10px 15px;
            border-bottom: 1px solid #dee2e6;
        }

        .credits-summary td:first-child {
            font-weight: 500;
            color: #495057;
        }

        .credits-summary td:last-child {
            text-align: right;
            font-weight: bold;
            color: #2c3e50;
        }

        .credits-summary .remaining-row td {
            border-bottom: none;
            padding-top: 15px;
            font-size: 1.1em;
        }

        .credits-summary .remaining-row td:last-child {
            color: #27ae60;
        }

        /* Fixed Generate Button */
        .generate-btn-fixed {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            display: block;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 18px 30px;
            font-size: 1.2em;
            font-weight: bold;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .generate-btn-fixed:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .generate-btn-fixed:active:not(:disabled) {
            transform: translateY(1px);
            box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
            background: linear-gradient(45deg, #5a6fd8, #6a42a0);
            border: 4px solid #4a5cb8;
        }

        .generate-btn-fixed:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Add bottom padding to main content to account for fixed section */
        .container {
            margin-bottom: 120px;
        }

        .view-all-btn {
            margin-top: 20px;
            padding: 12px 24px;
            background: linear-gradient(45deg, #4ecdc4, #44a08d);
            color: white;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(78, 205, 196, 0.3);
            font-size: 1em;
        }

        .view-all-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.4);
            background: linear-gradient(45deg, #5fd8cf, #4ecdc4);
        }

        /* Floating Status Messages */
        .status-message {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            padding: 15px 25px;
            border-radius: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 9999;
            max-width: 90%;
            width: auto;
            min-width: 300px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                transform: translateX(-50%) translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }

        .status-message.error {
            background: rgba(254, 226, 226, 0.95);
            color: #c53030;
            border: 2px solid #fed7d7;
        }

        .status-message.success {
            background: rgba(240, 255, 244, 0.95);
            color: #22543d;
            border: 2px solid #c6f6d5;
        }

        .status-message.info {
            background: rgba(227, 242, 253, 0.95);
            color: #1976d2;
            border: 2px solid #90caf9;
        }

        .status-message .close-btn {
            margin-left: 10px;
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .status-message .close-btn:hover {
            opacity: 1;
        }

        /* Loading State */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-content {
            background: white;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 450px;
            width: 90vw;
        }

        .progress-container {
            width: 100%;
            background: #f0f0f0;
            border-radius: 25px;
            padding: 4px;
            margin: 20px 0;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }

        .progress-bar {
            height: 20px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            border-radius: 20px;
            width: 0%;
            transition: width 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: progressShine 2s ease-in-out infinite;
        }

        @keyframes progressShine {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .progress-text {
            margin-top: 15px;
            color: #6c757d;
            font-size: 0.9em;
        }

        .success-content {
            display: none;
        }

        .success-content.show {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .result-preview {
            text-align: center;
            margin-bottom: 25px;
        }

        .result-preview img {
            max-width: 250px;
            max-height: 300px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            transition: transform 0.3s ease;
        }

        .result-preview img:hover {
            transform: scale(1.02);
        }

        .success-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .see-full-size-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .see-full-size-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .generate-another-btn {
            background: linear-gradient(45deg, #4ecdc4, #44a08d);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(78, 205, 196, 0.3);
        }

        .generate-another-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.4);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
                margin-bottom: 100px;
            }

            .selection-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 10px;
            }

            .selection-tabs {
                flex-direction: column;
            }

            .tab-button {
                width: 100%;
            }

            .bottom-section {
                padding: 15px;
            }

            .generate-btn-fixed {
                font-size: 1.1em;
                padding: 16px 25px;
            }

            .credits-summary {
                padding: 15px;
                margin-top: 20px;
            }

            .credits-summary table {
                font-size: 0.9em;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>Check Your Fit</h1>
            <p>Try on outfits from anywhere or anyone - on you, right now</p>
        </div>

        <div class="form-container">
            <form id="tryOnForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="ajax" value="1">

                <!-- Person Photo Selection -->
                <div class="selection-section">
                    <h3>📸 Step 1: Select Your Photo</h3>

                    <div class="selection-tabs">
                        <button type="button" class="tab-button <?= !empty($userPhotos) ? 'active' : '' ?>" data-tab="person-stored">My Photos</button>
                        <button type="button" class="tab-button <?= empty($userPhotos) ? 'active' : '' ?>" data-tab="person-upload">Upload New</button>
                    </div>

                    <!-- Stored Photos Tab -->
                    <div class="tab-content <?= !empty($userPhotos) ? 'active' : '' ?>" id="person-stored">
                        <?php if (!empty($userPhotos)): ?>
                            <div class="selection-grid">
                                <?php foreach ($userPhotos as $photo): ?>
                                    <div class="selection-item">
                                        <input type="radio"
                                               name="person_source"
                                               value="stored"
                                               id="photo_<?= $photo['id'] ?>"
                                               data-photo-id="<?= $photo['id'] ?>">
                                        <label for="photo_<?= $photo['id'] ?>">
                                            <img src="<?= htmlspecialchars($photo['url']) ?>" alt="Your photo">
                                            <div class="selection-name"><?= htmlspecialchars($photo['original_name']) ?></div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="stored_photo_id" id="stored_photo_id" value="">
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 10px;">
                                <div style="font-size: 3em; color: #adb5bd; margin-bottom: 10px;">📷</div>
                                <p style="color: #6c757d;">No saved photos yet. Upload one to get started!</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Upload Tab -->
                    <div class="tab-content <?= empty($userPhotos) ? 'active' : '' ?>" id="person-upload">
                        <div class="upload-area" id="personUploadArea">
                            <input type="file" name="person_upload" accept="image/*" id="personFileInput">
                            <div class="upload-icon">📤</div>
                            <div class="upload-text">Click or drag to upload</div>
                            <div class="upload-hint">JPEG, PNG, or WebP (max 10MB)</div>
                            <div class="upload-preview" id="personPreview"></div>
                        </div>

                        <label style="display: flex; align-items: center; gap: 8px; margin-top: 15px;">
                            <input type="checkbox" name="save_photo" value="1" checked>
                            <span>Save this photo for future use</span>
                        </label>
                    </div>
                </div>

                <!-- Outfit Selection -->
                <div class="selection-section">
                    <h3>👕 Step 2: Choose Your Outfit</h3>

                    <div class="selection-tabs">
                        <button type="button" class="tab-button" data-tab="outfit-default">The Catalogue</button>
                        <button type="button" class="tab-button active" data-tab="outfit-upload">Your Outfit Picture</button>
                    </div>

                    <!-- Default Outfits Tab -->
                    <div class="tab-content" id="outfit-default">
                        <!-- Featured Collections -->
                        <?php if (!empty($collections)): ?>
                            <div style="margin-bottom: 30px;">
                                <?php foreach ($collections as $collection): ?>
                                    <?php if ($collection['featured']): ?>
                                        <div style="margin-bottom: 25px;">
                                            <h3 style="color: #333; font-size: 1.3em; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                                <span><?= htmlspecialchars($collection['icon']) ?></span>
                                                <?= htmlspecialchars($collection['name']) ?>
                                                <span style="background: linear-gradient(45deg, #FFD700, #FFA500); color: white; font-size: 0.7em; padding: 3px 8px; border-radius: 12px; font-weight: 600;">FEATURED</span>
                                            </h3>
                                            <?php if (!empty($collection['description'])): ?>
                                                <p style="color: #666; margin-bottom: 15px; font-size: 0.95em;"><?= htmlspecialchars($collection['description']) ?></p>
                                            <?php endif; ?>
                                            <div class="selection-grid">
                                                <?php foreach ($collection['outfits'] as $cIndex => $outfit): ?>
                                                    <div class="selection-item">
                                                        <input type="radio"
                                                               name="outfit_source"
                                                               value="default"
                                                               id="collection_<?= $collection['id'] ?>_<?= $cIndex ?>"
                                                               data-outfit-path="<?= htmlspecialchars($outfit['path']) ?>">
                                                        <label for="collection_<?= $collection['id'] ?>_<?= $cIndex ?>">
                                                            <img src="<?= htmlspecialchars($outfit['url']) ?>"
                                                                 alt="<?= htmlspecialchars($outfit['name']) ?>"
                                                                 loading="lazy">
                                                            <div class="selection-name"><?= htmlspecialchars($outfit['name']) ?></div>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Regular Outfits -->
                        <?php if (!empty($outfitOptions)): ?>
                            <div>
                                <h3 style="color: #333; font-size: 1.2em; margin-bottom: 15px;">Classic Outfits</h3>
                                <div class="selection-grid">
                                    <?php foreach ($outfitOptions as $index => $outfit): ?>
                                        <div class="selection-item">
                                            <input type="radio"
                                                   name="outfit_source"
                                                   value="default"
                                                   id="outfit_<?= $index ?>"
                                                   data-outfit-path="<?= htmlspecialchars($outfit['path']) ?>">
                                            <label for="outfit_<?= $index ?>">
                                                <img src="<?= htmlspecialchars($outfit['url']) ?>"
                                                     alt="<?= htmlspecialchars($outfit['name']) ?>"
                                                     <?= $index >= 8 ? 'loading="lazy"' : '' ?>>
                                                <div class="selection-name"><?= htmlspecialchars($outfit['name']) ?></div>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="default_outfit_path" id="default_outfit_path" value="">
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 10px;">
                                <p style="color: #6c757d;">No outfits available. Please upload your own.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Upload Outfit Tab -->
                    <div class="tab-content active" id="outfit-upload">
                        <div style="margin-bottom: 20px; text-align: center; padding: 0 20px;">
                            <p style="color: #495057; font-size: 1.1em; line-height: 1.5; margin: 0;">
                                Upload a photo of an outfit, or you can even upload a picture of someone in an outfit, and try it on you. You can also choose from our catalogue.
                            </p>
                        </div>
                        <div class="upload-area" id="outfitUploadArea">
                            <input type="file" name="outfit_upload" accept="image/*" id="outfitFileInput">
                            <div class="upload-icon">👔</div>
                            <div class="upload-text">Click or drag to upload outfit</div>
                            <div class="upload-hint">JPEG, PNG, or WebP (max 10MB)</div>
                            <div class="upload-preview" id="outfitPreview"></div>
                        </div>
                    </div>
                </div>

                <!-- Privacy Settings -->
                <div class="selection-section">
                    <h3>🔒 Step 3: Privacy Settings</h3>

                    <label class="privacy-option">
                        <input type="radio" name="make_private" value="0" checked>
                        <div class="option-content">
                            <strong>📢 Public (0.5 credits)</strong>
                            <p>Your photo will be shareable and visible in our gallery.</p>
                        </div>
                    </label>

                    <label class="privacy-option">
                        <input type="radio" name="make_private" value="1">
                        <div class="option-content">
                            <strong>🔒 Private (1 credit)</strong>
                            <p>Keep your photo completely private. Only you can see it. You can always download your photos.</p>
                        </div>
                    </label>

                    <!-- Credits Summary Table -->
                    <div class="credits-summary">
                        <h4>Credit Summary</h4>
                        <table>
                            <tr>
                                <td>Total Credits:</td>
                                <td id="totalCredits"><?= number_format($credits, 1) ?></td>
                            </tr>
                            <tr>
                                <td>Deducted Credits:</td>
                                <td id="deductedCredits">0.5</td>
                            </tr>
                            <tr class="remaining-row">
                                <td>Remaining Credits:</td>
                                <td id="remainingCredits"><?= number_format(max(0, $credits - 0.5), 1) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <!-- Fixed bottom section with generate button -->
    <div class="bottom-section">
        <button type="submit" form="tryOnForm" class="generate-btn-fixed" id="generateBtn" <?= $credits < 0.5 ? 'disabled' : '' ?>>
            <span id="generateBtnText">
                <?= $credits < 0.5 ? 'Insufficient Credits' : 'Generate Fit' ?>
            </span>
        </button>

        <?php if ($credits < 1): ?>
            <div style="text-align: center; margin-top: 15px;">
                <a href="/pricing.php" style="color: #667eea; text-decoration: none; font-weight: bold; font-size: 0.9em;">
                    💳 Purchase More Credits
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div id="loadingContent">
                <h3 style="color: #2c3e50; margin-bottom: 10px;">Generating Your Fit</h3>
                <p style="color: #6c757d; margin-bottom: 10px;">Dang you are going to look good...</p>

                <div class="progress-container">
                    <div class="progress-bar" id="progressBar"></div>
                </div>

                <div class="progress-text" id="progressText">0% complete</div>
                <div style="display: flex; gap: 15px; justify-content: center; margin-top: 20px; flex-wrap: nowrap;">
                    <button class="view-all-btn" style="background: linear-gradient(45deg, #ff6b9d, #ee5a6f); white-space: nowrap; min-width: auto; padding: 12px 20px;" onclick="location.reload()">
                        ✨ Generate Another
                    </button>
                    <button class="view-all-btn" style="white-space: nowrap; min-width: auto; padding: 12px 20px;" onclick="window.location.href='/dashboard.php'">
                        👗 View My Fits
                    </button>
                </div>
            </div>

            <div class="success-content" id="successContent">
                <h3 style="color: #2c3e50; margin-bottom: 15px;">🎉 Try-On Complete!</h3>
                <div class="result-preview" id="resultPreview">
                    <!-- Image will be inserted here -->
                </div>
                <div class="success-buttons">
                    <button class="see-full-size-btn" id="seeFullSizeBtn" onclick="">
                        👀 See Full Size
                    </button>
                    <button class="generate-another-btn" onclick="location.reload()">
                        ✨ Generate Another
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // State management - single source of truth
        const appState = {
            personSource: null,     // 'stored' or 'upload'
            personId: null,         // ID for stored photo
            personFile: null,       // File object for upload
            outfitSource: null,     // 'default' or 'upload'
            outfitPath: null,       // Path for default outfit
            outfitFile: null,       // File object for upload
            isPrivate: false,
            creditCost: 0.5
        };

        // Image resizing utility for faster uploads
        async function resizeImage(file, maxWidth = 2048, maxHeight = 2048, quality = 0.92) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        // Calculate new dimensions
                        let width = img.width;
                        let height = img.height;

                        if (width > maxWidth || height > maxHeight) {
                            const ratio = Math.min(maxWidth / width, maxHeight / height);
                            width = Math.round(width * ratio);
                            height = Math.round(height * ratio);
                        }

                        // Create canvas
                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;

                        // Draw resized image
                        const ctx = canvas.getContext('2d');
                        ctx.imageSmoothingEnabled = true;
                        ctx.imageSmoothingQuality = 'high';
                        ctx.drawImage(img, 0, 0, width, height);

                        // Convert to blob
                        canvas.toBlob(
                            (blob) => {
                                if (blob) {
                                    // Create new file from blob
                                    const resizedFile = new File([blob], file.name, {
                                        type: blob.type || 'image/jpeg',
                                        lastModified: Date.now()
                                    });
                                    console.log(`Image resized: ${img.width}x${img.height} → ${width}x${height}, ${Math.round(file.size/1024)}KB → ${Math.round(blob.size/1024)}KB`);
                                    resolve(resizedFile);
                                } else {
                                    resolve(file); // Fallback to original
                                }
                            },
                            file.type === 'image/png' ? 'image/png' : 'image/jpeg',
                            quality
                        );
                    };
                    img.onerror = () => resolve(file); // Fallback to original
                    img.src = e.target.result;
                };
                reader.onerror = () => resolve(file); // Fallback to original
                reader.readAsDataURL(file);
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('tryOnForm');
            const generateBtn = document.getElementById('generateBtn');
            const loadingOverlay = document.getElementById('loadingOverlay');
            const userCredits = <?= $credits ?>;

            // Tab switching
            document.querySelectorAll('.tab-button').forEach(button => {
                button.addEventListener('click', function() {
                    const tabGroup = this.dataset.tab.split('-')[0];
                    const tabName = this.dataset.tab;

                    // Update active tab button
                    document.querySelectorAll(`.tab-button[data-tab^="${tabGroup}"]`).forEach(btn => {
                        btn.classList.remove('active');
                    });
                    this.classList.add('active');

                    // Update active tab content
                    document.querySelectorAll(`.tab-content[id^="${tabGroup}"]`).forEach(content => {
                        content.classList.remove('active');
                    });
                    document.getElementById(tabName).classList.add('active');
                });
            });

            // Person photo selection (stored)
            document.querySelectorAll('input[name="person_source"][value="stored"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.checked) {
                        appState.personSource = 'stored';
                        appState.personId = this.dataset.photoId;
                        appState.personFile = null;
                        document.getElementById('stored_photo_id').value = this.dataset.photoId;

                        // Clear upload area
                        document.getElementById('personFileInput').value = '';
                        document.getElementById('personPreview').style.display = 'none';
                        document.getElementById('personUploadArea').classList.remove('has-file');
                    }
                });
            });

            // Person photo upload
            const personFileInput = document.getElementById('personFileInput');
            const personUploadArea = document.getElementById('personUploadArea');
            const personPreview = document.getElementById('personPreview');

            personFileInput.addEventListener('change', async function() {
                if (this.files && this.files[0]) {
                    let file = this.files[0];

                    // Show immediate preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        personPreview.innerHTML = `<img src="${e.target.result}" alt="Preview"><div style="margin-top: 10px; color: #6c757d;">Optimizing image...</div>`;
                        personPreview.style.display = 'block';
                        personUploadArea.classList.add('has-file');
                    };
                    reader.readAsDataURL(file);

                    // Resize image in background
                    try {
                        file = await resizeImage(file);
                        personPreview.querySelector('div').textContent = 'Image optimized!';
                    } catch (err) {
                        console.error('Failed to resize image:', err);
                    }

                    // Update state
                    appState.personSource = 'upload';
                    appState.personFile = file;
                    appState.personId = null;

                    // Clear stored photo selection
                    document.querySelectorAll('input[name="person_source"][value="stored"]').forEach(radio => {
                        radio.checked = false;
                    });

                    // Auto-switch to upload tab
                    document.querySelector('[data-tab="person-upload"]').click();
                }
            });

            // Outfit selection (default)
            document.querySelectorAll('input[name="outfit_source"][value="default"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.checked) {
                        appState.outfitSource = 'default';
                        appState.outfitPath = this.dataset.outfitPath;
                        appState.outfitFile = null;
                        document.getElementById('default_outfit_path').value = this.dataset.outfitPath;

                        // Clear upload area
                        document.getElementById('outfitFileInput').value = '';
                        document.getElementById('outfitPreview').style.display = 'none';
                        document.getElementById('outfitUploadArea').classList.remove('has-file');
                    }
                });
            });

            // Outfit upload
            const outfitFileInput = document.getElementById('outfitFileInput');
            const outfitUploadArea = document.getElementById('outfitUploadArea');
            const outfitPreview = document.getElementById('outfitPreview');

            outfitFileInput.addEventListener('change', async function() {
                if (this.files && this.files[0]) {
                    let file = this.files[0];

                    // Show immediate preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        outfitPreview.innerHTML = `<img src="${e.target.result}" alt="Preview"><div style="margin-top: 10px; color: #6c757d;">Optimizing image...</div>`;
                        outfitPreview.style.display = 'block';
                        outfitUploadArea.classList.add('has-file');
                    };
                    reader.readAsDataURL(file);

                    // Resize image in background
                    try {
                        file = await resizeImage(file);
                        outfitPreview.querySelector('div').textContent = 'Image optimized!';
                    } catch (err) {
                        console.error('Failed to resize image:', err);
                    }

                    // Update state
                    appState.outfitSource = 'upload';
                    appState.outfitFile = file;
                    appState.outfitPath = null;

                    // Clear default outfit selection
                    document.querySelectorAll('input[name="outfit_source"][value="default"]').forEach(radio => {
                        radio.checked = false;
                    });

                    // Auto-switch to upload tab
                    document.querySelector('[data-tab="outfit-upload"]').click();
                }
            });

            // Privacy settings
            document.querySelectorAll('input[name="make_private"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    appState.isPrivate = this.value === '1';
                    appState.creditCost = appState.isPrivate ? 1 : 0.5;

                    // Update credits table
                    document.getElementById('deductedCredits').textContent = appState.creditCost;
                    document.getElementById('remainingCredits').textContent = Math.max(0, userCredits - appState.creditCost).toFixed(1);

                    // Update button text
                    const btnText = document.getElementById('generateBtnText');
                    if (userCredits < appState.creditCost) {
                        btnText.textContent = 'Insufficient Credits';
                        generateBtn.disabled = true;
                    } else {
                        btnText.textContent = 'Generate Fit';
                        generateBtn.disabled = false;
                    }
                });
            });

            // Form submission
            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                // Validate state
                if (!appState.personSource) {
                    showMessage('Please select or upload a photo of yourself', 'error');
                    return;
                }

                if (!appState.outfitSource) {
                    showMessage('Please select or upload an outfit', 'error');
                    return;
                }

                // Ensure radio buttons match state (for form submission)
                if (appState.personSource === 'upload') {
                    // Create a hidden radio to mark upload as selected
                    let uploadRadio = document.querySelector('input[name="person_source"][value="upload"]');
                    if (!uploadRadio) {
                        uploadRadio = document.createElement('input');
                        uploadRadio.type = 'radio';
                        uploadRadio.name = 'person_source';
                        uploadRadio.value = 'upload';
                        uploadRadio.style.display = 'none';
                        form.appendChild(uploadRadio);
                    }
                    uploadRadio.checked = true;
                }

                if (appState.outfitSource === 'upload') {
                    // Create a hidden radio to mark upload as selected
                    let uploadRadio = document.querySelector('input[name="outfit_source"][value="upload"]');
                    if (!uploadRadio) {
                        uploadRadio = document.createElement('input');
                        uploadRadio.type = 'radio';
                        uploadRadio.name = 'outfit_source';
                        uploadRadio.value = 'upload';
                        uploadRadio.style.display = 'none';
                        form.appendChild(uploadRadio);
                    }
                    uploadRadio.checked = true;
                }

                // Show loading
                loadingOverlay.classList.add('active');
                generateBtn.disabled = true;
                startDirectProcessingProgress();

                // Submit form with resized images
                const formData = new FormData(form);

                // Replace file inputs with resized versions
                if (appState.personSource === 'upload' && appState.personFile) {
                    formData.delete('person_upload');
                    formData.append('person_upload', appState.personFile);
                }

                if (appState.outfitSource === 'upload' && appState.outfitFile) {
                    formData.delete('outfit_upload');
                    formData.append('outfit_upload', appState.outfitFile);
                }

                try {
                    const response = await fetch('/generate.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        showMessage(data.message, 'success');

                        // Update progress to 100%
                        updateProgress(100, 'COMPLETE');

                        // Show success state
                        setTimeout(() => {
                            showSuccessState({
                                result_url: data.result_url,
                                share_url: data.share_url,
                                is_public: data.is_public,
                                processing_time: data.processing_time
                            });
                        }, 500);
                    } else {
                        throw new Error(data.error || 'Generation failed');
                    }
                } catch (error) {
                    stopProgressTimer();
                    showMessage(error.message, 'error');
                    loadingOverlay.classList.remove('active');
                    generateBtn.disabled = false;
                }
            });

            // Enhanced progress function with realistic timing
            let currentProgress = 0;
            let progressInterval = null;
            let startTime = null;

            function updateProgress(progress, text) {
                const progressBar = document.getElementById('progressBar');
                const progressText = document.getElementById('progressText');

                progressBar.style.width = progress + '%';
                progressText.textContent = text;
            }

            function startDirectProcessingProgress() {
                const progressBar = document.getElementById('progressBar');
                const progressText = document.getElementById('progressText');

                // Reset progress
                progressBar.style.width = '0%';
                progressText.textContent = 'Optimizing images...';

                // Simple progress simulation for direct processing
                setTimeout(() => updateProgress(20, 'Uploading to AI...'), 300);
                setTimeout(() => updateProgress(40, 'AI processing your fit...'), 1000);
                setTimeout(() => updateProgress(70, 'Generating result...'), 3000);
                setTimeout(() => updateProgress(90, 'Almost done...'), 8000);
            }

            function startProgressTimer() {
                const progressBar = document.getElementById('progressBar');
                const progressText = document.getElementById('progressText');

                // Reset progress
                currentProgress = 0;
                startTime = Date.now();
                progressBar.style.width = '0%';
                progressText.textContent = 'Starting generation...';

                // Clear any existing timer
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }

                // More realistic progress timing based on actual API performance
                const stages = [
                    { duration: 2000, progress: 15, text: 'Optimizing images...' },
                    { duration: 3000, progress: 30, text: 'Uploading to AI...' },
                    { duration: 8000, progress: 60, text: 'AI analyzing your photo...' },
                    { duration: 12000, progress: 85, text: 'Generating your fit...' },
                    { duration: 5000, progress: 95, text: 'Finalizing result...' }
                ];

                let currentStage = 0;
                let stageStartTime = Date.now();

                progressInterval = setInterval(() => {
                    const totalElapsed = Date.now() - startTime;
                    const stageElapsed = Date.now() - stageStartTime;
                    
                    if (currentStage < stages.length) {
                        const stage = stages[currentStage];
                        const stageProgress = Math.min(100, (stageElapsed / stage.duration) * 100);
                        const totalProgress = (currentStage * 20) + (stageProgress * 0.2);
                        
                        currentProgress = Math.min(95, totalProgress);
                        progressBar.style.width = currentProgress + '%';
                        progressText.textContent = stage.text;

                        // Move to next stage
                        if (stageElapsed >= stage.duration && currentStage < stages.length - 1) {
                            currentStage++;
                            stageStartTime = Date.now();
                        }
                    } else {
                        // Final stage - wait for completion
                        currentProgress = Math.min(95, currentProgress + 0.5);
                        progressBar.style.width = currentProgress + '%';
                        progressText.textContent = 'Almost done...';
                    }

                    // Stop at 95%
                    if (currentProgress >= 95) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                        progressText.textContent = 'Finalizing...';
                    }
                }, 200); // Check every 200ms for smoother updates
            }

            function updateProgressFromJob(job) {
                // Only update if we have real progress from the server
                if (job.status === 'completed') {
                    const progressBar = document.getElementById('progressBar');
                    const progressText = document.getElementById('progressText');

                    // Jump to 100% when complete
                    currentProgress = 100;
                    progressBar.style.width = '100%';
                    progressText.textContent = 'Complete!';

                    // Clear any running interval
                    if (progressInterval) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                    }
                }
            }

            function stopProgressTimer() {
                // No intervals to clear in direct processing mode
                // This function is kept for compatibility
            }

            function showSuccessState(result) {
                stopProgressTimer();
                document.getElementById('loadingContent').style.display = 'none';

                // Show the result image in the modal
                if (result && result.result_url) {
                    const resultPreview = document.getElementById('resultPreview');

                    // Ensure image URL is absolute for proper display
                    let imageUrl = result.result_url;
                    if (imageUrl.startsWith('/')) {
                        // Convert relative URL to absolute
                        imageUrl = window.location.origin + imageUrl;
                    }

                    resultPreview.innerHTML = `<img src="${imageUrl}" alt="AI Generated Try-On Result" style="width: 300px; height: 300px; object-fit: cover; object-position: top; border-radius: 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.15);" onload="console.log('Image loaded successfully')" onerror="console.error('Failed to load image:', this.src)">`;

                    // Set up "See Full Size" button
                    const seeFullSizeBtn = document.getElementById('seeFullSizeBtn');
                    if (result.share_token) {
                        // Go to share page for public photos
                        seeFullSizeBtn.onclick = () => window.location.href = `/share/${result.share_token}?new=1`;
                    } else {
                        // For private photos, also go to share page if available, otherwise open image
                        if (result.share_url) {
                            seeFullSizeBtn.onclick = () => window.location.href = result.share_url;
                        } else {
                            seeFullSizeBtn.onclick = () => window.open(result.result_url, '_blank');
                        }
                    }
                }

                document.getElementById('successContent').classList.add('show');
            }

            // Helper function to show messages
            function showMessage(message, type) {
                // Remove existing messages
                document.querySelectorAll('.status-message').forEach(msg => msg.remove());

                // Create new message
                const messageDiv = document.createElement('div');
                messageDiv.className = `status-message ${type}`;
                messageDiv.innerHTML = `
                    ${type === 'error' ? '❌' : type === 'success' ? '✅' : 'ℹ️'}
                    <span>${message}</span>
                    <button class="close-btn" onclick="this.parentElement.remove()">×</button>
                `;

                // Append to body for fixed positioning
                document.body.appendChild(messageDiv);

                // Auto-remove after time based on type
                const autoRemoveTime = type === 'success' ? 5000 : type === 'error' ? 8000 : 6000;
                setTimeout(() => {
                    if (messageDiv.parentElement) {
                        messageDiv.remove();
                    }
                }, autoRemoveTime);
            }

            // Poll job status with adaptive polling rate
            async function pollJobStatus(jobId) {
                const maxPolls = 120;
                let pollCount = 0;
                let pollDelay = 700; // Start with 700ms

                const poll = async () => {
                    if (pollCount >= maxPolls) {
                        stopProgressTimer();
                        showMessage('Processing is taking longer than expected', 'error');
                        loadingOverlay.classList.remove('active');
                        generateBtn.disabled = false;
                        return;
                    }

                    try {
                        const response = await fetch(`/api/job_status.php?job_id=${jobId}`);
                        const data = await response.json();

                        if (data.job) {
                            // Update progress with real data
                            updateProgressFromJob(data.job);

                            if (data.job.status === 'completed') {
                                // Success! Show success state with result
                                showSuccessState(data.job.result);
                            } else if (data.job.status === 'failed') {
                                stopProgressTimer();
                                throw new Error(data.job.error || 'Generation failed');
                            } else {
                                pollCount++;
                                // Adaptive polling: increase delay over time (backoff)
                                if (pollCount > 10) {
                                    pollDelay = Math.min(2000, pollDelay + 100);
                                }
                                setTimeout(poll, pollDelay);
                            }
                        } else {
                            pollCount++;
                            setTimeout(poll, pollDelay);
                        }
                    } catch (error) {
                        stopProgressTimer();
                        showMessage(error.message, 'error');
                        loadingOverlay.classList.remove('active');
                        generateBtn.disabled = false;
                    }
                };

                // Start polling after a short delay
                setTimeout(poll, 1000);
            }

            // Show private result (when no share token)
            function showPrivateResult(result) {
                const resultHtml = `
                    <div class="selection-section" style="text-align: center;">
                        <h3>🔒 Your Private AI-Generated Try-On!</h3>
                        <img src="${result.result_url}" style="max-width: 100%; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                        <p style="margin-top: 20px; color: #6c757d;">This is a private photo only visible to you.</p>
                        <button onclick="location.reload()" style="margin-top: 15px; padding: 12px 30px; background: linear-gradient(45deg, #667eea, #764ba2); color: white; border: none; border-radius: 25px; font-weight: 600; cursor: pointer;">
                            Generate Another
                        </button>
                    </div>
                `;

                const formContainer = document.querySelector('.form-container');
                formContainer.insertAdjacentHTML('afterbegin', resultHtml);
            }
        });
    </script>
</body>
</html>