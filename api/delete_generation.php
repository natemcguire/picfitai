<?php
// api/delete_generation.php - Delete user generation
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    // Ensure user is logged in
    Session::start();
    if (!Session::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }

    $user = Session::getCurrentUser();

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    // Validate CSRF token
    if (!Session::validateCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    // Validate generation ID
    $generationId = $input['generation_id'] ?? null;
    if (!$generationId || !is_numeric($generationId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid generation ID']);
        exit;
    }

    $pdo = Database::getInstance();

    // First, verify the generation belongs to the current user
    $stmt = $pdo->prepare('
        SELECT id, result_url, user_id
        FROM generations
        WHERE id = ? AND user_id = ?
    ');
    $stmt->execute([(int)$generationId, $user['id']]);
    $generation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$generation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Generation not found or access denied']);
        exit;
    }

    // Delete the generated image file if it exists
    if (!empty($generation['result_url'])) {
        $imagePath = __DIR__ . '/../' . ltrim($generation['result_url'], '/');
        if (file_exists($imagePath)) {
            @unlink($imagePath);
        }
    }

    // Delete related photo ratings
    $stmt = $pdo->prepare('DELETE FROM photo_ratings WHERE generation_id = ?');
    $stmt->execute([(int)$generationId]);

    // Delete the generation record
    $stmt = $pdo->prepare('DELETE FROM generations WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$generationId, $user['id']]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Generation not found']);
        exit;
    }

    Logger::info('Generation deleted', [
        'user_id' => $user['id'],
        'generation_id' => $generationId,
        'user_email' => $user['email']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Generation deleted successfully'
    ]);

} catch (Exception $e) {
    Logger::error('Delete generation error', [
        'error' => $e->getMessage(),
        'user_id' => $user['id'] ?? null,
        'generation_id' => $generationId ?? null
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to delete generation'
    ]);
}