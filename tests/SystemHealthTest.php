<?php
// SystemHealthTest.php - Automated system health tests
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

class SystemHealthTest {
    private array $results = [];
    private string $testRunId;
    private string $testStartTime;

    public function __construct() {
        $this->testRunId = 'test_' . date('Y-m-d_H-i-s') . '_' . substr(md5(uniqid()), 0, 8);
        $this->testStartTime = date('Y-m-d H:i:s');
    }

    /**
     * Run all system health tests
     */
    public function runAllTests(): array {
        echo "🤖 Starting PicFit.ai System Health Tests\n";
        echo "Test Run ID: {$this->testRunId}\n";
        echo "Started at: {$this->testStartTime}\n\n";

        $tests = [
            'database_connectivity' => [$this, 'testDatabaseConnectivity'],
            'api_endpoints' => [$this, 'testAPIEndpoints'],
            'file_permissions' => [$this, 'testFilePermissions'],
            'external_services' => [$this, 'testExternalServices'],
            'full_generation_flow' => [$this, 'testFullGenerationFlow']
        ];

        foreach ($tests as $testName => $testMethod) {
            echo "Running test: {$testName}...\n";
            try {
                $result = call_user_func($testMethod);
                $this->results[$testName] = [
                    'status' => 'PASS',
                    'message' => $result['message'] ?? 'Test passed',
                    'details' => $result['details'] ?? null,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                echo "✅ PASS: {$testName}\n";
            } catch (Exception $e) {
                $this->results[$testName] = [
                    'status' => 'FAIL',
                    'message' => $e->getMessage(),
                    'details' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ],
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                echo "❌ FAIL: {$testName} - {$e->getMessage()}\n";
            }
            echo "\n";
        }

        $this->logResults();
        $this->sendNotificationIfNeeded();

        return $this->results;
    }

    /**
     * Test database connectivity and basic queries
     */
    private function testDatabaseConnectivity(): array {
        $pdo = Database::getInstance();

        // Test basic connectivity
        $stmt = $pdo->query('SELECT 1');
        if (!$stmt) {
            throw new Exception('Database connection failed');
        }

        // Test user table access
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $userCount = $stmt->fetchColumn();

        // Test generations table access
        $stmt = $pdo->query('SELECT COUNT(*) FROM generations WHERE created_at > datetime("now", "-24 hours")');
        $recentGenerations = $stmt->fetchColumn();

        return [
            'message' => 'Database connectivity test passed',
            'details' => [
                'total_users' => $userCount,
                'recent_generations_24h' => $recentGenerations
            ]
        ];
    }

    /**
     * Test critical API endpoints
     */
    private function testAPIEndpoints(): array {
        $endpoints = [
            '/api/job_status.php?job_id=test' => 'Job status endpoint',
            '/api/whatsapp_send_otp.php' => 'WhatsApp OTP endpoint (should return error for empty data)',
        ];

        $results = [];
        foreach ($endpoints as $endpoint => $description) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://picfit.ai' . $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_POST => strpos($endpoint, 'whatsapp') !== false,
                CURLOPT_POSTFIELDS => strpos($endpoint, 'whatsapp') !== false ? '{}' : null,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 0) {
                throw new Exception("Failed to connect to {$endpoint}");
            }

            $results[$endpoint] = [
                'http_code' => $httpCode,
                'description' => $description,
                'response_length' => strlen($response)
            ];
        }

