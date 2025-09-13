<?php
// generate.php - Image generation page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

Session::requireLogin();
$user = Session::getCurrentUser();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            // Check if user has credits
            if ($user['credits_remaining'] <= 0) {
                header('Location: /pricing.php?reason=no_credits');
                exit;
            }
            
            // Validate files
            if (empty($_FILES['standing_photos']['name'][0]) || empty($_FILES['outfit_photo']['name'])) {
                $error = 'Please upload both standing photos and an outfit photo.';
            } else {
                // Process files
                $standingPhotos = [];
                for ($i = 0; $i < count($_FILES['standing_photos']['name']); $i++) {
                    if (!empty($_FILES['standing_photos']['name'][$i])) {
                        $standingPhotos[] = [
                            'tmp_name' => $_FILES['standing_photos']['tmp_name'][$i],
                            'name' => $_FILES['standing_photos']['name'][$i],
                            'type' => $_FILES['standing_photos']['type'][$i],
                            'size' => $_FILES['standing_photos']['size'][$i]
                        ];
                    }
                }
                
                $outfitPhoto = [
                    'tmp_name' => $_FILES['outfit_photo']['tmp_name'],
                    'name' => $_FILES['outfit_photo']['name'],
                    'type' => $_FILES['outfit_photo']['type'],
                    'size' => $_FILES['outfit_photo']['size']
                ];
                
                // Debit credits
                if (!StripeService::debitUserCredits($user['id'], 1, 'AI outfit generation')) {
                    $error = 'Failed to process credits. Please try again.';
                } else {
                    // Generate image using Gemini
                    $result = generateVirtualTryOn($standingPhotos, $outfitPhoto);
                    
                    if ($result['success']) {
                        // Save generation record
                        $pdo = Database::getInstance();
                        $stmt = $pdo->prepare('
                            INSERT INTO generations (user_id, status, result_url, processing_time, input_data)
                            VALUES (?, "completed", ?, ?, ?)
                        ');
                        $stmt->execute([
                            $user['id'],
                            $result['url'],
                            $result['processing_time'],
                            json_encode(['standing_photos_count' => count($standingPhotos)])
                        ]);
                        
                        Session::refreshUserData();
                        $user = Session::getCurrentUser();
                        
                        header('Location: /dashboard.php?generated=' . $pdo->lastInsertId());
                        exit;
                    } else {
                        // Refund credits on failure
                        StripeService::addUserCredits($user['id'], 1, 'Refund for failed generation');
                        $error = 'Generation failed: ' . $result['error'];
                    }
                }
            }
        } catch (Exception $e) {
            // Refund credits on exception
            StripeService::addUserCredits($user['id'], 1, 'Refund for exception: ' . $e->getMessage());
            $error = 'Generation failed: ' . $e->getMessage();
        }
    }
}

