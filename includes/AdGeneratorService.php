<?php
// includes/AdGeneratorService.php - AI-powered ad generation service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/CDNService.php';

class AdGeneratorService {
    private string $geminiApiKey;
    private string $openaiApiKey;
    private int $currentUserId;
    private array $adSizes = [
        // Facebook/Meta
        'facebook_feed' => ['width' => 1200, 'height' => 630, 'name' => 'Facebook Feed Post'],
        'facebook_square' => ['width' => 1080, 'height' => 1080, 'name' => 'Facebook Square Post'],
        'facebook_story' => ['width' => 1080, 'height' => 1920, 'name' => 'Facebook Story'],

        // Instagram
        'instagram_feed' => ['width' => 1080, 'height' => 1080, 'name' => 'Instagram Feed Square'],
        'instagram_story' => ['width' => 1080, 'height' => 1920, 'name' => 'Instagram Story'],
        'instagram_reel' => ['width' => 1080, 'height' => 1920, 'name' => 'Instagram Reel'],
        'instagram_portrait' => ['width' => 1080, 'height' => 1350, 'name' => 'Instagram Portrait (4:5)'],

        // Twitter/X
        'twitter_post' => ['width' => 1200, 'height' => 675, 'name' => 'Twitter/X Post (16:9)'],
        'twitter_square' => ['width' => 1080, 'height' => 1080, 'name' => 'Twitter/X Square'],
        'twitter_header' => ['width' => 1500, 'height' => 500, 'name' => 'Twitter/X Header'],

        // LinkedIn
        'linkedin_feed' => ['width' => 1200, 'height' => 627, 'name' => 'LinkedIn Feed Post'],
        'linkedin_square' => ['width' => 1080, 'height' => 1080, 'name' => 'LinkedIn Square'],
        'linkedin_story' => ['width' => 1080, 'height' => 1920, 'name' => 'LinkedIn Story'],

        // TikTok
        'tiktok_video' => ['width' => 1080, 'height' => 1920, 'name' => 'TikTok Video (9:16)'],

        // YouTube
        'youtube_thumbnail' => ['width' => 1280, 'height' => 720, 'name' => 'YouTube Thumbnail'],
        'youtube_short' => ['width' => 1080, 'height' => 1920, 'name' => 'YouTube Short'],

        // Pinterest
        'pinterest_pin' => ['width' => 1000, 'height' => 1500, 'name' => 'Pinterest Pin (2:3)'],
        'pinterest_square' => ['width' => 1080, 'height' => 1080, 'name' => 'Pinterest Square'],

        // Snapchat
        'snapchat_ad' => ['width' => 1080, 'height' => 1920, 'name' => 'Snapchat Ad'],

        // Google Ads
        'google_banner' => ['width' => 728, 'height' => 90, 'name' => 'Google Banner (Leaderboard)'],
        'google_rectangle' => ['width' => 300, 'height' => 250, 'name' => 'Google Rectangle (Medium)'],
        'google_skyscraper' => ['width' => 160, 'height' => 600, 'name' => 'Google Skyscraper'],
        'google_large_rectangle' => ['width' => 336, 'height' => 280, 'name' => 'Google Large Rectangle'],

        // Universal
        'universal_landscape' => ['width' => 1920, 'height' => 1080, 'name' => 'Universal Landscape (16:9)'],
        'universal_portrait' => ['width' => 1080, 'height' => 1920, 'name' => 'Universal Portrait (9:16)'],
        'universal_square' => ['width' => 1080, 'height' => 1080, 'name' => 'Universal Square (1:1)']
    ];

    public function __construct(int $userId) {
        $this->geminiApiKey = Config::get('gemini_api_key', '');
        $this->openaiApiKey = Config::get('openai_api_key', '');
        $this->currentUserId = $userId;

        if (empty($this->geminiApiKey) && empty($this->openaiApiKey)) {
            throw new Exception('No AI API key configured (Gemini or OpenAI required)');
        }
    }

