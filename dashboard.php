<?php
// dashboard.php - User dashboard
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

Session::requireLogin();
$user = Session::getCurrentUser();

// Get recent generations
$pdo = Database::getInstance();
$stmt = $pdo->prepare('
    SELECT * FROM generations 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
');
$stmt->execute([$user['id']]);
$generations = $stmt->fetchAll();

// Get credit transactions
$stmt = $pdo->prepare('
    SELECT * FROM credit_transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
');
$stmt->execute([$user['id']]);
$transactions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - <?= Config::get('app_name') ?></title>
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
                    <span class="text-ivory">Dashboard</span>
                </div>
                <div class="flex items-center gap-md">
                    <span class="text-sm text-mist">
                        <?= htmlspecialchars($user['name']) ?>
                    </span>
                    <div class="credits-badge">
                        <span class="text-sm font-medium"><?= $user['credits_remaining'] ?> credits</span>
                    </div>
                    <a href="/auth/logout.php" class="btn btn-ghost btn-sm">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container py-lg">
        <!-- Welcome & Quick Actions -->
        <div class="mb-lg">
            <h1 class="h1 mb-sm">Welcome back, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>!</h1>
            <p class="text-mist mb-lg">Ready to try on some outfits?</p>
            
            <div class="flex gap-md">
                <a href="/generate.php" class="btn btn-primary btn-lg">
                    ✨ Generate New Fit
                </a>
                <?php if ($user['credits_remaining'] < 5): ?>
                    <a href="/pricing.php" class="btn btn-gold btn-lg">
                        💳 Buy More Credits
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-3 gap-lg">
            <!-- Main Content -->
            <div class="col-span-2 space-y-lg">
                <!-- Recent Generations -->
                <div class="card">
                    <h2 class="h2 mb-md">Recent Generations</h2>
                    
                    <?php if (empty($generations)): ?>
                        <div class="text-center py-xl text-mist">
                            <div class="text-4xl mb-md">👗</div>
                            <p>No generations yet. <a href="/generate.php" class="text-gold hover:text-bronze underline">Create your first one!</a></p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-md">
                            <?php foreach ($generations as $gen): ?>
                                <div class="flex items-center justify-between p-md border border-slate rounded-lg">
                                    <div>
                                        <div class="font-medium">
                                            Generation #<?= $gen['id'] ?>
                                        </div>
                                        <div class="text-sm text-mist">
                                            <?= date('M j, Y g:i A', strtotime($gen['created_at'])) ?>
                                        </div>
                                        <div class="text-sm">
                                            Status: 
                                            <span class="px-sm py-xs rounded text-xs font-medium <?= 
                                                $gen['status'] === 'completed' ? 'bg-success text-void' :
                                                ($gen['status'] === 'failed' ? 'bg-error text-void' : 'bg-warning text-void')
                                            ?>">
                                                <?= ucfirst($gen['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex gap-sm">
                                        <?php if ($gen['status'] === 'completed' && $gen['result_url']): ?>
                                            <a href="<?= htmlspecialchars($gen['result_url']) ?>" 
                                               class="btn btn-primary btn-sm" 
                                               target="_blank">
                                                View
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($gen['status'] === 'failed'): ?>
                                            <span class="text-sm text-error">
                                                <?= htmlspecialchars($gen['error_message'] ?? 'Unknown error') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-lg">
                <!-- Account Info -->
                <div class="card">
                    <h3 class="h3 mb-md">Account</h3>
                    <div class="space-y-sm">
                        <div class="flex justify-between">
                            <span class="text-mist">Credits:</span>
                            <span class="font-medium text-gold"><?= $user['credits_remaining'] ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-mist">Member since:</span>
                            <span class="font-medium"><?= date('M Y', strtotime($user['created_at'])) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-mist">Total generations:</span>
                            <span class="font-medium"><?= count($generations) ?></span>
                        </div>
                    </div>
                    
                    <?php if ($user['credits_remaining'] === 0): ?>
                        <div class="mt-md p-sm alert alert-warning">
                            <p class="text-sm">
                                You're out of credits! <a href="/pricing.php" class="text-gold hover:text-bronze underline">Buy more</a> to continue generating.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Transactions -->
                <div class="card">
                    <h3 class="h3 mb-md">Recent Activity</h3>
                    
                    <?php if (empty($transactions)): ?>
                        <p class="text-mist text-sm">No activity yet.</p>
                    <?php else: ?>
                        <div class="space-y-sm">
                            <?php foreach ($transactions as $tx): ?>
                                <div class="flex justify-between items-center text-sm">
                                    <div>
                                        <div class="font-medium"><?= htmlspecialchars($tx['description']) ?></div>
                                        <div class="text-mist"><?= date('M j', strtotime($tx['created_at'])) ?></div>
                                    </div>
                                    <div class="<?= $tx['type'] === 'purchase' || $tx['type'] === 'bonus' ? 'text-success' : 'text-error' ?>">
                                        <?= $tx['type'] === 'purchase' || $tx['type'] === 'bonus' ? '+' : '-' ?><?= $tx['credits'] ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <h3 class="h3 mb-md">Quick Actions</h3>
                    <div class="space-y-sm">
                        <a href="/generate.php" class="btn btn-primary btn-sm w-full">
                            New Generation
                        </a>
                        <a href="/pricing.php" class="btn btn-secondary btn-sm w-full">
                            Buy Credits
                        </a>
                        <a href="/profile.php" class="btn btn-ghost btn-sm w-full">
                            Profile Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
