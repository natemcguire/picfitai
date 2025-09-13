<?php
// api/upload_photos.php - Handle batch photo uploads
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/UserPhotoService.php';

Session::start();

// Check authentication
if (!Session::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = Session::getCurrentUser();

// Validate CSRF token
if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token']);
    exit;
}

// Check if files were uploaded
if (empty($_FILES['photos'])) {
    echo json_encode(['success' => false, 'error' => 'No photos uploaded']);
    exit;
}

$uploadedPhotos = [];
$errors = [];
$maxPhotos = 10;

// Get current photo count
$currentPhotoCount = UserPhotoService::getUserPhotoCount($user['id']);

// Process each uploaded file
$files = $_FILES['photos'];
$fileCount = is_array($files['tmp_name']) ? count($files['tmp_name']) : 1;

for ($i = 0; $i < $fileCount && $i < $maxPhotos; $i++) {
    // Check if we've reached the limit
    if ($currentPhotoCount + count($uploadedPhotos) >= $maxPhotos) {
        $errors[] = "Maximum of $maxPhotos photos allowed. Some photos were not uploaded.";
        break;
    }

    // Prepare file array for single file processing
    $file = [
        'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
        'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
        'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
        'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
        'error' => is_array($files['error']) ? $files['error'][$i] : $files['error']
    ];

    // Skip empty slots
    if (empty($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    try {
        // Set as primary if it's the first photo
        $setPrimary = ($currentPhotoCount === 0 && count($uploadedPhotos) === 0);

        $result = UserPhotoService::uploadUserPhoto($user['id'], $file, $setPrimary);

        // Add photo info to response
        $uploadedPhotos[] = [
            'id' => $result['id'],
            'filename' => $result['filename'],
            'original_name' => $result['original_name'],
            'url' => UserPhotoService::getPhotoUrl($result['filename']),
            'is_primary' => $result['is_primary'] ? 1 : 0,
            'file_size' => $result['file_size']
        ];

        Logger::info('Photo uploaded via API', [
            'user_id' => $user['id'],
            'photo_id' => $result['id'],
            'filename' => $result['filename']
        ]);

    } catch (Exception $e) {
        $errors[] = "Failed to upload {$file['name']}: " . $e->getMessage();
        Logger::error('Photo upload failed', [
            'user_id' => $user['id'],
            'filename' => $file['name'],
            'error' => $e->getMessage()
        ]);
    }
}

// Return response
if (!empty($uploadedPhotos)) {
    echo json_encode([
        'success' => true,
        'photos' => $uploadedPhotos,
        'errors' => $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => !empty($errors) ? implode('. ', $errors) : 'No photos were uploaded successfully'
    ]);
}