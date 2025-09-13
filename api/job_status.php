<?php
// api/job_status.php - Check background job status
declare(strict_types=1);

// Start output buffering to prevent any unwanted output
ob_start();

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/BackgroundJobService.php';

// Clean buffer and set headers
ob_clean();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

Session::start();

if (!Session::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if (!isset($_GET['job_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Job ID required']);
    exit;
}

try {
    $jobId = $_GET['job_id'];
    $jobStatus = BackgroundJobService::getJobStatus($jobId);

    if (!$jobStatus) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        exit;
    }

    // Only allow users to check their own jobs
    $user = Session::getCurrentUser();
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT user_id FROM background_jobs WHERE job_id = ?');
    $stmt->execute([$jobId]);
    $jobUserId = $stmt->fetchColumn();

    if ($jobUserId != $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'job' => $jobStatus
    ]);

} catch (Exception $e) {
    Logger::error('JobStatus API - Error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}