<?php
// whatsapp_verify_otp.php - API endpoint to verify OTP and login user
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
    // Rate limiting by IP for verification attempts
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitKey = 'whatsapp_verify_' . $clientIp;

    if (!Security::checkRateLimit($rateLimitKey, 10, 300)) { // 10 verification attempts per 5 minutes per IP
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Too many verification attempts. Please wait before trying again.'
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
    $otpCode = $input['otp_code'] ?? '';

    // Validate inputs
    if (empty($phoneNumber)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Phone number is required']);
        exit();
    }

    if (empty($otpCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'OTP code is required']);
        exit();
    }

    // Validate OTP format (6 digits)
    if (!preg_match('/^\d{6}$/', $otpCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid OTP format']);
        exit();
    }

    // Verify OTP and login
    $result = WhatsAppAuthService::verifyOTP($phoneNumber, $otpCode);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit();
    }

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        Session::start();
    }

    // Log successful authentication
    Logger::info('WhatsApp authentication successful', [
        'ip' => $clientIp,
        'user_id' => $result['user']['id'],
        'phone' => substr($phoneNumber, 0, 2) . '***' . substr($phoneNumber, -2)
    ]);

    // Clean up sensitive data from response
    if (isset($result['user'])) {
        unset($result['user']['oauth_id']);
        unset($result['user']['stripe_customer_id']);
    }

    echo json_encode($result);

} catch (Exception $e) {
    Logger::error('WhatsApp OTP verification API error', [
        'error' => $e->getMessage(),
        'ip' => $clientIp ?? 'unknown'
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error. Please try again.'
    ]);
}