    /**
     * Generate a complete ad set with multiple sizes
     */
    public function generateAdSet(
        array $styleGuide,
        array $selectedSizes,
        string $campaignName = 'Untitled Campaign'
    ): array {
        $userId = $this->currentUserId;
        $pdo = Database::getInstance();
        $generatedAds = [];

        Logger::info('AdGeneratorService - Starting ad set generation', [
            'user_id' => $userId,
            'campaign_name' => $campaignName,
            'selected_sizes' => $selectedSizes,
            'style_guide_keys' => array_keys($styleGuide)
        ]);

        // Create ad campaign record
        $stmt = $pdo->prepare('
            INSERT INTO ad_campaigns (user_id, campaign_name, style_guide, status)
            VALUES (?, ?, ?, "processing")
        ');
        $stmt->execute([$userId, $campaignName, json_encode($styleGuide)]);
        $campaignId = $pdo->lastInsertId();

        Logger::info('AdGeneratorService - Campaign created', [
            'campaign_id' => $campaignId,
            'user_id' => $userId
        ]);

        try {
            $successCount = 0;
            $failedAds = [];

            foreach ($selectedSizes as $sizeKey) {
                if (!isset($this->adSizes[$sizeKey])) {
                    Logger::warning('AdGeneratorService - Invalid ad size requested', [
                        'size_key' => $sizeKey,
                        'campaign_id' => $campaignId
                    ]);
                    continue;
                }

                $size = $this->adSizes[$sizeKey];

                Logger::info('AdGeneratorService - Generating ad', [
                    'campaign_id' => $campaignId,
                    'ad_type' => $sizeKey,
                    'dimensions' => "{$size['width']}x{$size['height']}"
                ]);

                try {
                    $adData = $this->generateSingleAd(
                        $styleGuide,
                        $size['width'],
                        $size['height'],
                        $sizeKey
                    );

                    // Store ad record
                    $stmt = $pdo->prepare('
                        INSERT INTO ad_generations (
                            campaign_id, user_id, ad_type, width, height,
                            image_url, prompt_used, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, "completed")
                    ');
                    $stmt->execute([
                        $campaignId,
                        $userId,
                        $sizeKey,
                        $size['width'],
                        $size['height'],
                        $adData['image_url'],
                        $adData['prompt']
                    ]);

                    $generatedAds[] = [
                        'id' => $pdo->lastInsertId(),
                        'type' => $sizeKey,
                        'name' => $size['name'],
                        'dimensions' => "{$size['width']}x{$size['height']}",
                        'image_url' => CDNService::getImageUrl($adData['image_url']),
                        'with_text_url' => isset($adData['with_text_url']) ? CDNService::getImageUrl($adData['with_text_url']) : null
                    ];

                    $successCount++;

                    Logger::info('AdGeneratorService - Ad generated successfully', [
                        'campaign_id' => $campaignId,
                        'ad_type' => $sizeKey,
                        'image_url' => $adData['image_url']
                    ]);

                } catch (Exception $adError) {
                    Logger::error('AdGeneratorService - Failed to generate individual ad', [
                        'campaign_id' => $campaignId,
                        'ad_type' => $sizeKey,
                        'error' => $adError->getMessage()
                    ]);

                    $failedAds[] = [
                        'type' => $sizeKey,
                        'name' => $size['name'],
                        'error' => $adError->getMessage()
                    ];
                }
            }

            // Update campaign status based on results
            if ($successCount > 0) {
                $pdo->prepare('
                    UPDATE ad_campaigns
                    SET status = "completed", completed_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ')->execute([$campaignId]);

                Logger::info('AdGeneratorService - Campaign completed', [
                    'campaign_id' => $campaignId,
                    'success_count' => $successCount,
                    'failed_count' => count($failedAds)
                ]);

                return [
                    'success' => true,
                    'campaign_id' => $campaignId,
                    'ads' => $generatedAds,
                    'failed_ads' => $failedAds
                ];
            } else {
                throw new Exception('All ad generations failed. Please check your API configuration.');
            }

        } catch (Exception $e) {
            Logger::error('AdGeneratorService - Campaign generation failed', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $pdo->prepare('
                UPDATE ad_campaigns
                SET status = "failed", error_message = ?
                WHERE id = ?
            ')->execute([$e->getMessage(), $campaignId]);

            throw $e;
        }
    }

    /**
     * Generate a single ad using Gemini API
     */
    private function generateSingleAd(
        array $styleGuide,
        int $width,
        int $height,
        string $adType
    ): array {
        // Build prompt from style guide
        $prompt = $this->buildAdPrompt($styleGuide, $adType, $width, $height);

        // Call Gemini API for image generation
        $imageUrl = $this->callGeminiForImage($prompt, $width, $height, $styleGuide);

        $result = [
            'image_url' => $imageUrl,
            'prompt' => $prompt
        ];

        // Add text overlay if needed
        if (!empty($styleGuide['headline']) || !empty($styleGuide['cta_text'])) {
            $result['with_text_url'] = $this->addTextOverlay(
                $imageUrl,
                $styleGuide['headline'] ?? '',
                $styleGuide['body_text'] ?? '',
                $styleGuide['cta_text'] ?? '',
                $styleGuide
            );
        }

        return $result;
    }

    /**
     * Call Gemini API for image generation
     */
    private function callGeminiForImage(string $prompt, int $width, int $height, array $styleGuide = []): string {
        Logger::info('AdGeneratorService - Preparing Gemini image generation', [
            'width' => $width,
            'height' => $height,
            'prompt_preview' => substr($prompt, 0, 200) . '...',
            'api_key_configured' => !empty($this->geminiApiKey)
        ]);

        // Build request parts (text + optional logo)
        $parts = [['text' => $prompt]];

        // Add logo image if provided
        if (!empty($styleGuide['logo']['data']) && !empty($styleGuide['logo']['mime_type'])) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $styleGuide['logo']['mime_type'],
                    'data' => $styleGuide['logo']['data']
                ]
            ];

            Logger::info('AdGeneratorService - Including logo in generation', [
                'logo_mime_type' => $styleGuide['logo']['mime_type'],
                'logo_size_kb' => round(strlen($styleGuide['logo']['data']) / 1024, 1)
            ]);
        }

        // Use Gemini 2.5 Flash Image Preview for image generation
        $requestData = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 8192
            ]
        ];

        Logger::info('AdGeneratorService - Making Gemini API request', [
            'request_size_kb' => round(strlen(json_encode($requestData)) / 1024, 2),
            'prompt_length' => strlen($prompt),
            'model' => 'gemini-2.5-flash-image-preview'
        ]);

        try {
            $response = $this->makeGeminiRequest($requestData);

            Logger::info('AdGeneratorService - Gemini response received', [
                'has_candidates' => isset($response['candidates']),
                'num_candidates' => count($response['candidates'] ?? [])
            ]);

            // Extract image from response
            if (isset($response['candidates'][0]['content']['parts'])) {
                $partsCount = count($response['candidates'][0]['content']['parts']);
                Logger::info('AdGeneratorService - Processing response parts', [
                    'num_parts' => $partsCount
                ]);

                foreach ($response['candidates'][0]['content']['parts'] as $index => $part) {
                    $imageData = $part['inline_data']['data'] ?? $part['inlineData']['data'] ?? null;
                    $mimeType = $part['inline_data']['mime_type'] ?? $part['inlineData']['mimeType'] ?? 'image/jpeg';

                    Logger::debug('AdGeneratorService - Checking part', [
                        'part_index' => $index,
                        'has_image_data' => !empty($imageData),
                        'mime_type' => $mimeType
                    ]);

                    if ($imageData) {
                        Logger::info('AdGeneratorService - Image data found, saving', [
                            'mime_type' => $mimeType,
                            'data_length' => strlen($imageData)
                        ]);

                        $savedUrl = $this->saveGeneratedImageFromBase64($imageData, $mimeType);

                        Logger::info('AdGeneratorService - Image saved successfully', [
                            'url' => $savedUrl
                        ]);

                        return $savedUrl;
                    }
                }
            }

            Logger::error('AdGeneratorService - No image in Gemini response', [
                'response_structure' => array_keys($response),
                'candidates_structure' => isset($response['candidates'][0]) ? array_keys($response['candidates'][0]) : []
            ]);

            throw new Exception('No valid image returned from Gemini API - response structure invalid');

        } catch (Exception $e) {
            Logger::warning('AdGeneratorService - Gemini generation failed', [
                'error' => $e->getMessage(),
                'has_openai_fallback' => !empty($this->openaiApiKey)
            ]);

            // Fallback to DALL-E if available
            if (!empty($this->openaiApiKey)) {
                Logger::info('AdGeneratorService - Attempting DALL-E fallback');
                return $this->callDallE($prompt, $width, $height);
            }

            // Last resort: generate placeholder
            Logger::warning('AdGeneratorService - Using placeholder image');
            return $this->generatePlaceholderImage($width, $height, $prompt);
        }
    }

