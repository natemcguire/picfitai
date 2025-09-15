<?php
// nav.php - Reusable navigation component
// Usage: include this file in any page that needs navigation

// Ensure session is available
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$user = Session::getCurrentUser();

// Get most recent public share for "See Fits" button
$mostRecentShareUrl = null;
if ($user) {
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('
            SELECT share_token
            FROM generations
            WHERE user_id = ?
            AND status = "completed"
            AND is_public = 1
            AND share_token IS NOT NULL
            ORDER BY completed_at DESC
            LIMIT 1
        ');
        $stmt->execute([$user['id']]);
        $shareToken = $stmt->fetchColumn();

        if ($shareToken) {
            $mostRecentShareUrl = '/share/' . $shareToken;
        }
    } catch (Exception $e) {
        // Silently fail - just won't show the button
    }
}
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Fredoka+One&display=swap');

    .header-nav {
        position: fixed;
        top: 15px;
        left: 20px;
        right: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(20px);
        border-radius: 20px;
        padding: 10px 20px;
        border: 1px solid rgba(255, 255, 255, 0.3);
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

    .nav-btn.tertiary {
        background: rgba(0, 0, 0, 0.15);
        backdrop-filter: blur(10px);
        border: 2px solid rgba(0, 0, 0, 0.2);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        color: #333;
    }

    .nav-btn.tertiary:hover {
        background: rgba(0, 0, 0, 0.25);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        color: #000;
    }

    .credits-badge {
        background: linear-gradient(45deg, #ffd93d, #ffb73d);
        color: white;
        padding: 10px 20px;
        border-radius: 50px;
        font-size: 14px;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(255, 183, 61, 0.3);
        border: 2px solid rgba(255, 255, 255, 0.3);
    }

    /* Add padding to body to account for fixed nav */
    body {
        padding-top: 100px;
    }

    @media (max-width: 768px) {
        .header-nav {
            top: 10px;
            left: 10px;
            right: 10px;
            padding: 10px 12px;
        }

        .logo {
            font-size: 18px;
        }

        .nav-buttons {
            gap: 6px;
        }

        .nav-btn {
            padding: 6px 12px;
            font-size: 11px;
        }
    }

    @media (max-width: 480px) {
        .logo {
            font-size: 16px;
        }

        .nav-btn {
            padding: 5px 10px;
            font-size: 10px;
        }

        .nav-buttons {
            gap: 4px;
        }
    }
</style>

<div class="header-nav">
    <a href="/" class="logo">PicFit.ai</a>
    <div class="nav-buttons">
        <?php if ($user): ?>
            <a href="/dashboard.php" class="nav-btn secondary">Dashboard</a>
            <a href="/generate.php" class="nav-btn">Generate</a>
            <?php if ($mostRecentShareUrl): ?>
                <a href="<?= htmlspecialchars($mostRecentShareUrl) ?>" class="nav-btn tertiary">See Fits</a>
            <?php endif; ?>
        <?php else: ?>
            <a href="/pricing.php" class="nav-btn secondary">Pricing</a>
            <a href="/auth/login.php" class="nav-btn">Get Started</a>
        <?php endif; ?>
    </div>
</div>