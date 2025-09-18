<?php
// includes/AIService.php - Streamlined AI generation service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CDNService.php';

class AIService {
    private string $geminiApiKey;
    private string $openaiApiKey;

    public function __construct() {
        $this->geminiApiKey = Config::get('gemini_api_key', '');
        $this->openaiApiKey = Config::get('openai_api_key', '');
        if (empty($this->geminiApiKey) && empty($this->openaiApiKey)) {
            throw new Exception('No AI API keys configured');
        }
    }

    /**
     * Generate fit - streamlined single-call version
     */
    public function generateFit(int $userId, array $standingPhotos, array $outfitPhoto, bool $isPublic = true): array {
        $pdo = Database::getInstance();
        $startTime = time();

        // Generate share token for public photos
        $shareToken = $isPublic ? bin2hex(random_bytes(16)) : null;

        // Create generation record
        $stmt = $pdo->prepare('
            INSERT INTO generations (user_id, status, is_public, share_token)
            VALUES (?, "processing", ?, ?)
        ');
        $stmt->execute([$userId, $isPublic ? 1 : 0, $shareToken]);
        $generationId = $pdo->lastInsertId();

        try {
            // Process with single API call
            $result = $this->generateWithGemini($standingPhotos, $outfitPhoto);
            $processingTime = time() - $startTime;

            // Update generation record with success
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

    /**
     * Single optimized Gemini API call
     */
    private function generateWithGemini(array $standingPhotos, array $outfitPhoto): array {
        // Optimize images for API
        $optimizedStanding = $this->optimizeImageForAPI($standingPhotos[0]);
        $optimizedOutfit = $this->optimizeImageForAPI($outfitPhoto);

        // Convert to base64
        $standingB64 = $this->imageToBase64($optimizedStanding);
        $outfitB64 = $this->imageToBase64($optimizedOutfit);

        // Single combined prompt
        $prompt = "Create a photorealistic square format (1:1) fashion photo.

Using the two images provided:
- First image: This is the person to use. Use their exact face, hair, and body type.
- Second image: This is the outfit to apply. Extract only the clothing.

Generate an image of the person from the first image wearing the outfit from the second image.

Requirements:
1. The face MUST be identical to the person in the first image (never use celebrity faces)
2. The outfit must be the clothing from the second image
3. Show full body in a natural pose
4. The person must be wearing the outfit naturally on their body
5. Professional fashion photo quality with good lighting

Important: If the first person resembles a celebrity, still use the actual person's face from the photo, not the celebrity's face.

Generate only the final image.";

        // API request
        $requestData = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $optimizedStanding['type'] ?? 'image/jpeg',
                            'data' => $standingB64
                        ]
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => $optimizedOutfit['type'] ?? 'image/jpeg',
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

        Logger::info('AIService - Making Gemini API request', [
            'request_size_mb' => round(strlen(json_encode($requestData)) / 1024 / 1024, 2)
        ]);

        $response = $this->makeGeminiRequest($requestData);

        // Extract image from response
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                $imageData = $part['inline_data']['data'] ?? $part['inlineData']['data'] ?? null;
                $mimeType = $part['inline_data']['mime_type'] ?? $part['inlineData']['mimeType'] ?? 'image/jpeg';

                if ($imageData) {
                    $filename = $this->saveGeneratedImage($imageData, $mimeType);
                    $generatedImageUrl = '/generated/' . $filename;

                    // Return CDN URL
                    return ['url' => CDNService::getImageUrl($generatedImageUrl)];
                }
            }
        }

        throw new Exception('No valid image returned from Gemini API');
    }

    /**
     * Make Gemini API request with retry logic
     */
    private function makeGeminiRequest(array $data): array {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent?key=' . $this->geminiApiKey;

        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ],
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($response === false) {
                    throw new Exception("cURL error: {$error}");
                }

                if ($httpCode !== 200) {
                    $errorData = json_decode($response, true);
                    $errorMessage = $errorData['error']['message'] ?? "HTTP {$httpCode}";
                    throw new Exception("Gemini API error: {$errorMessage}");
                }