    /**
     * Make Gemini API request with retry logic (copied from AIService)
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

                Logger::info('AdGeneratorService - Gemini API request successful', [
                    'attempt' => $attempt,
                    'response_size_mb' => round(strlen($response) / 1024 / 1024, 2)
                ]);

                return $decodedResponse;

            } catch (Exception $e) {
                if ($attempt === $maxRetries) {
                    Logger::error('AdGeneratorService - Gemini API final failure', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }

                // Exponential backoff
                $delay = min(pow(2, $attempt - 1), 10);
                Logger::warning('AdGeneratorService - Retrying Gemini API', [
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
     * Call OpenAI DALL-E for image generation
     */
    private function callDallE(string $prompt, int $width, int $height): string {
        $ch = curl_init('https://api.openai.com/v1/images/generations');

        // DALL-E 3 supports specific sizes
        $size = $this->getDallESize($width, $height);

        $payload = [
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => 'standard',
            'style' => 'vivid'
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->openaiApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error('DALL-E API error', ['response' => $response]);
            throw new Exception('Failed to generate image with DALL-E');
        }

        $data = json_decode($response, true);
        if (!isset($data['data'][0]['url'])) {
            throw new Exception('Invalid response from DALL-E API');
        }

        // Download and save image locally, then resize to exact dimensions
        $imageUrl = $this->saveGeneratedImage($data['data'][0]['url'], $this->currentUserId);
        return $this->resizeImage($imageUrl, $width, $height);
    }

    /**
     * Get DALL-E compatible size
     */
    private function getDallESize(int $width, int $height): string {
        $ratio = $width / $height;

        // DALL-E 3 supported sizes
        if ($ratio > 1.5) {
            return '1792x1024'; // Landscape
        } elseif ($ratio < 0.7) {
            return '1024x1792'; // Portrait
        } else {
            return '1024x1024'; // Square
        }
    }

