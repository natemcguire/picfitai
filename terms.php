<?php
// terms.php - Terms of Service page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = Session::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service - <?= Config::get('app_name') ?></title>
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
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .content {
            padding: 40px;
        }

        h1 {
            color: #2c3e50;
            font-size: 2.5em;
            margin-bottom: 20px;
            text-align: center;
        }

        .last-updated {
            text-align: center;
            color: #7f8c8d;
            font-style: italic;
            margin-bottom: 40px;
        }

        .intro {
            background: #f8f9fa;
            border-left: 4px solid #f39c12;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 5px;
        }

        .section {
            margin-bottom: 30px;
        }

        .section h2 {
            color: #2c3e50;
            font-size: 1.5em;
            margin-bottom: 15px;
            border-bottom: 2px solid #f39c12;
            padding-bottom: 5px;
        }

        .section p {
            color: #555;
            margin-bottom: 10px;
        }

        .section ul {
            margin-left: 20px;
            color: #555;
        }

        .section li {
            margin-bottom: 5px;
        }

        .back-link {
            text-align: center;
            margin-top: 40px;
        }

        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        @media (max-width: 768px) {
            .container {
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
            }

            .content {
                padding: 20px;
            }

            h1 {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <div class="container">
        <div class="content">
            <h1>Terms of Service for PicFit.ai</h1>

            <div class="last-updated">
                Last Updated: <?= date('F j, Y') ?>
            </div>

            <div class="intro">
                <p><strong>By accessing or using PicFit.ai, you agree to these terms:</strong></p>
            </div>

            <div class="section">
                <h2>1. Eligibility</h2>
                <p>You must be at least 13 years old to use PicFit.ai.</p>
            </div>

            <div class="section">
                <h2>2. Use of Service</h2>
                <p>PicFit.ai is for personal, non-commercial fun only.</p>
                <p>You agree not to upload unlawful, offensive, or infringing content.</p>
            </div>

            <div class="section">
                <h2>3. Credits and Refunds</h2>
                <p>PicFit.ai operates on a credit system for certain features.</p>
                <p>You may request a full refund within 24 hours of purchase for any unused credits.</p>
                <p><strong>Once credits are used, they are non-refundable.</strong></p>
            </div>

            <div class="section">
                <h2>4. Disclaimer</h2>
                <p>PicFit.ai is provided "as is." We do not guarantee uninterrupted service, accuracy, or availability.</p>
                <p>We are not responsible for any damages, losses, or liabilities arising from use of the service.</p>
            </div>

            <div class="section">
                <h2>5. Termination</h2>
                <p>We reserve the right to suspend or terminate access to the service at our discretion.</p>
            </div>

            <div class="section">
                <h2>6. Changes to Terms</h2>
                <p>We may update these terms from time to time. Continued use of the service means you accept any updates.</p>
            </div>

            <div class="back-link">
                <a href="/" class="btn">Back to Home</a>
            </div>
        </div>
    </div>
</body>
</html>