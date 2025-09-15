<?php
// whatsapp_send_otp.php - API endpoint to send OTP via WhatsApp
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    // Rate limiting by IP
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitKey = 'whatsapp_otp_' . $clientIp;

    if (!Security::checkRateLimit($rateLimitKey, 5, 300)) { // 5 requests per 5 minutes per IP
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Too many requests. Please wait before trying again.'
        ]);
        exit();
    }

    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit();
    }

    $phoneNumber = $input['phone_number'] ?? '';

    // Validate phone number
    if (empty($phoneNumber)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Phone number is required']);
        exit();
    }

    // Basic phone number validation
    $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid phone number format']);
        exit();
    }

    // Send OTP
    $result = WhatsAppAuthService::sendOTP($phoneNumber);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit();
    }

    // Log successful OTP request
    Logger::info('WhatsApp OTP requested', [
        'ip' => $clientIp,
        'phone' => substr($phoneNumber, 0, 2) . '***' . substr($phoneNumber, -2)
    ]);

    echo json_encode($result);

} catch (Exception $e) {
    Logger::error('WhatsApp OTP API error', [
        'error' => $e->getMessage(),
        'ip' => $clientIp ?? 'unknown'
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error. Please try again.'
    ]);
}