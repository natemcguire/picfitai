<?php
// privacy.php - Privacy Policy page
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = Session::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - <?= Config::get('app_name') ?></title>
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
            <h1>Privacy Policy for PicFit.ai</h1>

            <div class="last-updated">
                Last Updated: <?= date('F j, Y') ?>
            </div>

            <div class="intro">
                <p><strong>At PicFit.ai, we want to be clear and upfront about how we handle your photos and information. This service is designed for entertainment and fun — not for secure or private storage. By using PicFit.ai, you agree to the following:</strong></p>
            </div>

            <div class="section">
                <h2>1. Public Content</h2>
                <p>All photos and content uploaded to PicFit.ai may be publicly viewable.</p>
                <p><strong>Do not upload anything you consider private, sensitive, or confidential.</strong></p>
            </div>

            <div class="section">
                <h2>2. Data Retention and Storage</h2>
                <p>We do not guarantee backups or long-term storage of your photos or generated images.</p>
                <p>Your content may be deleted at any time without notice.</p>
            </div>

            <div class="section">
                <h2>3. Personal Information</h2>
                <p>We collect minimal information needed to operate the service (e.g., your email, payment info for purchases).</p>
                <p>We do not sell your personal information.</p>
            </div>

            <div class="section">
                <h2>4. Security</h2>
                <p>While we take reasonable steps to protect data, PicFit.ai cannot guarantee the security of uploads or generated content.</p>
            </div>

            <div class="section">
                <h2>5. Use at Your Own Risk</h2>
                <p>PicFit.ai is provided "as is" and "as available." Use the service at your own discretion and risk.</p>
            </div>

            <div class="back-link">
                <a href="/" class="btn">Back to Home</a>
            </div>
        </div>
    </div>
</body>
</html>