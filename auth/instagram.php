<?php
// auth/instagram.php - Instagram OAuth login initiation
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

// Check if already logged in
if (Session::isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

$instagramClientId = Config::get('instagram_client_id');
if (empty($instagramClientId)) {
    die('Instagram OAuth not configured. Please set INSTAGRAM_CLIENT_ID.');
}

$redirectUri = Config::get('instagram_redirect_uri', Config::get('base_url') . '/auth/instagram_callback.php');
$state = bin2hex(random_bytes(16));
Session::start();
$_SESSION['instagram_oauth_state'] = $state;

$instagramAuthUrl = 'https://api.instagram.com/oauth/authorize?' . http_build_query([
    'client_id' => $instagramClientId,
    'redirect_uri' => $redirectUri,
    'scope' => 'user_profile',
    'response_type' => 'code',
    'state' => $state
]);

// Redirect to Instagram
header('Location: ' . $instagramAuthUrl);
exit;
?>