function generateVirtualTryOn(array $standingPhotos, array $outfitPhoto): array {
    $geminiApiKey = Config::get('gemini_api_key');
    if (empty($geminiApiKey)) {
        return ['success' => false, 'error' => 'Gemini API not configured'];
    }
    
    try {
        // Convert images to base64 with proper MIME types
        $standingB64 = [];
        foreach ($standingPhotos as $photo) {
            $standingB64[] = [
                'data' => base64_encode(file_get_contents($photo['tmp_name'])),
                'mime_type' => $photo['type']
            ];
        }
        $outfitB64 = [
            'data' => base64_encode(file_get_contents($outfitPhoto['tmp_name'])),
            'mime_type' => $outfitPhoto['type']
        ];
        
        // Create the prompt - be more specific about wanting an image
        $prompt = "Generate a photorealistic virtual try-on image. Take the person from the first image and dress them in the outfit from the second image. The result should be a high-quality photograph showing the person wearing the outfit with realistic fit, lighting, and fabric behavior. Use a clean, professional background. Return only the generated image, no text description.";
        
        // Prepare the request with proper structure for image generation
        $requestData = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => $standingB64[0]['mime_type'],
                            'data' => $standingB64[0]['data']
                        ]
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => $outfitB64['mime_type'],
                            'data' => $outfitB64['data']
                        ]
                    ],
                    ['text' => $prompt]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048
            ]
        ];
        
        // Make API request
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent?key=' . $geminiApiKey;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 120
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'API request failed: ' . $error];
        }
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'API error: HTTP ' . $httpCode . ' - ' . $response];
        }
        
        $responseData = json_decode($response, true);
        if (!$responseData) {
            return ['success' => false, 'error' => 'Invalid JSON response from API'];
        }
        
        if (isset($responseData['error'])) {
            return ['success' => false, 'error' => 'API error: ' . $responseData['error']['message']];
        }
        
        // Debug: Log the response structure
        error_log('Gemini API Response Structure: ' . json_encode($responseData, JSON_PRETTY_PRINT));
        
        // Extract image from response
        if (isset($responseData['candidates'][0]['content']['parts'])) {
            foreach ($responseData['candidates'][0]['content']['parts'] as $index => $part) {
                error_log("Part $index: " . json_encode($part, JSON_PRETTY_PRINT));
                
                if (isset($part['inline_data']['data'])) {
                    $imageData = $part['inline_data']['data'];
                    $mimeType = $part['inline_data']['mime_type'] ?? 'image/jpeg';
                    
                    // Save image
                    $filename = saveGeneratedImage($imageData, $mimeType);
                    return [
                        'success' => true,
                        'url' => '/generated/' . $filename,
                        'processing_time' => 30 // Estimated
                    ];
                } elseif (isset($part['text'])) {
                    error_log("Text response found: " . $part['text']);
                }
            }
        }
        
        return ['success' => false, 'error' => 'No image generated in response. Check logs for details.'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function saveGeneratedImage(string $base64Data, string $mimeType): string {
    $generatedDir = dirname(__DIR__) . '/generated';
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
        throw new Exception('Invalid base64 image data');
    }
    
    if (file_put_contents($filepath, $imageData) === false) {
        throw new Exception('Failed to save generated image');
    }
    
    return $filename;
}

