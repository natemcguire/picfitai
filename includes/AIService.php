<?php
// includes/AIService.php - AI generation service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class AIService {
    private string $geminiApiKey;
    private string $openaiApiKey;
    
    public function __construct() {
        $this->geminiApiKey = Config::get('gemini_api_key', '');
        $this->openaiApiKey = Config::get('openai_api_key', '');
    }
    
    public function generateFit(int $userId, array $standingPhotos, array $outfitPhoto, bool $isPublic = true): array {
        $pdo = Database::getInstance();

        // Create hash of inputs for caching
        $hashInputs = [
            'user_id' => $userId,
            'is_public' => $isPublic
        ];

        foreach ($standingPhotos as $photo) {
            if (file_exists($photo['tmp_name'])) {
                $hashInputs['standing_photos'][] = md5_file($photo['tmp_name']);
            }
        }

        if (file_exists($outfitPhoto['tmp_name'])) {
            $hashInputs['outfit_photo'] = md5_file($outfitPhoto['tmp_name']);
        }

        $inputHash = md5(json_encode($hashInputs));

        // Check for cached result (within last 7 days) - only if caching is enabled
        if (Config::get('enable_cache', true)) {
            $stmt = $pdo->prepare('
                SELECT id, result_url, share_token, processing_time, created_at
                FROM generations
                WHERE input_hash = ?
                AND status = "completed"
                AND created_at > datetime("now", "-7 days")
                ORDER BY created_at DESC
                LIMIT 1
            ');
            $stmt->execute([$inputHash]);
            $cachedResult = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cachedResult) {
                Logger::info('AIService - Returning cached result', [
                    'user_id' => $userId,
                    'cached_generation_id' => $cachedResult['id'],
                    'input_hash' => $inputHash,
                    'cache_age_hours' => round((time() - strtotime($cachedResult['created_at'])) / 3600, 1)
                ]);

                return [
                    'success' => true,
                    'generation_id' => $cachedResult['id'],
                    'result_url' => $cachedResult['result_url'],
                    'processing_time' => 0, // Instant from cache
                    'is_public' => $isPublic,
                    'share_token' => $cachedResult['share_token'],
                    'share_url' => $cachedResult['share_token'] ? '/share/' . $cachedResult['share_token'] : null,
                    'from_cache' => true
                ];
            }
        } else {
            Logger::debug('AIService - Cache disabled, generating new result', [
                'user_id' => $userId,
                'input_hash' => $inputHash
            ]);
        }

        // Analyze outfit image to determine processing approach
        $outfitAnalysis = $this->analyzeOutfitImage($outfitPhoto);

        if (!$outfitAnalysis['is_valid']) {
            throw new Exception($outfitAnalysis['error_message']);
        }

        // Generate share token for public photos
        $shareToken = $isPublic ? bin2hex(random_bytes(16)) : null;

        // Create generation record with hash
        $stmt = $pdo->prepare('
            INSERT INTO generations (user_id, status, input_data, input_hash, is_public, share_token)
            VALUES (?, "processing", ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, json_encode([
            'standing_photos_count' => count($standingPhotos),
            'has_outfit_photo' => !empty($outfitPhoto),
            'is_public' => $isPublic
        ]), $inputHash, $isPublic ? 1 : 0, $shareToken]);

        $generationId = $pdo->lastInsertId();
        $startTime = time();
        
        try {
            // Try Gemini first if available
            if (!empty($this->geminiApiKey)) {
                $result = $this->generateWithGemini($standingPhotos, $outfitPhoto, $outfitAnalysis);
            } elseif (!empty($this->openaiApiKey)) {
                $result = $this->generateWithOpenAI($standingPhotos, $outfitPhoto, $outfitAnalysis);
            } else {
                throw new Exception('No AI service configured');
            }
            
            $processingTime = time() - $startTime;
            
            // Update generation record
            $pdo->prepare('
                UPDATE generations 
                SET status = "completed", result_url = ?, processing_time = ?, completed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$result['url'], $processingTime, $generationId]);
            
            return [
                'success' => true,
                'generation_id' => $generationId,
                'result_url' => $result['url'],
                'processing_time' => $processingTime,
                'is_public' => $isPublic,
                'share_token' => $shareToken,
                'share_url' => $shareToken ? '/share/' . $shareToken : null
            ];
            
        } catch (Exception $e) {
            $processingTime = time() - $startTime;
            
            // Update generation record with error
            $pdo->prepare('
                UPDATE generations 
                SET status = "failed", error_message = ?, processing_time = ?, completed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$e->getMessage(), $processingTime, $generationId]);
            
            throw $e;
        }
    }
    
    private function generateWithGemini(array $standingPhotos, array $outfitPhoto, array $outfitAnalysis): array {
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API key not configured');
        }

        // Optimize images before API call to reduce payload size
        $optimizedStanding = $this->optimizeImageForAPI($standingPhotos[0]);
        $optimizedOutfit = $this->optimizeImageForAPI($outfitPhoto);

        // Convert optimized images to base64
        $standingB64 = $this->imageToBase64($optimizedStanding);
        $standingMimeType = $optimizedStanding['type'] ?? 'image/jpeg';

        $outfitB64 = $this->imageToBase64($optimizedOutfit);
        $outfitMimeType = $optimizedOutfit['type'] ?? 'image/jpeg';

        $prompt = $this->getGenerationPrompt($outfitAnalysis['outfit_type']);

        // Prepare request according to Gemini image generation API format
        $requestData = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $standingMimeType,
                            'data' => $standingB64
                        ]
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => $outfitMimeType,
                            'data' => $outfitB64
                        ]
                    ]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 8192
            ]
        ];
        
        // Log the full request structure (without base64 data for readability)
        $debugRequest = $requestData;
        foreach ($debugRequest['contents'][0]['parts'] as &$part) {
            if (isset($part['inline_data']['data'])) {
                $part['inline_data']['data'] = '[BASE64_DATA_' . strlen($part['inline_data']['data']) . '_CHARS]';
            }
        }

        $requestSize = strlen(json_encode($requestData));
        Logger::info('AIService - Making Gemini API request', [
            'model' => 'gemini-2.5-flash-image-preview',
            'parts_count' => count($requestData['contents'][0]['parts']),
            'request_size' => $requestSize,
            'request_size_mb' => round($requestSize / 1024 / 1024, 2),
            'full_request_structure' => $debugRequest,
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent'
        ]);
        
        $startTime = microtime(true);
        $response = $this->makeGeminiRequest($requestData);
        $responseTime = microtime(true) - $startTime;
        
        Logger::info('AIService - Gemini API response received', [
            'response_time_seconds' => round($responseTime, 2),
            'response_size' => strlen(json_encode($response)),
            'response_size_mb' => round(strlen(json_encode($response)) / 1024 / 1024, 2),
            'has_candidates' => isset($response['candidates']),
            'candidates_count' => count($response['candidates'] ?? []),
            'throughput_mb_per_sec' => round($requestSize / 1024 / 1024 / $responseTime, 2)
        ]);
        
        // FULL DEBUG: Log the entire response structure for frontend debugging
        Logger::info('AIService - FULL Gemini Response Structure', [
            'full_response' => $response,
            'candidates_count' => count($response['candidates'] ?? []),
            'first_candidate_parts' => $response['candidates'][0]['content']['parts'] ?? null
        ]);

        // Check if we got an image back
        if (isset($response['candidates'][0]['content']['parts'])) {
            $foundImage = false;
            $foundText = false;
            $textContent = '';

            foreach ($response['candidates'][0]['content']['parts'] as $index => $part) {
                Logger::info("AIService - Examining part $index", [
                    'part_keys' => array_keys($part),
                    'has_inline_data' => isset($part['inline_data']),
                    'has_text' => isset($part['text']),
                    'part_structure' => $part
                ]);

                // Check both possible formats from Gemini API
                $imageData = null;
                $mimeType = null;

                if (isset($part['inline_data']['data'])) {
                    $foundImage = true;
                    $imageData = $part['inline_data']['data'];
                    $mimeType = $part['inline_data']['mime_type'] ?? 'image/jpeg';
                } elseif (isset($part['inlineData']['data'])) {
                    $foundImage = true;
                    $imageData = $part['inlineData']['data'];
                    $mimeType = $part['inlineData']['mimeType'] ?? 'image/jpeg';
                }

                if ($imageData) {

                    Logger::info('AIService - Found image data', [
                        'mime_type' => $mimeType,
                        'data_length' => strlen($imageData),
                        'data_preview' => substr($imageData, 0, 50) . '...'
                    ]);

                    // Save image and return URL
                    $filename = $this->saveGeneratedImage($imageData, $mimeType);
                    $generatedImageUrl = '/generated/' . $filename;

                    // Validate result if outfit type was person wearing outfit
                    if ($outfitAnalysis['outfit_type'] === 'person_wearing_outfit') {
                        $validationResult = $this->validateGeneratedResult($generatedImageUrl, $standingPhotos[0], $outfitPhoto);

                        if (!$validationResult['is_valid'] && $validationResult['needs_correction']) {
                            Logger::info('AIService - Initial result needs correction, attempting fix', [
                                'validation_reason' => $validationResult['reason']
                            ]);

                            // Try to fix the image
                            $correctedResult = $this->correctGeneratedImage($generatedImageUrl, $standingPhotos[0], $outfitPhoto, $validationResult['reason']);
                            if ($correctedResult) {
                                // Delete the original incorrect image
                                $originalPath = __DIR__ . '/../' . ltrim($generatedImageUrl, '/');
                                if (file_exists($originalPath)) {
                                    @unlink($originalPath);
                                }
                                return $correctedResult;
                            }
                        }
                    }

                    return ['url' => $generatedImageUrl];
                }

                if (isset($part['text'])) {
                    $foundText = true;
                    $textContent = $part['text'];
                    Logger::info('AIService - Found text content', [
                        'text_length' => strlen($textContent),
                        'text_content' => $textContent
                    ]);
                }
            }

            if ($foundText && !$foundImage) {
                throw new Exception('AI returned text instead of image: ' . $textContent);
            }
        }

        throw new Exception('No valid response from Gemini API. Response structure: ' . json_encode($response, JSON_PRETTY_PRINT));
    }
    
    private function generateWithOpenAI(array $standingPhotos, array $outfitPhoto, array $outfitAnalysis): array {
        // Note: OpenAI DALL-E doesn't support image input for editing
        // This would need to use a different approach or service
        throw new Exception('OpenAI generation not implemented - requires image-to-image service');
    }
    
    private function makeGeminiRequest(array $data): array {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent?key=' . $this->geminiApiKey;

        // Retry configuration
        $maxRetries = 3;
        $baseDelay = 1; // seconds
        $maxDelay = 30; // seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Logger::info('AIService - Making Gemini API request', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'url' => $url
                ]);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'User-Agent: PicFitAI/1.0'
                    ],
                    CURLOPT_TIMEOUT => 60,  // Reduced from 120s
                    CURLOPT_CONNECTTIMEOUT => 10,  // Connection timeout
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TCP_NODELAY => true,  // Disable Nagle's algorithm
                    CURLOPT_TCP_KEEPALIVE => 1,   // Enable TCP keep-alive
                    CURLOPT_TCP_KEEPIDLE => 10,   // Keep-alive idle time
                    CURLOPT_TCP_KEEPINTVL => 5,   // Keep-alive interval
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,  // Use HTTP/2 if available
                    CURLOPT_ENCODING => 'gzip,deflate'  // Enable compression
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $curlInfo = curl_getinfo($ch);
                curl_close($ch);

                Logger::logApiCall('Gemini', $url, $data, [
                    'attempt' => $attempt,
                    'http_code' => $httpCode,
                    'curl_error' => $error,
                    'total_time' => $curlInfo['total_time'] ?? 0,
                    'response_size' => strlen($response)
                ]);

                if ($error) {
                    throw new Exception('Gemini API request failed: ' . $error);
                }

                // Check for retryable HTTP errors
                if ($httpCode >= 500 || $httpCode === 429) {
                    // Server error or rate limit - retry
                    throw new Exception("Gemini API error: HTTP $httpCode - $response");
                } elseif ($httpCode !== 200) {
                    // Client error - don't retry
                    Logger::error('AIService - Gemini API non-retryable error', [
                        'http_code' => $httpCode,
                        'response' => $response,
                        'attempt' => $attempt
                    ]);
                    throw new Exception("Gemini API error: HTTP $httpCode - $response");
                }

                $responseData = json_decode($response, true);
                if (!$responseData) {
                    throw new Exception('Invalid JSON response from Gemini API');
                }

                if (isset($responseData['error'])) {
                    // Check if error is retryable
                    $errorCode = $responseData['error']['code'] ?? 0;
                    if ($errorCode >= 500 || $errorCode === 429) {
                        throw new Exception('Gemini API error: ' . $responseData['error']['message']);
                    } else {
                        // Non-retryable error
                        Logger::error('AIService - Gemini API non-retryable error', [
                            'error_code' => $errorCode,
                            'error_message' => $responseData['error']['message'],
                            'attempt' => $attempt
                        ]);
                        throw new Exception('Gemini API error: ' . $responseData['error']['message']);
                    }
                }

                // Success!
                Logger::info('AIService - Gemini API request successful', [
                    'attempt' => $attempt,
                    'total_attempts' => $attempt
                ]);
                return $responseData;

            } catch (Exception $e) {
                $isLastAttempt = ($attempt === $maxRetries);
                $isRetryableError = (
                    strpos($e->getMessage(), 'HTTP 5') !== false ||
                    strpos($e->getMessage(), 'HTTP 429') !== false ||
                    strpos($e->getMessage(), 'curl error') !== false ||
                    strpos($e->getMessage(), 'timeout') !== false
                );

                if ($isLastAttempt || !$isRetryableError) {
                    Logger::error('AIService - Gemini API final failure', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                        'retryable' => $isRetryableError
                    ]);
                    throw $e;
                }

                // Calculate exponential backoff delay
                $delay = min($baseDelay * pow(2, $attempt - 1), $maxDelay);

                Logger::warning('AIService - Gemini API retryable error, waiting before retry', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                    'delay_seconds' => $delay
                ]);

                sleep($delay);
            }
        }

        // This should never be reached due to the logic above, but just in case
        throw new Exception('Gemini API request failed after all retries');
    }
    
    private function imageToBase64(array $fileInfo): string {
        if (!isset($fileInfo['tmp_name']) || !file_exists($fileInfo['tmp_name'])) {
            throw new Exception('Invalid image file');
        }
        
        $imageData = file_get_contents($fileInfo['tmp_name']);
        if ($imageData === false) {
            throw new Exception('Failed to read image file');
        }
        
        return base64_encode($imageData);
    }

    /**
     * Optimize image for API calls - resize and compress to reduce payload
     */
    private function optimizeImageForAPI(array $fileInfo): array {
        if (!isset($fileInfo['tmp_name']) || !file_exists($fileInfo['tmp_name'])) {
            return $fileInfo;
        }

        $maxWidth = 1024;  // Reduce from 2048 to 1024 for faster API calls
        $maxHeight = 1024;
        $quality = 0.85;   // Slightly lower quality for smaller files

        try {
            $imageData = file_get_contents($fileInfo['tmp_name']);
            $image = imagecreatefromstring($imageData);
            
            if (!$image) {
                return $fileInfo; // Return original if optimization fails
            }

            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);

            // Calculate new dimensions
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            $newWidth = (int)($originalWidth * $ratio);
            $newHeight = (int)($originalHeight * $ratio);

            // Only resize if significantly larger
            if ($newWidth < $originalWidth * 0.9 || $newHeight < $originalHeight * 0.9) {
                $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

                // Save optimized image to temp file
                $tempFile = tempnam(sys_get_temp_dir(), 'optimized_');
                imagejpeg($resizedImage, $tempFile, (int)($quality * 100));
                
                imagedestroy($image);
                imagedestroy($resizedImage);

                Logger::info('AIService - Image optimized for API', [
                    'original_size' => strlen($imageData),
                    'optimized_size' => filesize($tempFile),
                    'reduction' => round((1 - filesize($tempFile) / strlen($imageData)) * 100, 1) . '%',
                    'dimensions' => "{$originalWidth}x{$originalHeight} → {$newWidth}x{$newHeight}"
                ]);

                return [
                    'tmp_name' => $tempFile,
                    'type' => 'image/jpeg',
                    'size' => filesize($tempFile),
                    'name' => pathinfo($fileInfo['name'], PATHINFO_FILENAME) . '_optimized.jpg'
                ];
            }

            imagedestroy($image);
            return $fileInfo;

        } catch (Exception $e) {
            Logger::warning('AIService - Image optimization failed', ['error' => $e->getMessage()]);
            return $fileInfo; // Return original if optimization fails
        }
    }
    
    public function saveGeneratedImage(string $base64Data, string $mimeType): string {
        $generatedDir = __DIR__ . '/../generated';
        if (!is_dir($generatedDir)) {
            @mkdir($generatedDir, 0755, true);
        }

        // Determine file extension
        $extension = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg'
        };

        $filename = uniqid('fit_', true) . '.' . $extension;
        $filepath = $generatedDir . '/' . $filename;

        // Decode image data
        $imageData = base64_decode($base64Data);
        if ($imageData === false) {
            throw new Exception('Invalid base64 image data');
        }

        // Apply watermark to all generated images
        $watermarkedData = $this->addWatermark($imageData, $mimeType);

        if (file_put_contents($filepath, $watermarkedData) === false) {
            throw new Exception('Failed to save generated image');
        }

        return $filename;
    }

    /**
     * Add PicFit.ai watermark to bottom-right corner of image
     * Uses Imagick for best quality, falls back to GD if Imagick unavailable
     */
    public function addWatermark(string $imageData, string $mimeType): string {
        if (extension_loaded('imagick')) {
            return $this->addWatermarkImagick($imageData, $mimeType);
        } else {
            return $this->addWatermarkGD($imageData, $mimeType);
        }
    }

    /**
     * Add watermark using Imagick (best quality, supports PNG alpha)
     */
    public function addWatermarkImagick(string $imageData, string $mimeType): string {
        try {
            $img = new Imagick();
            $img->readImageBlob($imageData);

            $imgWidth = $img->getImageWidth();
            $imgHeight = $img->getImageHeight();

            // Load watermark logo
            $watermarkPath = __DIR__ . '/../images/picfitlogo.jpg';
            if (!file_exists($watermarkPath)) {
                // Fallback if logo doesn't exist
                Logger::warning('AIService - Watermark logo not found', ['path' => $watermarkPath]);
                return $this->addWatermarkGD($imageData, $mimeType);
            }

            $watermark = new Imagick($watermarkPath);

            // Scale watermark to ~21% of image width (30% smaller than 30%)
            $targetWidth = (int)($imgWidth * 0.21);
            $watermark->scaleImage($targetWidth, 0); // 0 = auto height to maintain ratio

            $wmWidth = $watermark->getImageWidth();
            $wmHeight = $watermark->getImageHeight();

            // Position: pinned to bottom-right corner with padding
            $paddingRight = 90; // pixels from right edge
            $paddingBottom = 250; // pixels from bottom edge (moved up another 100px)
            $x = $imgWidth - $wmWidth - $paddingRight;
            $y = $imgHeight - $wmHeight - $paddingBottom;

            // Composite watermark onto main image
            $img->compositeImage($watermark, Imagick::COMPOSITE_OVER, $x, $y);

            // Set compression quality
            $img->setImageCompressionQuality(85);

            // Get the watermarked image data
            $watermarkedData = $img->getImageBlob();

            $watermark->clear();
            $img->clear();

            Logger::info('AIService - Watermark applied using Imagick', [
                'original_size' => strlen($imageData),
                'watermarked_size' => strlen($watermarkedData),
                'method' => 'imagick',
                'watermark_type' => 'image_overlay'
            ]);

            return $watermarkedData;

        } catch (Exception $e) {
            Logger::warning('AIService - Imagick watermark failed, falling back to GD', [
                'error' => $e->getMessage()
            ]);
            return $this->addWatermarkGD($imageData, $mimeType);
        }
    }

    /**
     * Add watermark using GD (fallback method)
     */
    public function addWatermarkGD(string $imageData, string $mimeType): string {
        // Create image resource from data
        $image = imagecreatefromstring($imageData);
        if (!$image) {
            throw new Exception('Failed to create image from data');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Load watermark logo
        $watermarkPath = __DIR__ . '/../images/picfitlogo.jpg';
        if (file_exists($watermarkPath)) {
            // Use image watermark
            $watermark = imagecreatefromjpeg($watermarkPath);
            if ($watermark) {
                $wmWidth = imagesx($watermark);
                $wmHeight = imagesy($watermark);

                // Scale watermark to ~21% of image width (30% smaller than 30%)
                $targetWidth = (int)($width * 0.21);
                $targetHeight = (int)($wmHeight * ($targetWidth / $wmWidth));

                // Create scaled watermark
                $scaledWatermark = imagecreatetruecolor($targetWidth, $targetHeight);
                imagecopyresampled($scaledWatermark, $watermark, 0, 0, 0, 0,
                                   $targetWidth, $targetHeight, $wmWidth, $wmHeight);

                // Position: pinned to bottom-right corner with padding
                $paddingRight = 90; // pixels from right edge
                $paddingBottom = 250; // pixels from bottom edge (moved up another 100px)
                $x = $width - $targetWidth - $paddingRight;
                $y = $height - $targetHeight - $paddingBottom;

                // Merge watermark onto main image
                imagecopy($image, $scaledWatermark, $x, $y, 0, 0, $targetWidth, $targetHeight);

                imagedestroy($watermark);
                imagedestroy($scaledWatermark);
            }
        } else {
            // Fallback to text watermark if logo not found
            $watermarkText = '👗 picfit.ai';
            $fontSize = max(14, $width * 0.035);
            $fontPath = __DIR__ . '/../public/fonts/Inter-Bold.ttf';

            if (file_exists($fontPath)) {
                $textBox = imagettfbbox($fontSize, 0, $fontPath, $watermarkText);
                $textWidth = $textBox[2] - $textBox[0];
                $x = $width - $textWidth - 30;
                $y = $height - 30;
                $textColor = imagecolorallocatealpha($image, 255, 255, 255, 45);
                imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $watermarkText);
            } else {
                $fontSize = 5;
                $textWidth = imagefontwidth($fontSize) * strlen($watermarkText);
                $x = $width - $textWidth - 30;
                $y = $height - 40;
                $textColor = imagecolorallocate($image, 255, 255, 255);
                imagestring($image, $fontSize, $x, $y, $watermarkText, $textColor);
            }
        }

        // Convert back to binary data
        ob_start();
        switch ($mimeType) {
            case 'image/png':
                imagepng($image, null, 9); // Max compression
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, null, 80);
                } else {
                    imagejpeg($image, null, 85); // Fallback to JPEG
                }
                break;
            default:
                imagejpeg($image, null, 85); // Good quality JPEG
                break;
        }
        $watermarkedData = ob_get_contents();
        ob_end_clean();

        imagedestroy($image);

        Logger::info('AIService - Watermark applied using GD', [
            'original_size' => strlen($imageData),
            'watermarked_size' => strlen($watermarkedData),
            'method' => 'gd'
        ]);

        return $watermarkedData;
    }
    
    private function getGenerationPrompt(string $outfitType = 'clothing_only'): string {
        $basePrompt = "Create a photorealistic SQUARE FORMAT image (1:1 aspect ratio) of the person from the first image wearing the outfit from the second image.

CRITICAL: Try really hard to match the facial features of the person in the first photo - preserve their exact identity, unique facial characteristics, face shape, skin tone, and hair. Make sure that the body and neck area are proportional, so the head and body are not abnormally sized. You should try to match the body shape of the person in the first photo too, so that the size feels natural to their face - maintain their build, height proportions, and overall body structure. The proportions should look natural and realistic.

Take the exact person shown in the first image (preserve their identity, face, hair, body proportions, and pose) and dress them in the clothing items shown in the second image. The person should be positioned facing the camera like a professional fashion model.";

        if ($outfitType === 'person_wearing_outfit') {
            $outfitInstructions = "From the second image, identify ONLY the clothing items: tops, bottoms, shoes, accessories worn by the person in that image. COMPLETELY IGNORE the person's face, body, background, and any other people in the second image - focus EXCLUSIVELY on extracting and identifying the outfit pieces.

SPECIAL ATTENTION TO DETAILS: Look specifically at the EXACT details that are actually present in the outfit. Do NOT add any elements that are not visible. Capture only what exists including:
- The actual fabric texture, patterns, and embellishments that are visible
- The precise cut, silhouette, and neckline as shown (if sleeveless, keep it sleeveless)
- If there are NO sleeves, do NOT add sleeves - preserve the exact sleeve style or lack thereof
- Exact color and any visible design elements
- Only the accessories that are actually visible and worn
- The precise way the outfit actually drapes and flows as shown

Take ONLY the clothing elements that are actually visible in the second image and apply them exactly as they appear to the person from the first image.";
        } else {
            $outfitInstructions = "From the second image, identify and apply each clothing item: tops, bottoms, shoes, accessories.

SPECIAL ATTENTION TO DETAILS: Look specifically at the EXACT details that are actually present in the outfit. Do NOT add any elements that are not visible. Capture only what exists including:
- The actual fabric texture, patterns, and embellishments that are visible
- The precise cut and silhouette as shown
- If there are NO sleeves, do NOT add sleeves - preserve the exact style
- Exact colors and any visible design elements
- Only the accessories that are actually visible

Ensure realistic fit, proper fabric draping, and accurate colors/textures from the original outfit exactly as shown.";
        }

        return $basePrompt . "\n\n" . $outfitInstructions . "\n\n" .
            "IMPORTANT: Frame the shot as a square photo (1:1 aspect ratio) showing the person from head to at least mid-thigh or knee level, ensuring the full head and face are visible within the square frame. Use a slightly wider shot to accommodate the square format.

Set the scene in a bright, clean outdoor environment with natural lighting and soft shadows. The final image should look like professional fashion photography with sharp focus and high detail, optimized for square social media formats.

Generate only the image - do not provide any text description or explanation.";
    }

    private function validateGeneratedResult(string $generatedImageUrl, array $personPhoto, array $outfitPhoto): array {
        if (empty($this->geminiApiKey)) {
            // If no AI available for validation, assume it's valid
            return ['is_valid' => true, 'needs_correction' => false];
        }

        try {
            // Read the generated image
            $generatedImagePath = __DIR__ . '/../' . ltrim($generatedImageUrl, '/');
            if (!file_exists($generatedImagePath)) {
                return ['is_valid' => false, 'needs_correction' => false, 'reason' => 'Generated image not found'];
            }

            $generatedImageData = base64_encode(file_get_contents($generatedImagePath));
            $personB64 = $this->imageToBase64($personPhoto);
            $outfitB64 = $this->imageToBase64($outfitPhoto);

            $validationPrompt = "Analyze these three images:
1. Person photo (reference person)
2. Outfit source photo (may contain a different person wearing clothes)
3. Generated result photo

Check if the generated result shows the REFERENCE PERSON (from image 1) wearing the OUTFIT from image 2, while completely ignoring any person that may be in image 2.

Respond with ONLY ONE of these exact phrases:
- 'CORRECT_PERSON_CORRECT_OUTFIT' - if the generated image shows the reference person wearing the outfit correctly
- 'WRONG_PERSON_MIXED' - if the generated image mixed/blended faces or shows the wrong person from the outfit photo
- 'UNCLEAR_RESULT' - if the result is unclear or has other issues

Look carefully and respond with exactly one of those phrases, nothing else.";

            $requestData = [
                'contents' => [[
                    'parts' => [
                        ['text' => $validationPrompt],
                        [
                            'inline_data' => [
                                'mime_type' => $personPhoto['type'] ?? 'image/jpeg',
                                'data' => $personB64
                            ]
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $outfitPhoto['type'] ?? 'image/jpeg',
                                'data' => $outfitB64
                            ]
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => $generatedImageData
                            ]
                        ]
                    ]
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 50
                ]
            ];

            $response = $this->makeGeminiRequest($requestData);

            if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                $validationResult = trim($response['candidates'][0]['content']['parts'][0]['text']);

                Logger::info('AIService - Validation result', [
                    'validation_result' => $validationResult
                ]);

                switch ($validationResult) {
                    case 'CORRECT_PERSON_CORRECT_OUTFIT':
                        return ['is_valid' => true, 'needs_correction' => false];

                    case 'WRONG_PERSON_MIXED':
                        return [
                            'is_valid' => false,
                            'needs_correction' => true,
                            'reason' => 'Generated image shows wrong person or mixed faces from outfit photo'
                        ];

                    case 'UNCLEAR_RESULT':
                        return [
                            'is_valid' => false,
                            'needs_correction' => true,
                            'reason' => 'Generated result is unclear or has quality issues'
                        ];

                    default:
                        Logger::warning('AIService - Unexpected validation result', [
                            'result' => $validationResult
                        ]);
                        return ['is_valid' => true, 'needs_correction' => false];
                }
            }

            return ['is_valid' => true, 'needs_correction' => false];

        } catch (Exception $e) {
            Logger::error('AIService - Validation error', [
                'error' => $e->getMessage()
            ]);
            return ['is_valid' => true, 'needs_correction' => false];
        }
    }

    private function correctGeneratedImage(string $originalImageUrl, array $personPhoto, array $outfitPhoto, string $reason): ?array {
        if (empty($this->geminiApiKey)) {
            return null;
        }

        try {
            $personB64 = $this->imageToBase64($personPhoto);
            $outfitB64 = $this->imageToBase64($outfitPhoto);

            // Read the problematic generated image
            $originalImagePath = __DIR__ . '/../' . ltrim($originalImageUrl, '/');
            $originalImageData = base64_encode(file_get_contents($originalImagePath));

            $correctionPrompt = "CRITICAL CORRECTION NEEDED: The previous generation failed to properly follow instructions.

Here are the images:
1. Target person (USE THIS PERSON'S FACE AND BODY)
2. Outfit source (EXTRACT ONLY THE CLOTHES, IGNORE ANY PERSON)
3. Failed result (showing the wrong person or mixed faces)

CREATE A NEW CORRECTED VERSION that shows ONLY the person from image 1 wearing ONLY the clothing from image 2.

STRICT REQUIREMENTS:
- Use EXACTLY the face, hair, and body from the TARGET PERSON (image 1)
- COMPLETELY IGNORE any person in the outfit source (image 2) - extract ONLY their clothing
- DO NOT blend or mix faces
- DO NOT use any facial features from the outfit source image
- The result should look like the target person tried on the clothes from image 2

Create a photorealistic SQUARE FORMAT image (1:1 aspect ratio) with professional fashion photography lighting.
Generate only the corrected image - no text.";

            $requestData = [
                'contents' => [[
                    'parts' => [
                        ['text' => $correctionPrompt],
                        [
                            'inline_data' => [
                                'mime_type' => $personPhoto['type'] ?? 'image/jpeg',
                                'data' => $personB64
                            ]
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $outfitPhoto['type'] ?? 'image/jpeg',
                                'data' => $outfitB64
                            ]
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => $originalImageData
                            ]
                        ]
                    ]
                ]],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 8192
                ]
            ];

            $response = $this->makeGeminiRequest($requestData);

            // Process response same as normal generation
            if (isset($response['candidates'][0]['content']['parts'])) {
                foreach ($response['candidates'][0]['content']['parts'] as $part) {
                    $imageData = null;
                    $mimeType = null;

                    if (isset($part['inline_data']['data'])) {
                        $imageData = $part['inline_data']['data'];
                        $mimeType = $part['inline_data']['mime_type'] ?? 'image/jpeg';
                    } elseif (isset($part['inlineData']['data'])) {
                        $imageData = $part['inlineData']['data'];
                        $mimeType = $part['inlineData']['mimeType'] ?? 'image/jpeg';
                    }

                    if ($imageData) {
                        Logger::info('AIService - Generated corrected image', [
                            'mime_type' => $mimeType,
                            'data_length' => strlen($imageData),
                            'correction_reason' => $reason
                        ]);

                        $filename = $this->saveGeneratedImage($imageData, $mimeType);
                        return ['url' => '/generated/' . $filename];
                    }
                }
            }

            Logger::warning('AIService - Correction failed, no image in response');
            return null;

        } catch (Exception $e) {
            Logger::error('AIService - Correction error', [
                'error' => $e->getMessage(),
                'reason' => $reason
            ]);
            return null;
        }
    }

    private function analyzeOutfitImage(array $outfitPhoto): array {
        if (empty($this->geminiApiKey)) {
            // If no AI available for analysis, assume it's valid clothing
            return [
                'is_valid' => true,
                'outfit_type' => 'clothing_only',
                'confidence' => 0.5
            ];
        }

        try {
            $outfitB64 = $this->imageToBase64($outfitPhoto);
            $outfitMimeType = $outfitPhoto['type'] ?? 'image/jpeg';

            $analysisPrompt = "Analyze this image and determine if it contains clothing/outfits that could be worn by someone. Respond with ONLY ONE of these exact phrases:

1. 'CLOTHING_ONLY' - if the image shows clothing items laid out, on hangers, on mannequins, or displayed without a real person wearing them
2. 'PERSON_WEARING_OUTFIT' - if the image shows a real person wearing clothing/outfit
3. 'INVALID_NO_OUTFIT' - if the image does not contain any clear clothing items or outfits (like random objects, landscapes, unclear images, etc.)

Look carefully at the image and respond with exactly one of those three phrases, nothing else.";

            $requestData = [
                'contents' => [[
                    'parts' => [
                        ['text' => $analysisPrompt],
                        [
                            'inline_data' => [
                                'mime_type' => $outfitMimeType,
                                'data' => $outfitB64
                            ]
                        ]
                    ]
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 50
                ]
            ];

            $response = $this->makeGeminiRequest($requestData);

            if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                $analysisResult = trim($response['candidates'][0]['content']['parts'][0]['text']);

                Logger::info('AIService - Outfit analysis result', [
                    'analysis_result' => $analysisResult
                ]);

                switch ($analysisResult) {
                    case 'CLOTHING_ONLY':
                        return [
                            'is_valid' => true,
                            'outfit_type' => 'clothing_only',
                            'confidence' => 0.9
                        ];

                    case 'PERSON_WEARING_OUTFIT':
                        return [
                            'is_valid' => true,
                            'outfit_type' => 'person_wearing_outfit',
                            'confidence' => 0.9
                        ];

                    case 'INVALID_NO_OUTFIT':
                        return [
                            'is_valid' => false,
                            'error_message' => "Sorry, we couldn't figure out what outfit we should put you in from this photo. Try another?",
                            'confidence' => 0.9
                        ];

                    default:
                        // If we get an unexpected response, default to clothing_only
                        Logger::warning('AIService - Unexpected analysis result', [
                            'result' => $analysisResult
                        ]);
                        return [
                            'is_valid' => true,
                            'outfit_type' => 'clothing_only',
                            'confidence' => 0.3
                        ];
                }
            }

            // If analysis fails, default to allowing the image
            Logger::warning('AIService - Outfit analysis failed, defaulting to valid');
            return [
                'is_valid' => true,
                'outfit_type' => 'clothing_only',
                'confidence' => 0.3
            ];

        } catch (Exception $e) {
            Logger::error('AIService - Outfit analysis error', [
                'error' => $e->getMessage()
            ]);

            // If analysis fails, default to allowing the image
            return [
                'is_valid' => true,
                'outfit_type' => 'clothing_only',
                'confidence' => 0.3
            ];
        }
    }

    public static function validateUploadedFiles(array $standingFiles, array $outfitFile): array {
        $errors = [];
        $maxFileSize = Config::get('max_file_size', 10 * 1024 * 1024);
        $maxStandingPhotos = Config::get('max_standing_photos', 5);
        
        // Debug logging
        Logger::debug('AIService::validateUploadedFiles - Input data', [
            'standing_files' => $standingFiles,
            'outfit_file' => $outfitFile
        ]);
        
        // Validate standing photos
        if (empty($standingFiles['tmp_name']) || !is_array($standingFiles['tmp_name'])) {
            Logger::debug('AIService::validateUploadedFiles - No standing photos found or not array', [
                'standing_files_structure' => $standingFiles
            ]);
            $errors[] = 'At least one standing photo is required';
        } else {
            $count = count(array_filter($standingFiles['tmp_name']));
            if ($count === 0) {
                $errors[] = 'At least one standing photo is required';
            } elseif ($count > $maxStandingPhotos) {
                $errors[] = "Maximum $maxStandingPhotos standing photos allowed";
            }
            
            // Check each standing photo
            for ($i = 0; $i < count($standingFiles['tmp_name']); $i++) {
                if (empty($standingFiles['tmp_name'][$i])) continue;
                
                if ($standingFiles['error'][$i] !== UPLOAD_ERR_OK) {
                    $errors[] = "Standing photo " . ($i + 1) . " upload failed";
                    continue;
                }
                
                if ($standingFiles['size'][$i] > $maxFileSize) {
                    $errors[] = "Standing photo " . ($i + 1) . " is too large (max 10MB)";
                }
                
                if (!self::isValidImageType($standingFiles['type'][$i])) {
                    $errors[] = "Standing photo " . ($i + 1) . " is not a valid image type";
                }
            }
        }
        
        // Validate outfit photo
        if (empty($outfitFile['tmp_name'])) {
            $errors[] = 'Outfit photo is required';
        } else {
            if ($outfitFile['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Outfit photo upload failed';
            } elseif ($outfitFile['size'] > $maxFileSize) {
                $errors[] = 'Outfit photo is too large (max 10MB)';
            } elseif (!self::isValidImageType($outfitFile['type'])) {
                $errors[] = 'Outfit photo is not a valid image type';
            }
        }
        
        return $errors;
    }
    
    private static function isValidImageType(string $mimeType): bool {
        return in_array($mimeType, [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/webp'
        ]);
    }
}
