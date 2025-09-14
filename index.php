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
            background: linear-gradient(135deg, #ffeef8 0%, #ffe0f7 100%);
            min-height: 100vh;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
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
            background: rgba(0, 0, 0, 0.15);
            color: #333;
            border: 1px solid rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(0, 0, 0, 0.25);
            color: #000;
            transform: translateY(-2px);
        }


        /* Polaroid Photo Section */
        .polaroid-showcase {
            padding: 4rem 0;
            text-align: center;
        }

        .polaroid-container {
            position: relative;
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
        }

        .polaroid {
            background: linear-gradient(145deg, #fff 0%, #fefefe 100%);
            padding: 20px 20px 60px 20px;
            box-shadow:
                0 0 0 3px rgba(255, 255, 255, 0.8),
                0 0 0 6px rgba(255, 182, 193, 0.4),
                0 0 0 9px rgba(255, 218, 185, 0.3),
                0 0 0 12px rgba(255, 255, 186, 0.2),
                0 8px 32px rgba(255, 107, 157, 0.3),
                0 16px 64px rgba(255, 107, 157, 0.2),
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
            bottom: -50px;
            left: 5%;
            right: 5%;
            height: 50px;
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
        }

        .photo-frame {
            width: 100%;
            height: 300px;
            background: #f8f8f8;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
        }

        .photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center 30%;
            transition: all 0.3s ease;
        }

        .caption {
            position: absolute;
            bottom: 15px;
            left: 25px;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            color: #333;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(255,255,255,0.8);
        }

        @media (max-width: 768px) {
            .polaroid-container {
                max-width: 300px;
            }
            .polaroid {
                padding: 15px 15px 45px 15px;
            }
            .photo-frame {
                height: 250px;
            }
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
            color: #333;
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }

        .text-gradient {
            background: linear-gradient(45deg, #ff6b9d, #4ecdc4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-description {
            font-size: 1.3rem;
            color: #666;
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
            color: #333;
            text-align: center;
            margin-bottom: 3rem;
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
            color: #333;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .card p {
            color: #666;
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
            color: #666;
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
    <?php include __DIR__ . '/includes/nav.php'; ?>

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

    <!-- Polaroid Photo Showcase -->
    <section class="polaroid-showcase">
        <div class="container">
            <div class="polaroid-container">
                <div class="polaroid">
                    <div class="photo-frame">
			<img src="/images/outfits/outfit.png" alt="AI Virtual Try-On Example" class="photo">
                    </div>
                    <div class="caption">
                        AI Virtual Try-On Magic ✨
                    </div>
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
