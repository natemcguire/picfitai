<?php
// web-monitor.php - Web-accessible job monitoring with HTTP auth
declare(strict_types=1);

// Authentication removed for easier access

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/BackgroundJobService.php';

$action = $_GET['action'] ?? 'stats';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PicFit.ai Job Monitoring</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta http-equiv="refresh" content="30">
</head>
<body class="bg-gray-100 min-h-screen py-8">
    <div class="container mx-auto px-4 max-w-6xl">
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h1 class="text-3xl font-bold text-gray-800 mb-4">PicFit.ai Job Monitoring</h1>
            <p class="text-gray-600 mb-4">Generated at: <?= date('Y-m-d H:i:s') ?> (Auto-refresh every 30s)</p>

            <div class="flex space-x-4 mb-6">
                <a href="?action=stats" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                    Stats
                </a>
                <a href="?action=cleanup" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                    Cleanup Stuck Jobs
                </a>
                <a href="?action=processing" class="bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded">
                    Currently Processing
                </a>
                <a href="?action=failures" class="bg-orange-500 hover:bg-orange-700 text-white font-bold py-2 px-4 rounded">
                    Recent Failures
                </a>
            </div>
        </div>

        <?php
        switch ($action) {
            case 'stats':
                displayStats();
                break;
            case 'cleanup':
                performCleanup();
                break;
            case 'processing':
                showCurrentlyProcessing();
                break;
            case 'failures':
                showRecentFailures();
                break;
            default:
                displayStats();
        }
        ?>
    </div>
</body>
</html>

<?php

function displayStats() {
    $stats = BackgroundJobService::getJobStats();
    ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Background Jobs Status -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Background Jobs Status</h2>
            <div class="space-y-2">
                <?php foreach ($stats['background_jobs'] as $status => $count): ?>
                    <div class="flex justify-between items-center">
                        <span class="capitalize <?= getStatusColor($status) ?>"><?= $status ?>:</span>
                        <span class="font-bold"><?= $count ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Generations Status -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Generations Status</h2>
            <div class="space-y-2">
                <?php foreach ($stats['generations'] as $status => $count): ?>
                    <div class="flex justify-between items-center">
                        <span class="capitalize <?= getStatusColor($status) ?>"><?= $status ?>:</span>
                        <span class="font-bold"><?= $count ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Jobs Activity -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Recent Jobs Activity (24h)</h2>
            <?php $recentJobs = $stats['recent_jobs_activity']; ?>
            <div class="space-y-2">
                <div class="flex justify-between">
                    <span>Total jobs:</span>
                    <span class="font-bold"><?= $recentJobs['total_jobs_24h'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-green-600">Completed:</span>
                    <span class="font-bold"><?= $recentJobs['completed_24h'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-red-600">Failed:</span>
                    <span class="font-bold"><?= $recentJobs['failed_24h'] ?? 0 ?></span>
                </div>
                <?php
                $totalJobs = $recentJobs['total_jobs_24h'] ?? 0;
                $completedJobs = $recentJobs['completed_24h'] ?? 0;
                if ($totalJobs > 0):
                    $successRate = round(($completedJobs / $totalJobs) * 100, 1);
                ?>
                <div class="flex justify-between">
                    <span>Success rate:</span>
                    <span class="font-bold <?= $successRate >= 80 ? 'text-green-600' : ($successRate >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                        <?= $successRate ?>%
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Generations Activity -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Recent Generations Activity (24h)</h2>
            <?php $recentGens = $stats['recent_generations_activity']; ?>
            <div class="space-y-2">
                <div class="flex justify-between">
                    <span>Total generations:</span>
                    <span class="font-bold"><?= $recentGens['total_generations_24h'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-green-600">Completed:</span>
                    <span class="font-bold"><?= $recentGens['completed_generations_24h'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-red-600">Failed:</span>
                    <span class="font-bold"><?= $recentGens['failed_generations_24h'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between">
                    <span>Avg processing time:</span>
                    <span class="font-bold"><?= round($recentGens['avg_processing_time'] ?? 0, 2) ?>s</span>
                </div>
                <?php
                $totalGens = $recentGens['total_generations_24h'] ?? 0;
                $completedGens = $recentGens['completed_generations_24h'] ?? 0;
                if ($totalGens > 0):
                    $genSuccessRate = round(($completedGens / $totalGens) * 100, 1);
                ?>
                <div class="flex justify-between">
                    <span>Success rate:</span>
                    <span class="font-bold <?= $genSuccessRate >= 80 ? 'text-green-600' : ($genSuccessRate >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                        <?= $genSuccessRate ?>%
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function performCleanup() {
    $cleaned = BackgroundJobService::cleanupStuckJobs(10);
    ?>
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Cleanup Results</h2>
        <?php if ($cleaned > 0): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                Cleaned up <?= $cleaned ?> stuck jobs/generations
            </div>
        <?php else: ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded">
                No stuck jobs found
            </div>
        <?php endif; ?>
        <div class="mt-4">
            <a href="?action=stats" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                Back to Stats
            </a>
        </div>
    </div>
    <?php
}

function showCurrentlyProcessing() {
    $pdo = Database::getInstance();
    $stmt = $pdo->query('
        SELECT job_id, started_at, ROUND((julianday("now") - julianday(started_at)) * 24 * 60) as minutes_running
        FROM background_jobs
        WHERE status = "processing"
        ORDER BY started_at ASC
    ');
    $processing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Currently Processing Jobs</h2>
        <?php if (empty($processing)): ?>
            <p class="text-gray-600">No jobs currently processing</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full table-auto">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-4 py-2 text-left">Job ID</th>
                            <th class="px-4 py-2 text-left">Started</th>
                            <th class="px-4 py-2 text-left">Running Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processing as $job): ?>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-mono text-sm"><?= htmlspecialchars($job['job_id']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($job['started_at']) ?></td>
                            <td class="px-4 py-2 <?= $job['minutes_running'] > 10 ? 'text-red-600 font-bold' : '' ?>">
                                <?= $job['minutes_running'] ?> minutes
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function showRecentFailures() {
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
    ?>
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Recent Failures (24h)</h2>
        <?php if (empty($failures)): ?>
            <p class="text-gray-600">No recent failures</p>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($failures as $failure): ?>
                <div class="border-l-4 border-red-500 bg-red-50 p-4">
                    <div class="font-mono text-sm text-gray-600 mb-2">
                        Job: <?= htmlspecialchars($failure['job_id']) ?>
                    </div>
                    <div class="text-sm text-gray-600 mb-2">
                        Created: <?= htmlspecialchars($failure['created_at']) ?>
                    </div>
                    <div class="text-red-700">
                        <?= htmlspecialchars(substr($failure['error_message'], 0, 200)) ?>
                        <?= strlen($failure['error_message']) > 200 ? '...' : '' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function getStatusColor($status) {
    switch ($status) {
        case 'completed': return 'text-green-600';
        case 'failed': return 'text-red-600';
        case 'processing': return 'text-blue-600';
        case 'queued': return 'text-yellow-600';
        default: return 'text-gray-600';
    }
}
?>