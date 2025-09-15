<?php
// pricing.php - Pricing and checkout page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = Session::getCurrentUser();
$plans = Config::get('stripe_plans');

// Handle checkout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan'])) {
    if (!$user) {
        header('Location: /auth/login.php');
        exit;
    }
    
    if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $stripeService = new StripeService();
            $session = $stripeService->createCheckoutSession(
                $_POST['plan'],
                $user['email'],
                $user['id']
            );
            
            header('Location: ' . $session['url']);
            exit;
            
        } catch (Exception $e) {
            $error = 'Checkout failed: ' . $e->getMessage();
        }
    }
}

$reason = $_GET['reason'] ?? '';
$csrfToken = $user ? Session::generateCSRFToken() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing - <?= Config::get('app_name') ?></title>
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .header-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 15px;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.1);
        }

        .credits {
            background: #27ae60;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            display: inline-block;
            font-weight: bold;
        }

        /* Navigation Buttons */
        .nav-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 25px;
            padding: 0.5rem 1.2rem;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .nav-btn.primary {
            background: linear-gradient(45deg, #ff6b6b, #ff8e8e);
            border: 1px solid rgba(255, 107, 107, 0.3);
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
        }

        .nav-btn.primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 25px rgba(255, 107, 107, 0.4);
        }

        .content {
            padding: 40px 20px;
        }

        .hero {
            text-align: center;
            margin-bottom: 60px;
        }

        .hero h2 {
            color: #2c3e50;
            font-size: 2.5em;
            margin-bottom: 20px;
        }

        .hero p {
            color: #7f8c8d;
            font-size: 1.2em;
            max-width: 600px;
            margin: 0 auto;
        }

        .alert {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid;
        }

        .alert-warning {
            background: #fff3cd;
            border-left-color: #ffc107;
            color: #856404;
        }

        .alert-error {
            background: #f8d7da;
            border-left-color: #dc3545;
            color: #721c24;
        }

        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }

        .plan-card {
            background: #f8f9fa;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .plan-card.featured {
            border: 3px solid #f39c12;
            transform: scale(1.05);
        }

        .popular-badge {
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background: #f39c12;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
        }

        .plan-name {
            color: #2c3e50;
            font-size: 1.8em;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .plan-price {
            font-size: 3em;
            font-weight: 900;
            color: #f39c12;
            margin-bottom: 10px;
        }

        .plan-credits {
            color: #7f8c8d;
            font-size: 1.1em;
            margin-bottom: 10px;
        }

        .plan-per-credit {
            color: #95a5a6;
            font-size: 0.9em;
            margin-bottom: 30px;
        }

        .plan-features {
            list-style: none;
            margin-bottom: 40px;
            text-align: left;
        }

        .plan-features li {
            padding: 8px 0;
            color: #2c3e50;
            border-bottom: 1px solid #ecf0f1;
        }

        .plan-features li:last-child {
            border-bottom: none;
        }

        .plan-features li:before {
            content: "✓ ";
            color: #27ae60;
            font-weight: bold;
            margin-right: 8px;
        }

        .btn {
            display: inline-block;
            padding: 18px 40px;
            border: none;
            border-radius: 25px;
            font-size: 1.1em;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .free-trial {
            max-width: 400px;
            margin: 0 auto 60px;
        }

        .free-trial .plan-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .free-trial .plan-name,
        .free-trial .plan-features li {
            color: white;
        }

        .free-trial .plan-price {
            color: #f1c40f;
        }

        .faq-section {
            max-width: 800px;
            margin: 0 auto;
        }

        .faq-section h3 {
            color: #2c3e50;
            font-size: 2em;
            text-align: center;
            margin-bottom: 40px;
        }

        .faq-item {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .faq-item h4 {
            color: #2c3e50;
            font-size: 1.3em;
            margin-bottom: 15px;
        }

        .faq-item p {
            color: #7f8c8d;
            line-height: 1.6;
        }

        .footer {
            background: #2c3e50;
            color: white;
            padding: 40px 20px;
            text-align: center;
            margin-top: 60px;
        }

        .footer h4 {
            font-size: 1.5em;
            margin-bottom: 15px;
        }

        .footer p {
            color: #bdc3c7;
            margin-bottom: 20px;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: #bdc3c7;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: #f39c12;
        }

        @media (max-width: 768px) {
            .container {
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
            }

            .hero h2 {
                font-size: 2em;
            }

            .plans-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .plan-card.featured {
                transform: none;
            }

            .header h1 {
                font-size: 1.5em;
            }

            .header-nav {
                flex-direction: column;
                text-align: center;
            }

            .footer-links {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <div class="container">
        <div class="content" style="padding-top: 30px;">
            <?php if ($reason === 'no_credits'): ?>
                <div class="alert alert-warning">
                    <h4 style="margin-bottom: 10px;">You're out of credits!</h4>
                    <p>Purchase more credits below to continue generating outfit previews.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <!-- Hero -->
            <div class="hero">
                <h2>Simple, Fair Pricing</h2>
                <p>
                    Pay only for what you use. No subscriptions, no hidden fees.
                    <?php if (!$user): ?>Try your first generation free.<?php endif; ?>
                </p>
            </div>

            <?php if (!$user): ?>
                <!-- Free Trial -->
                <div class="free-trial">
                    <div class="plan-card">
                        <div class="plan-name">Free Trial</div>
                        <div class="plan-price">$0</div>
                        <div class="plan-credits">1 credit included</div>
                        <ul class="plan-features">
                            <li>2 public photos or 1 private photo</li>
                            <li>High-quality AI outfit generation</li>
                            <li>No credit card required</li>
                        </ul>
                        <a href="/auth/login.php" class="btn btn-primary">
                            Start Free Trial
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Pricing Plans -->
            <div class="plans-grid">
                <?php foreach ($plans as $key => $plan): ?>
                    <div class="plan-card <?= $key === 'popular' ? 'featured' : '' ?>">
                        <?php if ($key === 'popular'): ?>
                            <div class="popular-badge">Most Popular</div>
                        <?php endif; ?>

                        <div class="plan-name"><?= htmlspecialchars($plan['name']) ?></div>
                        <div class="plan-price">$<?= number_format($plan['price'] / 100, 2) ?></div>
                        <div class="plan-credits"><?= $plan['credits'] ?> credits</div>
                        <div class="plan-per-credit">
                            <?= $plan['credits'] * 2 ?> public photos or <?= $plan['credits'] ?> private photos
                        </div>

                        <ul class="plan-features">
                            <li><?= $plan['credits'] ?> credits for AI outfit generation</li>
                            <li>2x photos if shared publicly (default)</li>
                            <li>1x photos if kept private</li>
                            <li>High-quality results</li>
                            <li>Download & share images</li>
                            <?php if ($key === 'pro'): ?>
                                <li>Best value per photo</li>
                            <?php endif; ?>
                        </ul>

                        <?php if ($user): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="plan" value="<?= htmlspecialchars($key) ?>">
                                <button type="submit" class="btn btn-primary">
                                    Purchase Now
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="/auth/login.php" class="btn btn-primary">
                                Get Started
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- FAQ -->
            <div class="faq-section">
                <h3>Frequently Asked Questions</h3>

                <div class="faq-item">
                    <h4>How does the AI generation work?</h4>
                    <p>Upload photos of yourself and an outfit, and our AI creates a realistic preview of how the outfit will look on you. The process typically takes 30-60 seconds.</p>
                </div>

                <div class="faq-item">
                    <h4>What types of photos work best?</h4>
                    <p>For standing photos: clear, well-lit, full-body shots from multiple angles. For outfits: flat-lay photos with all items laid out on a clean surface.</p>
                </div>

                <div class="faq-item">
                    <h4>Do credits expire?</h4>
                    <p>No, your credits never expire. Use them whenever you want to try on new outfits.</p>
                </div>

                <div class="faq-item">
                    <h4>Can I get a refund?</h4>
                    <p>We offer refunds within 24 hours of purchase if you're not satisfied with the results. Contact support for assistance.</p>
                </div>

                <div class="faq-item">
                    <h4>Are my photos public or private?</h4>
                    <p>By default, all generated photos are public and shareable - it's all about having fun and showing off your style! This also gives you 2x more photos per credit. If you prefer privacy, you can always choose to make a generation private during upload, which uses 1 credit per photo.</p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <h4>PicFit.ai</h4>
            <p>AI-powered virtual try-on technology</p>
            <div class="footer-links">
                <a href="/privacy.php">Privacy</a>
                <a href="/terms.php">Terms</a>
            </div>
        </div>
    </div>
</body>
</html>
