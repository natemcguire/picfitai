<?php
// ad-randomizer.php - AI-powered ad generation interface
declare(strict_types=1);

require_once 'bootstrap.php';
require_once 'includes/AdGeneratorService.php';
require_once 'includes/FigmaService.php';
require_once 'includes/DocumentParserService.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$error = '';
$success = '';
$styleGuide = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid CSRF token');
        }

        // Check credits
        $stmt = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user['credits'] < 5) {
            throw new Exception('Insufficient credits. You need at least 5 credits to generate ads.');
        }

        $styleGuide = [];

        // Process Figma URL if provided
        if (!empty($_POST['figma_url'])) {
            $figmaService = new FigmaService();
            $figmaStyle = $figmaService->extractStyleFromFigmaUrl($_POST['figma_url']);
            $styleGuide = array_merge($styleGuide, $figmaStyle);
        }

        // Process uploaded documents
        $uploadedDocs = [];
        if (!empty($_FILES['brand_docs']['name'][0])) {
            $docParser = new DocumentParserService();

            for ($i = 0; $i < count($_FILES['brand_docs']['name']); $i++) {
                if ($_FILES['brand_docs']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES['brand_docs']['tmp_name'][$i];
                    $mimeType = $_FILES['brand_docs']['type'][$i];

                    try {
                        $docData = $docParser->parseDocument($tmpName, $mimeType);
                        $uploadedDocs[] = $docData;
                    } catch (Exception $e) {
                        Logger::warning('Document parsing failed', [
                            'file' => $_FILES['brand_docs']['name'][$i],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            if (!empty($uploadedDocs)) {
                $combinedDoc = $docParser->combineDocuments($uploadedDocs);
                $styleGuide = array_merge($styleGuide, $combinedDoc);
            }
        }

        // Override with manual inputs if provided
        if (!empty($_POST['primary_color'])) {
            $styleGuide['primary_color'] = $_POST['primary_color'];
        }
        if (!empty($_POST['brand_personality'])) {
            $styleGuide['brand_personality'] = $_POST['brand_personality'];
        }
        if (!empty($_POST['target_audience'])) {
            $styleGuide['target_audience'] = $_POST['target_audience'];
        }
        if (!empty($_POST['product_description'])) {
            $styleGuide['product_description'] = $_POST['product_description'];
        }
        if (!empty($_POST['headline'])) {
            $styleGuide['headline'] = $_POST['headline'];
        }
        if (!empty($_POST['cta_text'])) {
            $styleGuide['cta_text'] = $_POST['cta_text'];
        }

        // Get selected ad sizes
        $selectedSizes = $_POST['ad_sizes'] ?? ['facebook_square'];

        // Generate ads
        $adGenerator = new AdGeneratorService();
        $result = $adGenerator->generateAdSet(
            $userId,
            $styleGuide,
            $selectedSizes,
            $_POST['campaign_name'] ?? 'Untitled Campaign'
        );

        // Deduct credits (1 credit per ad size)
        $creditCost = count($selectedSizes);
        $pdo->prepare('
            UPDATE users SET credits = credits - ? WHERE id = ?
        ')->execute([$creditCost, $userId]);

        // Log credit transaction
        $pdo->prepare('
            INSERT INTO credit_transactions (user_id, amount, type, description)
            VALUES (?, ?, "debit", ?)
        ')->execute([
            $userId,
            -$creditCost,
            "Ad generation - {$creditCost} sizes"
        ]);

        $success = 'Ads generated successfully! Campaign ID: ' . $result['campaign_id'];
        $_SESSION['last_campaign_id'] = $result['campaign_id'];
        $_SESSION['generated_ads'] = $result['ads'];

    } catch (Exception $e) {
        $error = $e->getMessage();
        Logger::error('Ad generation failed', [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
    }
}

// Get user's recent campaigns
$stmt = $pdo->prepare('
    SELECT id, campaign_name, status, created_at
    FROM ad_campaigns
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
');
$stmt->execute([$userId]);
$recentCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available ad sizes
$adGenerator = new AdGeneratorService();
$availableSizes = $adGenerator->getAvailableAdSizes();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ad Generator - PicFit.ai</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .ad-size-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
    </style>
</head>
<body class="h-full bg-gradient-to-br from-purple-50 to-pink-50">
    <?php include 'includes/header.php'; ?>

    <div class="min-h-full pt-20 pb-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-gray-900 mb-2">AI Ad Generator</h1>
                <p class="text-lg text-gray-600">Create professional ads for all platforms in seconds</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4">
                    <p class="text-red-700"><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-6 bg-green-50 border-l-4 border-green-400 p-4">
                    <p class="text-green-700"><?= htmlspecialchars($success) ?></p>
                    <?php if (isset($_SESSION['generated_ads'])): ?>
                        <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-4">
                            <?php foreach ($_SESSION['generated_ads'] as $ad): ?>
                                <div class="bg-white rounded-lg p-2">
                                    <img src="<?= htmlspecialchars($ad['image_url']) ?>"
                                         alt="<?= htmlspecialchars($ad['name']) ?>"
                                         class="w-full h-auto rounded">
                                    <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($ad['name']) ?></p>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($ad['dimensions']) ?></p>
                                    <?php if ($ad['with_text_url']): ?>
                                        <a href="<?= htmlspecialchars($ad['with_text_url']) ?>"
                                           target="_blank"
                                           class="text-xs text-blue-600 hover:underline">View with text</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="bg-white rounded-xl shadow-lg p-8">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <!-- Campaign Name -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Campaign Name</label>
                    <input type="text"
                           name="campaign_name"
                           placeholder="Summer Sale Campaign"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>

                <!-- Style Guide Sources -->
                <div class="border-b pb-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Style Guide Sources</h3>

                    <!-- Figma URL -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Figma File URL (Optional)</label>
                        <input type="url"
                               name="figma_url"
                               placeholder="https://www.figma.com/file/..."
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <p class="text-xs text-gray-500 mt-1">Provide a view-only link to extract colors and styles</p>
                    </div>

                    <!-- Document Upload -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Brand Documents (Optional)</label>
                        <input type="file"
                               name="brand_docs[]"
                               multiple
                               accept=".pdf,.doc,.docx,.txt,.html"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">Upload brand guides, messaging docs, or old copy (PDF, DOC, TXT)</p>
                    </div>
                </div>

                <!-- Manual Style Guide -->
                <div class="border-b pb-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Brand Details</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Primary Color -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Primary Color</label>
                            <input type="color"
                                   name="primary_color"
                                   value="#2196F3"
                                   class="w-full h-10 rounded cursor-pointer">
                        </div>

                        <!-- Brand Personality -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Brand Personality</label>
                            <select name="brand_personality"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                                <option value="professional">Professional</option>
                                <option value="playful">Playful</option>
                                <option value="luxury">Luxury</option>
                                <option value="friendly">Friendly</option>
                                <option value="bold">Bold</option>
                                <option value="minimal">Minimal</option>
                            </select>
                        </div>

                        <!-- Target Audience -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Target Audience</label>
                            <input type="text"
                                   name="target_audience"
                                   placeholder="e.g., Young professionals 25-35"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>

                        <!-- Product Description -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Product/Service</label>
                            <input type="text"
                                   name="product_description"
                                   placeholder="What are you advertising?"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>

                    <!-- Ad Copy -->
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Headline (Optional)</label>
                            <input type="text"
                                   name="headline"
                                   placeholder="Your compelling headline"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Call to Action</label>
                            <input type="text"
                                   name="cta_text"
                                   placeholder="Learn More"
                                   value="Learn More"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>
                </div>

                <!-- Ad Sizes Selection -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Select Ad Sizes</h3>
                    <div class="ad-size-grid">
                        <?php foreach ($availableSizes as $key => $size): ?>
                            <label class="flex items-start space-x-3 cursor-pointer">
                                <input type="checkbox"
                                       name="ad_sizes[]"
                                       value="<?= $key ?>"
                                       <?= $key === 'facebook_square' ? 'checked' : '' ?>
                                       class="mt-1 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <div>
                                    <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($size['name']) ?></span>
                                    <span class="block text-xs text-gray-500"><?= $size['width'] ?>x<?= $size['height'] ?>px</span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-sm text-gray-600 mt-2">1 credit per ad size selected</p>
                </div>

                <!-- Submit Button -->
                <button type="submit"
                        class="w-full bg-gradient-to-r from-purple-600 to-pink-600 text-white font-semibold py-3 px-6 rounded-lg hover:from-purple-700 hover:to-pink-700 transition-all duration-200 shadow-lg">
                    Generate Ad Set
                </button>
            </form>

            <!-- Recent Campaigns -->
            <?php if (!empty($recentCampaigns)): ?>
                <div class="mt-12 bg-white rounded-xl shadow-lg p-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Campaigns</h3>
                    <div class="space-y-2">
                        <?php foreach ($recentCampaigns as $campaign): ?>
                            <div class="flex justify-between items-center p-3 hover:bg-gray-50 rounded-lg">
                                <div>
                                    <span class="font-medium"><?= htmlspecialchars($campaign['campaign_name']) ?></span>
                                    <span class="ml-2 text-sm text-gray-500"><?= date('M j, g:i a', strtotime($campaign['created_at'])) ?></span>
                                </div>
                                <div>
                                    <span class="px-2 py-1 text-xs rounded-full
                                          <?= $campaign['status'] === 'completed' ? 'bg-green-100 text-green-800' :
                                              ($campaign['status'] === 'processing' ? 'bg-yellow-100 text-yellow-800' :
                                               'bg-red-100 text-red-800') ?>">
                                        <?= ucfirst($campaign['status']) ?>
                                    </span>
                                    <a href="/view-campaign.php?id=<?= $campaign['id'] ?>"
                                       class="ml-2 text-purple-600 hover:text-purple-800 text-sm">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Preview uploaded files
        document.querySelector('input[name="brand_docs[]"]').addEventListener('change', function(e) {
            const fileCount = e.target.files.length;
            if (fileCount > 0) {
                const label = e.target.nextElementSibling;
                label.textContent = `${fileCount} file(s) selected`;
            }
        });

        // Auto-save style guide to localStorage
        const styleInputs = document.querySelectorAll('input[name^="primary_color"], select[name="brand_personality"], input[name="target_audience"]');
        styleInputs.forEach(input => {
            input.addEventListener('change', () => {
                const styleData = {
                    primary_color: document.querySelector('input[name="primary_color"]').value,
                    brand_personality: document.querySelector('select[name="brand_personality"]').value,
                    target_audience: document.querySelector('input[name="target_audience"]').value
                };
                localStorage.setItem('ad_style_guide', JSON.stringify(styleData));
            });
        });

        // Load saved style guide
        const savedStyle = localStorage.getItem('ad_style_guide');
        if (savedStyle) {
            const styleData = JSON.parse(savedStyle);
            if (styleData.primary_color) {
                document.querySelector('input[name="primary_color"]').value = styleData.primary_color;
            }
            if (styleData.brand_personality) {
                document.querySelector('select[name="brand_personality"]').value = styleData.brand_personality;
            }
            if (styleData.target_audience) {
                document.querySelector('input[name="target_audience"]').value = styleData.target_audience;
            }
        }
    </script>
</body>
</html>