                $decodedResponse = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON response from Gemini API');
                }

                Logger::info('AIService - Gemini API request successful', [
                    'attempt' => $attempt,
                    'response_size_mb' => round(strlen($response) / 1024 / 1024, 2)
                ]);

                return $decodedResponse;

            } catch (Exception $e) {
                if ($attempt === $maxRetries) {
                    Logger::error('AIService - Gemini API final failure', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }

                // Exponential backoff
                $delay = min(pow(2, $attempt - 1), 10);
                Logger::warning('AIService - Retrying Gemini API', [
                    'attempt' => $attempt,
                    'delay' => $delay,
                    'error' => $e->getMessage()
                ]);
                sleep($delay);
            }
        }

        throw new Exception('Gemini API request failed after all retries');
    }

    /**
     * Optimize image for API calls
     */
    private function optimizeImageForAPI(array $fileInfo): array {
        if (!isset($fileInfo['tmp_name']) || !file_exists($fileInfo['tmp_name'])) {
            return $fileInfo;
        }

        $maxSize = 768; // Optimal size for Gemini
        $quality = 80;  // Good quality/size balance

        try {
            $mimeType = mime_content_type($fileInfo['tmp_name']);

            $image = match($mimeType) {
                'image/jpeg' => imagecreatefromjpeg($fileInfo['tmp_name']),
                'image/png' => imagecreatefrompng($fileInfo['tmp_name']),
                'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($fileInfo['tmp_name']) : null,
                default => imagecreatefromstring(file_get_contents($fileInfo['tmp_name']))
            };

            if (!$image) {
                return $fileInfo;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            // Calculate resize dimensions
            $ratio = min($maxSize / $width, $maxSize / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);

            // Only resize if beneficial
            if ($ratio < 0.9) {
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                $tempFile = tempnam(sys_get_temp_dir(), 'opt_');
                imagejpeg($resized, $tempFile, $quality);

                imagedestroy($image);
                imagedestroy($resized);

                return [
                    'tmp_name' => $tempFile,
                    'type' => 'image/jpeg',
                    'size' => filesize($tempFile),
                    'name' => 'optimized.jpg'
                ];
            }

            imagedestroy($image);
            return $fileInfo;

        } catch (Exception $e) {
            Logger::warning('Image optimization failed', ['error' => $e->getMessage()]);
            return $fileInfo;
        }
    }

    /**
     * Convert image to base64
     */
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
     * Generate sign image with Gemini using text-only prompt (Nano Banana)
     * Used specifically for signs generator
     */
    public function generateSignWithGemini(string $prompt, int $width = 1024, int $height = 1024): string {
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API key not configured');
        }

        // API request with text-only prompt for image generation
        $requestData = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 8192
            ]
        ];

        Logger::info('AIService - Making Gemini API request for text-to-image generation');

        $response = $this->makeGeminiRequest($requestData);

        // Extract image from response
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                $imageData = $part['inline_data']['data'] ?? $part['inlineData']['data'] ?? null;
                $mimeType = $part['inline_data']['mime_type'] ?? $part['inlineData']['mimeType'] ?? 'image/jpeg';

                if ($imageData) {
                    $filename = $this->saveGeneratedImage($imageData, $mimeType, 'sign');
                    return CDNService::getImageUrl('/generated/' . $filename);
                }
            }
        }

        throw new Exception('No valid image returned from Gemini text-to-image API');
    }

    /**
     * Generate sign image with Gemini (with image input - for signs feature)
     */
    public function generateSignWithGeminiAndImage(string $imageBase64, string $prompt, int $width = 1024, int $height = 1024): string {
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API key not configured');
        }

        // API request with image and prompt
        $requestData = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $imageBase64
                        ]
                    ]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 8192
            ]
        ];

        Logger::info('AIService - Making Gemini API request for generic generation');

        $response = $this->makeGeminiRequest($requestData);

        // Extract image from response
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                $imageData = $part['inline_data']['data'] ?? $part['inlineData']['data'] ?? null;
                $mimeType = $part['inline_data']['mime_type'] ?? $part['inlineData']['mimeType'] ?? 'image/jpeg';

                if ($imageData) {
                    $filename = $this->saveGeneratedImage($imageData, $mimeType, 'sign');
                    return CDNService::getImageUrl('/generated/' . $filename);
                }
            }
        }

        throw new Exception('No valid image returned from Gemini API');
    }

    /**
     * Extract text from image using Gemini OCR
     */
    public function extractTextFromImage(string $imageBase64): string {
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API key not configured');
        }

        $ocrPrompt = "Extract all text from this image. Return only the text content, no descriptions or explanations. If there is no text, return 'No text found'.";

        // API request for OCR
        $requestData = [
            'contents' => [[
                'parts' => [
                    ['text' => $ocrPrompt],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $imageBase64
                        ]
                    ]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 1024
            ]
        ];

        Logger::info('AIService - Making Gemini API request for OCR');

        $response = $this->makeGeminiRequest($requestData);

        // Extract text response
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text = trim($part['text']);
                    Logger::info('AIService - OCR completed', ['text_length' => strlen($text)]);
                    return $text;
                }
            }
        }

        throw new Exception('No text response from Gemini OCR API');
    }

    /**
     * Analyze image with Gemini and return text description/prompt
     */
    public function analyzeImageWithGemini(string $imageBase64, string $prompt): string {
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API key not configured');
        }

        // API request for image analysis (text output only)
        $requestData = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data' => $imageBase64
                        ]
                    ]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048
            ]
        ];

        Logger::info('AIService - Making Gemini API request for image analysis');

        $response = $this->makeGeminiRequest($requestData);

        // Extract text response
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text = trim($part['text']);
                    Logger::info('AIService - Gemini analysis completed', ['text_length' => strlen($text)]);
                    return $text;
                }
            }
        }

        throw new Exception('No valid text response from Gemini API');
    }

    /**
     * Generate sign image with DALL-E (for signs generator)
     */
    public function generateSignWithDallE(string $prompt, int $width = 1024, int $height = 1024): string {
        if (empty($this->openaiApiKey)) {
            throw new Exception('OpenAI API key not configured');
        }

        // DALL-E 3 supports only specific sizes
        $size = $this->getValidDallESize($width, $height);

        $requestData = [
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'size' => $size,
            'quality' => 'standard',
            'n' => 1
        ];

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiApiKey
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("OpenAI API cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? "HTTP {$httpCode}";
            throw new Exception("OpenAI API error: {$errorMessage}");
        }

        $data = json_decode($response, true);
        if (!isset($data['data'][0]['url'])) {
            throw new Exception('No image URL returned from OpenAI API');
        }

        // Download and save the image
        $imageUrl = $data['data'][0]['url'];
        $imageContent = file_get_contents($imageUrl);

        if ($imageContent === false) {
            throw new Exception('Failed to download image from OpenAI');
        }

        // Save to generated folder with "sign" prefix
        $generatedDir = __DIR__ . '/../generated';
        if (!is_dir($generatedDir)) {
            @mkdir($generatedDir, 0755, true);
        }

        $filename = 'sign_' . uniqid('', true) . '.png';
        $filepath = $generatedDir . '/' . $filename;

        if (file_put_contents($filepath, $imageContent) === false) {
            throw new Exception('Failed to save generated image');
        }

        Logger::info('AIService - DALL-E sign image saved', [
            'filename' => $filename,
            'size' => $size
        ]);

        return CDNService::getImageUrl('/generated/' . $filename);
    }

    /**
     * Generate image with OpenAI DALL-E
     */
    public function generateWithDallE(string $prompt, int $width = 1024, int $height = 1024): string {
        if (empty($this->openaiApiKey)) {
            throw new Exception('OpenAI API key not configured');
        }

        // DALL-E 3 supports only specific sizes
        $size = $this->getValidDallESize($width, $height);

        $requestData = [
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'size' => $size,
            'quality' => 'standard',
            'n' => 1
        ];

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiApiKey
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("OpenAI API cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? "HTTP {$httpCode}";
            throw new Exception("OpenAI API error: {$errorMessage}");
        }

        $data = json_decode($response, true);
        if (!isset($data['data'][0]['url'])) {
            throw new Exception('No image URL returned from OpenAI API');
        }

        // Download and save the image
        $imageUrl = $data['data'][0]['url'];
        $imageContent = file_get_contents($imageUrl);

        if ($imageContent === false) {
            throw new Exception('Failed to download image from OpenAI');
        }

        // Save to generated folder
        $generatedDir = __DIR__ . '/../generated';
        if (!is_dir($generatedDir)) {
            @mkdir($generatedDir, 0755, true);
        }

        $filename = 'sign_' . uniqid('', true) . '.png';
        $filepath = $generatedDir . '/' . $filename;

        if (file_put_contents($filepath, $imageContent) === false) {
            throw new Exception('Failed to save generated image');
        }

        Logger::info('AIService - DALL-E image saved', [
            'filename' => $filename,
            'size' => $size
        ]);

        return CDNService::getImageUrl('/generated/' . $filename);
    }

    /**
     * Get valid DALL-E 3 size based on requested dimensions
     */
    private function getValidDallESize(int $width, int $height): string {
        // DALL-E 3 supports: 1024x1024, 1024x1792, 1792x1024
        $aspectRatio = $width / $height;

        if ($aspectRatio > 1.4) {
            // Landscape
            return '1792x1024';
        } elseif ($aspectRatio < 0.7) {
            // Portrait
            return '1024x1792';
        } else {
            // Square or close to square
            return '1024x1024';
        }
    }

    /**
     * Save generated image
     */
    private function saveGeneratedImage(string $base64Data, string $mimeType, string $prefix = 'fit'): string {
        $generatedDir = __DIR__ . '/../generated';
        if (!is_dir($generatedDir)) {
            @mkdir($generatedDir, 0755, true);
        }

        $extension = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg'
        };

        $filename = $prefix . '_' . uniqid('', true) . '.' . $extension;
        $filepath = $generatedDir . '/' . $filename;

        $imageData = base64_decode($base64Data);
        if ($imageData === false) {
            throw new Exception('Failed to decode image data');
        }

        if (file_put_contents($filepath, $imageData) === false) {
            throw new Exception('Failed to save generated image');
        }

        Logger::info('AIService - Image saved', [
            'filename' => $filename,
            'size_kb' => round(strlen($imageData) / 1024, 1)
        ]);

        return $filename;
    }
}