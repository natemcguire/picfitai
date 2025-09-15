<?php
// auth/instagram_callback.php - Instagram OAuth callback handler
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

Session::start();

// Verify state parameter
$state = $_GET['state'] ?? '';
$sessionState = $_SESSION['instagram_oauth_state'] ?? '';
unset($_SESSION['instagram_oauth_state']);

if (empty($state) || $state !== $sessionState) {
    die('Invalid state parameter. Possible CSRF attack.');
}

// Check for error from Instagram
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
    $errorDescription = htmlspecialchars($_GET['error_description'] ?? '');
    die("Instagram OAuth error: {$error}. {$errorDescription}");
}

// Get authorization code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    die('No authorization code received from Instagram.');
}

try {
    // Exchange code for access token
    $instagramClientId = Config::get('instagram_client_id');
    $instagramClientSecret = Config::get('instagram_client_secret');
    $redirectUri = Config::get('instagram_redirect_uri', Config::get('base_url') . '/auth/instagram_callback.php');

    $tokenData = [
        'client_id' => $instagramClientId,
        'client_secret' => $instagramClientSecret,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri,
        'code' => $code
    ];

    $tokenResponse = file_get_contents('https://api.instagram.com/oauth/access_token', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($tokenData)
        ]
    ]));

    if ($tokenResponse === false) {
        throw new Exception('Failed to exchange code for access token');
    }

    $tokenResult = json_decode($tokenResponse, true);
    if (!$tokenResult || isset($tokenResult['error'])) {
        throw new Exception('Invalid token response: ' . ($tokenResult['error_message'] ?? 'Unknown error'));
    }

    $accessToken = $tokenResult['access_token'];
    $instagramUserId = $tokenResult['user_id'];

    // Get user profile information
    $profileUrl = "https://graph.instagram.com/{$instagramUserId}?fields=id,username&access_token={$accessToken}";
    $profileResponse = file_get_contents($profileUrl);

    if ($profileResponse === false) {
        throw new Exception('Failed to fetch user profile');
    }

    $profile = json_decode($profileResponse, true);
    if (!$profile || isset($profile['error'])) {
        throw new Exception('Invalid profile response: ' . ($profile['error']['message'] ?? 'Unknown error'));
    }

    // Check if user exists or create new user
    $pdo = Database::getInstance();

    // Look for existing user by Instagram ID
    $stmt = $pdo->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ?');
    $stmt->execute(['instagram', $instagramUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Create new user
        $stmt = $pdo->prepare('
            INSERT INTO users (oauth_provider, oauth_id, name, credits_remaining, free_credits_used, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            'instagram',
            $instagramUserId,
            $profile['username'],
            10, // 10 free credits for new users
            0,
            date('Y-m-d H:i:s')
        ]);

        $userId = $pdo->lastInsertId();

        // Record the free credits transaction
        $stmt = $pdo->prepare('
            INSERT INTO credit_transactions (user_id, type, credits, description, created_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            'bonus',
            10,
            'Welcome bonus for new Instagram user',
            date('Y-m-d H:i:s')
        ]);

        // Fetch the created user
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Update last login
        $stmt = $pdo->prepare('UPDATE users SET updated_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $user['id']]);
    }

    // Create session
    Session::login($user);

    // Redirect to dashboard
    header('Location: /dashboard.php');
    exit;

} catch (Exception $e) {
    error_log('Instagram OAuth error: ' . $e->getMessage());
    die('Login failed: ' . $e->getMessage());
}
?>