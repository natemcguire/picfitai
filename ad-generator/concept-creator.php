<?php
// concept-creator.php - Video game style ad concept creation interface
declare(strict_types=1);

require_once '../bootstrap.php';
require_once '../includes/AdGeneratorService.php';
require_once '../includes/FigmaService.php';
require_once '../includes/DocumentParserService.php';

// Check authentication
Session::requireLogin();
$user = Session::getCurrentUser();
$userId = $user['id'];
$pdo = Database::getInstance();

// Generate CSRF token
$csrfToken = Session::generateCSRFToken();

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Generate concept
    if ($_GET['ajax'] === 'generate_concept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token. Please refresh the page.');
            }

            // Check credits
            $stmt = $pdo->prepare('SELECT credits_remaining FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $userCreds = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userCreds['credits_remaining'] < 1) {
                throw new Exception("Insufficient credits. You need at least 1 credit to generate a concept.");
            }

            // Collect concept data
            $conceptData = [
                'brand_name' => $_POST['brand_name'] ?? '',
                'primary_color' => $_POST['primary_color'] ?? '#2196F3',
                'secondary_color' => $_POST['secondary_color'] ?? '#FF9800',
                'custom_prompt' => $_POST['custom_prompt'] ?? '',
                'visual_style' => $_POST['visual_style'] ?? 'modern',
                'mood' => $_POST['mood'] ?? 'professional',
                'target_audience' => $_POST['target_audience'] ?? '',
                'campaign_goal' => $_POST['campaign_goal'] ?? 'awareness',
                'primary_platform' => $_POST['primary_platform'] ?? 'social'
            ];

            // Generate the concept using AdGeneratorService
            $adGenerator = new AdGeneratorService($userId);

            // Build a strategic creative prompt
            $prompt = "You are an expert creative director tasked with developing a NEW advertising concept. ";
            $prompt .= "You have been provided with brand guidelines and reference materials, NOT to copy, but to understand the brand DNA. ";
            $prompt .= "Your job is to create an ORIGINAL concept that is on-brand but completely fresh and innovative.\n\n";

            // Brand context
            if (!empty($conceptData['brand_name'])) {
                $prompt .= "Brand: {$conceptData['brand_name']}\n";
            }

            // Strategic goal
            $campaignGoal = $_POST['campaign_goal'] ?? 'awareness';
            $goalStrategy = match($campaignGoal) {
                'awareness' => "Create maximum visual impact and memorability. Use bold, attention-grabbing visuals that make people stop scrolling. Focus on brand recognition elements.",
                'conversion' => "Drive immediate action. Create urgency and desire. Include visual cues that suggest value, benefits, and clear next steps.",
                'engagement' => "Spark curiosity and conversation. Create something share-worthy, relatable, or emotionally resonant that people want to interact with.",
                'consideration' => "Build trust and credibility. Showcase quality, expertise, and differentiation. Help viewers imagine themselves using the product/service.",
                default => "Create a compelling visual that achieves marketing objectives."
            };
            $prompt .= "\nCampaign Goal: {$campaignGoal} - {$goalStrategy}\n";

            // Target audience psychology
            if (!empty($conceptData['target_audience'])) {
                $prompt .= "\nTarget Audience: {$conceptData['target_audience']}";
                $prompt .= "\nDesign to resonate with their values, aspirations, and visual preferences.\n";
            }

            // Brand personality and tone
            $prompt .= "\nBrand Personality: {$conceptData['mood']} and {$conceptData['visual_style']}";
            $prompt .= "\nMaintain brand consistency while pushing creative boundaries.\n";

            // Color strategy
            $prompt .= "\nColor Psychology: Primary color {$conceptData['primary_color']} should evoke the right emotions.";
            $prompt .= " Secondary color {$conceptData['secondary_color']} for contrast and hierarchy.\n";

            // Custom creative direction
            if (!empty($conceptData['custom_prompt'])) {
                $prompt .= "\nAdditional Creative Direction: {$conceptData['custom_prompt']}\n";
            }

            // Creative mandate
            $prompt .= "\nCREATIVE MANDATE:\n";
            $prompt .= "- Generate a UNIQUE concept, not a copy of existing ads\n";
            $prompt .= "- Apply advertising best practices for the platform and goal\n";
            $prompt .= "- Use visual hierarchy to guide the eye\n";
            $prompt .= "- Consider the scroll-stopping power\n";
            $prompt .= "- Balance brand consistency with creative innovation\n";
            $prompt .= "- Think about the emotional response you want to trigger\n";

            // Platform considerations
            $platform = $_POST['primary_platform'] ?? 'social';
            $platformStrategy = match($platform) {
                'social' => "Optimized for mobile viewing, thumb-stopping visuals, works without sound",
                'display' => "Clear visual hierarchy, readable at small sizes, strong call-to-action",
                'video' => "Strong opening frame, tells story visually, works with and without sound",
                default => "Multi-platform optimization"
            };
            $prompt .= "\nPlatform Strategy: {$platformStrategy}\n";

            $prompt .= "\nDeliver: A breakthrough advertising concept that stands out in a crowded marketplace while maintaining brand integrity.";

            // Call Gemini API directly for concept generation
            $imageUrl = $adGenerator->generateConceptImage($prompt, $conceptData);

            // Save concept to database
            $stmt = $pdo->prepare('
                INSERT INTO ad_concepts (
                    user_id, concept_name, concept_data, image_url, prompt_used, status
                ) VALUES (?, ?, ?, ?, ?, "completed")
            ');

            $conceptName = $_POST['concept_name'] ?? 'Untitled Concept';
            $stmt->execute([
                $userId,
                $conceptName,
                json_encode($conceptData),
                $imageUrl,
                $prompt
            ]);

            $conceptId = $pdo->lastInsertId();

            // Deduct 1 credit
            $pdo->prepare('UPDATE users SET credits_remaining = credits_remaining - 1 WHERE id = ?')
                ->execute([$userId]);

            // Log transaction
            $pdo->prepare('
                INSERT INTO credit_transactions (user_id, type, credits, description)
                VALUES (?, "debit", ?, ?)
            ')->execute([
                $userId,
                -1,
                "Concept generation - {$conceptName}"
            ]);

            echo json_encode([
                'success' => true,
                'conceptId' => $conceptId,
                'imageUrl' => $imageUrl
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Upload brand assets
    if ($_GET['ajax'] === 'upload_assets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token.');
            }

            $uploadedFiles = [];

            if (!empty($_FILES['assets'])) {
                $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/generated/ads/temp_assets/{$userId}/";
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                foreach ($_FILES['assets']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['assets']['error'][$key] === UPLOAD_ERR_OK) {
                        $filename = uniqid() . '_' . basename($_FILES['assets']['name'][$key]);
                        $filepath = $uploadDir . $filename;

                        if (move_uploaded_file($tmp_name, $filepath)) {
                            $uploadedFiles[] = [
                                'name' => $_FILES['assets']['name'][$key],
                                'path' => "/generated/ads/temp_assets/{$userId}/" . $filename,
                                'type' => $_FILES['assets']['type'][$key],
                                'size' => $_FILES['assets']['size'][$key]
                            ];
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'files' => $uploadedFiles
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }
}

// Get user's credits
$stmt = $pdo->prepare('SELECT credits_remaining FROM users WHERE id = ?');
$stmt->execute([$userId]);
$userCredits = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎮 Ad Concept Creator - PicFit.ai</title>
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
            overflow-x: hidden;
        }

        /* Cyber Grid Background */
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
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border: 2px solid #00ff00;
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
        }

        .credits {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #1a1a1a;
            border: 2px solid #00ff00;
            padding: 10px 20px;
            font-size: 18px;
            color: #00ff00;
            font-weight: bold;
        }

        /* Main Layout */
        .main-layout {
            display: grid;
            grid-template-columns: 350px 1fr 350px;
            gap: 20px;
            height: calc(100vh - 200px);
        }

        /* Left Panel - Assets & Colors */
        .left-panel {
            background: #1a1a1a;
            border: 2px solid #00ff00;
            padding: 20px;
            overflow-y: auto;
        }

        .panel-title {
            font-size: 20px;
            color: #00ff00;
            margin-bottom: 15px;
            text-transform: uppercase;
            border-bottom: 1px solid #00ff00;
            padding-bottom: 10px;
        }

        /* Center - Concept Preview */
        .center-panel {
            background: #0d0d0d;
            border: 2px solid #00ff00;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .concept-preview {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .concept-image {
            max-width: 90%;
            max-height: 90%;
            border: 2px solid #00ff00;
            box-shadow: 0 0 30px rgba(0, 255, 0, 0.5);
        }

        .placeholder {
            text-align: center;
            color: #00ff00;
            opacity: 0.5;
        }

        .placeholder-icon {
            font-size: 100px;
            margin-bottom: 20px;
        }

        /* Right Panel - Configuration */
        .right-panel {
            background: #1a1a1a;
            border: 2px solid #00ff00;
            padding: 20px;
            overflow-y: auto;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }

        .label {
            display: block;
            color: #00ff00;
            margin-bottom: 8px;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 1px;
        }

        .input {
            width: 100%;
            padding: 12px;
            background: #0d0d0d;
            border: 1px solid #00ff00;
            color: #00ff00;
            font-family: 'Space Mono', monospace;
            transition: all 0.3s;
        }

        .input:focus {
            outline: none;
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
            background: #1a1a1a;
        }

        .textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            background: #0d0d0d;
            border: 1px solid #00ff00;
            color: #00ff00;
            font-family: 'Space Mono', monospace;
            resize: vertical;
        }

        .textarea:focus {
            outline: none;
            box-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
            background: #1a1a1a;
        }

        /* Color Pickers */
        .color-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .color-picker {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .color-input {
            width: 60px;
            height: 40px;
            border: 2px solid #00ff00;
            cursor: pointer;
            background: #0d0d0d;
        }

        .color-label {
            flex: 1;
            color: #00ff00;
            font-size: 12px;
        }

        /* Style Selector */
        .style-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .style-option {
            padding: 10px;
            background: #0d0d0d;
            border: 1px solid #333;
            color: #00ff00;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
        }

        .style-option:hover {
            border-color: #00ff00;
            background: #1a1a1a;
        }

        .style-option.active {
            background: #00ff00;
            color: #000;
            border-color: #00ff00;
            font-weight: bold;
        }

        /* Assets List */
        .asset-list {
            margin-top: 15px;
        }

        .asset-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background: #0d0d0d;
            border: 1px solid #333;
            margin-bottom: 8px;
            transition: all 0.3s;
        }

        .asset-item:hover {
            border-color: #00ff00;
        }

        .asset-icon {
            width: 40px;
            height: 40px;
            background: #00ff00;
            margin-right: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            font-weight: bold;
        }

        .asset-name {
            flex: 1;
            color: #00ff00;
            font-size: 12px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .asset-remove {
            color: #ff0000;
            cursor: pointer;
            padding: 5px;
        }

        /* Upload Area */
        .upload-area {
            border: 2px dashed #00ff00;
            padding: 30px;
            text-align: center;
            background: #0d0d0d;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 20px;
        }

        .upload-area:hover {
            background: #1a1a1a;
            border-color: #00ccff;
        }

        .upload-area.dragover {
            background: #1a1a1a;
            border-color: #ff00ff;
            box-shadow: 0 0 20px rgba(255, 0, 255, 0.5);
        }

        /* Generate Button */
        .generate-btn {
            width: 100%;
            padding: 20px;
            background: linear-gradient(45deg, #00ff00, #00ccff);
            border: none;
            color: #000;
            font-family: 'Orbitron', sans-serif;
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
        }

        .generate-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(0, 255, 0, 0.8);
        }

        .generate-btn:active {
            transform: scale(0.95);
        }

        .generate-btn::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            transform: rotate(45deg);
            transition: all 0.5s;
        }

        .generate-btn:hover::before {
            animation: shine 0.5s ease-in-out;
        }

        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        /* Loading State */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loader {
            text-align: center;
        }

        .loader-spinner {
            width: 80px;
            height: 80px;
            border: 4px solid #333;
            border-top: 4px solid #00ff00;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loader-text {
            color: #00ff00;
            font-size: 18px;
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Success State */
        .success-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #1a1a1a;
            border: 2px solid #00ff00;
            padding: 30px;
            text-align: center;
            z-index: 2000;
            display: none;
            box-shadow: 0 0 50px rgba(0, 255, 0, 0.8);
        }

        .success-message.active {
            display: block;
        }

        .success-title {
            font-size: 24px;
            color: #00ff00;
            margin-bottom: 20px;
        }

        .success-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .action-btn {
            padding: 10px 20px;
            background: #0d0d0d;
            border: 1px solid #00ff00;
            color: #00ff00;
            cursor: pointer;
            transition: all 0.3s;
        }

        .action-btn:hover {
            background: #00ff00;
            color: #000;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">🎮 Ad Concept Creator</h1>
            <div class="credits">Credits: <?= $userCredits['credits_remaining'] ?></div>
        </div>

        <div class="main-layout">
            <!-- Left Panel - Assets & Colors -->
            <div class="left-panel">
                <h2 class="panel-title">📁 Brand Assets</h2>

                <div class="upload-area" onclick="document.getElementById('file-input').click()">
                    <div>📤 Drop files here or click to upload</div>
                    <div style="font-size: 12px; margin-top: 10px; opacity: 0.7;">
                        Logos, products, brand docs (PDF, JPG, PNG)
                    </div>
                </div>

                <input type="file" id="file-input" multiple accept="image/*,.pdf,.doc,.docx" style="display: none;">

                <div class="asset-list" id="asset-list">
                    <!-- Assets will be added here dynamically -->
                </div>

                <h2 class="panel-title" style="margin-top: 30px;">🎨 Brand Colors</h2>

                <div class="color-group">
                    <div class="color-picker">
                        <input type="color" id="primary-color" class="color-input" value="#00ff00">
                        <span class="color-label">PRIMARY</span>
                    </div>
                    <div class="color-picker">
                        <input type="color" id="secondary-color" class="color-input" value="#00ccff">
                        <span class="color-label">SECONDARY</span>
                    </div>
                </div>
            </div>

            <!-- Center - Concept Preview -->
            <div class="center-panel">
                <div class="loading-overlay" id="loading">
                    <div class="loader">
                        <div class="loader-spinner"></div>
                        <div class="loader-text">GENERATING CONCEPT...</div>
                    </div>
                </div>

                <div class="concept-preview" id="preview">
                    <div class="placeholder">
                        <div class="placeholder-icon">🎨</div>
                        <div>Your concept will appear here</div>
                    </div>
                </div>
            </div>

            <!-- Right Panel - Configuration -->
            <div class="right-panel">
                <h2 class="panel-title">⚙️ Configuration</h2>

                <div class="form-group">
                    <label class="label">Concept Name</label>
                    <input type="text" id="concept-name" class="input" placeholder="Summer Campaign 2024">
                </div>

                <div class="form-group">
                    <label class="label">Brand Name</label>
                    <input type="text" id="brand-name" class="input" placeholder="Your Brand">
                </div>

                <div class="form-group">
                    <label class="label">Visual Style</label>
                    <div class="style-grid">
                        <div class="style-option active" data-style="modern">Modern</div>
                        <div class="style-option" data-style="retro">Retro</div>
                        <div class="style-option" data-style="minimal">Minimal</div>
                        <div class="style-option" data-style="bold">Bold</div>
                        <div class="style-option" data-style="elegant">Elegant</div>
                        <div class="style-option" data-style="playful">Playful</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Mood</label>
                    <div class="style-grid">
                        <div class="style-option active" data-mood="professional">Professional</div>
                        <div class="style-option" data-mood="friendly">Friendly</div>
                        <div class="style-option" data-mood="luxury">Luxury</div>
                        <div class="style-option" data-mood="energetic">Energetic</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Campaign Goal</label>
                    <div class="style-grid">
                        <div class="style-option active" data-goal="awareness">Awareness</div>
                        <div class="style-option" data-goal="conversion">Conversion</div>
                        <div class="style-option" data-goal="engagement">Engagement</div>
                        <div class="style-option" data-goal="consideration">Consideration</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Primary Platform</label>
                    <div class="style-grid">
                        <div class="style-option active" data-platform="social">Social Media</div>
                        <div class="style-option" data-platform="display">Display Ads</div>
                        <div class="style-option" data-platform="video">Video</div>
                        <div class="style-option" data-platform="all">All Platforms</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label">Target Audience</label>
                    <input type="text" id="target-audience" class="input" placeholder="e.g., Millennials interested in sustainable fashion, B2B decision makers">
                </div>

                <div class="form-group">
                    <label class="label">Custom Prompt (Inject your ideas)</label>
                    <textarea id="custom-prompt" class="textarea" placeholder="Add any specific details you want in the concept...&#10;&#10;Example: Include product bottles, summer theme, beach background, young professionals using the product"></textarea>
                </div>

                <button class="generate-btn" id="generate-btn">
                    🚀 GENERATE CONCEPT
                </button>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    <div class="success-message" id="success-message">
        <div class="success-title">✅ CONCEPT GENERATED!</div>
        <div class="success-actions">
            <button class="action-btn" onclick="saveAndContinue()">Save & Export Sizes</button>
            <button class="action-btn" onclick="generateAnother()">Generate Another</button>
            <button class="action-btn" onclick="goToDashboard()">View Dashboard</button>
        </div>
    </div>

    <script>
        let uploadedAssets = [];
        let currentConceptId = null;

        // Style selectors
        document.querySelectorAll('.style-option').forEach(option => {
            option.addEventListener('click', function() {
                const group = this.parentElement;
                group.querySelectorAll('.style-option').forEach(opt => opt.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // File upload handling
        const fileInput = document.getElementById('file-input');
        const uploadArea = document.querySelector('.upload-area');
        const assetList = document.getElementById('asset-list');

        // Drag and drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            handleFiles(e.dataTransfer.files);
        });

        fileInput.addEventListener('change', (e) => {
            handleFiles(e.target.files);
        });

        function handleFiles(files) {
            const formData = new FormData();
            formData.append('csrf_token', '<?= $csrfToken ?>');

            Array.from(files).forEach(file => {
                formData.append('assets[]', file);
            });

            fetch('concept-creator.php?ajax=upload_assets', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    data.files.forEach(file => {
                        uploadedAssets.push(file);
                        addAssetToList(file);
                    });
                }
            });
        }

        function addAssetToList(file) {
            const ext = file.name.split('.').pop().toUpperCase();
            const assetHtml = `
                <div class="asset-item" data-path="${file.path}">
                    <div class="asset-icon">${ext}</div>
                    <div class="asset-name">${file.name}</div>
                    <div class="asset-remove" onclick="removeAsset(this)">✕</div>
                </div>
            `;
            assetList.insertAdjacentHTML('beforeend', assetHtml);
        }

        function removeAsset(element) {
            const item = element.closest('.asset-item');
            const path = item.dataset.path;
            uploadedAssets = uploadedAssets.filter(a => a.path !== path);
            item.remove();
        }

        // Generate concept
        document.getElementById('generate-btn').addEventListener('click', generateConcept);

        function generateConcept() {
            const conceptName = document.getElementById('concept-name').value || 'Untitled Concept';
            const brandName = document.getElementById('brand-name').value;
            const primaryColor = document.getElementById('primary-color').value;
            const secondaryColor = document.getElementById('secondary-color').value;
            const customPrompt = document.getElementById('custom-prompt').value;
            const targetAudience = document.getElementById('target-audience').value;

            const visualStyle = document.querySelector('.style-grid .style-option.active[data-style]').dataset.style;
            const mood = document.querySelector('.style-grid .style-option.active[data-mood]').dataset.mood;
            const campaignGoal = document.querySelector('.style-grid .style-option.active[data-goal]').dataset.goal;
            const primaryPlatform = document.querySelector('.style-grid .style-option.active[data-platform]').dataset.platform;

            // Show loading
            document.getElementById('loading').classList.add('active');

            const formData = new FormData();
            formData.append('csrf_token', '<?= $csrfToken ?>');
            formData.append('concept_name', conceptName);
            formData.append('brand_name', brandName);
            formData.append('primary_color', primaryColor);
            formData.append('secondary_color', secondaryColor);
            formData.append('custom_prompt', customPrompt);
            formData.append('visual_style', visualStyle);
            formData.append('mood', mood);
            formData.append('campaign_goal', campaignGoal);
            formData.append('primary_platform', primaryPlatform);
            formData.append('target_audience', targetAudience);
            formData.append('assets', JSON.stringify(uploadedAssets));

            fetch('concept-creator.php?ajax=generate_concept', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('loading').classList.remove('active');

                if (data.success) {
                    currentConceptId = data.conceptId;
                    displayConcept(data.imageUrl);
                    document.getElementById('success-message').classList.add('active');
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(err => {
                document.getElementById('loading').classList.remove('active');
                alert('Network error: ' + err.message);
            });
        }

        function displayConcept(imageUrl) {
            const preview = document.getElementById('preview');
            preview.innerHTML = `<img src="${imageUrl}" class="concept-image" alt="Generated Concept">`;
        }

        function saveAndContinue() {
            if (currentConceptId) {
                window.location.href = `export-sizes.php?concept_id=${currentConceptId}`;
            }
        }

        function generateAnother() {
            document.getElementById('success-message').classList.remove('active');
            document.getElementById('preview').innerHTML = `
                <div class="placeholder">
                    <div class="placeholder-icon">🎨</div>
                    <div>Your concept will appear here</div>
                </div>
            `;
        }

        function goToDashboard() {
            window.location.href = 'ad-dashboard.php';
        }
    </script>
</body>
</html>