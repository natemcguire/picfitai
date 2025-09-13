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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .credits-badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .text-secondary {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }

        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(45deg, #ff6b6b, #ff8e8e);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.6);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 1.1rem;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .nav-link:hover {
            color: white;
        }

        /* Hero Section */
        .hero {
            padding: 6rem 0;
            text-align: center;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: bold;
            color: white;
            margin-bottom: 1.5rem;
            text-shadow: 0 4px 8px rgba(0,0,0,0.3);
            line-height: 1.2;
        }

        .text-gradient {
            background: linear-gradient(45deg, #ff6b6b, #ffd93d);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-description {
            font-size: 1.3rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 3rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* Sections */
        .section {
            padding: 4rem 0;
        }

        .section-alt {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: bold;
            color: white;
            text-align: center;
            margin-bottom: 3rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .grid {
            display: grid;
            gap: 2rem;
            margin-top: 2rem;
        }

        .grid-2 {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }

        .grid-3 {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }

        .card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            background: rgba(255, 255, 255, 0.2);
        }

        .card-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }

        .card h3 {
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .card p {
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.6;
        }

        /* Features */
        .feature-icon {
            font-size: 2rem;
            margin-right: 0.5rem;
        }

        /* CTA */
        .cta-section {
            padding: 5rem 0;
            text-align: center;
        }

        .text-xl {
            font-size: 1.25rem;
        }

        .text-muted {
            color: rgba(255, 255, 255, 0.7);
        }

        .mb-lg {
            margin-bottom: 2rem;
        }

        .flex {
            display: flex;
        }

        .justify-center {
            justify-content: center;
        }

        .gap-md {
            gap: 1rem;
        }

        /* Footer */
        .footer {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding: 3rem 0;
            margin-top: 4rem;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .footer-brand {
            color: white;
        }

        .footer-brand .text-xl {
            font-size: 1.25rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .footer-links {
            display: flex;
            gap: 2rem;
        }

        .footer-link {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-link:hover {
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }

            .hero-description {
                font-size: 1.1rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .nav-links {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .hero-actions {
                flex-direction: column;
                align-items: center;
            }

            .footer-content {
                flex-direction: column;
                text-align: center;
            }

            .grid-3 {
                grid-template-columns: 1fr;
            }
        }

        .text-muted.hidden {
            display: none;
        }

        @media (min-width: 640px) {
            .text-muted.hidden.sm\\:inline {
                display: inline;
            }
        }
    </style>
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
                            💎 <?= number_format($user['credits_remaining'], ($user['credits_remaining'] == floor($user['credits_remaining'])) ? 0 : 1) ?> Credits
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
    <section class="section section-alt">
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