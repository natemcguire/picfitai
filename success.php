<?php
// success.php - Payment success page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

Session::requireLogin();
$user = Session::getCurrentUser();

$sessionId = $_GET['session_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Successful - <?= Config::get('app_name') ?></title>
    <link rel="stylesheet" href="/public/styles.css">
    <meta http-equiv="refresh" content="5;url=/dashboard.php">
</head>
<body class="min-h-screen bg-void text-ivory flex items-center justify-center p-md">
    <div class="card p-xl container-xs text-center">
        <div class="text-6xl mb-lg">🎉</div>
        <h1 class="h1 mb-md">Payment Successful!</h1>
        <p class="text-mist mb-lg">
            Your credits have been added to your account. 
            You can now generate more outfit previews.
        </p>
        
        <div class="alert alert-success mb-lg">
            <p class="font-medium">
                Current balance: <?= $user['credits_remaining'] ?> credits
            </p>
        </div>
        
        <div class="space-y-sm">
            <a href="/generate.php" class="btn btn-primary btn-lg w-full">
                Start Generating
            </a>
            <a href="/dashboard.php" class="btn btn-secondary btn-lg w-full">
                Go to Dashboard
            </a>
        </div>
        
        <p class="text-sm text-slash mt-lg">
            Redirecting to dashboard in 5 seconds...
        </p>
    </div>
</body>
</html>