    /**
     * Generate a placeholder image when no image API is available
     */
    private function generatePlaceholderImage(int $width, int $height, string $prompt): string {
        $image = imagecreatetruecolor($width, $height);

        // Use a gradient background
        $color1 = imagecolorallocate($image, 33, 150, 243); // Blue
        $color2 = imagecolorallocate($image, 156, 39, 176); // Purple

        // Create gradient
        for ($i = 0; $i < $height; $i++) {
            $alpha = $i / $height;
            $r = (int)(33 + (156 - 33) * $alpha);
            $g = (int)(150 + (39 - 150) * $alpha);
            $b = (int)(243 + (176 - 243) * $alpha);
            $color = imagecolorallocate($image, $r, $g, $b);
            imageline($image, 0, $i, $width, $i, $color);
        }

        // Add text
        $white = imagecolorallocate($image, 255, 255, 255);
        $text = "Ad Placeholder\n" . $width . 'x' . $height;
        $fontSize = min(30, $width / 20);
        $fontPath = __DIR__ . '/../fonts/Roboto-Bold.ttf';

        if (file_exists($fontPath)) {
            imagettftext($image, (int)$fontSize, 0, (int)($width/2 - 100), (int)($height/2), $white, $fontPath, $text);
        }

        // Save image to user folder
        $filename = uniqid('ad_placeholder_') . '.jpg';
        $userFolder = $this->createUserAdFolder($this->currentUserId);
        $relativePath = $userFolder . '/' . $filename;
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $relativePath;

        imagejpeg($image, $fullPath, 90);
        imagedestroy($image);

        return $relativePath;
    }

