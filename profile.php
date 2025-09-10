<?php
// profile.php - User profile page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

Session::requireLogin();
$user = Session::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile - <?= Config::get('app_name') ?></title>
    <link rel="stylesheet" href="/public/styles.css">
</head>
<body class="min-h-screen bg-void text-ivory">
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-md">
                    <a href="/" class="logo">PicFit.ai</a>
                    <span class="text-slash">/</span>
                    <a href="/dashboard.php" class="text-ivory hover:text-gold transition-colors">Dashboard</a>
                    <span class="text-slash">/</span>
                    <span class="text-ivory">Profile</span>
                </div>
                <div class="flex items-center gap-md">
                    <a href="/dashboard.php" class="btn btn-ghost btn-sm">
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container py-lg">
        <div class="grid grid-3 gap-lg">
            <!-- Profile Info -->
            <div class="col-span-2">
                <div class="card">
                    <h1 class="h1 mb-lg">Profile</h1>
                    
                    <div class="flex items-center gap-lg mb-lg">
                        <?php if ($user['avatar_url']): ?>
                            <img src="<?= htmlspecialchars($user['avatar_url']) ?>" 
                                 alt="Profile picture" 
                                 class="w-20 h-20 rounded-full border-2 border-slate">
                        <?php else: ?>
                            <div class="w-20 h-20 rounded-full border-2 border-slate bg-charcoal flex items-center justify-center text-2xl font-bold text-gold">
                                <?= strtoupper(substr($user['name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h2 class="h2"><?= htmlspecialchars($user['name']) ?></h2>
                            <p class="text-mist"><?= htmlspecialchars($user['email']) ?></p>
                            <p class="text-sm text-slash">Member since <?= date('F Y', strtotime($user['created_at'])) ?></p>
                        </div>
                    </div>

                    <div class="grid grid-2 gap-lg">
                        <div class="border border-slate p-md rounded-lg">
                            <h3 class="h3 mb-sm">Credits Remaining</h3>
                            <div class="text-3xl font-black text-gold"><?= $user['credits_remaining'] ?></div>
                            <p class="text-sm text-mist">Available for generations</p>
                        </div>
                        
                        <div class="border border-slate p-md rounded-lg">
                            <h3 class="h3 mb-sm">Account Status</h3>
                            <div class="text-lg font-medium text-success">Active</div>
                            <p class="text-sm text-mist">All features available</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Actions -->
            <div class="space-y-lg">
                <div class="card">
                    <h3 class="h3 mb-md">Quick Actions</h3>
                    <div class="space-y-sm">
                        <a href="/generate.php" class="btn btn-primary btn-sm w-full">
                            Generate New Fit
                        </a>
                        <a href="/pricing.php" class="btn btn-secondary btn-sm w-full">
                            Buy More Credits
                        </a>
                    </div>
                </div>

                <div class="card">
                    <h3 class="h3 mb-md">Account Settings</h3>
                    <div class="space-y-sm text-sm">
                        <p class="text-mist">
                            Your account is linked with Google OAuth. 
                            Profile information is automatically synced.
                        </p>
                        <div class="pt-md border-t border-slate">
                            <a href="/auth/logout.php" class="text-error hover:text-red-400 transition-colors">
                                Sign Out
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3 class="h3 mb-md">Privacy & Data</h3>
                    <div class="space-y-sm text-sm text-mist">
                        <p>• Your photos are processed securely</p>
                        <p>• Generated images are private to you</p>
                        <p>• We don't share your data with third parties</p>
                        <div class="pt-md border-t border-slate">
                            <a href="/privacy.php" class="text-gold hover:text-bronze transition-colors">
                                Read Privacy Policy
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
