<?php
// includes/BackgroundJobService.php - Background job processing service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AIService.php';

class BackgroundJobService {

    public static function queueGeneration(int $userId, array $standingPhotos, array $outfitPhoto, bool $isPublic = true): string {
        $pdo = Database::getInstance();

        // Create hash of inputs for idempotency
        $hashInputs = [
            'user_id' => $userId,
            'is_public' => $isPublic
        ];

        // Add file hashes for idempotency check
        foreach ($standingPhotos as $photo) {
            if (file_exists($photo['tmp_name'])) {
                $hashInputs['standing_photos'][] = md5_file($photo['tmp_name']);
            }
        }

        if (file_exists($outfitPhoto['tmp_name'])) {
            $hashInputs['outfit_photo'] = md5_file($outfitPhoto['tmp_name']);
        }

        $inputHash = md5(json_encode($hashInputs));

        // Check for existing job with same hash within last 5 minutes
        $stmt = $pdo->prepare('
            SELECT job_id, status, progress, progress_stage
            FROM background_jobs
            WHERE user_id = ?
            AND input_hash = ?
            AND created_at > datetime("now", "-5 minutes")
            AND status IN ("queued", "processing", "completed")
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $stmt->execute([$userId, $inputHash]);
        $existingJob = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingJob) {
            Logger::info('BackgroundJobService - Returning existing job (idempotency)', [
                'existing_job_id' => $existingJob['job_id'],
                'status' => $existingJob['status'],
                'progress' => $existingJob['progress'],
                'stage' => $existingJob['progress_stage'],
                'input_hash' => $inputHash
            ]);
            return $existingJob['job_id'];
        }

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

        // Store job in database with progress tracking
        $stmt = $pdo->prepare('
            INSERT INTO background_jobs (
                job_id, user_id, job_type, job_data, status,
                progress, progress_stage, input_hash, created_at
            )
            VALUES (?, ?, "ai_generation", ?, "queued", 5, "UPLOADED", ?, CURRENT_TIMESTAMP)
        ');

        $jobData = json_encode([
            'standing_photos' => $savedStandingPhotos,
            'outfit_photo' => $savedOutfitPhoto,
            'temp_dir' => $tempDir,
            'is_public' => $isPublic,
            'input_hash' => $inputHash
        ]);

        $stmt->execute([$jobId, $userId, $jobData, $inputHash]);

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
        $pdo->prepare('
            UPDATE background_jobs
            SET status = "processing",
                progress = 10,
                progress_stage = "PROCESSING",
                started_at = CURRENT_TIMESTAMP
            WHERE job_id = ?
        ')->execute([$jobId]);

        // Update progress helper function
        $updateProgress = function($jobId, $progress, $stage) use ($pdo) {
            $pdo->prepare('
                UPDATE background_jobs
                SET progress = ?, progress_stage = ?
                WHERE job_id = ?
            ')->execute([$progress, $stage, $jobId]);
        };

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

            // Update progress: Starting AI processing
            $updateProgress($jobId, 30, 'PROCESSING');

            // Process with AI service
            $aiService = new AIService();
            $result = $aiService->generateFit((int)$job['user_id'], $standingPhotos, $outfitPhoto, $isPublic);

            // Update progress: Post-processing
            $updateProgress($jobId, 90, 'POSTPROCESSING');

            // Deduct credit (0.5 for public, 1 for private)
            Database::deductCredit((int)$job['user_id'], $isPublic);

            // Update job with result
            $pdo->prepare('
                UPDATE background_jobs
                SET status = "completed",
                    progress = 100,
                    progress_stage = "COMPLETE",
                    result_data = ?,
                    completed_at = CURRENT_TIMESTAMP
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

        // Calculate ETA based on average processing time
        $eta = null;
        if ($job['status'] === 'processing' && $job['started_at']) {
            $avgTime = $pdo->query('
                SELECT AVG(julianday(completed_at) - julianday(started_at)) * 86400 as avg_seconds
                FROM background_jobs
                WHERE status = "completed"
                AND completed_at IS NOT NULL
                AND started_at IS NOT NULL
                AND created_at > datetime("now", "-7 days")
            ')->fetchColumn();

            if ($avgTime) {
                $elapsed = time() - strtotime($job['started_at']);
                $remaining = max(0, $avgTime - $elapsed);
                $eta = round($remaining);
            }
        }

        $result = [
            'job_id' => $job['job_id'],
            'status' => $job['status'],
            'progress' => (int)($job['progress'] ?? 0),
            'progress_stage' => $job['progress_stage'],
            'eta_seconds' => $eta,
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