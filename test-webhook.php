<?php
// test-webhook.php - Manual webhook processor for testing
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

Session::requireLogin();
$user = Session::getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_credit'])) {
    $creditsToAdd = (float) ($_POST['credits'] ?? 0);

    if ($creditsToAdd > 0 && $creditsToAdd <= 100) {
        // Manually add credits (for testing)
        $pdo = Database::getInstance();

        // Add transaction record
        $stmt = $pdo->prepare('
            INSERT INTO credit_transactions (user_id, type, credits, description, created_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user['id'],
            'purchase',
            $creditsToAdd,
            'Manual test credit addition',
            date('Y-m-d H:i:s')
        ]);

        // Update user credits
        $stmt = $pdo->prepare('
            UPDATE users
            SET credits_remaining = credits_remaining + ?, updated_at = ?
            WHERE id = ?
        ');
        $stmt->execute([$creditsToAdd, date('Y-m-d H:i:s'), $user['id']]);

        $message = "✅ Successfully added {$creditsToAdd} credits!";
        header('Location: /dashboard.php?message=' . urlencode($message));
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Webhook - <?= Config::get('app_name') ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 40px auto;
            max-width: 600px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin: 20px 0;
        }
        h1, h2 {
            color: #333;
        }
        .btn {
            background: #007cba;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #0056b3;
        }
        input, select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            margin: 10px 0;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🧪 Webhook Test Tool</h1>
        <p><strong>Current Credits:</strong> <?= $user['credits_remaining'] ?></p>
        <p><a href="/dashboard.php">← Back to Dashboard</a></p>
    </div>

    <div class="card">
        <h2>🔧 Manual Credit Addition (Testing Only)</h2>
        <div class="warning">
            <strong>⚠️ This is for testing only!</strong> In production, credits should only be added via Stripe webhooks.
        </div>

        <form method="POST">
            <label>Credits to Add:</label>
            <select name="credits">
                <option value="5">5 Credits ($4.99)</option>
                <option value="25">25 Credits ($19.99)</option>
                <option value="60">60 Credits ($39.99)</option>
                <option value="1">1 Credit (Test)</option>
            </select>

            <button type="submit" name="manual_credit" class="btn">
                💳 Add Credits (Test)
            </button>
        </form>
    </div>

    <div class="card">
        <h2>🔍 Webhook Debugging Steps</h2>
        <ol>
            <li><strong>Check Stripe Dashboard:</strong>
                <ul>
                    <li>Go to Stripe Dashboard → Webhooks</li>
                    <li>Verify endpoint URL: <code><?= Config::get('app_url') ?? 'http://localhost:8000' ?>/webhooks/stripe.php</code></li>
                    <li>Check events: <code>checkout.session.completed</code></li>
                    <li>Look for failed webhook attempts</li>
                </ul>
            </li>
            <li><strong>Test Webhook URL:</strong>
                <ul>
                    <li>Access: <a href="/webhooks/stripe.php" target="_blank">/webhooks/stripe.php</a></li>
                    <li>Should show "Method not allowed" (405 error is expected for GET requests)</li>
                </ul>
            </li>
            <li><strong>Check Environment:</strong>
                <ul>
                    <li>HTTPS required for production webhooks</li>
                    <li>Webhook secret must match Stripe dashboard</li>
                    <li>Server must be publicly accessible</li>
                </ul>
            </li>
            <li><strong>Local Testing:</strong>
                <ul>
                    <li>Use <a href="https://ngrok.com" target="_blank">ngrok</a> to expose localhost</li>
                    <li>Update Stripe webhook URL to ngrok URL</li>
                    <li>Test complete purchase flow</li>
                </ul>
            </li>
        </ol>
    </div>

    <div class="card">
        <h2>📊 Recent Data</h2>
        <p><a href="/debug-webhooks.php" class="btn">📊 View Full Debug Dashboard</a></p>
    </div>
</body>
</html>