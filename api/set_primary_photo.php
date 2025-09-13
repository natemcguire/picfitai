<?php
// api/set_primary_photo.php - Set a photo as primary
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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token
if (!Session::validateCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token']);
    exit;
}

// Validate photo ID
$photoId = (int) ($input['photo_id'] ?? 0);
if ($photoId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid photo ID']);
    exit;
}

try {
    // Set as primary (UserPhotoService will verify ownership)
    $result = UserPhotoService::setPrimaryPhoto($photoId, $user['id']);

    if ($result) {
        Logger::info('Primary photo set via API', [
            'user_id' => $user['id'],
            'photo_id' => $photoId
        ]);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Photo not found or access denied']);
    }

} catch (Exception $e) {
    Logger::error('Set primary photo failed', [
        'user_id' => $user['id'],
        'photo_id' => $photoId,
        'error' => $e->getMessage()
    ]);

    echo json_encode(['success' => false, 'error' => 'Failed to set primary photo']);
}