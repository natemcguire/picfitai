<?php
// includes/AIService.php - Streamlined AI generation service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CDNService.php';

class AIService {
    private string $geminiApiKey;

    public function __construct() {
        $this->geminiApiKey = Config::get('gemini_api_key', '');
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API key not configured');
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
        $prompt = "You are a professional fashion AI. Create a photorealistic SQUARE FORMAT image (1:1 aspect ratio) showing the person from the first image wearing the outfit from the second image.

CRITICAL REQUIREMENTS:
1. PRESERVE IDENTITY: Match the exact facial features, skin tone, hair, and body proportions of the person in the first photo
2. EXTRACT OUTFIT: From the second image, take ONLY the clothing items (ignore any people/faces in that image if present)
3. NATURAL PROPORTIONS: Ensure head, neck, and body are proportionally correct
4. EXACT DETAILS: Preserve exact style - if sleeveless, keep sleeveless; preserve cuts, patterns, colors exactly

Frame as a square fashion photo with natural outdoor lighting. Generate ONLY the final image, no text.";

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
     * Save generated image
     */
    private function saveGeneratedImage(string $base64Data, string $mimeType): string {
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

        $filename = uniqid('fit_', true) . '.' . $extension;
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