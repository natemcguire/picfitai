<?php
// monitor-jobs.php - Job monitoring and diagnostics script
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/BackgroundJobService.php';

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    http_response_code(404);
    exit('Not Found');
}

function displayStats() {
    echo "=== PicFit.ai Job Monitoring ===\n";
    echo "Generated at: " . date('Y-m-d H:i:s') . "\n\n";

    $stats = BackgroundJobService::getJobStats();

    echo "Background Jobs Status:\n";
    foreach ($stats['background_jobs'] as $status => $count) {
        echo "  $status: $count\n";
    }

    echo "\nGenerations Status:\n";
    foreach ($stats['generations'] as $status => $count) {
        echo "  $status: $count\n";
    }

    echo "\nRecent Jobs Activity (24h):\n";
    $recentJobs = $stats['recent_jobs_activity'];
    echo "  Total jobs: " . ($recentJobs['total_jobs_24h'] ?? 0) . "\n";
    echo "  Completed: " . ($recentJobs['completed_24h'] ?? 0) . "\n";
    echo "  Failed: " . ($recentJobs['failed_24h'] ?? 0) . "\n";

    // Job success rate
    $totalJobs = ($recentJobs['total_jobs_24h'] ?? 0);
    $completedJobs = ($recentJobs['completed_24h'] ?? 0);
    if ($totalJobs > 0) {
        $jobSuccessRate = round(($completedJobs / $totalJobs) * 100, 1);
        echo "  Success rate: $jobSuccessRate%\n";
    }

    echo "\nRecent Generations Activity (24h):\n";
    $recentGens = $stats['recent_generations_activity'];
    echo "  Total generations: " . ($recentGens['total_generations_24h'] ?? 0) . "\n";
    echo "  Completed: " . ($recentGens['completed_generations_24h'] ?? 0) . "\n";
    echo "  Failed: " . ($recentGens['failed_generations_24h'] ?? 0) . "\n";
    echo "  Avg processing time: " . round($recentGens['avg_processing_time'] ?? 0, 2) . " seconds\n";

    // Generation success rate
    $totalGens = ($recentGens['total_generations_24h'] ?? 0);
    $completedGens = ($recentGens['completed_generations_24h'] ?? 0);
    if ($totalGens > 0) {
        $genSuccessRate = round(($completedGens / $totalGens) * 100, 1);
        echo "  Success rate: $genSuccessRate%\n";
    }
}

function cleanupStuckJobs() {
    echo "\n=== Cleaning up stuck jobs ===\n";

    $cleaned = BackgroundJobService::cleanupStuckJobs(10);

    if ($cleaned > 0) {
        echo "Cleaned up $cleaned stuck jobs/generations\n";
    } else {
        echo "No stuck jobs found\n";
    }
}

function showRecentFailures() {
    echo "\n=== Recent Failures ===\n";

    $pdo = Database::getInstance();

    $stmt = $pdo->query('
        SELECT job_id, error_message, created_at, completed_at
        FROM background_jobs
        WHERE status = "failed"
        AND created_at > datetime("now", "-24 hours")
        ORDER BY created_at DESC
        LIMIT 10
    ');

    $failures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($failures)) {
        echo "No recent failures\n";
        return;
    }

    foreach ($failures as $failure) {
        echo "Job: " . $failure['job_id'] . "\n";
        echo "  Created: " . $failure['created_at'] . "\n";
        echo "  Error: " . substr($failure['error_message'], 0, 100) . "...\n\n";
    }
}

function showCurrentlyProcessing() {
    echo "\n=== Currently Processing ===\n";

    $pdo = Database::getInstance();

    $stmt = $pdo->query('
        SELECT job_id, started_at, ROUND((julianday("now") - julianday(started_at)) * 24 * 60) as minutes_running
        FROM background_jobs
        WHERE status = "processing"
        ORDER BY started_at ASC
    ');

    $processing = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($processing)) {
        echo "No jobs currently processing\n";
        return;
    }

    foreach ($processing as $job) {
        echo "Job: " . $job['job_id'] . "\n";
        echo "  Started: " . $job['started_at'] . "\n";
        echo "  Running for: " . $job['minutes_running'] . " minutes\n\n";
    }
}

// Main execution
$command = $argv[1] ?? 'stats';

switch ($command) {
    case 'stats':
        displayStats();
        break;

    case 'cleanup':
        cleanupStuckJobs();
        break;

    case 'failures':
        showRecentFailures();
        break;

    case 'processing':
        showCurrentlyProcessing();
        break;

    case 'full':
        displayStats();
        showCurrentlyProcessing();
        showRecentFailures();
        break;

    default:
        echo "Usage: php monitor-jobs.php [command]\n";
        echo "Commands:\n";
        echo "  stats      - Show job statistics (default)\n";
        echo "  cleanup    - Clean up stuck jobs\n";
        echo "  failures   - Show recent failures\n";
        echo "  processing - Show currently processing jobs\n";
        echo "  full       - Show all information\n";
        break;
}

echo "\n";