<?php
// ad-dashboard.php - Ad Campaign Management Dashboard
declare(strict_types=1);

require_once 'bootstrap.php';
require_once 'includes/CDNService.php';

// Check authentication
Session::requireLogin();
$user = Session::getCurrentUser();
$userId = $user['id'];
$pdo = Database::getInstance();

// Get campaign ID from URL if provided
$campaignId = isset($_GET['campaign']) ? (int)$_GET['campaign'] : null;

// Fetch user's campaigns
$stmt = $pdo->prepare('
    SELECT
        c.*,
        COUNT(DISTINCT g.id) as ad_count,
        MAX(c.created_at) as latest_date
    FROM ad_campaigns c
    LEFT JOIN ad_generations g ON c.id = g.campaign_id
    WHERE c.user_id = ?
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 20
');
$stmt->execute([$userId]);
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If specific campaign selected, get its ads
$selectedCampaign = null;
$campaignAds = [];

if ($campaignId) {
    // Verify ownership
    $stmt = $pdo->prepare('SELECT * FROM ad_campaigns WHERE id = ? AND user_id = ?');
    $stmt->execute([$campaignId, $userId]);
    $selectedCampaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedCampaign) {
        // Get all ads for this campaign
        $stmt = $pdo->prepare('
            SELECT * FROM ad_generations
            WHERE campaign_id = ?
            ORDER BY created_at DESC
        ');
        $stmt->execute([$campaignId]);
        $campaignAds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get user stats
$stmt = $pdo->prepare('
    SELECT
        COUNT(DISTINCT c.id) as total_campaigns,
        COUNT(DISTINCT g.id) as total_ads,
        SUM(CASE WHEN c.created_at > datetime("now", "-7 days") THEN 1 ELSE 0 END) as recent_campaigns
    FROM ad_campaigns c
    LEFT JOIN ad_generations g ON c.id = g.campaign_id
    WHERE c.user_id = ?
');
$stmt->execute([$userId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ad Campaign Dashboard - PicFit.ai</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.5rem;
            color: #333;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }

        .stat-label {
            color: #666;
            margin-top: 0.5rem;
        }

        .campaigns-section {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: #333;
        }

        .campaigns-grid {
            display: grid;
            gap: 1rem;
        }

        .campaign-card {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .campaign-card:hover {
            background: #f9fafb;
            border-color: #667eea;
        }

        .campaign-card.selected {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-color: #667eea;
        }

        .campaign-info {
            flex: 1;
        }

        .campaign-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .campaign-meta {
            color: #666;
            font-size: 0.875rem;
        }

        .campaign-actions {
            display: flex;
            gap: 0.5rem;
        }

        .ads-gallery {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .ads-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .ad-card {
            background: #f9fafb;
            border-radius: 0.5rem;
            overflow: hidden;
            transition: all 0.3s;
        }

        .ad-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }

        .ad-image {
            width: 100%;
            height: auto;
            display: block;
        }

        .ad-info {
            padding: 1rem;
        }

        .ad-type {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .ad-dimensions {
            color: #666;
            font-size: 0.875rem;
        }

        .ad-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .btn-small {
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: -40px;
            right: 0;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-image {
            max-width: 100%;
            max-height: 90vh;
            display: block;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎨 Ad Campaign Dashboard</h1>
        <div class="nav-buttons">
            <a href="/figma-ad-generator" class="btn btn-primary">+ Create New Campaign</a>
            <a href="/dashboard" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>

    <div class="container">
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_campaigns'] ?? 0 ?></div>
                <div class="stat-label">Total Campaigns</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_ads'] ?? 0 ?></div>
                <div class="stat-label">Ads Generated</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['recent_campaigns'] ?? 0 ?></div>
                <div class="stat-label">This Week</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $user['credits_remaining'] ?></div>
                <div class="stat-label">Credits Available</div>
            </div>
        </div>

        <!-- Campaigns List -->
        <div class="campaigns-section">
            <h2 class="section-title">Your Campaigns</h2>

            <?php if (empty($campaigns)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p>No campaigns yet. Create your first campaign to get started!</p>
                    <a href="/figma-ad-generator" class="btn btn-primary" style="margin-top: 1rem;">Create Campaign</a>
                </div>
            <?php else: ?>
                <div class="campaigns-grid">
                    <?php foreach ($campaigns as $campaign): ?>
                        <div class="campaign-card <?= $campaignId == $campaign['id'] ? 'selected' : '' ?>"
                             onclick="selectCampaign(<?= $campaign['id'] ?>)">
                            <div class="campaign-info">
                                <div class="campaign-name"><?= htmlspecialchars($campaign['campaign_name']) ?></div>
                                <div class="campaign-meta">
                                    <?= $campaign['ad_count'] ?> ads •
                                    <?= date('M j, Y', strtotime($campaign['created_at'])) ?>
                                    <?php if ($campaign['status'] === 'completed'): ?>
                                        <span style="color: #10b981;">✓ Complete</span>
                                    <?php elseif ($campaign['status'] === 'processing'): ?>
                                        <span style="color: #f59e0b;">⏳ Processing</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="campaign-actions">
                                <button class="btn btn-small btn-secondary" onclick="event.stopPropagation(); downloadCampaign(<?= $campaign['id'] ?>)">
                                    📥 Download
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Selected Campaign Ads -->
        <?php if ($selectedCampaign && !empty($campaignAds)): ?>
            <div class="ads-gallery">
                <h2 class="section-title">
                    <?= htmlspecialchars($selectedCampaign['campaign_name']) ?> - Ads
                </h2>

                <div class="ads-grid">
                    <?php foreach ($campaignAds as $ad): ?>
                        <div class="ad-card">
                            <img src="<?= htmlspecialchars(CDNService::getImageUrl($ad['image_url'])) ?>"
                                 alt="<?= htmlspecialchars($ad['ad_type']) ?>"
                                 class="ad-image"
                                 onclick="viewFullSize('<?= htmlspecialchars(CDNService::getImageUrl($ad['image_url'])) ?>')">
                            <div class="ad-info">
                                <div class="ad-type"><?= htmlspecialchars($ad['ad_type']) ?></div>
                                <div class="ad-dimensions"><?= $ad['width'] ?>x<?= $ad['height'] ?>px</div>
                                <div class="ad-actions">
                                    <a href="<?= htmlspecialchars(CDNService::getImageUrl($ad['image_url'])) ?>"
                                       download="<?= $ad['ad_type'] ?>_<?= $ad['id'] ?>.png"
                                       class="btn btn-small btn-primary">Download</a>
                                    <?php if ($ad['with_text_url']): ?>
                                        <a href="<?= htmlspecialchars(CDNService::getImageUrl($ad['with_text_url'])) ?>"
                                           download="<?= $ad['ad_type'] ?>_text_<?= $ad['id'] ?>.png"
                                           class="btn btn-small btn-secondary">With Text</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($campaignId && !$selectedCampaign): ?>
            <div class="ads-gallery">
                <div class="empty-state">
                    <div class="empty-state-icon">🚫</div>
                    <p>Campaign not found or you don't have access to it.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Full Size Image Modal -->
    <div id="imageModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <img id="modalImage" class="modal-image" src="" alt="Full size ad">
        </div>
    </div>

    <script>
        function selectCampaign(campaignId) {
            window.location.href = '/ad-dashboard?campaign=' + campaignId;
        }

        function downloadCampaign(campaignId) {
            // Create a zip download of all campaign assets
            window.location.href = '/api/download-campaign.php?id=' + campaignId;
        }

        function viewFullSize(imageUrl) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modalImg.src = imageUrl;
            modal.classList.add('show');
        }

        function closeModal() {
            document.getElementById('imageModal').classList.remove('show');
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Close modal on click outside
        document.getElementById('imageModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>