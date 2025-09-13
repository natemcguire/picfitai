<?php
// api/photos.php - User photo management API
declare(strict_types=1);

// Start output buffering to prevent any unwanted output
ob_start();

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/UserPhotoService.php';

// Clean buffer and set headers
if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

Session::start();

if (!Session::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user = Session::getCurrentUser();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get user photos
            $photos = UserPhotoService::getUserPhotos($user['id']);

            // Add URLs to photos
            foreach ($photos as &$photo) {
                $photo['url'] = UserPhotoService::getPhotoUrl($photo['filename']);
                $photo['file_path'] = null; // Don't expose file paths
            }

            echo json_encode([
                'success' => true,
                'photos' => $photos
            ]);
            break;

        case 'POST':
            // Upload new photo
            if (!isset($_FILES['photo'])) {
                throw new Exception('No photo uploaded');
            }

            if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            $setPrimary = isset($_POST['set_primary']) && $_POST['set_primary'] === '1';

            $photo = UserPhotoService::uploadUserPhoto($user['id'], $_FILES['photo'], $setPrimary);
            $photo['url'] = UserPhotoService::getPhotoUrl($photo['filename']);

            echo json_encode([
                'success' => true,
                'message' => 'Photo uploaded successfully',
                'photo' => $photo
            ]);
            break;

        case 'PUT':
            // Update photo (set as primary)
            parse_str(file_get_contents('php://input'), $input);

            if (!isset($input['photo_id'])) {
                throw new Exception('Photo ID required');
            }

            if (!Session::validateCSRFToken($input['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            $photoId = (int) $input['photo_id'];
            $success = UserPhotoService::setPrimaryPhoto($photoId, $user['id']);

            if (!$success) {
                throw new Exception('Failed to set primary photo');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Primary photo updated'
            ]);
            break;

        case 'DELETE':
            // Delete photo
            if (!isset($_GET['id'])) {
                throw new Exception('Photo ID required');
            }

            if (!Session::validateCSRFToken($_GET['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            $photoId = (int) $_GET['id'];
            $success = UserPhotoService::deletePhoto($photoId, $user['id']);

            if (!$success) {
                throw new Exception('Failed to delete photo');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Photo deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    Logger::error('Photos API - Error', [
        'error' => $e->getMessage(),
        'user_id' => $user['id'],
        'method' => $method
    ]);

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}