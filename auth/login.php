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
    <style>
        /* Bubblegum Pop Login Styles */
        .bubblegum-body {
            background: linear-gradient(45deg,
                #ff6b9d, #c44569, #6c5ce7, #74b9ff,
                #00cec9, #55a3ff, #ff7675, #fd79a8);
            background-size: 400% 400%;
            animation: bubblegum-gradient 8s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        @keyframes bubblegum-gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Floating bubbles */
        .bubblegum-body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image:
                radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.1) 1px, transparent 1px),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.15) 2px, transparent 2px),
                radial-gradient(circle at 40% 40%, rgba(255, 255, 255, 0.1) 1px, transparent 1px),
                radial-gradient(circle at 70% 70%, rgba(255, 255, 255, 0.2) 3px, transparent 3px);
            background-size: 50px 50px, 100px 100px, 75px 75px, 120px 120px;
            animation: float-bubbles 15s linear infinite;
            pointer-events: none;
        }

        @keyframes float-bubbles {
            0% { transform: translateY(0px); }
            100% { transform: translateY(-100vh); }
        }

        .bubblegum-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 30px;
            box-shadow:
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.5),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
            padding: 3rem;
            max-width: 400px;
            width: 100%;
            position: relative;
            transform: translateY(0);
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .bubblegum-card::before {
            content: '';
            position: absolute;
            top: -5px;
            left: -5px;
            right: -5px;
            bottom: -5px;
            background: linear-gradient(45deg,
                #ff6b9d, #c44569, #6c5ce7, #74b9ff,
                #00cec9, #55a3ff, #ff7675, #fd79a8);
            background-size: 400% 400%;
            animation: bubblegum-gradient 8s ease infinite;
            border-radius: 35px;
            z-index: -1;
            filter: blur(20px);
            opacity: 0.7;
        }

        .bubblegum-title {
            background: linear-gradient(45deg, #ff6b9d, #6c5ce7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.5rem;
            font-weight: 900;
            text-align: center;
            margin-bottom: 0.5rem;
            animation: title-bounce 2s ease-in-out infinite;
        }

        @keyframes title-bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .bubblegum-subtitle {
            color: #6c5ce7;
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .bubblegum-btn {
            background: linear-gradient(45deg, #ff6b9d, #6c5ce7);
            border: none;
            border-radius: 50px;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            padding: 1rem 2rem;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(108, 92, 231, 0.3);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .bubblegum-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(108, 92, 231, 0.4);
        }

        .bubblegum-btn:active {
            transform: translateY(-1px);
        }

        .bubblegum-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg,
                transparent,
                rgba(255, 255, 255, 0.4),
                transparent);
            transition: left 0.7s;
        }

        .bubblegum-btn:hover::before {
            left: 100%;
        }

        .bubblegum-icon {
            width: 24px;
            height: 24px;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        }

        .bubblegum-footer {
            text-align: center;
            margin-top: 2rem;
            color: #74b9ff;
            font-size: 0.9rem;
        }

        /* Sparkle animation */
        .sparkle {
            position: fixed;
            width: 6px;
            height: 6px;
            background: white;
            border-radius: 50%;
            animation: sparkle 2s linear infinite;
            pointer-events: none;
        }

        @keyframes sparkle {
            0% {
                transform: scale(0) rotate(0deg);
                opacity: 1;
            }
            50% {
                transform: scale(1) rotate(180deg);
                opacity: 1;
            }
            100% {
                transform: scale(0) rotate(360deg);
                opacity: 0;
            }
        }

        /* Mobile responsive */
        @media (max-width: 480px) {
            .bubblegum-card {
                margin: 1rem;
                padding: 2rem;
            }

            .bubblegum-title {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body class="bubblegum-body">
    <div class="bubblegum-card">
        <h1 class="bubblegum-title">PicFit.ai ✨</h1>
        <p class="bubblegum-subtitle">Sign in to start trying on outfits!</p>
        
        <a href="<?= htmlspecialchars($googleAuthUrl) ?>" class="bubblegum-btn">
            <svg class="bubblegum-icon" viewBox="0 0 24 24">
                <path fill="currentColor" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="currentColor" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="currentColor" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="currentColor" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Continue with Google
        </a>

            <!-- Instagram and WhatsApp login temporarily hidden for Facebook verification process -->
            <!--
            <div class="text-center text-sm text-slash">
                <span>or</span>
            </div>

            <a href="/auth/whatsapp.php"
               class="btn btn-secondary btn-lg w-full flex items-center justify-center gap-sm">
                <span style="font-size: 1.2em;">💬</span>
                Continue with WhatsApp
            </a>

            <a href="/auth/instagram.php"
               class="btn btn-instagram btn-lg w-full flex items-center justify-center gap-sm">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.40s-.644-1.44-1.439-1.44z"/>
                </svg>
                Continue with Instagram
            </a>
            -->

        <div class="bubblegum-footer">
            By signing in, you agree to our terms of service and privacy policy.
        </div>
    </div>

    <!-- Add some sparkle effects -->
    <script>
        function createSparkle() {
            const sparkle = document.createElement('div');
            sparkle.className = 'sparkle';
            sparkle.style.left = Math.random() * 100 + 'vw';
            sparkle.style.top = Math.random() * 100 + 'vh';
            document.body.appendChild(sparkle);

            setTimeout(() => {
                sparkle.remove();
            }, 2000);
        }

        // Create sparkles periodically
        setInterval(createSparkle, 300);
    </script>
</body>
</html>
