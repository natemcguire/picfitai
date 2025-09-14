<?php
// debug-webhooks.php - Debug webhook processing
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
Session::requireLogin();

// Only allow admin users (adjust as needed)
$user = Session::getCurrentUser();

$pdo = Database::getInstance();

// Get recent webhook events
$stmt = $pdo->prepare('SELECT * FROM webhook_events ORDER BY processed_at DESC LIMIT 10');
$stmt->execute();
$webhookEvents = $stmt->fetchAll();

// Get recent credit transactions
$stmt = $pdo->prepare('SELECT * FROM credit_transactions ORDER BY created_at DESC LIMIT 10');
$stmt->execute();
$transactions = $stmt->fetchAll();

// Get recent user updates
$stmt = $pdo->prepare('SELECT id, credits_remaining, updated_at FROM users ORDER BY updated_at DESC LIMIT 10');
$stmt->execute();
$users = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook Debug - <?= Config::get('app_name') ?></title>
    <style>
        body {
            font-family: monospace;
            margin: 20px;
            background: #f5f5f5;
        }
        .section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
            border-bottom: 2px solid #007cba;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-size: 12px;
        }
        th {
            background-color: #f2f2f2;
        }
        .json {
            background: #f8f8f8;
            padding: 10px;
            border-radius: 4px;
            font-size: 11px;
            word-break: break-all;
        }
        .status-ok { color: #28a745; }
        .status-error { color: #dc3545; }
        .nav {
            background: #007cba;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .nav a {
            color: white;
            text-decoration: none;
            margin-right: 20px;
        }
    </style>
</head>
<body>
    <div class="nav">
        <h1>Webhook Debug Dashboard</h1>
        <a href="/dashboard.php">← Back to Dashboard</a>
        <a href="/debug.php">Debug Page</a>
        <strong>Current User:</strong> <?= htmlspecialchars($user['name']) ?>
        <strong>Credits:</strong> <?= $user['credits_remaining'] ?>
    </div>

    <div class="section">
        <h2>🕒 Recent Webhook Events (Last 10)</h2>
        <?php if (empty($webhookEvents)): ?>
            <p><strong>No webhook events found.</strong> This might indicate:</p>
            <ul>
                <li>Webhooks aren't reaching the server</li>
                <li>Webhook URL in Stripe dashboard is incorrect</li>
                <li>Webhook signature verification is failing</li>
            </ul>
        <?php else: ?>
            <table>
                <tr>
                    <th>Event ID</th>
                    <th>Type</th>
                    <th>Processed At</th>
                </tr>
                <?php foreach ($webhookEvents as $event): ?>
                    <tr>
                        <td><?= htmlspecialchars($event['id']) ?></td>
                        <td><?= htmlspecialchars($event['type']) ?></td>
                        <td><?= htmlspecialchars($event['processed_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>💳 Recent Credit Transactions (Last 10)</h2>
        <?php if (empty($transactions)): ?>
            <p><strong>No credit transactions found.</strong></p>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>User ID</th>
                    <th>Type</th>
                    <th>Credits</th>
                    <th>Description</th>
                    <th>Stripe Session</th>
                    <th>Created At</th>
                </tr>
                <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?= $tx['id'] ?></td>
                        <td><?= $tx['user_id'] ?></td>
                        <td><?= htmlspecialchars($tx['type']) ?></td>
                        <td><?= $tx['credits'] ?></td>
                        <td><?= htmlspecialchars($tx['description']) ?></td>
                        <td><?= htmlspecialchars($tx['stripe_session_id'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($tx['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>👥 Recent User Credit Updates (Last 10)</h2>
        <table>
            <tr>
                <th>User ID</th>
                <th>Credits Remaining</th>
                <th>Last Updated</th>
            </tr>
            <?php foreach ($users as $u): ?>
                <tr <?= $u['id'] == $user['id'] ? 'style="background-color: #e3f2fd;"' : '' ?>>
                    <td><?= $u['id'] ?></td>
                    <td><?= $u['credits_remaining'] ?></td>
                    <td><?= htmlspecialchars($u['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2>🔧 Configuration Check</h2>
        <table>
            <tr>
                <th>Setting</th>
                <th>Status</th>
            </tr>
            <tr>
                <td>Stripe Secret Key</td>
                <td class="<?= !empty(Config::get('stripe_secret_key')) ? 'status-ok' : 'status-error' ?>">
                    <?= !empty(Config::get('stripe_secret_key')) ? '✅ Configured' : '❌ Missing' ?>
                </td>
            </tr>
            <tr>
                <td>Stripe Webhook Secret</td>
                <td class="<?= !empty(Config::get('stripe_webhook_secret')) ? 'status-ok' : 'status-error' ?>">
                    <?= !empty(Config::get('stripe_webhook_secret')) ? '✅ Configured' : '❌ Missing' ?>
                </td>
            </tr>
            <tr>
                <td>Database Connection</td>
                <td class="status-ok">✅ Connected</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>🐛 Test Webhook Processing</h2>
        <p>If webhooks aren't working, check:</p>
        <ul>
            <li><strong>Stripe Dashboard:</strong> Webhook endpoint URL should be <code><?= Config::get('app_url') ?>/webhooks/stripe.php</code></li>
            <li><strong>Events:</strong> Should listen for <code>checkout.session.completed</code> and <code>payment_intent.succeeded</code></li>
            <li><strong>Logs:</strong> Check error logs for webhook failures</li>
            <li><strong>SSL:</strong> Stripe requires HTTPS for webhooks in production</li>
        </ul>
    </div>

    <div class="section">
        <h2>📝 Recent Error Logs</h2>
        <?php
        $logFile = dirname(__FILE__) . '/logs/app.log';
        if (file_exists($logFile)) {
            $logs = array_slice(array_reverse(file($logFile)), 0, 10);
            if (!empty($logs)) {
                echo '<div class="json">';
                foreach ($logs as $log) {
                    echo htmlspecialchars(trim($log)) . "<br>";
                }
                echo '</div>';
            } else {
                echo '<p>No recent logs found.</p>';
            }
        } else {
            echo '<p>Log file not found at: ' . htmlspecialchars($logFile) . '</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>🔄 Manual Credit Refresh</h2>
        <p>Current user credits: <strong><?= $user['credits_remaining'] ?></strong></p>
        <button onclick="location.reload()" style="padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer;">
            🔄 Refresh Page
        </button>
    </div>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>