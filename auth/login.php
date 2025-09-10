<?php
// auth/login.php - OAuth login page
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

// Check if already logged in
if (Session::isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

$googleClientId = Config::get('google_client_id');
if (empty($googleClientId)) {
    die('Google OAuth not configured. Please set GOOGLE_CLIENT_ID.');
}

$redirectUri = Config::get('oauth_redirect_uri');
$state = bin2hex(random_bytes(16));
Session::start();
$_SESSION['oauth_state'] = $state;

$googleAuthUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id' => $googleClientId,
    'redirect_uri' => $redirectUri,
    'scope' => 'openid email profile',
    'response_type' => 'code',
    'state' => $state
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?= Config::get('app_name') ?></title>
    <link rel="stylesheet" href="/public/styles.css">
</head>
<body class="min-h-screen bg-void text-ivory flex items-center justify-center p-md">
    <div class="card p-xl container-xs">
        <div class="text-center mb-lg">
            <h1 class="h1 mb-sm">Welcome to PicFit.ai</h1>
            <p class="text-mist">Sign in to start trying on outfits</p>
        </div>
        
        <div class="space-y-md">
            <a href="<?= htmlspecialchars($googleAuthUrl) ?>" 
               class="btn btn-primary btn-lg w-full flex items-center justify-center gap-sm">
                <svg class="w-5 h-5" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="currentColor" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="currentColor" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="currentColor" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Continue with Google
            </a>
        </div>
        
        <div class="mt-lg text-center text-sm text-slash">
            <p>By signing in, you agree to our terms of service and privacy policy.</p>
        </div>
    </div>
</body>
</html>