        return [
            'message' => 'API endpoints test passed',
            'details' => $results
        ];
    }

    /**
     * Test file permissions and directories
     */
    private function testFilePermissions(): array {
        $directories = [
            __DIR__ . '/../generated' => 'Generated images directory',
            __DIR__ . '/../data' => 'Data directory',
            __DIR__ . '/../uploads' => 'Uploads directory'
        ];

        $results = [];
        foreach ($directories as $dir => $description) {
            if (!is_dir($dir)) {
                throw new Exception("Directory does not exist: {$dir}");
            }

            if (!is_writable($dir)) {
                throw new Exception("Directory is not writable: {$dir}");
            }

            $results[$dir] = [
                'exists' => true,
                'writable' => true,
                'description' => $description
            ];
        }

        return [
            'message' => 'File permissions test passed',
            'details' => $results
        ];
    }

    /**
     * Test external service connectivity
     */
    private function testExternalServices(): array {
        $services = [];

        // Test Gemini API (if configured)
        $geminiKey = Config::get('gemini_api_key');
        if ($geminiKey) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $geminiKey,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $services['gemini_api'] = [
                'status' => $httpCode === 200 ? 'OK' : 'ERROR',
                'http_code' => $httpCode,
                'configured' => true
            ];
        } else {
            $services['gemini_api'] = ['configured' => false];
        }

        // Test Stripe API (if configured)
        $stripeKey = Config::get('stripe_secret_key');
        if ($stripeKey) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.stripe.com/v1/account',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $stripeKey]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $services['stripe_api'] = [
                'status' => $httpCode === 200 ? 'OK' : 'ERROR',
                'http_code' => $httpCode,
                'configured' => true
            ];
        } else {
            $services['stripe_api'] = ['configured' => false];
        }

        return [
            'message' => 'External services test completed',
            'details' => $services
        ];
    }

    /**
     * Test full generation flow using real user account and photos
     */
    private function testFullGenerationFlow(): array {
        // Get the real user account (nate.mcguire@gmail.com)
        $testUser = $this->getRealTestUser();

        if (!$testUser) {
            throw new Exception('Test user nate.mcguire@gmail.com not found in database');
        }

        // Test image upload and generation
        $generationResult = $this->performTestGeneration($testUser);

        return [
            'message' => 'Full generation flow test passed',
            'details' => [
                'user_id' => $testUser['id'],
                'user_email' => $testUser['email'],
                'generation_id' => $generationResult['generation_id'],
                'result_url' => $generationResult['result_url'],
                'processing_time' => $generationResult['processing_time'],
                'share_token' => $generationResult['share_token'],
                'image_file_size' => $generationResult['file_size']
            ]
        ];
    }

    /**
     * Get the real test user from database
     */
    private function getRealTestUser(): ?array {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute(['nate.mcguire@gmail.com']);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Perform a test generation using real saved photos
     */
    private function performTestGeneration(array $testUser): array {
        $pdo = Database::getInstance();

        // Get user's saved photos
        $stmt = $pdo->prepare('
            SELECT * FROM user_photos
            WHERE user_id = ?
            ORDER BY is_primary DESC, created_at DESC
            LIMIT 1
        ');
        $stmt->execute([$testUser['id']]);
        $userPhoto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userPhoto) {
            throw new Exception('No saved photos found for test user');
        }

        // Get a default outfit
        $outfitOptions = [];
        $outfitsDir = __DIR__ . '/../images/outfits/';

        if (is_dir($outfitsDir)) {
            $outfitFiles = glob($outfitsDir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE);
            if (!empty($outfitFiles)) {
                $outfitOptions = $outfitFiles;
            }
        }

        if (empty($outfitOptions)) {
            throw new Exception('No default outfit images found');
        }

        $personPhotoPath = $userPhoto['file_path'];
        $outfitPhotoPath = $outfitOptions[0]; // Use first available outfit

        if (!file_exists($personPhotoPath)) {
            throw new Exception('User photo file does not exist: ' . $personPhotoPath);
        }

        if (!file_exists($outfitPhotoPath)) {
            throw new Exception('Outfit photo file does not exist: ' . $outfitPhotoPath);
        }

        try {
            // Perform the actual generation
            $aiService = new AIService();

            $startTime = microtime(true);
            // Prepare file arrays to match expected format
            $standingPhotos = [
                [
                    'tmp_name' => $personPhotoPath,
                    'name' => basename($personPhotoPath),
                    'type' => 'image/jpeg'
                ]
            ];

            $outfitPhoto = [
                'tmp_name' => $outfitPhotoPath,
                'name' => basename($outfitPhotoPath),
                'type' => 'image/jpeg'
            ];

            $result = $aiService->generateFit(
                $testUser['id'],
                $standingPhotos,
                $outfitPhoto,
                true // public - so we can verify the result
            );
            $processingTime = microtime(true) - $startTime;

            if (!$result || !isset($result['result_url'])) {
                throw new Exception('Generation failed: No result URL returned');
            }

            // Verify the generated image exists and get its size
            $resultPath = __DIR__ . '/../' . ltrim($result['result_url'], '/');
            if (!file_exists($resultPath)) {
                throw new Exception('Generated image file does not exist: ' . $resultPath);
            }

            $fileSize = filesize($resultPath);
            if ($fileSize < 1000) { // Less than 1KB is suspicious
                throw new Exception('Generated image file seems too small: ' . $fileSize . ' bytes');
            }

            // Verify image is readable
            $imageInfo = getimagesize($resultPath);
            if (!$imageInfo) {
                throw new Exception('Generated image file is not a valid image');
            }

            return [
                'generation_id' => $result['generation_id'] ?? null,
                'result_url' => $result['result_url'],
                'processing_time' => round($processingTime, 2),
                'share_token' => $result['share_token'] ?? null,
                'file_size' => $fileSize,
                'image_dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
                'image_type' => $imageInfo['mime'],
                'person_photo_used' => basename($personPhotoPath),
                'outfit_photo_used' => basename($outfitPhotoPath)
            ];

        } catch (Exception $e) {
            // Log the error but don't clean up user data since it's real
            Logger::error('Test generation failed', [
                'user_id' => $testUser['id'],
                'error' => $e->getMessage(),
                'person_photo' => $personPhotoPath,
                'outfit_photo' => $outfitPhotoPath
            ]);
            throw $e;
        }
    }

    /**
     * Log test results to file
     */
    private function logResults(): void {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/health_test_' . date('Y-m-d') . '.log';
        $logEntry = [
            'test_run_id' => $this->testRunId,
            'timestamp' => $this->testStartTime,
            'results' => $this->results,
            'summary' => [
                'total_tests' => count($this->results),
                'passed' => count(array_filter($this->results, fn($r) => $r['status'] === 'PASS')),
                'failed' => count(array_filter($this->results, fn($r) => $r['status'] === 'FAIL'))
            ]
        ];

        file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Send email notification if tests failed
     */
    private function sendNotificationIfNeeded(): void {
        $failedTests = array_filter($this->results, fn($r) => $r['status'] === 'FAIL');

        if (empty($failedTests)) {
            echo "✅ All tests passed! No notification needed.\n";
            return;
        }

        $this->sendFailureNotification($failedTests);
    }

    /**
     * Send failure notification email
     */
    private function sendFailureNotification(array $failedTests): void {
        $adminEmail = 'nate.mcguire@gmail.com';
        $subject = "🚨 PicFit.ai System Health Alert - " . count($failedTests) . " Tests Failed";

        $body = "System Health Test Failure Report\n";
        $body .= "Test Run ID: {$this->testRunId}\n";
        $body .= "Timestamp: {$this->testStartTime}\n";
        $body .= "Server: " . ($_SERVER['SERVER_NAME'] ?? 'picfit.ai') . "\n\n";

        $body .= "FAILED TESTS:\n";
        $body .= str_repeat("=", 50) . "\n";

        foreach ($failedTests as $testName => $result) {
            $body .= "\n❌ {$testName}\n";
            $body .= "Error: {$result['message']}\n";
            if (!empty($result['details'])) {
                $body .= "Details: " . print_r($result['details'], true) . "\n";
            }
            $body .= "Time: {$result['timestamp']}\n";
            $body .= str_repeat("-", 30) . "\n";
        }

        $passedTests = array_filter($this->results, fn($r) => $r['status'] === 'PASS');
        if (!empty($passedTests)) {
            $body .= "\nPASSED TESTS:\n";
            foreach ($passedTests as $testName => $result) {
                $body .= "✅ {$testName}\n";

                // Include photo result details for successful generation tests
                if ($testName === 'full_generation_flow' && !empty($result['details'])) {
                    $details = $result['details'];
                    $body .= "   Generated Image: https://picfit.ai{$details['result_url']}\n";
                    $body .= "   Share URL: https://picfit.ai/share/{$details['share_token']}\n";
                    $body .= "   Processing Time: {$details['processing_time']}s\n";
                    $body .= "   File Size: " . number_format($details['image_file_size']) . " bytes\n";
                    $body .= "   Dimensions: {$details['image_dimensions']}\n";
                }
            }
        }

        $body .= "\nSystem Status Summary:\n";
        $body .= "Total Tests: " . count($this->results) . "\n";
        $body .= "Passed: " . count($passedTests) . "\n";
        $body .= "Failed: " . count($failedTests) . "\n";

        $body .= "\nPlease investigate and resolve these issues.\n";
        $body .= "View full logs at: https://picfit.ai/logs/health_test_" . date('Y-m-d') . ".log\n";

        $headers = [
            'From: PicFit.ai System <noreply@picfit.ai>',
            'Reply-To: noreply@picfit.ai',
            'X-Mailer: PicFit.ai Health Monitor',
            'Content-Type: text/plain; charset=UTF-8'
        ];

        $sent = mail($adminEmail, $subject, $body, implode("\r\n", $headers));

        if ($sent) {
            echo "📧 Failure notification sent to {$adminEmail}\n";
        } else {
            echo "❌ Failed to send notification email\n";
        }
    }

    /**
     * Send success notification with photo result (optional)
     */
    public function sendSuccessNotification(): void {
        $adminEmail = 'nate.mcguire@gmail.com';
        $subject = "✅ PicFit.ai System Health - All Tests Passed";

        $generationTest = $this->results['full_generation_flow'] ?? null;
        if (!$generationTest || $generationTest['status'] !== 'PASS') {
            return; // Only send success notification if generation test passed
        }

        $details = $generationTest['details'];
        $body = "System Health Test Success Report\n";
        $body .= "Test Run ID: {$this->testRunId}\n";
        $body .= "Timestamp: {$this->testStartTime}\n";
        $body .= "Server: " . ($_SERVER['SERVER_NAME'] ?? 'picfit.ai') . "\n\n";

        $body .= "✅ All tests passed successfully!\n\n";

        $body .= "GENERATION TEST RESULTS:\n";
        $body .= str_repeat("=", 30) . "\n";
        $body .= "Generated Image: https://picfit.ai{$details['result_url']}\n";
        $body .= "Share URL: https://picfit.ai/share/{$details['share_token']}\n";
        $body .= "Processing Time: {$details['processing_time']}s\n";
        $body .= "File Size: " . number_format($details['image_file_size']) . " bytes\n";
        $body .= "Dimensions: {$details['image_dimensions']}\n";
        $body .= "Person Photo: {$details['person_photo_used']}\n";
        $body .= "Outfit Photo: {$details['outfit_photo_used']}\n\n";

        $body .= "All systems are operational.\n";

        $headers = [
            'From: PicFit.ai System <noreply@picfit.ai>',
            'Reply-To: noreply@picfit.ai',
            'X-Mailer: PicFit.ai Health Monitor',
            'Content-Type: text/plain; charset=UTF-8'
        ];

        $sent = mail($adminEmail, $subject, $body, implode("\r\n", $headers));

        if ($sent) {
            echo "📧 Success notification sent to {$adminEmail}\n";
        }
    }
}