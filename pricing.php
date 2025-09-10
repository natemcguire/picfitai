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
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pricing - <?= Config::get('app_name') ?></title>
    <link rel="stylesheet" href="/public/styles.css">
</head>
<body class="min-h-screen bg-void text-ivory">
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-md">
                    <a href="/" class="logo">PicFit.ai</a>
                    <?php if ($user): ?>
                        <span class="text-slash">/</span>
                        <a href="/dashboard.php" class="text-ivory hover:text-gold transition-colors">Dashboard</a>
                        <span class="text-slash">/</span>
                        <span class="text-ivory">Pricing</span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-md">
                    <?php if ($user): ?>
                        <div class="credits-badge">
                            <span class="text-sm font-medium"><?= $user['credits_remaining'] ?> credits</span>
                        </div>
                        <a href="/dashboard.php" class="btn btn-ghost btn-sm">Dashboard</a>
                    <?php else: ?>
                        <a href="/auth/login.php" class="btn btn-primary">Get Started</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="container py-lg">
        <?php if ($reason === 'no_credits'): ?>
            <div class="alert alert-warning mb-lg">
                <h2 class="text-xl font-bold mb-sm">You're out of credits!</h2>
                <p>Purchase more credits below to continue generating outfit previews.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error mb-lg">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <!-- Hero -->
        <div class="text-center mb-xl">
            <h1 class="h1 mb-md">Simple, Fair Pricing</h1>
            <p class="text-xl text-mist max-w-2xl mx-auto">
                Pay only for what you use. No subscriptions, no hidden fees. 
                <?php if (!$user): ?>Try your first generation free.<?php endif; ?>
            </p>
        </div>

        <?php if (!$user): ?>
            <!-- Free Trial -->
            <div class="max-w-md mx-auto mb-xl">
                <div class="card text-center relative">
                    <h2 class="h2 mb-md">Free Trial</h2>
                    <div class="text-4xl font-black mb-sm text-gold">$0</div>
                    <div class="text-mist mb-lg">1 generation included</div>
                    <ul class="text-left space-y-sm mb-lg text-sm">
                        <li>✓ 1 AI-generated outfit preview</li>
                        <li>✓ High-quality results</li>
                        <li>✓ No credit card required</li>
                    </ul>
                    <a href="/auth/login.php" class="btn btn-primary btn-lg w-full">
                        Start Free Trial
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pricing Plans -->
        <div class="grid grid-3 gap-lg mb-xl">
            <?php foreach ($plans as $key => $plan): ?>
                <div class="card text-center relative <?= $key === 'popular' ? 'border-gold' : '' ?>">
                    <?php if ($key === 'popular'): ?>
                        <div class="absolute -top-sm left-1/2 transform -translate-x-1/2 bg-gold text-void px-md py-xs text-sm font-bold rounded-full">
                            Most Popular
                        </div>
                    <?php endif; ?>
                    
                    <h2 class="h2 mb-md"><?= htmlspecialchars($plan['name']) ?></h2>
                    <div class="text-4xl font-black mb-sm text-gold">$<?= number_format($plan['price'] / 100, 2) ?></div>
                    <div class="text-mist mb-lg"><?= $plan['credits'] ?> generations</div>
                    <div class="text-sm text-mist mb-lg">
                        $<?= number_format($plan['price'] / 100 / $plan['credits'], 2) ?> per generation
                    </div>
                    
                    <ul class="text-left space-y-sm mb-lg text-sm">
                        <li>✓ <?= $plan['credits'] ?> AI-generated outfit previews</li>
                        <li>✓ High-quality results</li>
                        <li>✓ Download & share images</li>
                        <li>✓ Priority processing</li>
                        <?php if ($key === 'pro'): ?>
                            <li>✓ Best value per generation</li>
                        <?php endif; ?>
                    </ul>
                    
                    <?php if ($user): ?>
                        <form method="POST" class="w-full">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="plan" value="<?= htmlspecialchars($key) ?>">
                            <button type="submit" class="btn btn-primary btn-lg w-full">
                                Purchase Now
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="/auth/login.php" class="btn btn-primary btn-lg w-full">
                            Get Started
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- FAQ -->
        <div class="container-sm">
            <h2 class="h2 text-center mb-lg">Frequently Asked Questions</h2>
            
            <div class="space-y-lg">
                <div class="card">
                    <h3 class="h3 mb-sm">How does the AI generation work?</h3>
                    <p class="text-mist">Upload photos of yourself and an outfit, and our AI creates a realistic preview of how the outfit will look on you. The process typically takes 30-60 seconds.</p>
                </div>
                
                <div class="card">
                    <h3 class="h3 mb-sm">What types of photos work best?</h3>
                    <p class="text-mist">For standing photos: clear, well-lit, full-body shots from multiple angles. For outfits: flat-lay photos with all items laid out on a clean surface.</p>
                </div>
                
                <div class="card">
                    <h3 class="h3 mb-sm">Do credits expire?</h3>
                    <p class="text-mist">No, your credits never expire. Use them whenever you want to try on new outfits.</p>
                </div>
                
                <div class="card">
                    <h3 class="h3 mb-sm">Can I get a refund?</h3>
                    <p class="text-mist">We offer refunds within 24 hours of purchase if you're not satisfied with the results. Contact support for assistance.</p>
                </div>
                
                <div class="card">
                    <h3 class="h3 mb-sm">Is my data secure?</h3>
                    <p class="text-mist">Yes, we take privacy seriously. Your photos are processed securely and are not shared with third parties. Generated images are stored securely and only accessible to you.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="border-t border-slate py-xl mt-xl">
        <div class="container text-center">
            <div class="text-xl font-black mb-md">PicFit.ai</div>
            <p class="text-mist mb-md">AI-powered virtual try-on technology</p>
            <div class="flex justify-center gap-lg text-sm text-mist">
                <a href="/privacy.php" class="hover:text-gold transition-colors">Privacy</a>
                <a href="/terms.php" class="hover:text-gold transition-colors">Terms</a>
                <a href="mailto:support@picfit.ai" class="hover:text-gold transition-colors">Support</a>
            </div>
        </div>
    </footer>
</body>
</html>
