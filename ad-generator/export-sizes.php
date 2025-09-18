<?php
// export-sizes.php - Export concept to various ad sizes
declare(strict_types=1);

require_once '../bootstrap.php';
require_once '../includes/AdGeneratorService.php';

// Check authentication
Session::requireLogin();
$user = Session::getCurrentUser();
$userId = $user['id'];
$pdo = Database::getInstance();

$conceptId = (int)($_GET['concept_id'] ?? 0);

if (!$conceptId) {
    header('Location: ad-dashboard.php');
    exit;
}

// Get concept details
$stmt = $pdo->prepare('
    SELECT * FROM ad_concepts
    WHERE id = ? AND user_id = ?
');
$stmt->execute([$conceptId, $userId]);
$concept = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$concept) {
    header('Location: ad-dashboard.php');
    exit;
}

$conceptData = json_decode($concept['concept_data'], true);

// Get available ad sizes
$adGenerator = new AdGeneratorService($userId);
$availableSizes = $adGenerator->getAvailableAdSizes();

// Generate CSRF token
$csrfToken = Session::generateCSRFToken();

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $selectedSizes = $_POST['sizes'] ?? [];
        if (empty($selectedSizes)) {
            throw new Exception('Please select at least one size');
        }

        // Check credits
        $creditCost = count($selectedSizes);
        $stmt = $pdo->prepare('SELECT credits_remaining FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $userCreds = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userCreds['credits_remaining'] < $creditCost) {
            throw new Exception("Insufficient credits. You need {$creditCost} credits.");
        }

        // Create campaign from concept
        $campaignName = $_POST['campaign_name'] ?? $concept['concept_name'] . ' - Export';

        // Generate ads for each size
        $result = $adGenerator->generateAdSet(
            $conceptData,
            $selectedSizes,
            $campaignName
        );

        // Deduct credits
        $pdo->prepare('UPDATE users SET credits_remaining = credits_remaining - ? WHERE id = ?')
            ->execute([$creditCost, $userId]);

        // Log transaction
        $pdo->prepare('
            INSERT INTO credit_transactions (user_id, type, credits, description)
            VALUES (?, "debit", ?, ?)
        ')->execute([
            $userId,
            -$creditCost,
            "Export concept to {$creditCost} sizes"
        ]);

        // Redirect to campaign view
        header('Location: ../view-campaign.php?id=' . $result['campaign_id']);
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Sizes - <?= htmlspecialchars($concept['concept_name']) ?></title>
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

        .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border: 2px solid #00ff00;
            padding: 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .title {
            font-family: 'Orbitron', sans-serif;
            font-size: 36px;
            font-weight: 900;
            background: linear-gradient(45deg, #00ff00, #00ccff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .credits-badge {
            background: #0d0d0d;
            border: 2px solid #00ff00;
            padding: 10px 20px;
            font-size: 18px;
            color: #00ff00;
        }

        .main-content {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 30px;
        }

        /* Concept Preview */
        .concept-preview {
            background: #1a1a1a;
            border: 2px solid #00ff00;
            padding: 20px;
        }

        .preview-image {
            width: 100%;
            height: auto;
            border: 2px solid #00ff00;
            margin-bottom: 20px;
        }

        .concept-details {
            padding: 15px;
            background: #0d0d0d;
            border: 1px solid #333;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .detail-label {
            color: #00cc00;
        }

        .detail-value {
            color: #00ff00;
        }

        /* Size Selection */
        .size-selection {
            background: #1a1a1a;
            border: 2px solid #00ff00;
            padding: 20px;
        }

        .section-title {
            font-size: 24px;
            color: #00ff00;
            margin-bottom: 20px;
            text-transform: uppercase;
            border-bottom: 1px solid #00ff00;
            padding-bottom: 10px;
        }

        .campaign-name-input {
            width: 100%;
            padding: 12px;
            background: #0d0d0d;
            border: 1px solid #00ff00;
            color: #00ff00;
            font-family: 'Space Mono', monospace;
            margin-bottom: 20px;
        }

        .campaign-name-input:focus {
            outline: none;
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
        }

        /* Platform Groups */
        .platform-group {
            margin-bottom: 30px;
        }

        .platform-title {
            font-size: 18px;
            color: #00ff00;
            margin-bottom: 15px;
            padding: 10px;
            background: #0d0d0d;
            border-left: 4px solid #00ff00;
        }

        .sizes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .size-option {
            background: #0d0d0d;
            border: 2px solid #333;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .size-option:hover {
            border-color: #00ff00;
            background: #1a1a1a;
        }

        .size-option.selected {
            border-color: #00ff00;
            background: #001100;
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
        }

        .size-option.selected::after {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 10px;
            color: #00ff00;
            font-size: 20px;
        }

        .size-checkbox {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .size-name {
            font-size: 14px;
            color: #00ff00;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .size-dimensions {
            font-size: 12px;
            color: #00cc00;
        }

        .size-preview {
            width: 60px;
            height: 40px;
            border: 1px solid #00ff00;
            margin-top: 10px;
            position: relative;
        }

        /* Actions */
        .actions {
            position: sticky;
            bottom: 0;
            background: #0a0a0a;
            padding: 20px 0;
            border-top: 2px solid #00ff00;
            margin-top: 30px;
        }

        .action-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .selected-count {
            font-size: 18px;
            color: #00ff00;
        }

        .credit-cost {
            font-size: 20px;
            color: #ffcc00;
            font-weight: bold;
        }

        .export-btn {
            padding: 15px 40px;
            background: linear-gradient(45deg, #00ff00, #00ccff);
            border: none;
            color: #000;
            font-family: 'Orbitron', sans-serif;
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s;
        }

        .export-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(0, 255, 0, 0.8);
        }

        .export-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #00ff00;
            text-decoration: none;
            padding: 10px 20px;
            border: 1px solid #00ff00;
            transition: all 0.3s;
        }

        .back-link:hover {
            background: #00ff00;
            color: #000;
        }

        /* Error Message */
        .error-message {
            background: #1a0000;
            border: 2px solid #ff0000;
            padding: 15px;
            color: #ff0000;
            margin-bottom: 20px;
        }

        /* Quick Select */
        .quick-select {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .quick-btn {
            padding: 8px 15px;
            background: #0d0d0d;
            border: 1px solid #00ff00;
            color: #00ff00;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 12px;
        }

        .quick-btn:hover {
            background: #00ff00;
            color: #000;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="ad-dashboard.php" class="back-link">← Back to Dashboard</a>

        <div class="header">
            <h1 class="title">📐 Export to Ad Sizes</h1>
            <div class="credits-badge">Credits: <?= $user['credits_remaining'] ?></div>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="main-content">
                <!-- Concept Preview -->
                <div class="concept-preview">
                    <h2 class="section-title">Concept</h2>
                    <img src="<?= htmlspecialchars($concept['image_url']) ?>"
                         alt="<?= htmlspecialchars($concept['concept_name']) ?>"
                         class="preview-image">

                    <div class="concept-details">
                        <div class="detail-row">
                            <span class="detail-label">Name:</span>
                            <span class="detail-value"><?= htmlspecialchars($concept['concept_name']) ?></span>
                        </div>
                        <?php if (!empty($conceptData['brand_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Brand:</span>
                                <span class="detail-value"><?= htmlspecialchars($conceptData['brand_name']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($conceptData['visual_style'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Style:</span>
                                <span class="detail-value"><?= htmlspecialchars($conceptData['visual_style']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($conceptData['mood'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Mood:</span>
                                <span class="detail-value"><?= htmlspecialchars($conceptData['mood']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Size Selection -->
                <div class="size-selection">
                    <h2 class="section-title">Select Export Sizes</h2>

                    <input type="text"
                           name="campaign_name"
                           class="campaign-name-input"
                           placeholder="Campaign Name (optional)"
                           value="<?= htmlspecialchars($concept['concept_name']) ?> - Export">

                    <div class="quick-select">
                        <button type="button" class="quick-btn" onclick="selectAll()">Select All</button>
                        <button type="button" class="quick-btn" onclick="selectNone()">Clear All</button>
                        <button type="button" class="quick-btn" onclick="selectPlatform('facebook')">Facebook Pack</button>
                        <button type="button" class="quick-btn" onclick="selectPlatform('instagram')">Instagram Pack</button>
                        <button type="button" class="quick-btn" onclick="selectPlatform('google')">Google Ads Pack</button>
                    </div>

                    <?php
                    // Group sizes by platform
                    $platforms = [
                        'Facebook/Meta' => ['facebook_'],
                        'Instagram' => ['instagram_'],
                        'Twitter/X' => ['twitter_'],
                        'LinkedIn' => ['linkedin_'],
                        'TikTok' => ['tiktok_'],
                        'YouTube' => ['youtube_'],
                        'Pinterest' => ['pinterest_'],
                        'Snapchat' => ['snapchat_'],
                        'Google Ads' => ['google_'],
                        'Universal' => ['universal_']
                    ];

                    foreach ($platforms as $platform => $prefixes): ?>
                        <?php
                        $platformSizes = array_filter($availableSizes, function($key) use ($prefixes) {
                            foreach ($prefixes as $prefix) {
                                if (strpos($key, $prefix) === 0) return true;
                            }
                            return false;
                        }, ARRAY_FILTER_USE_KEY);

                        if (empty($platformSizes)) continue;
                        ?>

                        <div class="platform-group">
                            <div class="platform-title"><?= $platform ?></div>
                            <div class="sizes-grid">
                                <?php foreach ($platformSizes as $key => $size): ?>
                                    <label class="size-option" data-platform="<?= strtolower(str_replace(['/', ' '], '', $platform)) ?>">
                                        <input type="checkbox"
                                               name="sizes[]"
                                               value="<?= $key ?>"
                                               class="size-checkbox">
                                        <div class="size-name"><?= htmlspecialchars($size['name']) ?></div>
                                        <div class="size-dimensions"><?= $size['width'] ?> × <?= $size['height'] ?>px</div>
                                        <div class="size-preview" style="width: <?= min(60, $size['width'] / 20) ?>px; height: <?= min(40, $size['height'] / 20) ?>px;"></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="actions">
                <div class="action-row">
                    <div>
                        <span class="selected-count">Selected: <span id="selected-count">0</span> sizes</span>
                    </div>
                    <div>
                        <span class="credit-cost">Cost: <span id="credit-cost">0</span> credits</span>
                    </div>
                    <button type="submit" class="export-btn" id="export-btn" disabled>
                        🚀 Export Sizes
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Handle size selection
        const sizeOptions = document.querySelectorAll('.size-option');
        const checkboxes = document.querySelectorAll('.size-checkbox');
        const selectedCountEl = document.getElementById('selected-count');
        const creditCostEl = document.getElementById('credit-cost');
        const exportBtn = document.getElementById('export-btn');

        sizeOptions.forEach((option, index) => {
            option.addEventListener('click', function(e) {
                if (e.target.type !== 'checkbox') {
                    const checkbox = checkboxes[index];
                    checkbox.checked = !checkbox.checked;
                    option.classList.toggle('selected', checkbox.checked);
                    updateCount();
                }
            });

            checkboxes[index].addEventListener('change', function() {
                option.classList.toggle('selected', this.checked);
                updateCount();
            });
        });

        function updateCount() {
            const selected = document.querySelectorAll('.size-checkbox:checked').length;
            selectedCountEl.textContent = selected;
            creditCostEl.textContent = selected;
            exportBtn.disabled = selected === 0;
        }

        function selectAll() {
            checkboxes.forEach((cb, i) => {
                cb.checked = true;
                sizeOptions[i].classList.add('selected');
            });
            updateCount();
        }

        function selectNone() {
            checkboxes.forEach((cb, i) => {
                cb.checked = false;
                sizeOptions[i].classList.remove('selected');
            });
            updateCount();
        }

        function selectPlatform(platform) {
            checkboxes.forEach((cb, i) => {
                const option = sizeOptions[i];
                if (option.dataset.platform && option.dataset.platform.includes(platform)) {
                    cb.checked = true;
                    option.classList.add('selected');
                }
            });
            updateCount();
        }

        // Initialize count
        updateCount();
    </script>
</body>
</html>