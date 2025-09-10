<?php
// index.php - Landing page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tryon.php';

$user = Session::getCurrentUser();
$tryonModule = new TryOnModule('homepage');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PicFit.ai - AI Virtual Try-On</title>
    <meta name="description" content="Try on outfits virtually with AI. Upload your photos and see how clothes look on you before buying.">
    <link rel="stylesheet" href="/public/styles.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="/" class="logo">PicFit.ai</a>
                <div class="nav-links">
                    <?php if ($user): ?>
                        <div class="credits-badge">
                            <?= $user['credits_remaining'] ?> credits
                        </div>
                        <span class="text-secondary">
                            <?= htmlspecialchars($user['name']) ?>
                        </span>
                        <a href="/dashboard.php" class="btn btn-secondary">Dashboard</a>
                        <a href="/auth/logout.php" class="nav-link">Logout</a>
                    <?php else: ?>
                        <span class="text-muted hidden sm:inline">First fit is free</span>
                        <a href="/auth/login.php" class="btn btn-primary">Get Started</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title">
                    Try On Outfits<br>
                    <span class="text-secondary">Before You Buy</span>
                </h1>
                <p class="hero-subtitle">
                    Upload photos of yourself and any outfit. Our AI shows you exactly how it will look on you.
                </p>
                <?php if ($user): ?>
                    <a href="/dashboard.php" class="btn btn-accent btn-lg">
                        Start Generating →
                    </a>
                <?php else: ?>
                    <a href="/auth/login.php" class="btn btn-accent btn-lg">
                        Try It Free →
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Try It Now -->
    <section style="padding: var(--space-3xl) 0;">
        <div class="container-sm">
            <h2 class="text-center mb-lg">Try It Now</h2>
            <p class="text-center text-secondary mb-2xl" style="font-size: 1.25rem;">Upload your photos and generate your first fit!</p>
            
            <?= $tryonModule->renderHTML() ?>
        </div>
    </section>
    
    <?php if (!$tryonModule->requireAuth()): ?>
    <!-- How It Works (for non-logged in users) -->
    <section style="padding: var(--space-3xl) 0;">
        <div class="container">
            <h2 class="text-center mb-2xl">How It Works</h2>
            <div class="grid grid-3">
                <div class="card text-center">
                    <div class="upload-icon">📸</div>
                    <h3 class="mb-md">1. Upload Photos</h3>
                    <p class="text-secondary">Upload 3-10 full-body photos of yourself from different angles.</p>
                </div>
                <div class="card text-center">
                    <div class="upload-icon">👕</div>
                    <h3 class="mb-md">2. Add Outfit</h3>
                    <p class="text-secondary">Upload a flat-lay photo of the outfit you want to try on.</p>
                </div>
                <div class="card text-center">
                    <div class="upload-icon">✨</div>
                    <h3 class="mb-md">3. Get Your Fit</h3>
                    <p class="text-secondary">Our AI generates a realistic preview of how the outfit looks on you.</p>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Example Images -->
    <section style="padding: var(--space-3xl) 0; background: var(--bg-secondary);">
        <div class="container">
            <h2 class="text-center mb-2xl">See It In Action</h2>
            <div class="grid grid-2 gap-xl items-center">
                <div>
                    <h3 class="mb-md">Your Photos</h3>
                    <p class="text-secondary mb-lg">Take clear, well-lit photos from multiple angles. The better your photos, the more accurate the result.</p>
                    <div class="card">
                        <img src="/images/person.png" alt="Example person photo" style="width: 100%; height: 16rem; object-fit: cover; border-radius: var(--radius-md);">
                    </div>
                </div>
                <div>
                    <h3 class="mb-md">Outfit Photo</h3>
                    <p class="text-secondary mb-lg">Lay out your clothes flat on a clean surface. Include all items you want to see in the final result.</p>
                    <div class="card">
                        <img src="/images/outfit.png" alt="Example outfit photo" style="width: 100%; height: 16rem; object-fit: cover; border-radius: var(--radius-md);">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section style="padding: var(--space-3xl) 0;">
        <div class="container-sm text-center">
            <h2 class="mb-lg">Simple Pricing</h2>
            <p class="text-secondary mb-2xl" style="font-size: 1.25rem;">Try your first outfit free. Then choose a plan that works for you.</p>
            
            <?php if (!$user): ?>
                <div class="card mb-xl" style="max-width: 400px; margin-left: auto; margin-right: auto;">
                    <h3 class="mb-md">Free Trial</h3>
                    <p class="text-secondary mb-lg">Try one outfit generation completely free. No credit card required.</p>
                    <a href="/auth/login.php" class="btn btn-accent" style="width: 100%;">Start Free Trial</a>
                </div>
            <?php endif; ?>
            
            <div class="text-center">
                <a href="/pricing.php" class="btn btn-secondary">
                    View All Plans →
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer style="border-top: 1px solid var(--border-primary); padding: var(--space-3xl) 0;">
        <div class="container">
            <div class="grid grid-3">
                <div>
                    <div class="logo mb-md">PicFit.ai</div>
                    <p class="text-secondary">AI-powered virtual try-on technology for the modern shopper.</p>
                </div>
                <div>
                    <h4 class="font-semibold mb-md">Product</h4>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <li style="margin-bottom: var(--space-sm);"><a href="/pricing.php" class="nav-link">Pricing</a></li>
                        <?php if ($user): ?>
                            <li style="margin-bottom: var(--space-sm);"><a href="/dashboard.php" class="nav-link">Dashboard</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-md">Support</h4>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <li style="margin-bottom: var(--space-sm);"><a href="mailto:support@picfit.ai" class="nav-link">Contact</a></li>
                        <li style="margin-bottom: var(--space-sm);"><a href="/privacy.php" class="nav-link">Privacy</a></li>
                        <li style="margin-bottom: var(--space-sm);"><a href="/terms.php" class="nav-link">Terms</a></li>
                    </ul>
                </div>
            </div>
            <div style="border-top: 1px solid var(--border-primary); margin-top: var(--space-xl); padding-top: var(--space-xl); text-align: center;">
                <p class="text-muted">&copy; <?= date('Y') ?> PicFit.ai. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <?= $tryonModule->renderJS() ?>
</body>
</html>