$csrfToken = Session::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generate Your Fit - <?= Config::get('app_name') ?></title>
    <link rel="stylesheet" href="/public/styles.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-md">
                    <a href="/" class="logo">PicFit.ai</a>
                    <span class="text-slash">/</span>
                    <a href="/dashboard.php" class="text-ivory hover:text-gold transition-colors">Dashboard</a>
                    <span class="text-slash">/</span>
                    <span class="text-ivory">Generate</span>
                </div>
                <div class="flex items-center gap-md">
                    <div class="credits-badge">
                        <span class="text-sm font-medium"><?= $user['credits_remaining'] ?> credits</span>
                    </div>
                    <a href="/dashboard.php" class="btn btn-ghost btn-sm">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </header>

    <div class="container py-lg">
        <div class="max-w-4xl mx-auto">
            <h1 class="h1 text-center mb-lg">Generate Your Fit</h1>
            <p class="text-center text-mist mb-xl">Upload photos of yourself and an outfit to see how it looks on you.</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error mb-lg">
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success mb-lg">
                    <p><?= htmlspecialchars($success) ?></p>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="card">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                
                <div class="grid grid-2 gap-lg">
                    <!-- Standing Photos -->
                    <div>
                        <h3 class="h3 mb-md">Your Photos</h3>
                        <p class="text-mist mb-md">Upload 3-10 clear, full-body photos from different angles.</p>
                        
                        <div class="form-group">
                            <label for="standing_photos" class="form-label">
                                📸 Select Your Photos (Multiple)
                            </label>
                            <input type="file" 
                                   id="standing_photos" 
                                   name="standing_photos[]" 
                                   multiple 
                                   accept="image/*" 
                                   class="form-input"
                                   required>
                            <p class="text-muted text-xs mt-sm">Max 10MB per file, 50MB total. Up to 10 photos.</p>
                        </div>
                        
                        <div id="standing_preview" class="preview-grid"></div>
                        <div id="standing_count" class="text-muted mt-sm text-sm">0 photos selected</div>
                    </div>
                    
                    <!-- Outfit Photo -->
                    <div>
                        <h3 class="h3 mb-md">Outfit Photo</h3>
                        <p class="text-mist mb-md">Upload a flat-lay photo of the outfit on a clean surface.</p>
                        
                        <div class="form-group">
                            <label for="outfit_photo" class="form-label">
                                👕 Select Outfit Photo
                            </label>
                            <input type="file" 
                                   id="outfit_photo" 
                                   name="outfit_photo" 
                                   accept="image/*" 
                                   class="form-input"
                                   required>
                            <p class="text-muted text-xs mt-sm">Max 10MB per file.</p>
                        </div>
                        
                        <div id="outfit_preview" class="mt-md"></div>
                    </div>
                </div>
                
                <div class="text-center mt-lg">
                    <button type="submit" class="btn btn-primary btn-lg" id="generate_btn">
                        ✨ Generate My Fit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const standingPhotos = document.getElementById('standing_photos');
            const outfitPhoto = document.getElementById('outfit_photo');
            const standingPreview = document.getElementById('standing_preview');
            const outfitPreview = document.getElementById('outfit_preview');
            const standingCount = document.getElementById('standing_count');
            const generateBtn = document.getElementById('generate_btn');
            const form = document.querySelector('form');
            
            // File size limits
            const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB per file
            const MAX_TOTAL_SIZE = 50 * 1024 * 1024; // 50MB total
            
            // Standing photos handler
            standingPhotos.addEventListener('change', function() {
                if (validateFiles(this.files, 'standing')) {
                    updateStandingPreview();
                } else {
                    this.value = '';
                }
            });
            
            // Outfit photo handler
            outfitPhoto.addEventListener('change', function() {
                if (validateFiles(this.files, 'outfit')) {
                    updateOutfitPreview();
                } else {
                    this.value = '';
                }
            });
            
            function validateFiles(files, type) {
                const fileArray = Array.from(files);
                let totalSize = 0;
                
                for (let file of fileArray) {
                    if (file.size > MAX_FILE_SIZE) {
                        alert(`File "${file.name}" is too large. Maximum size is 10MB per file.`);
                        return false;
                    }
                    
                    if (!file.type.startsWith('image/')) {
                        alert(`File "${file.name}" is not a valid image file.`);
                        return false;
                    }
                    
                    totalSize += file.size;
                }
                
                if (totalSize > MAX_TOTAL_SIZE) {
                    const totalMB = Math.round(totalSize / 1024 / 1024);
                    alert(`Total file size (${totalMB}MB) exceeds the limit of 50MB. Please select smaller files or fewer files.`);
                    return false;
                }
                
                if (type === 'standing' && fileArray.length > 10) {
                    alert('You can upload a maximum of 10 standing photos.');
                    return false;
                }
                
                return true;
            }
            
            function updateStandingPreview() {
                const files = Array.from(standingPhotos.files);
                standingPreview.innerHTML = '';
                standingCount.textContent = files.length + ' photos selected';
                
                files.forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        div.innerHTML = `
                            <img src="${e.target.result}" class="preview-image" alt="Standing photo ${index + 1}">
                        `;
                        standingPreview.appendChild(div);
                    };
                    reader.readAsDataURL(file);
                });
            }
            
            function updateOutfitPreview() {
                outfitPreview.innerHTML = '';
                if (outfitPhoto.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        div.style.maxWidth = '300px';
                        div.innerHTML = `
                            <img src="${e.target.result}" class="preview-image" alt="Outfit photo">
                        `;
                        outfitPreview.appendChild(div);
                    };
                    reader.readAsDataURL(outfitPhoto.files[0]);
                }
            }
            
            // Form submission
            form.addEventListener('submit', function(e) {
                if (standingPhotos.files.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one standing photo.');
                    return;
                }
                
                if (outfitPhoto.files.length === 0) {
                    e.preventDefault();
                    alert('Please select an outfit photo.');
                    return;
                }
                
                // Show loading state
                generateBtn.disabled = true;
                generateBtn.textContent = '⏳ Generating...';
            });
        });
    </script>
</body>
</html>