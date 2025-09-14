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
        
        // Generate share token for public photos
        $shareToken = $isPublic ? bin2hex(random_bytes(16)) : null;

        // Create generation record
        $stmt = $pdo->prepare('
            INSERT INTO generations (user_id, status, input_data, is_public, share_token)
            VALUES (?, "processing", ?, ?, ?)
        ');
        $stmt->execute([$userId, json_encode([
            'standing_photos_count' => count($standingPhotos),
            'has_outfit_photo' => !empty($outfitPhoto),
            'is_public' => $isPublic
        ]), $isPublic ? 1 : 0, $shareToken]);
        
        $generationId = $pdo->lastInsertId();
        $startTime = time();
        
        try {
            // Try Gemini first if available
            if (!empty($this->geminiApiKey)) {
                $result = $this->generateWithGemini($standingPhotos, $outfitPhoto);
            } elseif (!empty($this->openaiApiKey)) {
                $result = $this->generateWithOpenAI($standingPhotos, $outfitPhoto);
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
    
    private function generateWithGemini(array $standingPhotos, array $outfitPhoto): array {
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API key not configured');
        }

        // Convert images to base64 with proper MIME types
        $standingB64 = $this->imageToBase64($standingPhotos[0]);
        $standingMimeType = $standingPhotos[0]['type'] ?? 'image/jpeg';

        $outfitB64 = $this->imageToBase64($outfitPhoto);
        $outfitMimeType = $outfitPhoto['type'] ?? 'image/jpeg';

        $prompt = $this->getGenerationPrompt();

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

        Logger::info('AIService - Making Gemini API request', [
            'model' => 'gemini-2.5-flash-image-preview',
            'parts_count' => count($requestData['contents'][0]['parts']),
            'request_size' => strlen(json_encode($requestData)),
            'full_request_structure' => $debugRequest,
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent'
        ]);
        
        $response = $this->makeGeminiRequest($requestData);
        
        Logger::info('AIService - Gemini API response received', [
            'response_size' => strlen(json_encode($response)),
            'has_candidates' => isset($response['candidates']),
            'candidates_count' => count($response['candidates'] ?? [])
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
                    return ['url' => '/generated/' . $filename];
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
    
    private function generateWithOpenAI(array $standingPhotos, array $outfitPhoto): array {
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
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT => 120 // AI generation can take time
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
    
    private function saveGeneratedImage(string $base64Data, string $mimeType): string {
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
        
        $imageData = base64_decode($base64Data);
        if ($imageData === false) {
            throw new Exception('Invalid base64 image data');
        }
        
        if (file_put_contents($filepath, $imageData) === false) {
            throw new Exception('Failed to save generated image');
        }
        
        return $filename;
    }
    
    private function getGenerationPrompt(): string {
        return "Create a photorealistic SQUARE FORMAT image (1:1 aspect ratio) of the person from the first image wearing the outfit from the second image.

Take the exact person shown in the first image (preserve their identity, face, hair, body proportions, and pose) and dress them in the clothing items shown in the second image. The person should be positioned facing the camera like a professional fashion model.

From the second image, identify and apply each clothing item: tops, bottoms, shoes, accessories. Ensure realistic fit, proper fabric draping, and accurate colors/textures from the original outfit. If there is a person in the second image, disregard their face and body - focus only on the outfit and accessories, no background. Identify the outfit items and place them on the first person.

IMPORTANT: Frame the shot as a square photo (1:1 aspect ratio) showing the person from head to at least mid-thigh or knee level, ensuring the full head and face are visible within the square frame. Use a slightly wider shot to accommodate the square format.

Set the scene in a bright, clean outdoor environment with natural lighting and soft shadows. The final image should look like professional fashion photography with sharp focus and high detail, optimized for square social media formats.

Generate only the image - do not provide any text description or explanation.";
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
