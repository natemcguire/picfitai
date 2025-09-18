<?php
require_once '../bootstrap.php';

// Set JSON response header early and prevent any HTML output
header('Content-Type: application/json');
ob_start();

// Enable error logging for debugging
error_log("🔥 Signs generation request started - " . date('Y-m-d H:i:s'));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data keys: " . implode(', ', array_keys($_POST ?? [])));
error_log("FILES data keys: " . implode(', ', array_keys($_FILES ?? [])));

// Ensure user is logged in
$currentUser = Session::getCurrentUser();
if (!$currentUser) {
    error_log("❌ User not logged in");
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    ob_end_flush();
    exit;
}

$user = Session::getCurrentUser();
error_log("✅ User authenticated: " . $user['email'] . " (ID: " . $user['id'] . ")");
error_log("User credits: " . $user['credits_remaining']);

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Verify CSRF token
    if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }

    // Note: Credits check removed - signs generation is now free

    // Validate inputs
    $prompt = trim($_POST['prompt'] ?? '');
    $textOverlay = trim($_POST['text_overlay'] ?? '');
    $overlayPosition = $_POST['overlay_position'] ?? 'bottom-right';
    $overlayX = intval($_POST['overlay_x'] ?? 50);
    $overlayY = intval($_POST['overlay_y'] ?? 50);
    $overlayWidth = intval($_POST['overlay_width'] ?? 0);
    $overlayHeight = intval($_POST['overlay_height'] ?? 0);

    error_log("📝 Generation Prompt: " . $prompt);
    error_log("📝 Text Overlay: " . $textOverlay);
    error_log("📍 Overlay Position: " . $overlayPosition . " X:" . $overlayX . " Y:" . $overlayY);

    if (empty($prompt)) {
        throw new Exception('Please provide generation instructions');
    }

    $aiModel = $_POST['ai_model'] ?? 'gemini';
    error_log("🤖 AI Model: " . $aiModel);
    if (!in_array($aiModel, ['gemini', 'openai', 'hybrid'])) {
        throw new Exception('Invalid AI model selected');
    }

    $width = intval($_POST['width'] ?? 1024);
    $height = intval($_POST['height'] ?? 1024);
    error_log("📐 Dimensions: {$width}x{$height}");

    // Validate dimensions
    if ($width < 256 || $width > 2048 || $height < 256 || $height > 2048) {
        throw new Exception('Invalid dimensions. Width and height must be between 256 and 2048 pixels.');
    }

    // Check if this is a scratch generation (text-only)
    $generationType = $_POST['generation_type'] ?? 'upload';
    $isFromScratch = ($generationType === 'scratch');

    // Handle file upload (only required for upload type)
    if (!$isFromScratch) {
        error_log("📁 Checking file upload...");
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            error_log("❌ File upload error: " . ($_FILES['image']['error'] ?? 'No file'));
            throw new Exception('Please upload an image');
        }
    }

    // Handle multiple reference images if provided
    $referenceImagesData = [];
    $includeReference = isset($_POST['include_reference']) && $_POST['include_reference'] === 'on';

    if (isset($_FILES['reference_images']) && is_array($_FILES['reference_images']['name'])) {
        $referenceFiles = $_FILES['reference_images'];
        $maxImages = 5; // Limit to 5 images

        error_log("📎 Processing reference images: " . count($referenceFiles['name']) . " files uploaded");

        for ($i = 0; $i < min(count($referenceFiles['name']), $maxImages); $i++) {
            if ($referenceFiles['error'][$i] === UPLOAD_ERR_OK) {
                $fileName = $referenceFiles['name'][$i];
                $tmpName = $referenceFiles['tmp_name'][$i];

                error_log("📎 Processing reference image #{$i}: {$fileName}");

                // Validate reference file type
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $referenceMimeType = finfo_file($finfo, $tmpName);
                finfo_close($finfo);

                if (!in_array($referenceMimeType, $allowedTypes)) {
                    error_log("❌ Invalid reference file type for {$fileName}: {$referenceMimeType}");
                    continue; // Skip invalid files instead of throwing error
                }

                // Read reference image as base64
                $referenceImageData = base64_encode(file_get_contents($tmpName));
                $referenceImagesData[] = $referenceImageData;

                error_log("✅ Reference image #{$i} processed successfully: {$fileName}");
            }
        }

        error_log("🔄 Total reference images processed: " . count($referenceImagesData));
        error_log("🔄 Include reference: " . ($includeReference ? 'Yes' : 'No'));
    }

    $imageData = null;
    $tempFilePath = null;
    $uploadedFile = null;

    if (!$isFromScratch) {
        $uploadedFile = $_FILES['image'];
        error_log("📷 File uploaded: " . $uploadedFile['name'] . " (" . $uploadedFile['size'] . " bytes)");

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Invalid file type. Please upload a JPG, PNG, or WebP image.');
        }

        // Validate file size (10MB max)
        if ($uploadedFile['size'] > 10 * 1024 * 1024) {
            throw new Exception('File too large. Maximum size is 10MB.');
        }

        // Create temp directory if it doesn't exist
        $tempDir = __DIR__ . '/temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Save uploaded file temporarily
        $tempFilePath = $tempDir . '/' . uniqid('upload_') . '.jpg';

        // Convert to JPEG if needed and resize
        $image = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($uploadedFile['tmp_name']);
                break;
            case 'image/png':
                $image = imagecreatefrompng($uploadedFile['tmp_name']);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($uploadedFile['tmp_name']);
                break;
        }

        if (!$image) {
            throw new Exception('Failed to process image');
        }

        // Get original dimensions
        $origWidth = imagesx($image);
        $origHeight = imagesy($image);

        // Resize if larger than 1024px in any dimension (for API efficiency)
        $maxDim = 1024;
        if ($origWidth > $maxDim || $origHeight > $maxDim) {
            $ratio = min($maxDim / $origWidth, $maxDim / $origHeight);
            $newWidth = round($origWidth * $ratio);
            $newHeight = round($origHeight * $ratio);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($image);
            $image = $resized;
        }

        // Save as JPEG
        imagejpeg($image, $tempFilePath, 85);
        imagedestroy($image);

        // Read image as base64
        $imageData = base64_encode(file_get_contents($tempFilePath));
    } else {
        error_log("✨ Scratch generation - no file upload needed");
    }

    // Create signs_generations table if it doesn't exist
    $db = Database::getInstance();
    $db->exec("
        CREATE TABLE IF NOT EXISTS signs_generations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            prompt TEXT NOT NULL,
            ai_model TEXT NOT NULL,
            width INTEGER NOT NULL,
            height INTEGER NOT NULL,
            input_file_name TEXT,
            result_url TEXT,
            error_message TEXT,
            processing_time REAL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // Log the generation attempt
    $stmt = $db->prepare("
        INSERT INTO signs_generations (user_id, status, prompt, ai_model, width, height, input_file_name, created_at)
        VALUES (?, 'processing', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $fileName = $isFromScratch ? 'scratch_generation' : $uploadedFile['name'];
    $stmt->execute([$user['id'], $prompt, $aiModel, $width, $height, $fileName]);
    $generationId = $db->lastInsertId();
    error_log("📊 Created generation record ID: " . $generationId);

    // Initialize AI service
    $aiService = new AIService();

    // Generate image based on selected model
    $resultImageUrl = null;
    $startTime = microtime(true);

    try {
        if ($isFromScratch) {
            // For scratch generation, use selected AI model (Gemini or DALL-E)
            if ($aiModel === 'gemini') {
                // Use Gemini for text-to-image generation (Nano Banana)
                $geminiPrompt = $prompt;

                if (!empty($textOverlay)) {
                    $geminiPrompt .= ". Include this text in the image: \"" . $textOverlay . "\"";
                }

                if (!empty($referenceImagesData) && $includeReference) {
                    $referenceCount = count($referenceImagesData);
                    $geminiPrompt .= "\n\nReference Images included ({$referenceCount} images): Use these uploaded images as reference or inspiration for the generation.";
                }

                error_log("🚀 Calling Gemini for scratch generation...");
                error_log("📝 Gemini prompt: " . $geminiPrompt);

                if (!empty($referenceImagesData) && $includeReference) {
                    // Use the first reference image with the image-based Gemini method
                    // Note: Gemini API typically handles one image at a time, so we use the first one
                    $primaryReferenceImage = $referenceImagesData[0];
                    $resultImageUrl = $aiService->generateSignWithGeminiAndImage($primaryReferenceImage, $geminiPrompt, $width, $height);
                    error_log("🖼️ Using primary reference image (1 of {$referenceCount})");
                } else {
                    // Use text-only Gemini method when no reference image
                    $resultImageUrl = $aiService->generateSignWithGemini($geminiPrompt, $width, $height);
                }
                error_log("✅ Gemini returned: " . $resultImageUrl);
            } else {
                // Use DALL-E for text-to-image generation
                $dallePrompt = $prompt;

                if (!empty($textOverlay)) {
                    $dallePrompt .= ". Include this text in the image: \"" . $textOverlay . "\"";
                }

                if (!empty($referenceImagesData) && $includeReference) {
                    $referenceCount = count($referenceImagesData);
                    $dallePrompt .= "\n\nReference Images included ({$referenceCount} images): Use these uploaded images as reference or inspiration for the generation.";
                }

                error_log("🚀 Calling DALL-E for scratch generation...");
                error_log("📝 DALL-E prompt: " . $dallePrompt);
                $resultImageUrl = $aiService->generateSignWithDallE($dallePrompt, $width, $height);
                if (!empty($referenceImagesData) && $includeReference) {
                    error_log("🖼️ Referenced {$referenceCount} images in DALL-E generation");
                }
                error_log("✅ DALL-E returned: " . $resultImageUrl);
            }

        } elseif ($aiModel === 'gemini') {
            // Pure Gemini: Use Gemini to generate an image based on the uploaded image and prompt
            $generationPrompt = "Based on this image, " . $prompt;

            if (!empty($textOverlay)) {
                $generationPrompt .= "\n\nInclude this text on the image: \"" . $textOverlay . "\"";
            }

            $generationPrompt .= "\n\nGenerate a new image that fulfills this request while maintaining appropriate visual elements from the original.";

            error_log("🚀 Calling Gemini for image generation...");
            error_log("📝 Generation prompt: " . $generationPrompt);

            $resultImageUrl = $aiService->generateSignWithGeminiAndImage($imageData, $generationPrompt, $width, $height);
            error_log("✅ Gemini returned: " . $resultImageUrl);

        } elseif ($aiModel === 'hybrid') {
            // Hybrid: Use Gemini to analyze the image and create a detailed prompt, then DALL-E to generate
            $analysisPrompt = "Analyze this image in detail and then create a detailed DALL-E prompt with these modifications: " . $prompt;

            if (!empty($textOverlay)) {
                $analysisPrompt .= "\n\nInclude this text in the generated image: \"" . $textOverlay . "\"";
            }

            $analysisPrompt .= "\n\nProvide only the DALL-E prompt, no explanation. Make it detailed and specific about colors, style, composition, etc.";

            error_log("🔍 Hybrid - Gemini analysis prompt: " . $analysisPrompt);
            error_log("🚀 Calling Gemini for image analysis...");

            // First, use Gemini to analyze and create a better prompt
            $enhancedPrompt = $aiService->analyzeImageWithGemini($imageData, $analysisPrompt);
            error_log("📝 Enhanced prompt from Gemini: " . $enhancedPrompt);

            // Then use DALL-E to generate the actual image
            error_log("🚀 Calling DALL-E with enhanced prompt...");
            $resultImageUrl = $aiService->generateWithDallE($enhancedPrompt, $width, $height);
            error_log("✅ DALL-E returned: " . $resultImageUrl);

        } else {
            // Pure OpenAI: Use DALL-E directly with prompt and text overlay
            $dallePrompt = $prompt;

            if (!empty($textOverlay)) {
                $dallePrompt .= ". Include this text in the image: \"" . $textOverlay . "\"";
            }

            error_log("🚀 Calling DALL-E directly...");
            error_log("📝 DALL-E prompt: " . $dallePrompt);
            $resultImageUrl = $aiService->generateWithDallE($dallePrompt, $width, $height);
            error_log("✅ DALL-E returned: " . $resultImageUrl);
        }

        if (!$resultImageUrl) {
            throw new Exception('Failed to generate image');
        }

        error_log("📁 Image already saved by AI service at: " . $resultImageUrl);

        // Apply overlay if provided (COMMENTED OUT - letting AI handle it)
        /*
        if ($overlayImageData) {
            error_log("🎯 Applying overlay image to generated result...");

            // Download the generated image to apply overlay
            $generatedImagePath = str_replace('/generated/', __DIR__ . '/../generated/', parse_url($resultImageUrl, PHP_URL_PATH));

            if (file_exists($generatedImagePath)) {
                // Load the generated image
                $generatedImage = imagecreatefromstring(file_get_contents($generatedImagePath));
                if (!$generatedImage) {
                    error_log("❌ Failed to load generated image for overlay");
                } else {
                    // Load the overlay image
                    $overlayImage = imagecreatefromstring(base64_decode($overlayImageData));
                    if (!$overlayImage) {
                        error_log("❌ Failed to load overlay image");
                    } else {
                        // Get dimensions
                        $genWidth = imagesx($generatedImage);
                        $genHeight = imagesy($generatedImage);
                        $originalOverlayWidth = imagesx($overlayImage);
                        $originalOverlayHeight = imagesy($overlayImage);

                        // Use specified dimensions from form or original if not specified
                        $targetWidth = ($_POST['overlay_width'] ?? 0) > 0 ? intval($_POST['overlay_width']) : $originalOverlayWidth;
                        $targetHeight = ($_POST['overlay_height'] ?? 0) > 0 ? intval($_POST['overlay_height']) : $originalOverlayHeight;

                        // Resize overlay if needed
                        if ($targetWidth != $originalOverlayWidth || $targetHeight != $originalOverlayHeight) {
                            error_log("📐 Resizing overlay from {$originalOverlayWidth}×{$originalOverlayHeight} to {$targetWidth}×{$targetHeight}");

                            $resizedOverlay = imagecreatetruecolor($targetWidth, $targetHeight);

                            // Preserve transparency for PNG images
                            imagealphablending($resizedOverlay, false);
                            imagesavealpha($resizedOverlay, true);
                            $transparent = imagecolorallocatealpha($resizedOverlay, 0, 0, 0, 127);
                            imagefilledrectangle($resizedOverlay, 0, 0, $targetWidth, $targetHeight, $transparent);

                            imagealphablending($resizedOverlay, true);
                            imagecopyresampled($resizedOverlay, $overlayImage, 0, 0, 0, 0,
                                $targetWidth, $targetHeight, $originalOverlayWidth, $originalOverlayHeight);

                            imagedestroy($overlayImage);
                            $overlayImage = $resizedOverlay;
                        }

                        $overlayWidth = $targetWidth;
                        $overlayHeight = $targetHeight;

                        // Calculate position based on selected corner
                        switch ($overlayPosition) {
                            case 'bottom-right':
                                $destX = $genWidth - $overlayWidth - $overlayX;
                                $destY = $genHeight - $overlayHeight - $overlayY;
                                break;
                            case 'bottom-left':
                                $destX = $overlayX;
                                $destY = $genHeight - $overlayHeight - $overlayY;
                                break;
                            case 'top-right':
                                $destX = $genWidth - $overlayWidth - $overlayX;
                                $destY = $overlayY;
                                break;
                            case 'top-left':
                            default:
                                $destX = $overlayX;
                                $destY = $overlayY;
                                break;
                        }

                        // Ensure overlay stays within bounds
                        $destX = max(0, min($destX, $genWidth - $overlayWidth));
                        $destY = max(0, min($destY, $genHeight - $overlayHeight));

                        // Apply overlay with transparency support
                        imagealphablending($generatedImage, true);
                        imagesavealpha($generatedImage, true);

                        // Copy overlay onto generated image
                        imagecopy($generatedImage, $overlayImage, $destX, $destY, 0, 0, $overlayWidth, $overlayHeight);

                        // Save the composited image
                        $extension = pathinfo($generatedImagePath, PATHINFO_EXTENSION);
                        if ($extension === 'png') {
                            imagepng($generatedImage, $generatedImagePath);
                        } else {
                            imagejpeg($generatedImage, $generatedImagePath, 95);
                        }

                        error_log("✅ Overlay applied successfully at position {$overlayPosition} ({$destX}, {$destY})");

                        // Clean up
                        imagedestroy($overlayImage);
                        imagedestroy($generatedImage);
                    }
                }
            } else {
                error_log("❌ Generated image file not found for overlay: " . $generatedImagePath);
            }
        }
        */

        // The AI service already saved the image, just use the returned URL
        $publicUrl = $resultImageUrl;

        // Update generation record
        $processingTime = round(microtime(true) - $startTime, 2);
        $stmt = $db->prepare("
            UPDATE signs_generations
            SET status = 'completed',
                result_url = ?,
                processing_time = ?,
                completed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$publicUrl, $processingTime, $generationId]);
        error_log("✅ Updated generation record with success");

        // Note: Credit deduction removed - signs generation is now free

        // Clean up temp file (if it exists)
        if ($tempFilePath && file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }

        // Success response
        echo json_encode([
            'success' => true,
            'image_url' => $publicUrl,
            'generation_id' => $generationId,
            'credits_remaining' => $user['credits_remaining'], // No credits deducted
            'processing_time' => $processingTime
        ]);

    } catch (Exception $e) {
        // Update generation record with error
        $stmt = $db->prepare("
            UPDATE signs_generations
            SET status = 'failed',
                error_message = ?,
                completed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $generationId]);
        error_log("❌ Updated generation record with error: " . $e->getMessage());

        throw $e;
    }

} catch (Exception $e) {
    Logger::error('AI generation error: ' . $e->getMessage());
    ob_clean(); // Clear any previous output
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Clean up any temp files
if (isset($tempFilePath) && $tempFilePath && file_exists($tempFilePath)) {
    unlink($tempFilePath);
}

// Ensure we only output JSON
ob_end_flush();