    /**
     * Resize image to exact dimensions
     */
    private function resizeImage(string $imagePath, int $targetWidth, int $targetHeight): string {
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;
        $imageInfo = getimagesize($fullPath);

        if (!$imageInfo) {
            return $imagePath;
        }

        // Load image
        switch ($imageInfo['mime']) {
            case 'image/jpeg':
                $source = imagecreatefromjpeg($fullPath);
                break;
            case 'image/png':
                $source = imagecreatefrompng($fullPath);
                break;
            case 'image/webp':
                $source = imagecreatefromwebp($fullPath);
                break;
            default:
                return $imagePath;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        // Create new image with target dimensions
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        // Calculate crop dimensions to maintain aspect ratio
        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight;

        if ($sourceRatio > $targetRatio) {
            // Source is wider, crop sides
            $cropWidth = $sourceHeight * $targetRatio;
            $cropX = ($sourceWidth - $cropWidth) / 2;
            $cropY = 0;
            $cropHeight = $sourceHeight;
        } else {
            // Source is taller, crop top/bottom
            $cropHeight = $sourceWidth / $targetRatio;
            $cropX = 0;
            $cropY = ($sourceHeight - $cropHeight) / 2;
            $cropWidth = $sourceWidth;
        }

        // Copy and resize
        imagecopyresampled(
            $resized, $source,
            0, 0, $cropX, $cropY,
            $targetWidth, $targetHeight,
            $cropWidth, $cropHeight
        );

        // Save resized image to user folder
        $filename = uniqid('ad_resized_') . '.jpg';
        $userFolder = $this->createUserAdFolder($this->currentUserId);
        $relativePath = $userFolder . '/' . $filename;
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $relativePath;

        imagejpeg($resized, $fullPath, 90);
        imagedestroy($source);
        imagedestroy($resized);

        return $relativePath;
    }

    /**
     * Build strategic prompt for ad generation that creates original concepts
     */
    private function buildAdPrompt(array $styleGuide, string $adType, int $width, int $height): string {
        $aspectRatio = $width > $height ? 'landscape' : ($width < $height ? 'portrait' : 'square');

        $prompt = "You are creating an ORIGINAL advertising concept based on brand guidelines. ";
        $prompt .= "Format: {$aspectRatio} orientation ({$width}x{$height}px) for digital marketing.\n\n";

        $prompt .= "IMPORTANT: Create something NEW and INNOVATIVE that follows the brand guidelines but doesn't copy existing materials. ";

        // Add brand/company context
        if (!empty($styleGuide['brand_name'])) {
            $prompt .= "Brand: {$styleGuide['brand_name']}. ";
        }

        if (!empty($styleGuide['product_description'])) {
            $prompt .= "Product/Service: {$styleGuide['product_description']}. ";
        }

        // Add target audience
        if (!empty($styleGuide['target_audience'])) {
            $prompt .= "Target audience: {$styleGuide['target_audience']}. ";
        }

        // Add strategic campaign goal
        if (!empty($styleGuide['campaign_goal'])) {
            $goalContext = match($styleGuide['campaign_goal']) {
                'awareness' => 'GOAL: Maximum brand recall. Create memorable, distinctive visuals that stick in minds. Use bold, unexpected creative that breaks through the noise.',
                'sales' => 'GOAL: Drive immediate purchases. Create urgency, showcase value, demonstrate benefits. Make the product irresistible and the action clear.',
                'leads' => 'GOAL: Capture qualified interest. Build intrigue, offer value exchange, create curiosity gaps that compel information sharing.',
                'traffic' => 'GOAL: Generate clicks. Create visual tension that demands resolution, tease content that viewers must see, use pattern interrupts.',
                'engagement' => 'GOAL: Spark interaction. Design for shareability, create conversation starters, tap into emotions and cultural moments.',
                default => 'GOAL: Achieve marketing objectives through compelling creative.'
            };
            $prompt .= "\n{$goalContext}\n";
        }

        // Add copy/messaging if provided
        if (!empty($styleGuide['ad_copy'])) {
            $prompt .= "Key messaging: {$styleGuide['ad_copy']}. ";
        }

        // Add headline and CTA as visual elements
        if (!empty($styleGuide['headline'])) {
            $prompt .= "HEADLINE: \"{$styleGuide['headline']}\" - Make this the main attention-grabbing text element, prominently displayed. ";
        }

        if (!empty($styleGuide['cta_text'])) {
            $prompt .= "CALL-TO-ACTION: \"{$styleGuide['cta_text']}\" - Create this as a visible, clickable button or prominent text element that stands out and encourages action. ";
        }

        // Add visual style from Figma or defaults
        if (!empty($styleGuide['primary_color'])) {
            $prompt .= "Primary brand color: {$styleGuide['primary_color']}. ";
        }

        if (!empty($styleGuide['primary_font'])) {
            $prompt .= "Typography should be clean and modern, similar to {$styleGuide['primary_font']}. ";
        }

        // Add logo information
        if (!empty($styleGuide['logo'])) {
            $prompt .= "BRAND LOGO: Use the provided brand logo image in the ad design. Position it prominently but tastefully - typically in a corner or integrated into the layout. The logo should be clearly visible and recognizable. ";
        }

        // Platform-specific creative strategies
        $platformGuidance = match($adType) {
            'instagram_story', 'facebook_story', 'snapchat_ad' =>
                'PLATFORM: Stories - Full-screen immersive experience. Use vertical real estate dramatically. Create thumb-stopping moment in first 2 seconds. Layer depth with foreground/background. Safe zones for UI elements.',
            'instagram_feed', 'facebook_square' =>
                'PLATFORM: Social Feed Square - Compete in infinite scroll. Use high contrast, bold focal points. Create visual patterns that halt scrolling. Mobile-first composition. Make it work as a tiny thumbnail AND full screen.',
            'facebook_feed' =>
                'PLATFORM: Facebook Feed - Stand out in cluttered timeline. Use emotional triggers, faces perform well. Create curiosity gaps. Optimize for both desktop and mobile viewing.',
            'youtube_thumbnail' =>
                'PLATFORM: YouTube - Maximize click-through. Use faces with strong emotions, high contrast text, create intrigue without clickbait. Consider how it looks at multiple sizes.',
            'google_banner', 'google_rectangle' =>
                'PLATFORM: Display Network - Instant clarity is key. 3-second rule: message must be understood immediately. Strong visual hierarchy. Works at 50% size.',
            'pinterest_pin' =>
                'PLATFORM: Pinterest - Aspirational and actionable. Vertical format for maximum real estate. Step-by-step visual appeal. Save-worthy content.',
            'linkedin_feed' =>
                'PLATFORM: LinkedIn - Professional but not boring. Data visualization, industry insights. Thought leadership visual. B2B decision-maker appeal.',
            'twitter_post' =>
                'PLATFORM: Twitter/X - Speed of consumption. Bold, simple, meme-aware. Text-image harmony. Conversation starter.',
            default =>
                'PLATFORM: Multi-channel - Flexible creative that works across contexts. Strong brand recognition. Clear focal point.'
        };

        $prompt .= "\n{$platformGuidance}\n";

        // Creative excellence requirements
        $prompt .= "\nCREATIVE EXCELLENCE:\n";
        $prompt .= "- Apply advanced advertising psychology and visual hierarchy\n";
        $prompt .= "- Use the brand guidelines as DNA, not a template\n";
        $prompt .= "- Consider scroll behavior, attention patterns, and platform algorithms\n";
        $prompt .= "- Balance creativity with conversion principles\n";

        if (!empty($styleGuide['has_shadows']) && $styleGuide['has_shadows']) {
            $prompt .= "- Include subtle shadows and depth for premium feel\n";
        }

        // Final mandate
        $prompt .= "\nFINAL OUTPUT: A breakthrough ad that would win creative awards while delivering measurable business results. ";
        $prompt .= "Something the brand's CMO would proudly present to the board. ";
        $prompt .= "An ad that competitors will wish they had created first.";

        return $prompt;
    }

    /**
     * Add text overlay to generated image using GD
     */
    private function addTextOverlay(
        string $imageUrl,
        string $headline,
        string $bodyText,
        string $ctaText,
        array $styleGuide
    ): string {
        // Load the image
        $imagePath = parse_url($imageUrl, PHP_URL_PATH);
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $imagePath;

        $imageInfo = getimagesize($fullPath);
        if (!$imageInfo) {
            return $imageUrl; // Return original if we can't process
        }

        // Create image resource
        switch ($imageInfo['mime']) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($fullPath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($fullPath);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($fullPath);
                break;
            default:
                return $imageUrl;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Set up colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $overlay = imagecolorallocatealpha($image, 0, 0, 0, 60);

        // Add semi-transparent overlay for text areas
        if ($headline) {
            imagefilledrectangle($image, 0, 0, $width, (int)($height * 0.3), $overlay);
        }
        if ($ctaText) {
            imagefilledrectangle($image, 0, (int)($height * 0.8), $width, $height, $overlay);
        }

        // Add headline
        if ($headline) {
            $fontSize = min(50, $width / 20);
            $fontPath = __DIR__ . '/../fonts/Roboto-Bold.ttf';
            if (file_exists($fontPath)) {
                imagettftext(
                    $image, (int)$fontSize, 0,
                    (int)($width * 0.1), (int)($height * 0.15),
                    $white, $fontPath, $headline
                );
            }
        }

        // Add CTA button
        if ($ctaText) {
            $fontSize = min(30, $width / 30);
            $fontPath = __DIR__ . '/../fonts/Roboto-Medium.ttf';
            if (file_exists($fontPath)) {
                // Calculate text dimensions
                $bbox = imagettfbbox((int)$fontSize, 0, $fontPath, $ctaText);
                $textWidth = $bbox[2] - $bbox[0];

                // Draw button background
                $buttonX = (int)(($width - $textWidth) / 2 - 20);
                $buttonY = (int)($height * 0.85);
                $buttonColor = imagecolorallocate($image, 33, 150, 243); // Blue
                imagefilledrectangle(
                    $image,
                    $buttonX, (int)($buttonY - 40),
                    (int)($buttonX + $textWidth + 40), (int)($buttonY + 10),
                    $buttonColor
                );

                // Draw button text
                imagettftext(
                    $image, (int)$fontSize, 0,
                    (int)(($width - $textWidth) / 2), (int)($buttonY - 5),
                    $white, $fontPath, $ctaText
                );
            }
        }

        // Save the modified image to user folder
        $filename = uniqid('ad_text_') . '.jpg';
        $userFolder = $this->createUserAdFolder($this->currentUserId);
        $outputPath = $userFolder . '/' . $filename;
        $fullOutputPath = $_SERVER['DOCUMENT_ROOT'] . $outputPath;

        imagejpeg($image, $fullOutputPath, 90);
        imagedestroy($image);

        // Upload to CDN if enabled (still private)
        if (Config::get('cdn_enabled')) {
            return CDNService::uploadFile($fullOutputPath);
        }

        return $outputPath;
    }

    /**
     * Save generated image from base64 data
     */
    private function saveGeneratedImageFromBase64(string $base64Data, string $mimeType): string {
        Logger::info('AdGeneratorService - Starting base64 image save', [
            'mime_type' => $mimeType,
            'data_length' => strlen($base64Data)
        ]);

        $imageData = base64_decode($base64Data);
        if ($imageData === false) {
            Logger::error('AdGeneratorService - Failed to decode base64 data');
            throw new Exception('Failed to decode image data');
        }

        $extension = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg'
        };

        $filename = uniqid('ad_') . '.' . $extension;
        $userFolder = $this->createUserAdFolder($this->currentUserId);
        $relativePath = $userFolder . '/' . $filename;
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $relativePath;

        Logger::info('AdGeneratorService - Attempting to save image', [
            'filename' => $filename,
            'full_path' => $fullPath,
            'directory_exists' => is_dir(dirname($fullPath)),
            'directory_writable' => is_writable(dirname($fullPath)),
            'image_size_kb' => round(strlen($imageData) / 1024, 1)
        ]);

        // Ensure directory exists and is writable
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            Logger::error('AdGeneratorService - Directory does not exist', ['dir' => $dir]);
            throw new Exception('Save directory does not exist: ' . $dir);
        }

        if (!is_writable($dir)) {
            Logger::error('AdGeneratorService - Directory not writable', ['dir' => $dir]);
            throw new Exception('Save directory not writable: ' . $dir);
        }

        $bytesWritten = file_put_contents($fullPath, $imageData);
        if ($bytesWritten === false) {
            Logger::error('AdGeneratorService - file_put_contents failed', [
                'path' => $fullPath,
                'data_size' => strlen($imageData)
            ]);
            throw new Exception('Failed to save generated image to: ' . $fullPath);
        }

        // Verify file was actually saved and is readable
        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            Logger::error('AdGeneratorService - Saved file not accessible', [
                'path' => $fullPath,
                'exists' => file_exists($fullPath),
                'readable' => is_readable($fullPath)
            ]);
            throw new Exception('Saved image file is not accessible');
        }

