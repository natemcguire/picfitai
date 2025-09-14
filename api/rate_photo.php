<?php
// api/rate_photo.php - Photo rating endpoint
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $generationId = (int) ($input['generation_id'] ?? 0);
    $rating = (int) ($input['rating'] ?? 0);

    if ($generationId <= 0) {
        throw new Exception('Invalid generation ID');
    }

    if (!in_array($rating, [1, -1])) {
        throw new Exception('Rating must be 1 (thumbs up) or -1 (thumbs down)');
    }

    $pdo = Database::getInstance();

    // Verify generation exists and is public
    $stmt = $pdo->prepare('
        SELECT id FROM generations
        WHERE id = ? AND is_public = 1 AND status = "completed"
    ');
    $stmt->execute([$generationId]);

    if (!$stmt->fetch()) {
        throw new Exception('Photo not found or not public');
    }

    // Get user's IP and user agent for fingerprinting
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Try to insert or update rating
    try {
        $stmt = $pdo->prepare('
            INSERT INTO photo_ratings (generation_id, rating, ip_address, user_agent)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$generationId, $rating, $ipAddress, $userAgent]);
        $action = 'added';
    } catch (Exception $e) {
        // If duplicate, update existing rating
        if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
            $stmt = $pdo->prepare('
                UPDATE photo_ratings
                SET rating = ?, rated_at = CURRENT_TIMESTAMP
                WHERE generation_id = ? AND ip_address = ?
            ');
            $stmt->execute([$rating, $generationId, $ipAddress]);
            $action = 'updated';
        } else {
            throw $e;
        }
    }

    // Get updated rating counts
    $stmt = $pdo->prepare('
        SELECT
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as likes,
            SUM(CASE WHEN rating = -1 THEN 1 ELSE 0 END) as dislikes,
            COUNT(*) as total_ratings
        FROM photo_ratings
        WHERE generation_id = ?
    ');
    $stmt->execute([$generationId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    Logger::info('Photo rating submitted', [
        'generation_id' => $generationId,
        'rating' => $rating,
        'action' => $action,
        'ip_address' => $ipAddress,
        'new_counts' => $counts
    ]);

    echo json_encode([
        'success' => true,
        'action' => $action,
        'rating' => $rating,
        'counts' => [
            'likes' => (int) $counts['likes'],
            'dislikes' => (int) $counts['dislikes'],
            'total' => (int) $counts['total_ratings']
        ]
    ]);

} catch (Exception $e) {
    Logger::error('Photo rating error', [
        'error' => $e->getMessage(),
        'input' => $input ?? null
    ]);

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}