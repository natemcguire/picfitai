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
    
    public function generateFit(int $userId, array $standingPhotos, array $outfitPhoto): array {
        $pdo = Database::getInstance();
        
        // Create generation record
        $stmt = $pdo->prepare('
            INSERT INTO generations (user_id, status, input_data)
            VALUES (?, "processing", ?)
        ');
        $stmt->execute([$userId, json_encode([
            'standing_photos_count' => count($standingPhotos),
            'has_outfit_photo' => !empty($outfitPhoto)
        ])]);
        
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
                'processing_time' => $processingTime
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
        
        // Convert images to base64
        $standingB64 = [];
        foreach ($standingPhotos as $photo) {
            $standingB64[] = $this->imageToBase64($photo);
        }
        $outfitB64 = $this->imageToBase64($outfitPhoto);
        
        $prompt = $this->getGenerationPrompt();
        
        // Prepare request
        $parts = [
            ['text' => $prompt]
        ];
        
        // Add first standing photo
        if (!empty($standingB64)) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data' => $standingB64[0]
                ]
            ];
        }
        
        // Add outfit photo
        $parts[] = [
            'inline_data' => [
                'mime_type' => 'image/jpeg', 
                'data' => $outfitB64
            ]
        ];
        
        $requestData = [
            'contents' => [[
                'role' => 'user',
                'parts' => $parts
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048
            ]
        ];
        
        Logger::info('AIService - Making Gemini API request', [
            'model' => 'gemini-2.5-flash-image-preview',
            'parts_count' => count($parts),
            'request_size' => strlen(json_encode($requestData))
        ]);
        
        $response = $this->makeGeminiRequest($requestData);
        
        Logger::info('AIService - Gemini API response received', [
            'response_size' => strlen(json_encode($response)),
            'has_candidates' => isset($response['candidates']),
            'candidates_count' => count($response['candidates'] ?? [])
        ]);
        
        // Check if we got an image back
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inline_data']['data'])) {
                    $imageData = $part['inline_data']['data'];
                    $mimeType = $part['inline_data']['mime_type'] ?? 'image/jpeg';
                    
                    // Save image and return URL
                    $filename = $this->saveGeneratedImage($imageData, $mimeType);
                    return ['url' => '/generated/' . $filename];
                }
            }
        }
        
        // If no image, return text response or error
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $text = $response['candidates'][0]['content']['parts'][0]['text'];
            throw new Exception('AI returned text instead of image: ' . $text);
        }
        
        throw new Exception('No valid response from Gemini API');
    }
    
    private function generateWithOpenAI(array $standingPhotos, array $outfitPhoto): array {
        // Note: OpenAI DALL-E doesn't support image input for editing
        // This would need to use a different approach or service
        throw new Exception('OpenAI generation not implemented - requires image-to-image service');
    }
    
    private function makeGeminiRequest(array $data): array {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent?key=' . $this->geminiApiKey;
        
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
            'http_code' => $httpCode,
            'curl_error' => $error,
            'total_time' => $curlInfo['total_time'] ?? 0,
            'response_size' => strlen($response)
        ]);
        
        if ($error) {
            Logger::error('AIService - Gemini API curl error', ['error' => $error]);
            throw new Exception('Gemini API request failed: ' . $error);
        }
        
        if ($httpCode !== 200) {
            Logger::error('AIService - Gemini API HTTP error', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            throw new Exception("Gemini API error: HTTP $httpCode - $response");
        }
        
        $responseData = json_decode($response, true);
        if (!$responseData) {
            throw new Exception('Invalid JSON response from Gemini API');
        }
        
        if (isset($responseData['error'])) {
            throw new Exception('Gemini API error: ' . $responseData['error']['message']);
        }
        
        return $responseData;
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
        $generatedDir = dirname(__DIR__) . '/generated';
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
        return "Task: Virtual try-on composition.

Use the exact person from the first image (full-body standing photo). Preserve their identity, pose, body proportions, hair, skin tone, facial features. Camera perspective should be standing facing the camera like a model. Pay close attention to the face shape and eyes to try to make them look exactly like the person in the standing photo.

From the second image (flat-lay outfit), identify each clothing item and accessories (top, bottoms, shoes, bag, scarf, jewelry). Dress the person in those exact items with realistic fit, fabric behavior, and layering. Maintain correct scale, drape, and contact points at shoulders, waist, hips, and feet. Keep all garment textures, colors, and details accurate.

Background: Replace with a photorealistic bright outdoor scene (parklet/patio/garden vibe). Natural, slightly directional daylight; soft shadows; no other people.

Output: A single photorealistic image of the same person now wearing the outfit from the flat-lay. Frame like professional fashion photography. Person should be facing the camera like a model. Avoid artifacts, misalignment, or extra items.

When you make the output, make sure to double check that all the items you identified in the flat-lay photo are present in the output image. Remove any other items that are not in the flat-lay photo, like hats, or phones (no selfies or phones).";
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
