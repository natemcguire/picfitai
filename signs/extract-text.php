<?php
require_once '../bootstrap.php';

// Set JSON response header
header('Content-Type: application/json');
ob_start();

// Ensure user is logged in
$currentUser = Session::getCurrentUser();
if (!$currentUser) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    ob_end_flush();
    exit;
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Verify CSRF token
    if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }

    // Handle file upload
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Please upload an image');
    }

    $uploadedFile = $_FILES['image'];

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

    // Resize if larger than 1024px for OCR efficiency
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

    // Create temp file
    $tempFile = tempnam(sys_get_temp_dir(), 'ocr_');
    imagejpeg($image, $tempFile, 90);
    imagedestroy($image);

    // Read image as base64
    $imageData = base64_encode(file_get_contents($tempFile));

    // Extract text using AI service
    $aiService = new AIService();
    $extractedText = $aiService->extractTextFromImage($imageData);

    // Clean up temp file
    unlink($tempFile);

    // Success response
    echo json_encode([
        'success' => true,
        'extracted_text' => $extractedText
    ]);

} catch (Exception $e) {
    Logger::error('OCR extraction error: ' . $e->getMessage());
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

ob_end_flush();