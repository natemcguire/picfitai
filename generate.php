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


$error = '';
$success = '';
$result = null;

// Handle POST request separately from HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set content type to JSON for AJAX handling
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        // Clean any previous output and set JSON headers
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

        // Handle both stored photos and uploaded photos
        $standingPhotos = [];
        $useStoredPhoto = isset($_POST['use_stored_photo']) && $_POST['use_stored_photo'] === '1';

        // Debug logging to see what's being submitted
        Logger::info('Form submission debug', [
            'use_stored_photo' => $_POST['use_stored_photo'] ?? 'not set',
            'stored_photo_id' => $_POST['stored_photo_id'] ?? 'not set',
            'has_standing_photos_files' => isset($_FILES['standing_photos']) ? 'yes' : 'no',
            'standing_photos_count' => isset($_FILES['standing_photos']['name']) ? count(array_filter($_FILES['standing_photos']['name'])) : 0,
            'useStoredPhoto_boolean' => $useStoredPhoto ? 'true' : 'false'
        ]);

        if ($useStoredPhoto) {
            // Use stored photo
            if (empty($_POST['stored_photo_id'])) {
                throw new Exception('Please select a stored photo or upload a new one');
            }

            $storedPhotoId = (int) $_POST['stored_photo_id'];
            $storedPhoto = UserPhotoService::getPhotoById($storedPhotoId, $user['id']);

            if (!$storedPhoto) {
                throw new Exception('Selected photo not found');
            }

            // Convert stored photo to the format expected by AIService
            $standingPhotos[] = [
                'tmp_name' => $storedPhoto['file_path'],
                'type' => $storedPhoto['mime_type'],
                'size' => $storedPhoto['file_size'],
                'name' => $storedPhoto['original_name']
            ];
        } else {
            // Use uploaded photos
            $validationErrors = AIService::validateUploadedFiles($_FILES['standing_photos'] ?? [], $_FILES['outfit_photo'] ?? []);

            if (!empty($validationErrors)) {
                throw new Exception(implode(', ', $validationErrors));
            }

            // Check if user wants to save the photo
            $savePhoto = isset($_POST['save_photo']) && $_POST['save_photo'] === '1';

            if (isset($_FILES['standing_photos']['tmp_name']) && is_array($_FILES['standing_photos']['tmp_name'])) {
                for ($i = 0; $i < count($_FILES['standing_photos']['tmp_name']); $i++) {
                    if (!empty($_FILES['standing_photos']['tmp_name'][$i])) {
                        $standingPhotos[] = [
                            'tmp_name' => $_FILES['standing_photos']['tmp_name'][$i],
                            'type' => $_FILES['standing_photos']['type'][$i],
                            'size' => $_FILES['standing_photos']['size'][$i],
                            'name' => $_FILES['standing_photos']['name'][$i]
                        ];

                        // Save the first photo to user_photos if requested
                        if ($savePhoto && $i === 0) {
                            try {
                                $uploadedFile = [
                                    'tmp_name' => $_FILES['standing_photos']['tmp_name'][$i],
                                    'type' => $_FILES['standing_photos']['type'][$i],
                                    'size' => $_FILES['standing_photos']['size'][$i],
                                    'name' => $_FILES['standing_photos']['name'][$i],
                                    'error' => $_FILES['standing_photos']['error'][$i]
                                ];

                                // Set as primary if user has no photos yet
                                $setPrimary = UserPhotoService::getUserPhotoCount($user['id']) === 0;
                                $photoResult = UserPhotoService::uploadUserPhoto($user['id'], $uploadedFile, $setPrimary);

                                // Update the standing photo path to use the saved location for background processing
                                $savedPhotoPath = UserPhotoService::getPhotoPath($photoResult['filename']);
                                $standingPhotos[$i]['tmp_name'] = $savedPhotoPath;

                                Logger::info('User photo saved to My Photos and path updated for processing', [
                                    'user_id' => $user['id'],
                                    'filename' => $photoResult['filename'],
                                    'original_path' => $_FILES['standing_photos']['tmp_name'][$i],
                                    'new_path' => $savedPhotoPath
                                ]);
                            } catch (Exception $e) {
                                Logger::error('Failed to save user photo', [
                                    'user_id' => $user['id'],
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }
                }
            }
        }

        if (empty($standingPhotos)) {
            throw new Exception('Please provide a photo of yourself');
        }

        $outfitPhoto = $_FILES['outfit_photo'] ?? [];

        // Always use background processing now
        $jobId = BackgroundJobService::queueGeneration($user['id'], $standingPhotos, $outfitPhoto, $isPublic);

        $costText = $isPublic ? '0.5 credits' : '1 credit';
        $privacyText = $isPublic ? 'public' : 'private';
        $success = "Generation started! Processing your {$privacyText} photo ({$costText}).";

        // For AJAX requests, return JSON response with job ID
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

        // For AJAX requests, return JSON error response
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: #2c3e50;
            color: white;
            padding: 30px 20px;
            text-align: center;
        }

        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .credits {
            background: #27ae60;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            display: inline-block;
            font-weight: bold;
        }

        .form-container {
            padding: 40px 20px;
        }

        .upload-section {
            margin-bottom: 30px;
        }

        .upload-section h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .file-upload {
            position: relative;
            display: block;
            width: 100%;
            min-height: 150px;
            border: 3px dashed #bdc3c7;
            border-radius: 10px;
            background: #f8f9fa;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 30px 20px;
        }

        .file-upload:hover {
            border-color: #3498db;
            background: #e3f2fd;
        }

        .file-upload.drag-over {
            border-color: #2ecc71;
            background: #e8f5e8;
        }

        .file-upload input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .upload-icon {
            font-size: 3em;
            margin-bottom: 10px;
            color: #95a5a6;
        }

        .upload-text {
            color: #7f8c8d;
            font-size: 1.1em;
            margin-bottom: 5px;
        }

        .upload-hint {
            color: #95a5a6;
            font-size: 0.9em;
        }

        .file-preview {
            margin-top: 15px;
            display: none;
        }

        .file-preview img {
            max-width: 100px;
            max-height: 100px;
            border-radius: 8px;
            margin: 5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

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
            margin-top: 20px;
        }

        .generate-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .generate-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-error {
            background: #fee;
            color: #c53030;
            border: 1px solid #fed7d7;
        }

        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border: 1px solid #c6f6d5;
        }

        .result-container {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .result-image {
            max-width: 100%;
            border-radius: 10px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .loading {
            display: none;
            text-align: center;
            margin-top: 20px;
        }

        .background-status {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 20px;
            color: #1976d2;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4caf50;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .photo-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-radius: 10px;
            overflow: hidden;
            background: #ecf0f1;
        }

        .tab-btn {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: #ecf0f1;
            color: #7f8c8d;
            font-size: 1em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
        }

        .tab-btn:hover:not(.active) {
            background: #d5d8dc;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .stored-photos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .stored-photo {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid transparent;
        }

        .stored-photo:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .stored-photo.selected {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .stored-photo.primary {
            border-color: #27ae60;
        }

        .stored-photo img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .primary-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #27ae60;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7em;
            font-weight: bold;
        }

        .photo-name {
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

        .stored-photos-hint {
            color: #7f8c8d;
            font-size: 0.9em;
            text-align: center;
        }

        .stored-photos-hint a {
            color: #667eea;
            text-decoration: none;
        }

        .stored-photos-hint a:hover {
            text-decoration: underline;
        }

        .save-photo-option {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ecf0f1;
        }

        .save-photo-option label {
            font-size: 0.9em;
            color: #7f8c8d;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .privacy-section {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }

        .privacy-section h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .privacy-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .privacy-option {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
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

        .privacy-option input[type="radio"]:checked + .option-content {
            color: #2c3e50;
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

        .previous-generations-section {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }

        .previous-generations-section h3 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 1.2em;
        }

        .generation-thumbnails {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .generation-thumbnail {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #dee2e6;
        }

        .generation-thumbnail:hover {
            transform: scale(1.05);
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .generation-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .thumbnail-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0) 50%, rgba(0,0,0,0.6) 100%);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 6px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .generation-thumbnail:hover .thumbnail-overlay {
            opacity: 1;
        }

        .thumbnail-date {
            font-size: 0.7em;
            color: white;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0,0,0,0.8);
        }

        .thumbnail-public,
        .thumbnail-private {
            font-size: 0.8em;
            align-self: flex-end;
        }

        @media (max-width: 600px) {
            .generation-thumbnails {
                grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
                gap: 8px;
            }
        }

        @media (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
            }

            .header h1 {
                font-size: 1.5em;
            }

            .file-upload {
                min-height: 120px;
                padding: 20px 15px;
            }

            .upload-icon {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>AI Virtual Try-On</h1>
            <div style="display: flex; align-items: center; gap: 15px;">
                <a href="/dashboard.php" style="color: white; text-decoration: none; padding: 8px 16px; border: 1px solid rgba(255,255,255,0.3); border-radius: 15px; font-size: 0.9em;">
                    📊 Dashboard
                </a>
                <div class="credits">💎 <?= number_format($credits, ($credits == floor($credits)) ? 0 : 1) ?> Credits</div>
            </div>
        </div>

        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($result && isset($result['result_url'])): ?>
                <div class="result-container">
                    <h3>Your AI-Generated Try-On Result!</h3>
                    <img src="<?= htmlspecialchars($result['result_url']) ?>" alt="AI Generated Try-On" class="result-image">
                    <p><small>Generated in <?= $result['processing_time'] ?? 0 ?> seconds</small></p>
                </div>
            <?php endif; ?>

            <form id="tryOnForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="upload-section">
                    <h3>📸 Your Photo</h3>

                    <!-- Photo Selection Tabs -->
                    <div class="photo-tabs">
                        <?php if (!empty($userPhotos)): ?>
                            <button type="button" class="tab-btn active" data-tab="stored">Use Saved Photo</button>
                            <button type="button" class="tab-btn" data-tab="upload">Upload New</button>
                        <?php else: ?>
                            <button type="button" class="tab-btn active" data-tab="upload">Upload Photo</button>
                        <?php endif; ?>
                    </div>

                    <!-- Stored Photos Tab -->
                    <?php if (!empty($userPhotos)): ?>
                        <div class="tab-content active" id="stored-tab">
                            <input type="hidden" name="use_stored_photo" value="1">
                            <input type="hidden" name="stored_photo_id" value="<?= $userPhotos[0]['id'] ?>">

                            <div class="stored-photos-grid">
                                <?php foreach ($userPhotos as $photo): ?>
                                    <div class="stored-photo <?= $photo['is_primary'] ? 'primary' : '' ?>" data-photo-id="<?= $photo['id'] ?>">
                                        <img src="<?= htmlspecialchars($photo['url']) ?>" alt="Your photo" loading="lazy">
                                        <?php if ($photo['is_primary']): ?>
                                            <div class="primary-badge">Primary</div>
                                        <?php endif; ?>
                                        <div class="photo-name"><?= htmlspecialchars($photo['original_name']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <p class="stored-photos-hint">Select a photo to use, or <a href="/dashboard.php">manage your photos</a></p>
                        </div>
                    <?php endif; ?>

                    <!-- Upload Tab -->
                    <div class="tab-content <?= empty($userPhotos) ? 'active' : '' ?>" id="upload-tab">
                        <label class="file-upload" id="standingUpload">
                            <input type="file" name="standing_photos[]" accept="image/*" multiple>
                            <div class="upload-icon">📷</div>
                            <div class="upload-text">Tap to upload your photo</div>
                            <div class="upload-hint">JPEG, PNG, or WebP (max 10MB each)</div>
                            <div class="file-preview" id="standingPreview"></div>
                        </label>

                        <div class="save-photo-option">
                            <label style="display: flex; align-items: center; gap: 8px; margin-top: 15px;">
                                <input type="checkbox" name="save_photo" value="1" checked>
                                <span>Save this photo for future use</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="upload-section">
                    <h3>👕 Upload Outfit Photo</h3>
                    <label class="file-upload" id="outfitUpload">
                        <input type="file" name="outfit_photo" accept="image/*" required>
                        <div class="upload-icon">👔</div>
                        <div class="upload-text">Tap to upload outfit</div>
                        <div class="upload-hint">JPEG, PNG, or WebP (max 10MB)</div>
                        <div class="file-preview" id="outfitPreview"></div>
                    </label>
                </div>

                <?php if (!empty($userPhotos)): ?>
                <div class="previous-generations-section">
                    <h3>📸 Your Saved Photos</h3>
                    <p style="color: #7f8c8d; font-size: 0.9em; margin-bottom: 15px;">
                        Click any photo to use it for generation
                    </p>
                    <div class="generation-thumbnails">
                        <?php foreach ($userPhotos as $photo): ?>
                            <div class="generation-thumbnail user-photo-thumbnail"
                                 data-photo-id="<?= htmlspecialchars($photo['id']) ?>"
                                 data-filename="<?= htmlspecialchars($photo['filename']) ?>"
                                 title="<?= htmlspecialchars($photo['original_name']) ?>">
                                <img src="<?= htmlspecialchars($photo['url']) ?>"
                                     alt="<?= htmlspecialchars($photo['original_name']) ?>"
                                     loading="lazy">
                                <div class="thumbnail-overlay">
                                    <div class="thumbnail-date">
                                        <?= date('M j', strtotime($photo['created_at'])) ?>
                                    </div>
                                    <?php if ($photo['is_primary']): ?>
                                        <div class="thumbnail-primary">⭐</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="privacy-section">
                    <h3>🔒 Privacy Settings</h3>
                    <div class="privacy-options">
                        <label class="privacy-option">
                            <input type="radio" name="make_private" value="0" checked>
                            <div class="option-content">
                                <strong>📢 Public (0.5 credits)</strong>
                                <p>Your photo will be shareable and visible in our gallery. Perfect for showing off your style!</p>
                            </div>
                        </label>
                        <label class="privacy-option">
                            <input type="radio" name="make_private" value="1">
                            <div class="option-content">
                                <strong>🔒 Private (1 credit)</strong>
                                <p>Keep your photo completely private. Only you can see the result.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" class="generate-btn" id="generateBtn" <?= $credits < 0.5 ? 'disabled' : '' ?>>
                    <span id="generateBtnText">
                        <?= $credits < 0.5 ? 'Insufficient Credits' : '✨ Generate AI Try-On (0.5 Credits)' ?>
                    </span>
                </button>

                <div class="loading" id="loadingDiv">
                    <div class="spinner"></div>
                    <p>AI is working its magic... This may take up to 2 minutes.</p>
                </div>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('tryOnForm');
            const generateBtn = document.getElementById('generateBtn');
            const loadingDiv = document.getElementById('loadingDiv');

            // Handle photo tabs
            const tabBtns = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            const useStoredPhotoInput = document.querySelector('input[name="use_stored_photo"]');

            tabBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const tab = btn.dataset.tab;

                    // Update tab buttons
                    tabBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');

                    // Update tab contents
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                    });
                    document.getElementById(tab + '-tab').classList.add('active');

                    // Update form data
                    if (useStoredPhotoInput) {
                        useStoredPhotoInput.value = tab === 'stored' ? '1' : '0';
                    }
                });
            });

            // Handle stored photo selection
            const storedPhotos = document.querySelectorAll('.stored-photo');
            const storedPhotoIdInput = document.querySelector('input[name="stored_photo_id"]');

            storedPhotos.forEach(photo => {
                photo.addEventListener('click', () => {
                    // Remove selection from all photos
                    storedPhotos.forEach(p => p.classList.remove('selected'));

                    // Select this photo
                    photo.classList.add('selected');

                    // Update form data
                    if (storedPhotoIdInput) {
                        storedPhotoIdInput.value = photo.dataset.photoId;
                    }
                });
            });

            // Select primary photo by default
            const primaryPhoto = document.querySelector('.stored-photo.primary');
            if (primaryPhoto) {
                primaryPhoto.classList.add('selected');
            }

            // Handle privacy option changes
            const privacyOptions = document.querySelectorAll('input[name="make_private"]');
            const generateBtnText = document.getElementById('generateBtnText');
            const userCredits = <?= $credits ?>;

            privacyOptions.forEach(option => {
                option.addEventListener('change', () => {
                    const isPrivate = option.value === '1';
                    const creditCost = isPrivate ? 1 : 0.5;

                    if (userCredits < creditCost) {
                        generateBtnText.textContent = 'Insufficient Credits';
                        generateBtn.disabled = true;
                    } else {
                        const privacyText = isPrivate ? 'Private' : 'Public';
                        generateBtnText.textContent = `✨ Generate AI Try-On (${creditCost} ${creditCost === 1 ? 'Credit' : 'Credits'}) - ${privacyText}`;
                        generateBtn.disabled = false;
                    }
                });
            });

            // Handle user photo thumbnail clicks
            const userPhotoThumbnails = document.querySelectorAll('.user-photo-thumbnail');
            userPhotoThumbnails.forEach(thumbnail => {
                thumbnail.addEventListener('click', () => {
                    const photoId = thumbnail.dataset.photoId;

                    // Switch to stored photos tab
                    document.querySelector('[data-tab="stored"]').click();

                    // Select this photo in the stored photos grid
                    setTimeout(() => {
                        const storedPhoto = document.querySelector(`[data-photo-id="${photoId}"]`);
                        if (storedPhoto && storedPhoto.classList.contains('stored-photo')) {
                            storedPhoto.click();
                        }
                    }, 100);
                });
            });

            // File upload handling
            const setupFileUpload = (uploadElement, previewElement, multiple = false) => {
                const input = uploadElement.querySelector('input[type="file"]');

                // Drag and drop
                uploadElement.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    uploadElement.classList.add('drag-over');
                });

                uploadElement.addEventListener('dragleave', () => {
                    uploadElement.classList.remove('drag-over');
                });

                uploadElement.addEventListener('drop', (e) => {
                    e.preventDefault();
                    uploadElement.classList.remove('drag-over');

                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        input.files = files;
                        showPreview(files, previewElement, multiple);
                    }
                });

                // File input change
                input.addEventListener('change', (e) => {
                    showPreview(e.target.files, previewElement, multiple);
                });
            };

            // Show file preview
            const showPreview = (files, previewElement, multiple) => {
                previewElement.innerHTML = '';
                previewElement.style.display = 'block';

                const maxFiles = multiple ? Math.min(files.length, 5) : 1;

                for (let i = 0; i < maxFiles; i++) {
                    const file = files[i];
                    if (file && file.type.startsWith('image/')) {
                        const img = document.createElement('img');
                        img.src = URL.createObjectURL(file);
                        img.onload = () => URL.revokeObjectURL(img.src);
                        previewElement.appendChild(img);
                    }
                }
            };

            // Setup uploads
            setupFileUpload(
                document.getElementById('standingUpload'),
                document.getElementById('standingPreview'),
                true
            );

            setupFileUpload(
                document.getElementById('outfitUpload'),
                document.getElementById('outfitPreview'),
                false
            );

            // Form submission with AJAX
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // Validate form first
                if (!validateForm()) {
                    return;
                }

                generateBtn.style.display = 'none';
                loadingDiv.style.display = 'block';

                // Create FormData from the form
                const formData = new FormData(form);
                formData.append('ajax', '1');
                formData.append('background', '1'); // Always use background processing

                // Show progress updates
                let progress = 0;
                const progressInterval = setInterval(() => {
                    progress += Math.random() * 10;
                    if (progress > 90) {
                        clearInterval(progressInterval);
                        loadingDiv.innerHTML = '<div class="spinner"></div><p>Almost ready... Finalizing your result!</p>';
                    }
                }, 2000);

                // Submit via AJAX
                fetch('/generate.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    // Handle authentication errors
                    if (!data.success && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }

                    clearInterval(progressInterval);

                    if (data.success) {
                        if (data.background && data.job_id) {
                            // Background job started, poll for status
                            showAlert(data.message, 'success');
                            showBackgroundStatus('Your request is queued...');
                            loadingDiv.innerHTML = '<div class="spinner"></div><p>Processing in background. You can continue using the app!</p>';
                            pollJobStatus(data.job_id);
                        } else {
                            // Immediate processing completed
                            loadingDiv.style.display = 'none';
                            generateBtn.style.display = 'block';

                            // Update credits display
                            const creditsEl = document.querySelector('.credits');
                            if (creditsEl && data.credits_remaining !== undefined) {
                                const credits = data.credits_remaining;
                                const formattedCredits = (credits == Math.floor(credits)) ? credits.toString() : credits.toFixed(1);
                                creditsEl.innerHTML = `💎 ${formattedCredits} Credits`;
                            }

                            // Show success and result
                            showAlert(data.message, 'success');
                            if (data.result && data.result.result_url) {
                                showResult(data.result);
                            }
                        }
                    } else {
                        loadingDiv.style.display = 'none';
                        generateBtn.style.display = 'block';
                        showAlert(data.error, 'error');
                    }
                })
                .catch(error => {
                    clearInterval(progressInterval);
                    loadingDiv.style.display = 'none';
                    generateBtn.style.display = 'block';
                    showAlert('Network error. Please try again.', 'error');
                    console.error('Error:', error);
                });
            });

            // Helper function to show alerts
            function showAlert(message, type) {
                // Remove any existing alerts
                const existingAlerts = document.querySelectorAll('.alert');
                existingAlerts.forEach(alert => alert.remove());

                // Create new alert
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type}`;
                alertDiv.textContent = message;

                // Insert at the top of form container
                const formContainer = document.querySelector('.form-container');
                formContainer.insertBefore(alertDiv, formContainer.firstChild);

                // Auto-remove success alerts after 5 seconds
                if (type === 'success') {
                    setTimeout(() => {
                        alertDiv.remove();
                    }, 5000);
                }
            }

            // Helper function to show result
            function showResult(result) {
                // Remove existing result container
                const existingResult = document.querySelector('.result-container');
                if (existingResult) {
                    existingResult.remove();
                }

                // Create result container
                const resultDiv = document.createElement('div');
                resultDiv.className = 'result-container';

                let shareSection = '';
                if (result.share_url) {
                    shareSection = `
                        <div style="margin-top: 20px; padding: 15px; background: #e8f5e8; border-radius: 8px; border: 1px solid #c3e6c3;">
                            <h4 style="color: #2d6a2d; margin: 0 0 10px 0;">📢 Your Photo is Public!</h4>
                            <p style="margin: 0 0 10px 0; color: #2d6a2d; font-size: 0.9em;">Share your amazing try-on result with friends:</p>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="text" value="${window.location.origin}${result.share_url}" readonly
                                       style="flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em;"
                                       onclick="this.select()">
                                <button onclick="navigator.clipboard.writeText('${window.location.origin}${result.share_url}'); this.textContent='Copied!'"
                                        style="padding: 8px 15px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                    Copy Link
                                </button>
                            </div>
                        </div>
                    `;
                }

                resultDiv.innerHTML = `
                    <h3>Your AI-Generated Try-On Result!</h3>
                    <img src="${result.result_url}" alt="AI Generated Try-On" class="result-image">
                    <p><small>Generated in ${result.processing_time || 0} seconds</small></p>
                    ${shareSection}
                `;

                // Insert after any alerts
                const alerts = document.querySelectorAll('.alert');
                const lastAlert = alerts[alerts.length - 1];
                if (lastAlert) {
                    lastAlert.insertAdjacentElement('afterend', resultDiv);
                } else {
                    const formContainer = document.querySelector('.form-container');
                    formContainer.insertBefore(resultDiv, form);
                }
            }

            // Helper function to show background status
            function showBackgroundStatus(message) {
                // Remove existing status
                const existingStatus = document.querySelector('.background-status');
                if (existingStatus) {
                    existingStatus.remove();
                }

                // Create status container
                const statusDiv = document.createElement('div');
                statusDiv.className = 'background-status';
                statusDiv.innerHTML = `
                    <div class="status-indicator"></div>
                    <span>${message}</span>
                `;

                // Insert at the top of form container
                const formContainer = document.querySelector('.form-container');
                formContainer.insertBefore(statusDiv, formContainer.firstChild);
            }

            // Helper function to hide background status
            function hideBackgroundStatus() {
                const existingStatus = document.querySelector('.background-status');
                if (existingStatus) {
                    existingStatus.remove();
                }
            }

            // Form validation helper
            function validateForm() {
                const useStoredPhoto = document.querySelector('input[name="use_stored_photo"]')?.value === '1';
                const standingInput = document.querySelector('input[name="standing_photos[]"]');
                const outfitInput = document.querySelector('input[name="outfit_photo"]');

                if (useStoredPhoto) {
                    const selectedPhoto = document.querySelector('.stored-photo.selected');
                    if (!selectedPhoto) {
                        showAlert('Please select a photo of yourself', 'error');
                        return false;
                    }
                } else {
                    if (!standingInput.files.length) {
                        showAlert('Please upload at least one photo of yourself', 'error');
                        return false;
                    }
                }

                if (!outfitInput.files.length) {
                    showAlert('Please upload an outfit photo', 'error');
                    return false;
                }

                // Check file sizes
                const maxSize = 10 * 1024 * 1024; // 10MB
                const allFiles = [...standingInput.files, ...outfitInput.files];

                for (const file of allFiles) {
                    if (file.size > maxSize) {
                        showAlert(`File "${file.name}" is too large. Maximum size is 10MB.`, 'error');
                        return false;
                    }
                }

                return true;
            }

            // Poll job status for background jobs
            function pollJobStatus(jobId) {
                let pollCount = 0;
                const maxPolls = 120; // 10 minutes max (5 second intervals)

                const poll = () => {
                    if (pollCount >= maxPolls) {
                        hideBackgroundStatus();
                        loadingDiv.style.display = 'none';
                        generateBtn.style.display = 'block';
                        showAlert('Processing is taking longer than expected. Please check back later.', 'error');
                        return;
                    }

                    fetch(`/api/job_status.php?job_id=${jobId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                throw new Error(data.error);
                            }

                            const job = data.job;

                            switch (job.status) {
                                case 'queued':
                                    showBackgroundStatus('Your request is in the queue...');
                                    setTimeout(poll, 5000);
                                    break;

                                case 'processing':
                                    showBackgroundStatus('AI is working on your generation...');
                                    setTimeout(poll, 5000);
                                    break;

                                case 'completed':
                                    hideBackgroundStatus();
                                    loadingDiv.style.display = 'none';
                                    generateBtn.style.display = 'block';

                                    // Update credits (deducted on completion)
                                    const creditsEl = document.querySelector('.credits');
                                    if (creditsEl) {
                                        const currentCredits = parseInt(creditsEl.textContent.match(/\d+/)[0]);
                                        const newCredits = Math.max(0, currentCredits - 1);
                                        const formattedNewCredits = (newCredits == Math.floor(newCredits)) ? newCredits.toString() : newCredits.toFixed(1);
                                        creditsEl.innerHTML = `💎 ${formattedNewCredits} Credits`;
                                    }

                                    showAlert('Your AI generation is complete!', 'success');
                                    if (job.result && job.result.result_url) {
                                        showResult(job.result);
                                    }
                                    break;

                                case 'failed':
                                    hideBackgroundStatus();
                                    loadingDiv.style.display = 'none';
                                    generateBtn.style.display = 'block';
                                    showAlert(job.error || 'Generation failed', 'error');
                                    break;

                                default:
                                    setTimeout(poll, 5000);
                            }

                            pollCount++;
                        })
                        .catch(error => {
                            console.error('Polling error:', error);
                            setTimeout(poll, 5000);
                            pollCount++;
                        });
                };

                // Start polling after a short delay
                setTimeout(poll, 2000);
            }
        });
    </script>
</body>
</html>