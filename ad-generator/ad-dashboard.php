<?php
// ad-dashboard.php - Ad concepts dashboard
declare(strict_types=1);

require_once '../bootstrap.php';

// Check authentication
Session::requireLogin();
$user = Session::getCurrentUser();
$userId = $user['id'];
$pdo = Database::getInstance();

// Handle concept deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_concept'])) {
    $conceptId = (int)$_POST['concept_id'];

    // Verify ownership
    $stmt = $pdo->prepare('SELECT user_id FROM ad_concepts WHERE id = ?');
    $stmt->execute([$conceptId]);
    $concept = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($concept && $concept['user_id'] == $userId) {
        // Archive instead of delete
        $pdo->prepare('UPDATE ad_concepts SET is_archived = 1 WHERE id = ?')
            ->execute([$conceptId]);
    }

    header('Location: ad-dashboard.php');
    exit;
}

// Get user's concepts
$stmt = $pdo->prepare('
    SELECT * FROM ad_concepts
    WHERE user_id = ? AND is_archived = 0
    ORDER BY created_at DESC
');
$stmt->execute([$userId]);
$concepts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user stats
$stmt = $pdo->prepare('
    SELECT
        COUNT(*) as total_concepts,
        COUNT(CASE WHEN created_at > datetime("now", "-7 days") THEN 1 END) as concepts_this_week
    FROM ad_concepts
    WHERE user_id = ? AND is_archived = 0
');
$stmt->execute([$userId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's campaigns
$stmt = $pdo->prepare('
    SELECT * FROM ad_campaigns
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
');
$stmt->execute([$userId]);
$recentCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ad Dashboard - PicFit.ai</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Space+Mono&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Space Mono', monospace;
            background: #0a0a0a;
            color: #00ff00;
            min-height: 100vh;
        }

        /* Grid Background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image:
                linear-gradient(rgba(0, 255, 0, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 255, 0, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -1;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border: 2px solid #00ff00;
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, #00ff00, #00ccff, #ff00ff, #00ff00);
            z-index: -1;
            animation: glow 3s linear infinite;
            opacity: 0.5;
        }

        @keyframes glow {
            0% { filter: hue-rotate(0deg); }
            100% { filter: hue-rotate(360deg); }
        }

        .title {
            font-family: 'Orbitron', sans-serif;
            font-size: 48px;
            font-weight: 900;
            background: linear-gradient(45deg, #00ff00, #00ccff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 20px;
        }

        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 30px;
            margin-top: 20px;
        }

        .stat {
            background: #0d0d0d;
            padding: 15px 25px;
            border: 1px solid #00ff00;
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #00ff00;
        }

        .stat-label {
            font-size: 12px;
            color: #00cc00;
            text-transform: uppercase;
            margin-top: 5px;
        }

        /* Action Buttons */
        .actions {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }

        .btn {
            padding: 15px 30px;
            background: linear-gradient(45deg, #00ff00, #00ccff);
            border: none;
            color: #000;
            font-family: 'Orbitron', sans-serif;
            font-weight: bold;
            text-transform: uppercase;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(0, 255, 0, 0.8);
        }

        .btn-secondary {
            background: transparent;
            color: #00ff00;
            border: 2px solid #00ff00;
        }

        .btn-secondary:hover {
            background: #00ff00;
            color: #000;
        }

        /* Concepts Grid */
        .section-title {
            font-size: 24px;
            color: #00ff00;
            margin-bottom: 20px;
            text-transform: uppercase;
            border-bottom: 2px solid #00ff00;
            padding-bottom: 10px;
        }

        .concepts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 50px;
        }

        .concept-card {
            background: #1a1a1a;
            border: 2px solid #333;
            overflow: hidden;
            transition: all 0.3s;
            position: relative;
        }

        .concept-card:hover {
            border-color: #00ff00;
            box-shadow: 0 0 20px rgba(0, 255, 0, 0.5);
            transform: translateY(-5px);
        }

        .concept-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-bottom: 2px solid #333;
        }

        .concept-info {
            padding: 15px;
        }

        .concept-name {
            font-size: 18px;
            color: #00ff00;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .concept-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #00cc00;
            margin-bottom: 15px;
        }

        .concept-data {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .data-badge {
            padding: 5px 10px;
            background: #0d0d0d;
            border: 1px solid #00ff00;
            font-size: 10px;
            text-transform: uppercase;
        }

        .color-badge {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 1px solid #00ff00;
            vertical-align: middle;
            margin-left: 5px;
        }

        .concept-actions {
            display: flex;
            gap: 10px;
        }

        .concept-btn {
            flex: 1;
            padding: 8px;
            background: #0d0d0d;
            border: 1px solid #00ff00;
            color: #00ff00;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-size: 12px;
        }

        .concept-btn:hover {
            background: #00ff00;
            color: #000;
        }

        .concept-btn.delete {
            border-color: #ff0000;
            color: #ff0000;
        }

        .concept-btn.delete:hover {
            background: #ff0000;
            color: #fff;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #1a1a1a;
            border: 2px solid #333;
        }

        .empty-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        .empty-message {
            font-size: 24px;
            color: #00ff00;
            margin-bottom: 20px;
        }

        .empty-submessage {
            font-size: 16px;
            color: #00cc00;
            margin-bottom: 30px;
        }

        /* Campaigns Section */
        .campaigns-list {
            background: #1a1a1a;
            border: 2px solid #333;
            padding: 20px;
        }

        .campaign-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #333;
            transition: all 0.3s;
        }

        .campaign-item:hover {
            background: #0d0d0d;
        }

        .campaign-item:last-child {
            border-bottom: none;
        }

        .campaign-name {
            color: #00ff00;
            font-weight: bold;
        }

        .campaign-status {
            padding: 5px 10px;
            background: #0d0d0d;
            border: 1px solid;
            font-size: 12px;
            text-transform: uppercase;
        }

        .campaign-status.completed {
            border-color: #00ff00;
            color: #00ff00;
        }

        .campaign-status.processing {
            border-color: #ffcc00;
            color: #ffcc00;
        }

        .campaign-status.failed {
            border-color: #ff0000;
            color: #ff0000;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">📊 Ad Dashboard</h1>
            <div class="stats-bar">
                <div class="stat">
                    <div class="stat-value"><?= $stats['total_concepts'] ?></div>
                    <div class="stat-label">Total Concepts</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= $stats['concepts_this_week'] ?></div>
                    <div class="stat-label">This Week</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= $user['credits_remaining'] ?></div>
                    <div class="stat-label">Credits</div>
                </div>
            </div>
        </div>

        <div class="actions">
            <a href="concept-creator.php" class="btn">
                🎨 Create New Concept
            </a>
            <a href="../figma-ad-generator.php" class="btn btn-secondary">
                ✨ Classic Wizard
            </a>
            <a href="../pricing.php" class="btn btn-secondary">
                💳 Get More Credits
            </a>
        </div>

        <h2 class="section-title">Your Concepts</h2>

        <?php if (empty($concepts)): ?>
            <div class="empty-state">
                <div class="empty-icon">🎨</div>
                <div class="empty-message">No concepts yet!</div>
                <div class="empty-submessage">Start creating amazing ad concepts with AI</div>
                <a href="concept-creator.php" class="btn">Create Your First Concept</a>
            </div>
        <?php else: ?>
            <div class="concepts-grid">
                <?php foreach ($concepts as $concept): ?>
                    <?php $conceptData = json_decode($concept['concept_data'], true); ?>
                    <div class="concept-card">
                        <img src="<?= htmlspecialchars($concept['image_url']) ?>"
                             alt="<?= htmlspecialchars($concept['concept_name']) ?>"
                             class="concept-image">
                        <div class="concept-info">
                            <div class="concept-name"><?= htmlspecialchars($concept['concept_name']) ?></div>
                            <div class="concept-meta">
                                <span><?= date('M j, g:i a', strtotime($concept['created_at'])) ?></span>
                                <span><?= strtoupper($concept['status']) ?></span>
                            </div>
                            <div class="concept-data">
                                <?php if (!empty($conceptData['visual_style'])): ?>
                                    <span class="data-badge"><?= htmlspecialchars($conceptData['visual_style']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($conceptData['mood'])): ?>
                                    <span class="data-badge"><?= htmlspecialchars($conceptData['mood']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($conceptData['primary_color'])): ?>
                                    <span class="data-badge">
                                        Color
                                        <span class="color-badge" style="background: <?= htmlspecialchars($conceptData['primary_color']) ?>"></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="concept-actions">
                                <a href="export-sizes.php?concept_id=<?= $concept['id'] ?>" class="concept-btn">
                                    📐 Export Sizes
                                </a>
                                <a href="concept-editor.php?id=<?= $concept['id'] ?>" class="concept-btn">
                                    ✏️ Edit
                                </a>
                                <form method="POST" style="flex: 1;" onsubmit="return confirm('Archive this concept?');">
                                    <input type="hidden" name="concept_id" value="<?= $concept['id'] ?>">
                                    <button type="submit" name="delete_concept" class="concept-btn delete" style="width: 100%;">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($recentCampaigns)): ?>
            <h2 class="section-title">Recent Campaigns</h2>
            <div class="campaigns-list">
                <?php foreach ($recentCampaigns as $campaign): ?>
                    <div class="campaign-item">
                        <div>
                            <div class="campaign-name"><?= htmlspecialchars($campaign['campaign_name']) ?></div>
                            <div style="color: #00cc00; font-size: 12px; margin-top: 5px;">
                                <?= date('M j, g:i a', strtotime($campaign['created_at'])) ?>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <span class="campaign-status <?= $campaign['status'] ?>">
                                <?= $campaign['status'] ?>
                            </span>
                            <a href="../view-campaign.php?id=<?= $campaign['id'] ?>" class="concept-btn">
                                View
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>