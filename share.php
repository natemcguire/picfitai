<?php
// share.php - Public photo sharing with retro polaroid style
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Check if user is logged in
$user = Session::getCurrentUser();

$shareToken = $_GET['token'] ?? '';
$shareToken = basename($shareToken);

if (empty($shareToken)) {
    http_response_code(404);
    exit('Photo not found');
}

// Get the generation
$pdo = Database::getInstance();
$stmt = $pdo->prepare('
    SELECT g.*, u.name as user_name
    FROM generations g
    JOIN users u ON g.user_id = u.id
    WHERE g.share_token = ? AND g.status = "completed" AND g.is_public = 1
');
$stmt->execute([$shareToken]);
$generation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$generation) {
    http_response_code(404);
    exit('Photo not found or is private');
}

// Get next/previous public photos for navigation
$stmt = $pdo->prepare('
    SELECT share_token
    FROM generations
    WHERE is_public = 1 AND status = "completed" AND share_token IS NOT NULL
    AND id > ?
    ORDER BY id ASC
    LIMIT 1
');
$stmt->execute([$generation['id']]);
$nextPhoto = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('
    SELECT share_token
    FROM generations
    WHERE is_public = 1 AND status = "completed" AND share_token IS NOT NULL
    AND id < ?
    ORDER BY id DESC
    LIMIT 1
');
$stmt->execute([$generation['id']]);
$prevPhoto = $stmt->fetch(PDO::FETCH_ASSOC);

// Get rating counts for this photo
$stmt = $pdo->prepare('
    SELECT
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as likes,
        SUM(CASE WHEN rating = -1 THEN 1 ELSE 0 END) as dislikes,
        COUNT(*) as total_ratings
    FROM photo_ratings
    WHERE generation_id = ?
');
$stmt->execute([$generation['id']]);
$ratings = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if current user has already rated this photo
$userRating = null;
$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if ($ipAddress) {
    $stmt = $pdo->prepare('SELECT rating FROM photo_ratings WHERE generation_id = ? AND ip_address = ?');
    $stmt->execute([$generation['id'], $ipAddress]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $userRating = (int) $result['rating'];
    }
}

$pageTitle = 'PicFit.ai - AI Virtual Try-On';
$shareUrl = 'https://picfit.ai/share/' . $shareToken;
$imageUrl = $generation['result_url'];

// Ensure image URL is absolute for social media sharing
if (!str_starts_with($imageUrl, 'http')) {
    $imageUrl = 'https://picfit.ai/' . ltrim($imageUrl, '/');
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <!-- Social Media Meta Tags -->
    <meta property="og:title" content="Check out this AI virtual try-on!">
    <meta property="og:description" content="See how AI can transform your style with PicFit.ai - the future of virtual fashion">
    <meta property="og:image" content="<?= htmlspecialchars($imageUrl) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="1500">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:url" content="<?= htmlspecialchars($shareUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="PicFit.ai">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Check out this AI virtual try-on!">
    <meta name="twitter:description" content="See how AI can transform your style with PicFit.ai">
    <meta name="twitter:image" content="<?= htmlspecialchars($imageUrl) ?>">
    <meta name="twitter:site" content="@PicFitAI">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka+One:wght@400&family=Poppins:wght@300;400;600;700&display=swap');

        :root {
            --header-h: 64px;
            --cta-h: 60px;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg,
                #ff9a9e 0%,
                #fecfef 25%,
                #fecfef 50%,
                #a8e6cf 75%,
                #88d8c0 100%
            );
            background-size: 400% 400%;
            animation: gradientShift 8s ease infinite;
            min-height: 100dvh;
            overflow-x: hidden;
            display: block;
            padding-top: calc(var(--header-h) + 20px);
            padding-bottom: calc(var(--cta-h) + var(--safe-bottom) + 16px);
            color: #2c3e50;
            position: relative;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Floating bubble decorations */
        body::before,
        body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
            pointer-events: none;
        }

        body::before {
            width: 200px;
            height: 200px;
            background: #ff6b9d;
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        body::after {
            width: 150px;
            height: 150px;
            background: #4ecdc4;
            bottom: 10%;
            right: 5%;
            animation-delay: 3s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .polaroid-container {
            max-width: 500px;
            margin: 0 auto;
            padding: 20px 12px 0;
        }

        .polaroid {
            background: linear-gradient(145deg, #fff 0%, #fefefe 100%);
            padding: 25px 25px 80px 25px;
            box-shadow:
                /* Rainbow holographic border effect */
                0 0 0 3px rgba(255, 255, 255, 0.8),
                0 0 0 6px rgba(255, 182, 193, 0.4),
                0 0 0 9px rgba(255, 218, 185, 0.3),
                0 0 0 12px rgba(255, 255, 186, 0.2),
                /* Main shadows */
                0 8px 32px rgba(255, 107, 157, 0.3),
                0 16px 64px rgba(255, 107, 157, 0.2),
                /* Glowing aura */
                0 0 80px rgba(255, 182, 193, 0.1);
            transform: rotate(-2deg) translateY(-10px);
            position: relative;
            border-radius: 15px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background-image:
                radial-gradient(circle at 20% 20%, rgba(255, 182, 193, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(173, 216, 230, 0.1) 0%, transparent 50%);
        }

        .polaroid::before {
            content: '';
            position: absolute;
            bottom: -60px;
            left: 5%;
            right: 5%;
            height: 60px;
            background: radial-gradient(ellipse at center,
                rgba(255, 107, 157, 0.3) 0%,
                rgba(255, 107, 157, 0.1) 40%,
                transparent 70%);
            filter: blur(30px);
            z-index: -1;
        }

        .polaroid:hover {
            transform: rotate(1deg) scale(1.03) translateY(-20px);
            box-shadow:
                0 0 0 3px rgba(255, 255, 255, 0.9),
                0 0 0 6px rgba(255, 182, 193, 0.6),
                0 0 0 9px rgba(255, 218, 185, 0.5),
                0 0 0 12px rgba(255, 255, 186, 0.4),
                0 12px 48px rgba(255, 107, 157, 0.4),
                0 24px 96px rgba(255, 107, 157, 0.3),
                0 0 120px rgba(255, 182, 193, 0.2);
            animation: sparkle 1.5s ease-in-out infinite;
        }

        @keyframes sparkle {
            0%, 100% { filter: brightness(1) saturate(1); }
            50% { filter: brightness(1.05) saturate(1.1); }
        }

        .photo-frame {
            width: 100%;
            aspect-ratio: 4 / 5;
            height: auto;
            background: linear-gradient(135deg, #f8f9fa 0%, #fff 50%, #f1f3f4 100%);
            border: 3px solid transparent;
            background-image: linear-gradient(white, white), linear-gradient(45deg, #ff9a9e, #fecfef, #a8e6cf);
            background-origin: border-box;
            background-clip: content-box, border-box;
            position: relative;
            overflow: hidden;
            border-radius: 12px;
        }

        .photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
            display: block;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            object-position: center top;
        }

        .caption {
            margin-top: 20px;
            font-size: 18px;
            color: #2c3e50;
            text-align: center;
            font-weight: 600;
            font-family: 'Fredoka One', cursive;
            text-shadow: 0 2px 4px rgba(255,182,193,0.3);
            letter-spacing: 0.5px;
        }

        .date {
            position: absolute;
            bottom: 15px;
            right: 20px;
            font-size: 13px;
            color: #7f8c8d;
            transform: rotate(-1deg);
            font-weight: 500;
            background: rgba(255,255,255,0.7);
            padding: 4px 8px;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }

        .navigation {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,0.95);
            border: none;
            border-radius: 50%;
            width: 42px;
            height: 42px;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #333;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .navigation:hover {
            background: white;
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }

        .nav-prev {
            left: 8px;
        }

        .nav-next {
            right: 8px;
        }

        .viral-signup {
            position: fixed;
            bottom: calc(12px + var(--safe-bottom));
            left: 50%;
            transform: translateX(-50%);
            z-index: 1100;
            height: var(--cta-h);
            line-height: calc(var(--cta-h) - 8px);
            padding: 0 24px;
            background: linear-gradient(45deg, #ff6b9d, #4ecdc4);
            color: white;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            font-family: 'Fredoka One', cursive;
            box-shadow: 0 6px 25px rgba(255, 107, 157, 0.4);
            transition: all 0.3s ease;
            animation: bubbleFloat 3s ease-in-out infinite;
            border: 3px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
            font-size: 16px;
        }

        .viral-signup:hover {
            transform: translateX(-50%) translateY(-8px) scale(1.05);
            box-shadow: 0 12px 35px rgba(255, 107, 157, 0.5);
            background: linear-gradient(45deg, #ff8fab, #5fd8cf);
        }

        @keyframes bubbleFloat {
            0%, 100% { transform: translateX(-50%) translateY(0px) rotate(-1deg); }
            25% { transform: translateX(-50%) translateY(-8px) rotate(0deg); }
            50% { transform: translateX(-50%) translateY(-4px) rotate(1deg); }
            75% { transform: translateX(-50%) translateY(-12px) rotate(0deg); }
        }

        .header-nav {
            position: fixed;
            top: var(--safe-top, 0);
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 182, 193, 0.2);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .logo {
            font-size: 28px;
            font-weight: 700;
            font-family: 'Fredoka One', cursive;
            background: linear-gradient(45deg, #ff6b9d, #4ecdc4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
            text-shadow: 0 4px 8px rgba(255, 107, 157, 0.2);
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .nav-btn {
            padding: 12px 24px;
            background: linear-gradient(45deg, #ff6b9d 0%, #ff8fab 50%, #ff6b9d 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 6px 20px rgba(255, 107, 157, 0.3);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }

        .nav-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 107, 157, 0.4);
            background: linear-gradient(45deg, #ff8fab 0%, #ffafc9 50%, #ff8fab 100%);
        }

        .nav-btn.secondary {
            background: linear-gradient(45deg, #4ecdc4 0%, #44a08d 50%, #4ecdc4 100%);
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.3);
        }

        .nav-btn.secondary:hover {
            background: linear-gradient(45deg, #5fd8cf 0%, #4ecdc4 50%, #5fd8cf 100%);
            box-shadow: 0 8px 25px rgba(78, 205, 196, 0.4);
        }

        .share-section {
            margin: 18px 0 0;
            text-align: center;
        }

        .url-copy-field {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: stretch;
            background: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border-radius: 15px;
            border: 2px solid rgba(255, 182, 193, 0.3);
            backdrop-filter: blur(10px);
        }

        .url-input {
            flex: 1;
            padding: 10px 15px;
            border: 2px solid rgba(255, 182, 193, 0.2);
            border-radius: 10px;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.8);
            color: #2c3e50;
            font-family: 'Poppins', sans-serif;
        }

        .copy-btn {
            padding: 10px 20px;
            background: linear-gradient(45deg, #ff6b9d, #ff8fab);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            box-shadow: 0 4px 15px rgba(255, 107, 157, 0.3);
        }

        .copy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 157, 0.4);
            background: linear-gradient(45deg, #ff8fab, #ffafc9);
        }

        .copy-btn.copied {
            background: linear-gradient(45deg, #4ecdc4, #44a08d);
            animation: copySuccess 0.6s ease;
        }

        @keyframes copySuccess {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .share-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .share-btn {
            padding: 12px 24px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }

        .share-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
            background: linear-gradient(45deg, #7b8bef, #8a5fb5);
        }

        .share-btn.twitter {
            background: linear-gradient(45deg, #1da1f2, #0d8bd9);
            box-shadow: 0 4px 15px rgba(29, 161, 242, 0.3);
        }

        .share-btn.twitter:hover {
            background: linear-gradient(45deg, #42b0f5, #1da1f2);
            box-shadow: 0 6px 20px rgba(29, 161, 242, 0.4);
        }

        .share-btn.facebook {
            background: linear-gradient(45deg, #4267B2, #365899);
            box-shadow: 0 4px 15px rgba(66, 103, 178, 0.3);
        }

        .share-btn.facebook:hover {
            background: linear-gradient(45deg, #5a7bc0, #4267B2);
            box-shadow: 0 6px 20px rgba(66, 103, 178, 0.4);
        }

        .rating-section {
            margin: 18px 0;
            text-align: center;
        }

        .rating-buttons {
            display: flex;
            gap: 30px;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
        }

        .rating-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 16px 24px;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(255, 182, 193, 0.3);
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            backdrop-filter: blur(10px);
            user-select: none;
            min-height: 44px;
            min-width: 44px;
        }

        .rating-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 1);
            border-color: rgba(255, 182, 193, 0.5);
            box-shadow: 0 4px 15px rgba(255, 182, 193, 0.3);
        }

        .rating-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .rating-btn.active {
            background: linear-gradient(45deg, #ff6b9d, #ff8fab);
            color: white;
            border-color: #ff6b9d;
            box-shadow: 0 4px 15px rgba(255, 107, 157, 0.4);
        }

        .rating-btn.active:hover {
            background: linear-gradient(45deg, #ff8fab, #ffafc9);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(255, 107, 157, 0.5);
        }

        .rating-btn.thumbs-up.active {
            background: linear-gradient(45deg, #4ecdc4, #44a08d);
            border-color: #4ecdc4;
            box-shadow: 0 4px 15px rgba(78, 205, 196, 0.4);
        }

        .rating-btn.thumbs-up.active:hover {
            background: linear-gradient(45deg, #5fd8cf, #4ecdc4);
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.5);
        }

        .rating-display {
            font-size: 14px;
            color: #7f8c8d;
            font-weight: 500;
        }

        .rating-display.has-ratings {
            color: #2c3e50;
            font-weight: 600;
        }

        .rating-emoji {
            font-size: 20px;
        }

        .rating-count {
            font-size: 14px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .polaroid-container {
                max-width: 350px;
                padding: 10px 8px 0;
            }

            .header-nav {
                padding: 10px 12px;
            }

            .logo {
                font-size: 24px;
            }

            .nav-btn {
                padding: 10px 18px;
                font-size: 13px;
            }

            .polaroid {
                padding: 15px 15px 50px 15px;
            }

            .navigation {
                width: 36px;
                height: 36px;
                font-size: 18px;
            }

            .nav-prev {
                left: 4px;
            }

            .nav-next {
                right: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="header-nav">
        <a href="/" class="logo">PicFit.ai</a>
        <div class="nav-buttons">
            <?php if ($user): ?>
                <a href="/dashboard.php" class="nav-btn secondary">Dashboard</a>
            <?php endif; ?>
            <a href="/generate.php" class="nav-btn">Generate Yours</a>
        </div>
    </div>

    <div class="polaroid-container">
        <?php
        // Check if this is the user's own photo and they just created it
        $isOwner = $user && $user['id'] == $generation['user_id'];
        $justCreated = isset($_GET['new']) && $_GET['new'] === '1';

        if ($isOwner && $justCreated):
        ?>
            <div class="status-message success" style="margin-bottom: 20px; padding: 20px; background: linear-gradient(45deg, #4ecdc4, #44a08d); color: white; border-radius: 15px; text-align: center; font-weight: 600; box-shadow: 0 4px 15px rgba(78, 205, 196, 0.3);">
                🎉 Congratulations! Your AI try-on is ready! Share it with friends or generate another.
            </div>
        <?php endif; ?>

        <?php if ($prevPhoto): ?>
            <a href="/share/<?= htmlspecialchars($prevPhoto['share_token']) ?>" class="navigation nav-prev" title="Previous photo">
                ←
            </a>
        <?php endif; ?>

        <?php if ($nextPhoto): ?>
            <a href="/share/<?= htmlspecialchars($nextPhoto['share_token']) ?>" class="navigation nav-next" title="Next photo">
                →
            </a>
        <?php endif; ?>

        <div class="polaroid">
            <div class="photo-frame">
                <img src="<?= htmlspecialchars($imageUrl) ?>" alt="AI Virtual Try-On Result" class="photo">
            </div>
            <div class="caption">
                <?php if ($user && $user['id'] == $generation['user_id']): ?>
                    Your AI Try-On Creation! 🎉
                <?php else: ?>
                    AI Virtual Try-On Magic ✨
                <?php endif; ?>
            </div>
            <div class="date">
                <?= date('M j, Y', strtotime($generation['completed_at'])) ?>
            </div>
        </div>

        <!-- Rating Section -->
        <div class="rating-section">
            <div class="rating-buttons">
                <button class="rating-btn thumbs-up <?= $userRating === 1 ? 'active' : '' ?>" onclick="ratePhoto(1)" id="thumbsUpBtn">
                    <span class="rating-emoji">👍</span>
                    <span class="rating-count" id="likesCount"><?= (int)($ratings['likes'] ?? 0) ?></span>
                </button>
                <button class="rating-btn thumbs-down <?= $userRating === -1 ? 'active' : '' ?>" onclick="ratePhoto(-1)" id="thumbsDownBtn">
                    <span class="rating-emoji">👎</span>
                    <span class="rating-count" id="dislikesCount"><?= (int)($ratings['dislikes'] ?? 0) ?></span>
                </button>
            </div>

            <?php
            $totalRatings = (int)($ratings['total_ratings'] ?? 0);
            if ($totalRatings > 0):
            ?>
                <div class="rating-display has-ratings">
                    <?= number_format((int)($ratings['likes'] ?? 0)) ?> likes, <?= number_format((int)($ratings['dislikes'] ?? 0)) ?> dislikes
                </div>
            <?php else: ?>
                <div class="rating-display" id="noRatingsText">
                    Be the first to rate this photo!
                </div>
            <?php endif; ?>
        </div>

        <div class="share-section">
            <div class="url-copy-field">
                <input type="text" class="url-input" value="<?= htmlspecialchars($shareUrl) ?>" readonly id="shareUrl">
                <button class="copy-btn" onclick="copyUrl()" id="copyBtn">📋 Copy Link</button>
            </div>

            <div class="share-buttons">
                <a href="https://twitter.com/intent/tweet?url=<?= urlencode($shareUrl) ?>&text=<?= urlencode('Check out this amazing AI virtual try-on! 🤩✨ #AIFashion #VirtualTryOn') ?>"
                   class="share-btn twitter" target="_blank">🐦 Share on Twitter</a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
                   class="share-btn facebook" target="_blank">👍 Share on Facebook</a>
            </div>
        </div>
    </div>

    <a href="/auth/login.php" class="viral-signup">
        🚀 Try PicFit.ai FREE - Transform Your Style with AI!
    </a>

    <script>
        // Generation ID for rating
        const generationId = <?= (int)$generation['id'] ?>;

        // Photo rating function
        async function ratePhoto(rating) {
            const thumbsUpBtn = document.getElementById('thumbsUpBtn');
            const thumbsDownBtn = document.getElementById('thumbsDownBtn');
            const likesCount = document.getElementById('likesCount');
            const dislikesCount = document.getElementById('dislikesCount');
            const noRatingsText = document.getElementById('noRatingsText');

            try {
                // Disable buttons temporarily
                thumbsUpBtn.disabled = true;
                thumbsDownBtn.disabled = true;

                const response = await fetch('/api/rate_photo.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        generation_id: generationId,
                        rating: rating
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // Update counts
                    likesCount.textContent = result.counts.likes;
                    dislikesCount.textContent = result.counts.dislikes;

                    // Update active states
                    thumbsUpBtn.classList.toggle('active', rating === 1);
                    thumbsDownBtn.classList.toggle('active', rating === -1);

                    // Update rating display
                    if (result.counts.total > 0 && noRatingsText) {
                        noRatingsText.innerHTML = `<div class="rating-display has-ratings">${result.counts.likes} likes, ${result.counts.dislikes} dislikes</div>`;
                        noRatingsText.className = 'rating-display has-ratings';
                    }

                    // Add sparkle effect
                    const activeBtn = rating === 1 ? thumbsUpBtn : thumbsDownBtn;
                    activeBtn.style.animation = 'copySuccess 0.6s ease';
                    setTimeout(() => {
                        activeBtn.style.animation = '';
                    }, 600);

                } else {
                    console.error('Rating failed:', result.error);
                    alert('Failed to submit rating. Please try again.');
                }

            } catch (error) {
                console.error('Rating error:', error);
                alert('Failed to submit rating. Please try again.');
            } finally {
                // Re-enable buttons
                thumbsUpBtn.disabled = false;
                thumbsDownBtn.disabled = false;
            }
        }

        // Copy URL function
        function copyUrl() {
            const urlInput = document.getElementById('shareUrl');
            const copyBtn = document.getElementById('copyBtn');

            urlInput.select();
            urlInput.setSelectionRange(0, 99999); // For mobile devices

            navigator.clipboard.writeText(urlInput.value).then(function() {
                copyBtn.textContent = '✅ Copied!';
                copyBtn.classList.add('copied');

                setTimeout(() => {
                    copyBtn.textContent = '📋 Copy Link';
                    copyBtn.classList.remove('copied');
                }, 2000);
            }).catch(function() {
                // Fallback for older browsers
                document.execCommand('copy');
                copyBtn.textContent = '✅ Copied!';
                copyBtn.classList.add('copied');

                setTimeout(() => {
                    copyBtn.textContent = '📋 Copy Link';
                    copyBtn.classList.remove('copied');
                }, 2000);
            });
        }

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft' && document.querySelector('.nav-prev')) {
                document.querySelector('.nav-prev').click();
            } else if (e.key === 'ArrowRight' && document.querySelector('.nav-next')) {
                document.querySelector('.nav-next').click();
            }
        });

        // Add sparkle effects on hover
        const polaroid = document.querySelector('.polaroid');
        polaroid.addEventListener('mouseenter', function() {
            this.style.filter = 'brightness(1.05) saturate(1.1)';
        });

        polaroid.addEventListener('mouseleave', function() {
            this.style.filter = 'brightness(1) saturate(1)';
        });

        // Preload next/prev images
        <?php if ($nextPhoto): ?>
        setTimeout(() => {
            fetch('/share/<?= htmlspecialchars($nextPhoto['share_token']) ?>').then(response => response.text());
        }, 1000);
        <?php endif; ?>

        <?php if ($prevPhoto): ?>
        setTimeout(() => {
            fetch('/share/<?= htmlspecialchars($prevPhoto['share_token']) ?>').then(response => response.text());
        }, 1500);
        <?php endif; ?>
    </script>
</body>
</html>