        // Set proper permissions
        chmod($fullPath, 0644);

        Logger::info('AdGeneratorService - Image saved successfully', [
            'filename' => $filename,
            'path' => $relativePath,
            'bytes_written' => $bytesWritten,
            'final_size' => filesize($fullPath)
        ]);

        return $relativePath;
    }

    /**
     * Save generated image from API to user-specific private folder
     */
    private function saveGeneratedImage(string $remoteUrl, int $userId): string {
        $imageData = file_get_contents($remoteUrl);
        if (!$imageData) {
            throw new Exception('Failed to download generated image');
        }

        $filename = uniqid('ad_') . '.jpg';
        $userFolder = $this->createUserAdFolder($userId);
        $relativePath = $userFolder . '/' . $filename;
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $relativePath;

        file_put_contents($fullPath, $imageData);

        // Upload to CDN if enabled (still private)
        if (Config::get('cdn_enabled')) {
            return CDNService::uploadFile($fullPath);
        }

        return $relativePath;
    }

    /**
     * Create private ad folder for user (only if they generate ads)
     */
    private function createUserAdFolder(int $userId): string {
        $userFolder = "/generated/ads/user_{$userId}";
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $userFolder;

        if (!is_dir($fullPath)) {
            // Create user folder with public read permissions for web access
            mkdir($fullPath, 0755, true);

            Logger::info('AdGeneratorService - Created user ad folder', [
                'user_id' => $userId,
                'folder_path' => $fullPath,
                'permissions' => '0755'
            ]);

            // Track folder in database
            $pdo = Database::getInstance();
            $pdo->prepare('
                INSERT OR REPLACE INTO user_ad_folders (user_id, folder_path)
                VALUES (?, ?)
            ')->execute([$userId, $userFolder]);
        }

        return $userFolder;
    }

    /**
     * Get available ad sizes
     */
    public function getAvailableAdSizes(): array {
        return $this->adSizes;
    }

    /**
     * Generate a single concept image using Gemini API
     */
    public function generateConceptImage(string $prompt, array $conceptData): string {
        try {
            // Use Gemini API to generate concept
            $requestData = [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]],
                'generationConfig' => [
                    'temperature' => 0.8,
                    'maxOutputTokens' => 8192
                ]
            ];

            Logger::info('AdGeneratorService - Generating concept image', [
                'prompt_length' => strlen($prompt)
            ]);

            // For now, use placeholder since Gemini image generation needs proper setup
            // This will be replaced with actual Gemini Imagen API call when available
            return $this->generateConceptPlaceholder($conceptData);

        } catch (Exception $e) {
            Logger::error('Concept generation failed', [
                'error' => $e->getMessage()
            ]);

            // Fallback to placeholder
            return $this->generateConceptPlaceholder($conceptData);
        }
    }

    /**
     * Generate a placeholder concept image
     */
    private function generateConceptPlaceholder(array $conceptData): string {
        $width = 1024;
        $height = 1024;

        $image = imagecreatetruecolor($width, $height);

        // Parse hex colors
        $primaryHex = str_replace('#', '', $conceptData['primary_color'] ?? '2196F3');
        $r1 = hexdec(substr($primaryHex, 0, 2));
        $g1 = hexdec(substr($primaryHex, 2, 2));
        $b1 = hexdec(substr($primaryHex, 4, 2));

        $secondaryHex = str_replace('#', '', $conceptData['secondary_color'] ?? 'FF9800');
        $r2 = hexdec(substr($secondaryHex, 0, 2));
        $g2 = hexdec(substr($secondaryHex, 2, 2));
        $b2 = hexdec(substr($secondaryHex, 4, 2));

        // Create gradient background
        for ($i = 0; $i < $height; $i++) {
            $alpha = $i / $height;
            $r = (int)($r1 + ($r2 - $r1) * $alpha);
            $g = (int)($g1 + ($g2 - $g1) * $alpha);
            $b = (int)($b1 + ($b2 - $b1) * $alpha);
            $color = imagecolorallocate($image, $r, $g, $b);
            imageline($image, 0, $i, $width, $i, $color);
        }

        // Add some design elements
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $overlay = imagecolorallocatealpha($image, 255, 255, 255, 100);

        // Add circular elements for modern look
        for ($i = 0; $i < 5; $i++) {
            $x = rand(100, $width - 100);
            $y = rand(100, $height - 100);
            $size = rand(50, 200);
            imagefilledellipse($image, $x, $y, $size, $size, $overlay);
        }

        // Add brand name if provided
        if (!empty($conceptData['brand_name'])) {
            $fontPath = __DIR__ . '/../fonts/Roboto-Bold.ttf';
            if (file_exists($fontPath)) {
                $fontSize = 60;
                $text = strtoupper($conceptData['brand_name']);

                // Get text dimensions
                $bbox = imagettfbbox((int)$fontSize, 0, $fontPath, $text);
                $textWidth = $bbox[2] - $bbox[0];
                $textX = (int)(($width - $textWidth) / 2);

                // Add text with shadow
                imagettftext($image, (int)$fontSize, 0, (int)($textX + 3), (int)($height/2 + 3), $black, $fontPath, $text);
                imagettftext($image, (int)$fontSize, 0, $textX, (int)($height/2), $white, $fontPath, $text);
            }
        }

        // Add visual style indicator
        if (!empty($conceptData['visual_style'])) {
            $style = strtoupper($conceptData['visual_style']);
            $fontPath = __DIR__ . '/../fonts/Roboto-Regular.ttf';
            if (file_exists($fontPath)) {
                imagettftext($image, 20, 0, 50, (int)($height - 50), $white, $fontPath, "STYLE: {$style}");
            }
        }

        // Save the concept
        $filename = uniqid('concept_') . '.jpg';
        $userFolder = $this->createUserAdFolder($this->currentUserId);
        $relativePath = $userFolder . '/' . $filename;
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $relativePath;

        imagejpeg($image, $fullPath, 95);
        imagedestroy($image);

        Logger::info('Generated placeholder concept', [
            'path' => $relativePath,
            'brand' => $conceptData['brand_name'] ?? 'unknown'
        ]);

        return $relativePath;
    }

    /**
     * Call Gemini API with an image for reformatting/editing tasks
     */
    public function callGeminiWithImage(string $prompt, string $base64Image, string $mimeType, int $targetWidth, int $targetHeight): array {
        Logger::info('AdGeneratorService - Starting Gemini image reformatting', [
            'width' => $targetWidth,
            'height' => $targetHeight,
            'prompt_preview' => substr($prompt, 0, 200) . '...',
            'image_size_kb' => round(strlen($base64Image) / 1024, 1),
            'mime_type' => $mimeType
        ]);

        // Build request with image input
        $requestData = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $base64Image
                        ]
                    ]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.8,
                'maxOutputTokens' => 8192,
                'candidateCount' => 3  // Request 3 variations
            ]
        ];

        Logger::info('AdGeneratorService - Making Gemini API request with image', [
            'request_size_kb' => round(strlen(json_encode($requestData)) / 1024, 2),
            'prompt_length' => strlen($prompt),
            'model' => 'gemini-2.5-flash-image-preview'
        ]);

        try {
            $response = $this->makeGeminiRequest($requestData);

            Logger::info('AdGeneratorService - Gemini image response received', [
                'has_candidates' => isset($response['candidates']),
                'num_candidates' => count($response['candidates'] ?? [])
            ]);

            // Extract images from all candidates
            $savedImages = [];

            if (isset($response['candidates']) && is_array($response['candidates'])) {
                foreach ($response['candidates'] as $candidateIndex => $candidate) {
                    if (isset($candidate['content']['parts'])) {
                        $partsCount = count($candidate['content']['parts']);
                        Logger::info('AdGeneratorService - Processing candidate image response parts', [
                            'candidate_index' => $candidateIndex,
                            'num_parts' => $partsCount
                        ]);

                        foreach ($candidate['content']['parts'] as $partIndex => $part) {
                            $imageData = $part['inline_data']['data'] ?? $part['inlineData']['data'] ?? null;
                            $responseMimeType = $part['inline_data']['mime_type'] ?? $part['inlineData']['mimeType'] ?? 'image/jpeg';

                            Logger::debug('AdGeneratorService - Checking candidate image response part', [
                                'candidate_index' => $candidateIndex,
                                'part_index' => $partIndex,
                                'has_image_data' => !empty($imageData),
                                'mime_type' => $responseMimeType
                            ]);

                            if ($imageData) {
                                Logger::info('AdGeneratorService - Reformatted image data found, saving', [
                                    'candidate_index' => $candidateIndex,
                                    'mime_type' => $responseMimeType,
                                    'data_length' => strlen($imageData)
                                ]);

                                $savedUrl = $this->saveGeneratedImageFromBase64($imageData, $responseMimeType);

                                Logger::info('AdGeneratorService - Reformatted image saved successfully', [
                                    'candidate_index' => $candidateIndex,
                                    'url' => $savedUrl
                                ]);

                                $savedImages[] = $savedUrl;
                            }
                        }
                    }
                }
            }

            if (!empty($savedImages)) {
                Logger::info('AdGeneratorService - Multiple variations generated', [
                    'num_variations' => count($savedImages)
                ]);
                return $savedImages;
            }

            Logger::error('AdGeneratorService - No image in Gemini reformatting response', [
                'response_structure' => array_keys($response),
                'candidates_structure' => isset($response['candidates'][0]) ? array_keys($response['candidates'][0]) : []
            ]);

            throw new Exception('No valid reformatted image returned from Gemini API');

        } catch (Exception $e) {
            Logger::error('AdGeneratorService - Gemini image reformatting failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}