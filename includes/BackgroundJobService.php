<?php
// includes/BackgroundJobService.php - Background job processing service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AIService.php';

class BackgroundJobService {

    public static function queueGeneration(int $userId, array $standingPhotos, array $outfitPhoto, bool $isPublic = true): string {
        $pdo = Database::getInstance();

        // Store uploaded files in a temporary location with unique names
        $jobId = uniqid('job_', true);
        $tempJobsDir = __DIR__ . '/../temp_jobs';
        $tempDir = $tempJobsDir . '/' . $jobId;

        // Ensure temp_jobs directory exists (important for DreamHost deployment)
        if (!is_dir($tempJobsDir)) {
            @mkdir($tempJobsDir, 0755, true);
        }

        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        // Save standing photos
        $savedStandingPhotos = [];
        foreach ($standingPhotos as $index => $photo) {
            $filename = 'standing_' . $index . '_' . basename($photo['name']);
            $savePath = $tempDir . '/' . $filename;

            // Use appropriate method based on whether file is still an uploaded file or already processed
            $success = false;
            if (is_uploaded_file($photo['tmp_name'])) {
                $success = move_uploaded_file($photo['tmp_name'], $savePath);
            } else {
                $success = copy($photo['tmp_name'], $savePath);
            }

            if ($success) {
                $savedStandingPhotos[] = [
                    'path' => $savePath,
                    'type' => $photo['type'],
                    'name' => $photo['name']
                ];
            }
        }

        // Save outfit photo
        $outfitFilename = 'outfit_' . basename($outfitPhoto['name']);
        $outfitPath = $tempDir . '/' . $outfitFilename;
        $savedOutfitPhoto = null;

        // Check if file is uploaded or already on disk (default outfit)
        if (is_uploaded_file($outfitPhoto['tmp_name'])) {
            $success = move_uploaded_file($outfitPhoto['tmp_name'], $outfitPath);
        } else {
            $success = copy($outfitPhoto['tmp_name'], $outfitPath);
        }

        if ($success) {
            $savedOutfitPhoto = [
                'path' => $outfitPath,
                'type' => $outfitPhoto['type'],
                'name' => $outfitPhoto['name']
            ];
        }

        // Store job in database
        $stmt = $pdo->prepare('
            INSERT INTO background_jobs (job_id, user_id, job_type, job_data, status, created_at)
            VALUES (?, ?, "ai_generation", ?, "queued", CURRENT_TIMESTAMP)
        ');

        $jobData = json_encode([
            'standing_photos' => $savedStandingPhotos,
            'outfit_photo' => $savedOutfitPhoto,
            'temp_dir' => $tempDir,
            'is_public' => $isPublic
        ]);

        $stmt->execute([$jobId, $userId, $jobData]);

        Logger::info('BackgroundJobService - Job queued', [
            'job_id' => $jobId,
            'user_id' => $userId,
            'standing_photos_count' => count($savedStandingPhotos),
            'has_outfit_photo' => !empty($savedOutfitPhoto)
        ]);

        return $jobId;
    }

    public static function processJob(string $jobId): bool {
        $pdo = Database::getInstance();

        // Get job details
        $stmt = $pdo->prepare('SELECT * FROM background_jobs WHERE job_id = ? AND status = "queued"');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            Logger::error('BackgroundJobService - Job not found or already processed', ['job_id' => $jobId]);
            return false;
        }

        // Update status to processing
        $pdo->prepare('UPDATE background_jobs SET status = "processing", started_at = CURRENT_TIMESTAMP WHERE job_id = ?')
            ->execute([$jobId]);

        try {
            $jobData = json_decode($job['job_data'], true);

            // Reconstruct file arrays for AIService
            $standingPhotos = [];
            foreach ($jobData['standing_photos'] as $photo) {
                $standingPhotos[] = [
                    'tmp_name' => $photo['path'],
                    'type' => $photo['type'],
                    'name' => $photo['name'],
                    'size' => file_exists($photo['path']) ? filesize($photo['path']) : 0
                ];
            }

            $outfitPhoto = [
                'tmp_name' => $jobData['outfit_photo']['path'],
                'type' => $jobData['outfit_photo']['type'],
                'name' => $jobData['outfit_photo']['name'],
                'size' => file_exists($jobData['outfit_photo']['path']) ? filesize($jobData['outfit_photo']['path']) : 0
            ];

            // Get privacy setting from job data
            $isPublic = $jobData['is_public'] ?? true;

            // Process with AI service
            $aiService = new AIService();
            $result = $aiService->generateFit((int)$job['user_id'], $standingPhotos, $outfitPhoto, $isPublic);

            // Deduct credit (0.5 for public, 1 for private)
            Database::deductCredit((int)$job['user_id'], $isPublic);

            // Update job with result
            $pdo->prepare('
                UPDATE background_jobs
                SET status = "completed", result_data = ?, completed_at = CURRENT_TIMESTAMP
                WHERE job_id = ?
            ')->execute([json_encode($result), $jobId]);

            // Cleanup temp files
            self::cleanupTempFiles($jobData['temp_dir']);

            Logger::info('BackgroundJobService - Job completed successfully', [
                'job_id' => $jobId,
                'user_id' => $job['user_id'],
                'result_url' => $result['result_url'] ?? null
            ]);

            return true;

        } catch (Exception $e) {
            // Update job with error
            $pdo->prepare('
                UPDATE background_jobs
                SET status = "failed", error_message = ?, completed_at = CURRENT_TIMESTAMP
                WHERE job_id = ?
            ')->execute([$e->getMessage(), $jobId]);

            // Cleanup temp files
            if (isset($jobData['temp_dir'])) {
                self::cleanupTempFiles($jobData['temp_dir']);
            }

            Logger::error('BackgroundJobService - Job failed', [
                'job_id' => $jobId,
                'user_id' => $job['user_id'],
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public static function getJobStatus(string $jobId): ?array {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM background_jobs WHERE job_id = ?');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return null;
        }

        $result = [
            'job_id' => $job['job_id'],
            'status' => $job['status'],
            'created_at' => $job['created_at'],
            'started_at' => $job['started_at'],
            'completed_at' => $job['completed_at']
        ];

        if ($job['status'] === 'completed' && $job['result_data']) {
            $result['result'] = json_decode($job['result_data'], true);
        }

        if ($job['status'] === 'failed' && $job['error_message']) {
            $result['error'] = $job['error_message'];
        }

        return $result;
    }

    public static function processAllQueuedJobs(): int {
        $pdo = Database::getInstance();

        // First, clean up stuck jobs older than 10 minutes
        $stuckJobsCleanup = self::cleanupStuckJobs();
        if ($stuckJobsCleanup > 0) {
            Logger::warning('BackgroundJobService - Cleaned up stuck jobs', [
                'stuck_jobs_cleaned' => $stuckJobsCleanup
            ]);
        }

        $stmt = $pdo->prepare('SELECT job_id FROM background_jobs WHERE status = "queued" ORDER BY created_at ASC LIMIT 10');
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $processed = 0;
        foreach ($jobs as $jobId) {
            if (self::processJob($jobId)) {
                $processed++;
            }
        }

        return $processed;
    }

    private static function cleanupTempFiles(string $tempDir): void {
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($tempDir);
        }
    }

    public static function cleanupOldJobs(int $daysOld = 7): int {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('
            DELETE FROM background_jobs
            WHERE created_at < datetime("now", "-" || ? || " days")
            AND status IN ("completed", "failed")
        ');
        $stmt->execute([$daysOld]);

        return $stmt->rowCount();
    }

    public static function cleanupStuckJobs(int $timeoutMinutes = 10): int {
        $pdo = Database::getInstance();

        // Get stuck jobs for logging
        $stuckJobsStmt = $pdo->prepare('
            SELECT job_id, started_at, ROUND((julianday("now") - julianday(started_at)) * 24 * 60) as minutes_running
            FROM background_jobs
            WHERE status = "processing"
            AND datetime(started_at) < datetime("now", "-" || ? || " minutes")
        ');
        $stuckJobsStmt->execute([$timeoutMinutes]);
        $stuckJobs = $stuckJobsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($stuckJobs)) {
            Logger::warning('BackgroundJobService - Found stuck jobs', [
                'stuck_jobs' => $stuckJobs,
                'timeout_minutes' => $timeoutMinutes
            ]);
        }

        // Update stuck background jobs
        $backgroundJobsStmt = $pdo->prepare('
            UPDATE background_jobs
            SET status = "failed",
                error_message = "Job stuck - timeout after " || ? || " minutes",
                completed_at = CURRENT_TIMESTAMP
            WHERE status = "processing"
            AND datetime(started_at) < datetime("now", "-" || ? || " minutes")
        ');
        $backgroundJobsStmt->execute([$timeoutMinutes, $timeoutMinutes]);
        $backgroundJobsCount = $backgroundJobsStmt->rowCount();

        // Update stuck generations
        $generationsStmt = $pdo->prepare('
            UPDATE generations
            SET status = "failed",
                error_message = "Generation stuck - timeout after " || ? || " minutes",
                completed_at = CURRENT_TIMESTAMP
            WHERE status = "processing"
            AND datetime(created_at) < datetime("now", "-" || ? || " minutes")
        ');
        $generationsStmt->execute([$timeoutMinutes, $timeoutMinutes]);
        $generationsCount = $generationsStmt->rowCount();

        $totalCleaned = $backgroundJobsCount + $generationsCount;

        if ($totalCleaned > 0) {
            Logger::info('BackgroundJobService - Cleaned up stuck records', [
                'background_jobs_cleaned' => $backgroundJobsCount,
                'generations_cleaned' => $generationsCount,
                'total_cleaned' => $totalCleaned,
                'timeout_minutes' => $timeoutMinutes
            ]);
        }

        return $totalCleaned;
    }

    public static function getJobStats(): array {
        $pdo = Database::getInstance();

        // Get background job stats
        $backgroundStats = $pdo->query('
            SELECT status, COUNT(*) as count
            FROM background_jobs
            GROUP BY status
        ')->fetchAll(PDO::FETCH_KEY_PAIR);

        // Get generation stats
        $generationStats = $pdo->query('
            SELECT status, COUNT(*) as count
            FROM generations
            GROUP BY status
        ')->fetchAll(PDO::FETCH_KEY_PAIR);

        // Get recent activity (last 24 hours) - separate for background_jobs and generations
        $recentJobsActivity = $pdo->query('
            SELECT
                COUNT(*) as total_jobs_24h,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_24h,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_24h
            FROM background_jobs
            WHERE created_at > datetime("now", "-24 hours")
        ')->fetch(PDO::FETCH_ASSOC);

        $recentGenerationsActivity = $pdo->query('
            SELECT
                COUNT(*) as total_generations_24h,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_generations_24h,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_generations_24h,
                AVG(CASE WHEN processing_time IS NOT NULL THEN processing_time ELSE NULL END) as avg_processing_time
            FROM generations
            WHERE created_at > datetime("now", "-24 hours")
        ')->fetch(PDO::FETCH_ASSOC);

        return [
            'background_jobs' => $backgroundStats,
            'generations' => $generationStats,
            'recent_jobs_activity' => $recentJobsActivity,
            'recent_generations_activity' => $recentGenerationsActivity
        ];
    }
}