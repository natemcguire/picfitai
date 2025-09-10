<?php
// auth/callback.php - OAuth callback handler
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

Session::start();

// Validate state parameter
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || 
    !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
    die('Invalid state parameter');
}

unset($_SESSION['oauth_state']);

// Check for error
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
    die("OAuth error: $error");
}

// Check for authorization code
if (!isset($_GET['code'])) {
    die('No authorization code received');
}

$code = $_GET['code'];
$clientId = Config::get('google_client_id');
$clientSecret = Config::get('google_client_secret');
$redirectUri = Config::get('oauth_redirect_uri');

if (empty($clientId) || empty($clientSecret)) {
    die('Google OAuth not properly configured');
}

try {
    // Exchange code for access token
    $tokenData = exchangeCodeForToken($code, $clientId, $clientSecret, $redirectUri);
    
    // Get user info from Google
    $userInfo = getUserInfo($tokenData['access_token']);
    
    // Login user
    $sessionId = Session::login([
        'provider' => 'google',
        'id' => $userInfo['sub'],
        'email' => $userInfo['email'],
        'name' => $userInfo['name'],
        'avatar_url' => $userInfo['picture'] ?? null
    ]);
    
    // Redirect to dashboard
    header('Location: /dashboard.php');
    exit;
    
} catch (Exception $e) {
    error_log('OAuth callback error: ' . $e->getMessage());
    die('Authentication failed. Please try again.');
}

function exchangeCodeForToken(string $code, string $clientId, string $clientSecret, string $redirectUri): array {
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    
    $postData = [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $tokenUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        throw new Exception('Failed to exchange code for token');
    }
    
    $tokenData = json_decode($response, true);
    if (!$tokenData || !isset($tokenData['access_token'])) {
        throw new Exception('Invalid token response');
    }
    
    return $tokenData;
}

function getUserInfo(string $accessToken): array {
    $userInfoUrl = 'https://openidconnect.googleapis.com/v1/userinfo';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $userInfoUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        throw new Exception('Failed to get user info');
    }
    
    $userInfo = json_decode($response, true);
    if (!$userInfo || !isset($userInfo['sub'])) {
        throw new Exception('Invalid user info response');
    }
    
    return $userInfo;
}
