<?php
// index.php - Landing page with recent generations
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = Session::getCurrentUser();

// Get the most recent public generations for display
$pdo = Database::getInstance();
$stmt = $pdo->prepare('
    SELECT share_token, result_url, created_at
    FROM generations
    WHERE status = "completed"
    AND is_public = 1
    AND share_token IS NOT NULL
    AND result_url IS NOT NULL
    ORDER BY completed_at DESC
    LIMIT 10
');
$stmt->execute();
$recentGenerations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get the most recent (featured) and second most recent for navigation
$featuredGeneration = $recentGenerations[0] ?? null;
$previousGeneration = $recentGenerations[1] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PicFit.ai - AI Virtual Try-On</title>
    <meta name="description" content="Try on outfits virtually with AI. Upload your photos and see how clothes look on you before buying.">

    <!-- Open Graph (good for Facebook, WhatsApp, LinkedIn, etc.) -->
    <meta property="og:title" content="PicFit – Try on outfits with AI" />
    <meta property="og:description" content="Upload a photo and instantly see outfits styled on you." />
    <?php if ($featuredGeneration && $featuredGeneration['share_token']): ?>
    <meta property="og:image" content="https://picfit.ai/api/whatsapp_image.php?token=<?= urlencode($featuredGeneration['share_token']) ?>" />
    <?php else: ?>
    <meta property="og:image" content="https://picfit.ai/images/og-default.jpg" />
    <?php endif; ?>
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="1200" />
    <meta property="og:image:type" content="image/jpeg" />
    <meta property="og:url" content="https://picfit.ai/" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="PicFit.ai" />

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="PicFit – Try on outfits with AI" />
    <meta name="twitter:description" content="Upload a photo and instantly see outfits styled on you." />
    <?php if ($featuredGeneration && $featuredGeneration['share_token']): ?>
    <meta name="twitter:image" content="https://picfit.ai/api/twitter_image.php?token=<?= urlencode($featuredGeneration['share_token']) ?>" />
    <meta name="twitter:image:src" content="https://picfit.ai/api/twitter_image.php?token=<?= urlencode($featuredGeneration['share_token']) ?>" />
    <?php else: ?>
    <meta name="twitter:image" content="https://picfit.ai/images/og-default.jpg" />
    <meta name="twitter:image:src" content="https://picfit.ai/images/og-default.jpg" />
    <?php endif; ?>
    <meta name="twitter:image:alt" content="AI-generated virtual try-on result" />
    <meta name="twitter:image:width" content="1200" />
    <meta name="twitter:image:height" content="630" />
    <meta name="twitter:site" content="@PicFitAI" />

    <!-- Additional meta tags for better sharing -->
    <link rel="canonical" href="https://picfit.ai/" />

    <!-- WhatsApp-specific optimizations -->
    <?php if ($featuredGeneration && $featuredGeneration['share_token']): ?>
    <meta property="og:image:secure_url" content="https://picfit.ai/api/whatsapp_image.php?token=<?= urlencode($featuredGeneration['share_token']) ?>" />
    <?php endif; ?>
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-capable" content="yes" />

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka+One&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #ffeef8 0%, #ffe0f7 100%);
            min-height: 100vh;
            color: #333;
            overflow-x: hidden;
        }

        /* Container with iPad-like fixed width on desktop */
        .main-container {
            max-width: 768px;
            margin: 0 auto;
            min-height: 100vh;
            background: linear-gradient(135deg, #ffeef8 0%, #ffe0f7 100%);
            position: relative;
        }

        /* Mobile view - full width */
        @media (max-width: 768px) {
            .main-container {
                max-width: 100%;
            }
        }

        /* Gallery Background */
        .gallery-background {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1;
            opacity: 0.3;
            pointer-events: none;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding: 20px;
            align-content: flex-start;
            overflow: hidden;
        }

        .gallery-item {
            width: 200px;
            height: 300px;
            border-radius: 15px;
            object-fit: cover;
            filter: blur(2px) grayscale(30%);
            transform: rotate(-5deg);
        }

        .gallery-item:nth-child(even) {
            transform: rotate(3deg);
        }

        .gallery-item:nth-child(3n) {
            transform: rotate(-2deg);
        }

        /* Main Content */
        .main-content {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            margin-bottom: 100px;
        }

        /* Featured Photo */
        .featured-container {
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .featured-container:hover {
            transform: scale(1.02);
        }

        .featured-photo {
            background: white;
            padding: 20px 20px 60px 20px;
            border-radius: 20px;
            box-shadow:
                0 0 0 3px rgba(255, 255, 255, 0.8),
                0 0 0 6px rgba(255, 182, 193, 0.4),
                0 8px 32px rgba(255, 107, 157, 0.3),
                0 16px 64px rgba(255, 107, 157, 0.2);
            transform: rotate(-1deg);
            max-width: 400px;
            margin: 0 auto;
            position: relative;
        }

        .featured-photo img {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 10px;
        }

        .rate-button {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(45deg, #ff6b9d, #ff8fab);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(255, 107, 157, 0.3);
        }

        .rate-button:hover {
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 107, 157, 0.4);
            background: linear-gradient(45deg, #ff8fab, #ffafc9);
        }

        /* Title */
        .title {
            position: relative;
            text-align: center;
            z-index: 15;
            width: 100%;
            max-width: 600px;
            margin: 20px auto 40px auto;
            padding: 0 20px;
        }

        .title h1 {
            font-size: 3rem;
            font-weight: 700;
            font-family: 'Fredoka One', cursive;
            background: linear-gradient(45deg, #ff6b9d, #4ecdc4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 4px 8px rgba(255, 107, 157, 0.2);
            margin-bottom: 1rem;
        }

        .title p {
            font-size: 1.2rem;
            color: #666;
            margin-bottom: 2rem;
        }

        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            margin: 0 10px;
        }

        .btn-primary {
            background: linear-gradient(45deg, #ff6b9d, #ff8fab);
            color: white;
            box-shadow: 0 6px 20px rgba(255, 107, 157, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(255, 107, 157, 0.6);
        }

        .btn-secondary {
            background: linear-gradient(45deg, #4ecdc4, #44a08d);
            color: white;
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.4);
        }

        .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(78, 205, 196, 0.6);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-container {
                padding: 0;
            }

            .title {
                margin: 10px auto 30px auto;
                padding: 0 15px;
            }

            .title h1 {
                font-size: 2.2rem;
                margin-bottom: 10px;
            }

            .title p {
                font-size: 1rem;
                margin-bottom: 20px;
            }

            .main-content {
                padding: 1rem;
                margin-bottom: 60px;
            }

            .featured-photo {
                max-width: 280px;
                padding: 12px 12px 40px 12px;
                margin: 0 auto;
            }

            .featured-photo img {
                height: 320px;
            }

            .rate-button {
                bottom: 8px;
                padding: 6px 12px;
                font-size: 0.8rem;
            }

            .gallery-item {
                width: 120px;
                height: 180px;
            }

            .btn {
                padding: 0.8rem 1.2rem;
                font-size: 0.9rem;
                margin: 8px 5px;
                display: inline-block;
                max-width: 160px;
            }

            /* Fix footer spacing on mobile */
            footer {
                bottom: 10px !important;
            }

            footer p {
                font-size: 0.8rem !important;
                padding: 8px 15px !important;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include __DIR__ . '/includes/nav.php'; ?>

        <!-- Gallery Background -->
        <div class="gallery-background">
            <?php
            // Repeat the first 10 recent generations multiple times to fill the background
            $backgroundImages = array_slice($recentGenerations, 1);
            if (count($backgroundImages) > 0) {
                // Repeat images to get at least 40 total for good coverage
                $repeatedImages = [];
                while (count($repeatedImages) < 40) {
                    foreach ($backgroundImages as $gen) {
                        $repeatedImages[] = $gen;
                        if (count($repeatedImages) >= 40) break;
                    }
                }

                foreach ($repeatedImages as $gen):
            ?>
                <img src="<?= htmlspecialchars(CDNService::getImageUrl($gen['result_url'])) ?>" alt="Generated outfit" class="gallery-item" />
            <?php
                endforeach;
            }
            ?>
        </div>

        <!-- Title -->
        <div class="title">
            <h1>PicFit.ai</h1>
            <p>Try on outfits with AI</p>
            <div>
                <?php if ($user): ?>
                    <a href="/generate.php" class="btn btn-primary">Start Generating</a>
                    <a href="/dashboard.php" class="btn btn-secondary">Dashboard</a>
                <?php else: ?>
                    <a href="/auth/login.php" class="btn btn-primary">Get Started</a>
                    <a href="/pricing.php" class="btn btn-secondary">Pricing</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Featured Photo -->
        <div class="main-content">
            <?php if ($featuredGeneration): ?>
                <div class="featured-container" onclick="navigateToFeatured()">
                    <div class="featured-photo">
                        <img src="<?= htmlspecialchars(CDNService::getImageUrl($featuredGeneration['result_url'])) ?>" alt="Latest AI-generated outfit" />
                        <button class="rate-button" onclick="event.stopPropagation(); navigateToFeatured();">Rate This Fit</button>
                    </div>
                </div>
            <?php else: ?>
                <div class="featured-container" onclick="window.location.href='/generate.php'">
                    <div class="featured-photo">
                        <div style="height: 400px; display: flex; align-items: center; justify-content: center; color: #999;">
                            No generations yet - be the first!
                        </div>
                        <button class="rate-button" onclick="event.stopPropagation(); window.location.href='/generate.php';">Start Generating</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Simple Footer -->
        <footer style="position: absolute; bottom: 20px; width: 100%; text-align: center; z-index: 20;">
            <p style="color: #666; font-size: 0.9rem; background: rgba(255, 255, 255, 0.8); padding: 10px 20px; border-radius: 20px; display: inline-block; backdrop-filter: blur(10px);">
                It's just fun ya'll
            </p>
        </footer>
    </div></body>

    <script>
        function navigateToFeatured() {
            <?php if ($featuredGeneration): ?>
                window.location.href = '/share/<?= htmlspecialchars($featuredGeneration['share_token']) ?>';
            <?php else: ?>
                window.location.href = '/generate.php';
            <?php endif; ?>
        }
    </script>
</body>
</html>
