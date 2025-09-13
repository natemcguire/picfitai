<?php
// index.php - Landing page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = Session::getCurrentUser();
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
                    <span class="text-gradient">With AI</span>
                </h1>
                <p class="hero-description">
                    Upload your photos and see how clothes look on you before buying. 
                    Powered by advanced AI technology.
                </p>
                <div class="hero-actions">
                    <?php if ($user): ?>
                        <a href="/generate.php" class="btn btn-primary btn-lg">
                            ✨ Generate Your Fit
                        </a>
                    <?php else: ?>
                        <a href="/auth/login.php" class="btn btn-primary btn-lg">
                            Get Started Free
                        </a>
                    <?php endif; ?>
                    <a href="/pricing.php" class="btn btn-secondary btn-lg">
                        View Pricing
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="section">
        <div class="container">
            <h2 class="section-title">How It Works</h2>
            <div class="grid grid-3">
                <div class="card text-center">
                    <div class="text-4xl mb-md">📸</div>
                    <h3 class="h3 mb-sm">Upload Photos</h3>
                    <p class="text-muted">Upload 3-10 clear photos of yourself and a flat-lay photo of the outfit.</p>
                </div>
                <div class="card text-center">
                    <div class="text-4xl mb-md">🤖</div>
                    <h3 class="h3 mb-sm">AI Processing</h3>
                    <p class="text-muted">Our AI analyzes your photos and creates a realistic virtual try-on.</p>
                </div>
                <div class="card text-center">
                    <div class="text-4xl mb-md">✨</div>
                    <h3 class="h3 mb-sm">See Results</h3>
                    <p class="text-muted">Get a high-quality image showing how the outfit looks on you.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="section bg-charcoal">
        <div class="container">
            <h2 class="section-title">Why Choose PicFit.ai?</h2>
            <div class="grid grid-2">
                <div class="card">
                    <h3 class="h3 mb-sm">🎯 Accurate Results</h3>
                    <p class="text-muted">Advanced AI technology ensures realistic and accurate virtual try-ons.</p>
                </div>
                <div class="card">
                    <h3 class="h3 mb-sm">⚡ Fast Processing</h3>
                    <p class="text-muted">Get your results in under 60 seconds with our optimized AI models.</p>
                </div>
                <div class="card">
                    <h3 class="h3 mb-sm">🔒 Privacy First</h3>
                    <p class="text-muted">Your photos are processed securely and never shared with third parties.</p>
                </div>
                <div class="card">
                    <h3 class="h3 mb-sm">💳 Fair Pricing</h3>
                    <p class="text-muted">Pay only for what you use. No subscriptions, no hidden fees.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="section">
        <div class="container text-center">
            <h2 class="section-title">Ready to Try It?</h2>
            <p class="text-xl text-muted mb-lg">
                Join thousands of users who are already using AI to find their perfect fit.
            </p>
            <div class="flex justify-center gap-md">
                <?php if ($user): ?>
                    <a href="/generate.php" class="btn btn-primary btn-lg">
                        Start Generating
                    </a>
                <?php else: ?>
                    <a href="/auth/login.php" class="btn btn-primary btn-lg">
                        Get Started Free
                    </a>
                <?php endif; ?>
                <a href="/pricing.php" class="btn btn-secondary btn-lg">
                    View Pricing
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-brand">
                    <div class="text-xl font-bold mb-sm">PicFit.ai</div>
                    <p class="text-muted">AI-powered virtual try-on technology</p>
                </div>
                <div class="footer-links">
                    <a href="/privacy.php" class="footer-link">Privacy</a>
                    <a href="/terms.php" class="footer-link">Terms</a>
                    <a href="mailto:support@picfit.ai" class="footer-link">Support</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>