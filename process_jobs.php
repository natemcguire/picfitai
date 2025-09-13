<?php
// process_jobs.php - Background job processor
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/BackgroundJobService.php';

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    http_response_code(404);
    exit('Not Found');
}

try {
    $processed = BackgroundJobService::processAllQueuedJobs();

    // Only log/output when there are actual jobs processed
    if ($processed > 0) {
        Logger::info('BackgroundJobProcessor - Processed jobs', [
            'jobs_processed' => $processed
        ]);
        echo date('Y-m-d H:i:s') . " - Processed $processed jobs\n";
    }

} catch (Exception $e) {
    Logger::error('BackgroundJobProcessor - Error processing jobs', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    echo date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
    exit(1);
}