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

// Get default outfit options
$outfitOptions = [];
$outfitsDir = __DIR__ . '/images/outfits/';

if (is_dir($outfitsDir) && is_readable($outfitsDir)) {
    $outfitFiles = glob($outfitsDir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE);
    foreach ($outfitFiles as $filePath) {
        if (is_readable($filePath)) {
            $filename = basename($filePath);
            $outfitOptions[] = [
                'filename' => $filename,
                'url' => '/images/outfits/' . $filename,
                'path' => $filePath,
                'name' => ucfirst(pathinfo($filename, PATHINFO_FILENAME))
            ];
        }
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

        // Queue for background processing
        $jobId = BackgroundJobService::queueGeneration($user['id'], $standingPhotos, $outfitPhoto, $isPublic);

        $costText = $isPublic ? '0.5 credits' : '1 credit';
        $privacyText = $isPublic ? 'public' : 'private';
        $success = "Generation started! Processing your {$privacyText} photo ({$costText}).";

        // For AJAX requests, return JSON response
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            echo json_encode([
                'success' => true,
                'message' => $success,
                'job_id' => $jobId,
                'background' => true,
                'is_public' => $isPublic,
                'credit_cost' => $creditCost
            ]);
            exit;
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
    <title>AI Virtual Try-On - <?= Config::get('app_name') ?></title>
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

        .form-container {
            padding: 40px 20px;
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
            border: none;
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

        /* Generate Button */
        .generate-btn {
            width: 100%;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 18px 30px;
            font-size: 1.2em;
            font-weight: bold;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 30px;
        }

        .generate-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .generate-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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

        /* Status Messages */
        .status-message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-message.error {
            background: #fee;
            color: #c53030;
            border: 1px solid #fed7d7;
        }

        .status-message.success {
            background: #f0fff4;
            color: #22543d;
            border: 1px solid #c6f6d5;
        }

        .status-message.info {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #90caf9;
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

        .generate-another-btn {
            background: linear-gradient(45deg, #4ecdc4, #44a08d);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.3s ease;
        }

        .generate-another-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(78, 205, 196, 0.4);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
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
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <div class="container">
        <div style="background: linear-gradient(45deg, #ff6b9d, #4ecdc4); color: white; padding: 30px 20px; text-align: center; border-radius: 15px; margin-bottom: 20px;">
            <h1 style="font-size: 2em; margin-bottom: 10px;">AI Virtual Try-On</h1>
            <p style="opacity: 0.9;">Transform your style with AI - upload photos and see how outfits look on you!</p>
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
                        <button type="button" class="tab-button active" data-tab="outfit-default">Default Outfits</button>
                        <button type="button" class="tab-button" data-tab="outfit-upload">Upload Outfit</button>
                    </div>

                    <!-- Default Outfits Tab -->
                    <div class="tab-content active" id="outfit-default">
                        <?php if (!empty($outfitOptions)): ?>
                            <div class="selection-grid">
                                <?php foreach ($outfitOptions as $index => $outfit): ?>
                                    <div class="selection-item">
                                        <input type="radio"
                                               name="outfit_source"
                                               value="default"
                                               id="outfit_<?= $index ?>"
                                               data-outfit-path="<?= htmlspecialchars($outfit['path']) ?>">
                                        <label for="outfit_<?= $index ?>">
                                            <img src="<?= htmlspecialchars($outfit['url']) ?>" alt="<?= htmlspecialchars($outfit['name']) ?>">
                                            <div class="selection-name"><?= htmlspecialchars($outfit['name']) ?></div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="default_outfit_path" id="default_outfit_path" value="">
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 10px;">
                                <p style="color: #6c757d;">No default outfits available. Please upload your own.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Upload Outfit Tab -->
                    <div class="tab-content" id="outfit-upload">
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
                            <p>Keep your photo completely private. Only you can see it.</p>
                        </div>
                    </label>
                </div>

                <button type="submit" class="generate-btn" id="generateBtn" <?= $credits < 0.5 ? 'disabled' : '' ?>>
                    <span id="generateBtnText">
                        <?= $credits < 0.5 ? 'Insufficient Credits' : '✨ Generate AI Try-On (0.5 Credits)' ?>
                    </span>
                </button>
            </form>

            <?php if ($credits < 1): ?>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="/pricing.php" style="color: #667eea; text-decoration: none; font-weight: bold;">
                        💳 Purchase More Credits
                    </a>
                </div>
            <?php endif; ?>
        </div>
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
                <button class="view-all-btn" onclick="window.location.href='/dashboard.php'">
                    👗 View All Fits
                </button>
            </div>

            <div class="success-content" id="successContent">
                <h3 style="color: #2c3e50; margin-bottom: 10px;">🎉 Try-On Complete!</h3>
                <p style="color: #6c757d; margin-bottom: 20px;">Your AI virtual try-on has been generated successfully.</p>
                <button class="generate-another-btn" onclick="location.reload()">
                    ✨ Generate Another
                </button>
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

            personFileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const file = this.files[0];

                    // Update state
                    appState.personSource = 'upload';
                    appState.personFile = file;
                    appState.personId = null;

                    // Clear stored photo selection
                    document.querySelectorAll('input[name="person_source"][value="stored"]').forEach(radio => {
                        radio.checked = false;
                    });

                    // Show preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        personPreview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                        personPreview.style.display = 'block';
                        personUploadArea.classList.add('has-file');
                    };
                    reader.readAsDataURL(file);

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

            outfitFileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const file = this.files[0];

                    // Update state
                    appState.outfitSource = 'upload';
                    appState.outfitFile = file;
                    appState.outfitPath = null;

                    // Clear default outfit selection
                    document.querySelectorAll('input[name="outfit_source"][value="default"]').forEach(radio => {
                        radio.checked = false;
                    });

                    // Show preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        outfitPreview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                        outfitPreview.style.display = 'block';
                        outfitUploadArea.classList.add('has-file');
                    };
                    reader.readAsDataURL(file);

                    // Auto-switch to upload tab
                    document.querySelector('[data-tab="outfit-upload"]').click();
                }
            });

            // Privacy settings
            document.querySelectorAll('input[name="make_private"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    appState.isPrivate = this.value === '1';
                    appState.creditCost = appState.isPrivate ? 1 : 0.5;

                    // Update button text
                    const btnText = document.getElementById('generateBtnText');
                    if (userCredits < appState.creditCost) {
                        btnText.textContent = 'Insufficient Credits';
                        generateBtn.disabled = true;
                    } else {
                        const privacyText = appState.isPrivate ? 'Private' : 'Public';
                        btnText.textContent = `✨ Generate AI Try-On (${appState.creditCost} Credits) - ${privacyText}`;
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
                startProgressTimer();

                // Submit form
                const formData = new FormData(form);

                try {
                    const response = await fetch('/generate.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        showMessage(data.message, 'success');
                        if (data.job_id) {
                            pollJobStatus(data.job_id);
                        }
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

            // Progress timer function
            let progressTimer = null;
            let progressStartTime = null;

            function startProgressTimer() {
                progressStartTime = Date.now();
                const progressBar = document.getElementById('progressBar');
                const progressText = document.getElementById('progressText');

                // Reset progress
                progressBar.style.width = '0%';
                progressText.textContent = '0% complete';

                // Clear any existing timer
                if (progressTimer) clearInterval(progressTimer);

                progressTimer = setInterval(() => {
                    const elapsed = Date.now() - progressStartTime;
                    const totalTime = 25000; // 25 seconds
                    const progress = Math.min((elapsed / totalTime) * 100, 100);

                    progressBar.style.width = progress + '%';

                    if (progress >= 100) {
                        progressText.textContent = 'finishing touches being applied...';
                        clearInterval(progressTimer);
                        progressTimer = null;
                    } else {
                        progressText.textContent = Math.round(progress) + '% complete';
                    }
                }, 200); // Update every 200ms for smooth animation
            }

            function stopProgressTimer() {
                if (progressTimer) {
                    clearInterval(progressTimer);
                    progressTimer = null;
                }
            }

            function showSuccessState() {
                stopProgressTimer();
                document.getElementById('loadingContent').style.display = 'none';
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
                `;

                // Insert at top of form container
                const formContainer = document.querySelector('.form-container');
                formContainer.insertBefore(messageDiv, formContainer.firstChild);

                // Auto-remove success messages
                if (type === 'success') {
                    setTimeout(() => messageDiv.remove(), 5000);
                }
            }

            // Poll job status
            async function pollJobStatus(jobId) {
                const maxPolls = 120;
                let pollCount = 0;

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

                        if (data.job && data.job.status === 'completed') {
                            // Success! Show success state
                            showSuccessState();

                            if (data.job.result && data.job.result.share_token) {
                                // Redirect to share page immediately
                                window.location.href = `/share/${data.job.result.share_token}?new=1`;
                            } else if (data.job.result && data.job.result.result_url) {
                                // For private photos, show success state and allow "Generate Another"
                                // Success state is already shown, user can click "Generate Another"
                            }
                        } else if (data.job && data.job.status === 'failed') {
                            stopProgressTimer();
                            throw new Error(data.job.error || 'Generation failed');
                        } else {
                            pollCount++;
                            setTimeout(poll, 5000);
                        }
                    } catch (error) {
                        stopProgressTimer();
                        showMessage(error.message, 'error');
                        loadingOverlay.classList.remove('active');
                        generateBtn.disabled = false;
                    }
                };

                setTimeout(poll, 2000);
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