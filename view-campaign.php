<?php
// view-campaign.php - View ad campaign and generated ads
declare(strict_types=1);

require_once 'bootstrap.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$campaignId = (int)($_GET['id'] ?? 0);

if (!$campaignId) {
    header('Location: /ad-randomizer.php');
    exit;
}

// Get campaign details
$stmt = $pdo->prepare('
    SELECT * FROM ad_campaigns
    WHERE id = ? AND user_id = ?
');
$stmt->execute([$campaignId, $userId]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    header('Location: /ad-randomizer.php');
    exit;
}

// Get generated ads
$stmt = $pdo->prepare('
    SELECT * FROM ad_generations
    WHERE campaign_id = ?
    ORDER BY ad_type
');
$stmt->execute([$campaignId]);
$ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$styleGuide = json_decode($campaign['style_guide'], true);

// Note: Downloads are now handled by secure serve-ad.php endpoint
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($campaign['campaign_name']) ?> - PicFit.ai</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .ad-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
    </style>
</head>
<body class="h-full bg-gradient-to-br from-purple-50 to-pink-50">
    <?php include 'includes/header.php'; ?>

    <div class="min-h-full pt-20 pb-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Campaign Header -->
            <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($campaign['campaign_name']) ?></h1>
                        <p class="text-gray-600 mt-2">
                            Created <?= date('M j, Y g:i a', strtotime($campaign['created_at'])) ?>
                        </p>
                    </div>
                    <span class="px-3 py-1 text-sm rounded-full
                          <?= $campaign['status'] === 'completed' ? 'bg-green-100 text-green-800' :
                              ($campaign['status'] === 'processing' ? 'bg-yellow-100 text-yellow-800' :
                               'bg-red-100 text-red-800') ?>">
                        <?= ucfirst($campaign['status']) ?>
                    </span>
                </div>

                <!-- Style Guide Summary -->
                <?php if ($styleGuide): ?>
                    <div class="border-t pt-4">
                        <h3 class="font-semibold text-gray-900 mb-2">Style Guide</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <?php if (!empty($styleGuide['primary_color'])): ?>
                                <div>
                                    <span class="text-gray-600">Primary Color:</span>
                                    <div class="flex items-center gap-2 mt-1">
                                        <div class="w-6 h-6 rounded border"
                                             style="background-color: <?= htmlspecialchars($styleGuide['primary_color']) ?>"></div>
                                        <span><?= htmlspecialchars($styleGuide['primary_color']) ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($styleGuide['visual_style'])): ?>
                                <div>
                                    <span class="text-gray-600">Visual Style:</span>
                                    <div class="font-medium"><?= htmlspecialchars($styleGuide['visual_style']) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($styleGuide['target_audience'])): ?>
                                <div>
                                    <span class="text-gray-600">Target Audience:</span>
                                    <div class="font-medium"><?= htmlspecialchars($styleGuide['target_audience']) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($styleGuide['cta_text'])): ?>
                                <div>
                                    <span class="text-gray-600">CTA:</span>
                                    <div class="font-medium"><?= htmlspecialchars($styleGuide['cta_text']) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="flex gap-4 mt-6">
                    <button onclick="downloadAll()"
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                        Download All
                    </button>
                    <a href="/figma-ad-generator"
                       class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                        Create New Campaign
                    </a>
                </div>
            </div>

            <!-- Generated Ads Grid -->
            <?php if (!empty($ads)): ?>
                <div class="ad-grid">
                    <?php foreach ($ads as $ad): ?>
                        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                            <!-- Ad Preview -->
                            <div class="relative bg-gray-100">
                                <img src="/serve-ad.php?path=<?= urlencode($ad['image_url']) ?>&campaign=<?= $campaignId ?>"
                                     alt="<?= htmlspecialchars($ad['ad_type']) ?>"
                                     class="w-full h-auto"
                                     loading="lazy">

                                <?php if ($ad['with_text_url']): ?>
                                    <div class="absolute top-2 right-2">
                                        <button onclick="toggleTextVersion(<?= $ad['id'] ?>)"
                                                class="px-2 py-1 bg-white bg-opacity-90 text-xs rounded hover:bg-opacity-100">
                                            Toggle Text
                                        </button>
                                    </div>
                                    <img src="/serve-ad.php?path=<?= urlencode($ad['with_text_url']) ?>&campaign=<?= $campaignId ?>"
                                         alt="<?= htmlspecialchars($ad['ad_type']) ?> with text"
                                         id="text-version-<?= $ad['id'] ?>"
                                         class="w-full h-auto absolute top-0 left-0 hidden"
                                         loading="lazy">
                                <?php endif; ?>
                            </div>

                            <!-- Ad Details -->
                            <div class="p-4">
                                <h3 class="font-semibold text-gray-900">
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $ad['ad_type']))) ?>
                                </h3>
                                <p class="text-sm text-gray-600 mt-1">
                                    <?= $ad['width'] ?> × <?= $ad['height'] ?>px
                                </p>

                                <!-- Download Options -->
                                <div class="flex gap-2 mt-3">
                                    <a href="/serve-ad.php?path=<?= urlencode($ad['image_url']) ?>&campaign=<?= $campaignId ?>&download=1"
                                       class="flex-1 px-3 py-1.5 bg-purple-100 text-purple-700 text-sm rounded hover:bg-purple-200 text-center">
                                        Download
                                    </a>
                                    <?php if ($ad['with_text_url']): ?>
                                        <a href="/serve-ad.php?path=<?= urlencode($ad['with_text_url']) ?>&campaign=<?= $campaignId ?>&download=1"
                                           class="flex-1 px-3 py-1.5 bg-purple-100 text-purple-700 text-sm rounded hover:bg-purple-200 text-center">
                                            With Text
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-lg p-8 text-center">
                    <p class="text-gray-600">No ads generated yet for this campaign.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleTextVersion(adId) {
            const textVersion = document.getElementById(`text-version-${adId}`);
            textVersion.classList.toggle('hidden');
        }

        function downloadAll() {
            <?php foreach ($ads as $ad): ?>
                setTimeout(() => {
                    const link = document.createElement('a');
                    link.href = '?id=<?= $campaignId ?>&download=<?= $ad['id'] ?>';
                    link.download = '';
                    link.click();
                }, <?= $ad['id'] ?> * 500);
            <?php endforeach; ?>
        }
    </script>
</body>
</html>