<?php
// generate.php - AI generation interface
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tryon.php';

Session::requireLogin();
$user = Session::getCurrentUser();
$tryonModule = new TryOnModule('generate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generate Fit - <?= Config::get('app_name') ?></title>
    <link rel="stylesheet" href="/public/styles.css">
    <!-- Force cache refresh -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="flex items-center gap-md">
                    <a href="/" class="logo">PicFit.ai</a>
                    <span class="text-muted">/</span>
                    <a href="/dashboard.php" class="nav-link">Dashboard</a>
                    <span class="text-muted">/</span>
                    <span class="text-primary">Generate</span>
                </div>
                <div class="nav-links">
                    <div class="credits-badge">
                        <?= $user['credits_remaining'] ?> credits
                    </div>
                    <a href="/dashboard.php" class="btn btn-secondary">
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container-sm" style="padding-top: var(--space-xl); padding-bottom: var(--space-xl);">
        <?php if ($user['credits_remaining'] <= 0): ?>
            <div class="alert alert-warning mb-xl">
                <h2 class="font-bold mb-sm">No Credits Remaining</h2>
                <p class="mb-md">You need credits to generate new outfits. Each generation costs 1 credit.</p>
                <a href="/pricing.php" class="btn btn-accent">
                    Buy Credits
                </a>
            </div>
        <?php endif; ?>

        <div class="mb-xl text-center">
            <h1 class="mb-sm">Generate Your Fit</h1>
            <p class="text-secondary">Upload photos of yourself and an outfit to see how it looks on you.</p>
        </div>

        <?= $tryonModule->renderHTML() ?>
    </div>

    <?= $tryonModule->renderJS() ?>
</body>
</html>
