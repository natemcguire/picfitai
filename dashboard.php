<?php
// dashboard.php - User dashboard
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/UserPhotoService.php';

Session::requireLogin();
$user = Session::getCurrentUser();

// Get recent generations with share tokens
$pdo = Database::getInstance();
$stmt = $pdo->prepare('
    SELECT * FROM generations
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
');
$stmt->execute([$user['id']]);
$generations = $stmt->fetchAll();

// Add share URLs for completed generations
foreach ($generations as &$gen) {
    if ($gen['status'] === 'completed' && !empty($gen['share_token'])) {
        $gen['share_url'] = '/share/' . $gen['share_token'];
    }
}

// Get credit transactions
$stmt = $pdo->prepare('
    SELECT * FROM credit_transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
');
$stmt->execute([$user['id']]);
$transactions = $stmt->fetchAll();

// Get user photos
$userPhotos = UserPhotoService::getUserPhotos($user['id']);
foreach ($userPhotos as &$photo) {
    $photo['url'] = UserPhotoService::getPhotoUrl($photo['filename']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= Config::get('app_name') ?></title>
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
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .header-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 15px;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.1);
        }

        .credits {
            background: #27ae60;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            display: inline-block;
            font-weight: bold;
        }

        /* Navigation Buttons */
        .nav-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 25px;
            padding: 0.5rem 1.2rem;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .nav-btn.primary {
            background: linear-gradient(45deg, #ff6b6b, #ff8e8e);
            border: 1px solid rgba(255, 107, 107, 0.3);
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
        }

        .nav-btn.primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 25px rgba(255, 107, 107, 0.4);
        }

        .content {
            padding: 40px 20px;
        }

        .welcome {
            text-align: center;
            margin-bottom: 40px;
        }

        .welcome h2 {
            color: #2c3e50;
            font-size: 1.8em;
            margin-bottom: 10px;
        }

        .welcome p {
            color: #7f8c8d;
            margin-bottom: 30px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 25px;
            font-size: 1.1em;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f39c12;
            color: white;
        }

        .btn-secondary:hover {
            background: #e67e22;
            transform: translateY(-2px);
        }

        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-top: 40px;
        }

        .card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .card h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.3em;
        }

        .generation-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .generation-info h4 {
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .generation-info .date {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 5px;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-processing {
            background: #fff3cd;
            color: #856404;
        }

        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .status-queued {
            background: #cce5ff;
            color: #004085;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 0.9em;
            border-radius: 15px;
        }

        .result-thumbnail {
            display: inline-block;
            width: 50px;
            height: 50px;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #667eea;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .result-thumbnail:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.5);
        }

        .result-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }

        .empty-state .icon {
            font-size: 4em;
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #7f8c8d;
        }

        .info-value {
            font-weight: bold;
            color: #2c3e50;
        }

        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-credit {
            font-weight: bold;
        }

        .credit-positive {
            color: #27ae60;
        }

        .credit-negative {
            color: #e74c3c;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border-left: 4px solid;
        }

        .alert-warning {
            background: #fff3cd;
            border-left-color: #ffc107;
            color: #856404;
        }

        .photos-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .photo-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 1;
            border: 2px solid transparent;
        }

        .photo-item.primary {
            border-color: #27ae60;
        }

        .photo-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .primary-badge {
            position: absolute;
            top: 4px;
            right: 4px;
            background: #27ae60;
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7em;
            font-weight: bold;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal.active {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            color: #2c3e50;
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #7f8c8d;
        }

        .close-btn:hover {
            color: #2c3e50;
        }

        .photos-manager-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .manager-photo-item {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid transparent;
            background: #f8f9fa;
        }

        .manager-photo-item.primary {
            border-color: #27ae60;
        }

        .manager-photo-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .photo-actions {
            position: absolute;
            top: 5px;
            right: 5px;
            display: flex;
            gap: 5px;
        }

        .photo-action-btn {
            background: rgba(0,0,0,0.7);
            color: white;
            border: none;
            border-radius: 15px;
            width: 30px;
            height: 30px;
            cursor: pointer;
            font-size: 0.8em;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .photo-action-btn:hover {
            background: rgba(0,0,0,0.9);
        }

        .upload-area {
            border: 2px dashed #bdc3c7;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            margin-bottom: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .upload-area:hover {
            border-color: #667eea;
            background: #f8f9fa;
        }

        .upload-area input[type="file"] {
            display: none;
        }

        @media (max-width: 768px) {
            .container {
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
            }

            .grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .header h1 {
                font-size: 1.5em;
            }

            .header-nav {
                flex-direction: column;
                text-align: center;
            }

            .generation-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/nav.php'; ?>

    <div class="container">
        <div class="content" style="padding-top: 30px;">
            <!-- Welcome & Quick Actions -->
            <div class="welcome">
                <h2>Welcome back, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>!</h2>
                <p>Ready to try on some outfits?</p>

                <div class="action-buttons">
                    <a href="/generate.php" class="btn btn-primary">
                        ✨ Generate New Fit
                    </a>
                    <?php if ($user['credits_remaining'] < 5): ?>
                        <a href="/pricing.php" class="btn btn-secondary">
                            💳 Buy More Credits
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid">
                <!-- Main Content -->
                <div>
                    <!-- Recent Generations -->
                    <div class="card">
                        <h3>Recent Generations</h3>

                        <?php if (empty($generations)): ?>
                            <div class="empty-state">
                                <div class="icon">👗</div>
                                <p>No generations yet. <a href="/generate.php" style="color: #667eea; text-decoration: none;">Create your first one!</a></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($generations as $gen): ?>
                                <div class="generation-item">
                                    <div class="generation-info">
                                        <h4>Generation #<?= $gen['id'] ?></h4>
                                        <div class="date"><?= date('M j, Y g:i A', strtotime($gen['created_at'])) ?></div>
                                        <div>
                                            <span class="status-badge status-<?= $gen['status'] ?>">
                                                <?= ucfirst($gen['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <?php if ($gen['status'] === 'completed' && !empty($gen['share_url'])): ?>
                                            <a href="<?= htmlspecialchars($gen['share_url']) ?>"
                                               class="result-thumbnail"
                                               target="_blank"
                                               title="View result">
                                                <img src="<?= htmlspecialchars($gen['result_url']) ?>" alt="Generated result" />
                                            </a>
                                        <?php elseif ($gen['status'] === 'completed' && $gen['result_url']): ?>
                                            <a href="<?= htmlspecialchars($gen['result_url']) ?>"
                                               class="result-thumbnail"
                                               target="_blank"
                                               title="View image">
                                                <img src="<?= htmlspecialchars($gen['result_url']) ?>" alt="Generated result" />
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($gen['status'] === 'failed'): ?>
                                            <div style="color: #e74c3c; font-size: 0.9em; max-width: 200px;">
                                                <?= htmlspecialchars($gen['error_message'] ?? 'Unknown error') ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sidebar -->
                <div>
                    <!-- Account Info -->
                    <div class="card">
                        <h3>Account</h3>
                        <div class="info-row">
                            <span class="info-label">Credits:</span>
                            <span class="info-value" style="color: #27ae60;"><?= $user['credits_remaining'] ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Member since:</span>
                            <span class="info-value"><?= date('M Y', strtotime($user['created_at'])) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total generations:</span>
                            <span class="info-value"><?= count($generations) ?></span>
                        </div>

                        <?php if ($user['credits_remaining'] === 0): ?>
                            <div class="alert alert-warning">
                                <p>
                                    You're out of credits! <a href="/pricing.php" style="color: #667eea; text-decoration: none;">Buy more</a> to continue generating.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="card" style="margin-top: 30px;">
                        <h3>Recent Activity</h3>

                        <?php if (empty($transactions)): ?>
                            <p style="color: #7f8c8d; font-size: 0.9em;">No activity yet.</p>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                                <div class="transaction-item">
                                    <div>
                                        <div style="font-weight: 500;"><?= htmlspecialchars($tx['description']) ?></div>
                                        <div style="color: #7f8c8d; font-size: 0.9em;"><?= date('M j', strtotime($tx['created_at'])) ?></div>
                                    </div>
                                    <div class="transaction-credit <?= $tx['type'] === 'purchase' || $tx['type'] === 'bonus' ? 'credit-positive' : 'credit-negative' ?>">
                                        <?= $tx['type'] === 'purchase' || $tx['type'] === 'bonus' ? '+' : '-' ?><?= $tx['credits'] ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- My Photos -->
                    <div class="card" style="margin-top: 30px;">
                        <h3>My Photos (<?= count($userPhotos) ?>)</h3>

                        <?php if (empty($userPhotos)): ?>
                            <p style="color: #7f8c8d; font-size: 0.9em; text-align: center; margin: 20px 0;">
                                No photos uploaded yet.<br>
                                <a href="#" onclick="uploadPhotosNow(); return false;" style="color: #667eea; text-decoration: none;">Upload one now!</a>
                            </p>
                        <?php else: ?>
                            <div class="photos-grid">
                                <?php foreach (array_slice($userPhotos, 0, 4) as $photo): ?>
                                    <div class="photo-item <?= $photo['is_primary'] ? 'primary' : '' ?>">
                                        <img src="<?= htmlspecialchars($photo['url']) ?>" alt="Your photo" loading="lazy">
                                        <?php if ($photo['is_primary']): ?>
                                            <div class="primary-badge">Primary</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (count($userPhotos) > 4): ?>
                                <p style="color: #7f8c8d; font-size: 0.9em; text-align: center; margin-top: 10px;">
                                    +<?= count($userPhotos) - 4 ?> more photos
                                </p>
                            <?php endif; ?>

                            <div style="margin-top: 15px; display: flex; gap: 10px;">
                                <button type="button" class="btn btn-primary btn-small" style="flex: 1;" onclick="openPhotoManager()">
                                    Manage Photos
                                </button>
                                <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="uploadPhotosNow()">
                                    Add More
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card" style="margin-top: 30px;">
                        <h3>Quick Actions</h3>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <a href="/generate.php" class="btn btn-primary btn-small" style="width: 100%; text-align: center;">
                                New Generation
                            </a>
                            <a href="/pricing.php" class="btn btn-secondary btn-small" style="width: 100%; text-align: center;">
                                Buy Credits
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Hidden file input for photo uploads -->
    <input type="file" id="photoUploadInput" multiple accept="image/*" style="display: none;" onchange="handlePhotoUpload(this)">

    <!-- Photo Manager Modal -->
    <div id="photoManagerModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 10000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #2c3e50; border-radius: 20px; padding: 30px; max-width: 90%; max-height: 90%; overflow: auto; width: 800px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: white; margin: 0;">My Photos</h2>
                <button onclick="closePhotoManager()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer;">&times;</button>
            </div>

            <div id="photoManagerContent" style="color: white;">
                <!-- Content will be loaded here -->
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button class="btn btn-primary" onclick="uploadMorePhotos()">Upload More Photos</button>
                <button class="btn btn-secondary" onclick="closePhotoManager()">Close</button>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = '<?= Session::generateCSRFToken() ?>';
        let uploadedPhotos = <?= json_encode($userPhotos) ?>;

        function uploadPhotosNow() {
            document.getElementById('photoUploadInput').click();
        }

        function handlePhotoUpload(input) {
            if (input.files && input.files.length > 0) {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                let fileCount = 0;

                // Limit to 10 files
                for (let i = 0; i < Math.min(input.files.length, 10); i++) {
                    formData.append('photos[]', input.files[i]);
                    fileCount++;
                }

                // Show loading state
                const modal = document.getElementById('photoManagerModal');
                modal.style.display = 'block';
                document.getElementById('photoManagerContent').innerHTML = '<p>Uploading ' + fileCount + ' photo(s)...</p>';

                // Upload photos
                fetch('/api/upload_photos.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Add new photos to the list
                        uploadedPhotos = uploadedPhotos.concat(data.photos);
                        showPhotoManager();
                    } else {
                        alert('Upload failed: ' + (data.error || 'Unknown error'));
                        closePhotoManager();
                    }
                })
                .catch(error => {
                    alert('Upload failed: ' + error.message);
                    closePhotoManager();
                });

                // Clear the input
                input.value = '';
            }
        }

        function openPhotoManager() {
            document.getElementById('photoManagerModal').style.display = 'block';
            showPhotoManager();
        }

        function showPhotoManager() {
            const content = document.getElementById('photoManagerContent');

            if (uploadedPhotos.length === 0) {
                content.innerHTML = '<p style="text-align: center; color: #95a5a6;">No photos uploaded yet. Click "Upload More Photos" to add some!</p>';
            } else {
                let html = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px;">';

                uploadedPhotos.forEach((photo, index) => {
                    html += `
                        <div style="position: relative; background: #34495e; border-radius: 10px; overflow: hidden;">
                            <img src="${photo.url}" style="width: 100%; height: 150px; object-fit: cover;">
                            ${photo.is_primary ? '<div style="position: absolute; top: 5px; left: 5px; background: #f39c12; color: white; padding: 2px 8px; border-radius: 5px; font-size: 12px;">Primary</div>' : ''}
                            <div style="position: absolute; top: 5px; right: 5px;">
                                <button onclick="deletePhotoNew(${photo.id})" style="background: rgba(231, 76, 60, 0.9); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 12px;">&times;</button>
                            </div>
                            ${!photo.is_primary ? `<button onclick="setPrimaryPhotoNew(${photo.id})" style="position: absolute; bottom: 5px; left: 5px; background: rgba(52, 152, 219, 0.9); border: none; color: white; padding: 3px 8px; border-radius: 5px; cursor: pointer; font-size: 11px;">Set Primary</button>` : ''}
                        </div>
                    `;
                });

                html += '</div>';
                html += '<p style="margin-top: 15px; color: #95a5a6; font-size: 14px;">You have ' + uploadedPhotos.length + ' photo(s). Maximum 10 photos allowed.</p>';
                content.innerHTML = html;
            }
        }

        function closePhotoManager() {
            document.getElementById('photoManagerModal').style.display = 'none';
        }

        function uploadMorePhotos() {
            document.getElementById('photoUploadInput').click();
        }

        function deletePhotoNew(photoId) {
            if (!confirm('Are you sure you want to delete this photo?')) return;

            fetch('/api/delete_photo.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({photo_id: photoId, csrf_token: csrfToken})
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    uploadedPhotos = uploadedPhotos.filter(p => p.id !== photoId);
                    showPhotoManager();
                    // Reload page to update dashboard
                    if (uploadedPhotos.length === 0) {
                        location.reload();
                    }
                } else {
                    alert('Failed to delete photo: ' + (data.error || 'Unknown error'));
                }
            });
        }

        function setPrimaryPhotoNew(photoId) {
            fetch('/api/set_primary_photo.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({photo_id: photoId, csrf_token: csrfToken})
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    uploadedPhotos.forEach(p => {
                        p.is_primary = (p.id === photoId) ? 1 : 0;
                    });
                    showPhotoManager();
                } else {
                    alert('Failed to set primary photo: ' + (data.error || 'Unknown error'));
                }
            });
        }

        // Keep existing loadPhotos function for compatibility
        function loadPhotos() {
            fetch('/api/photos.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderPhotos(data.photos);
                    }
                })
                .catch(error => console.error('Error loading photos:', error));
        }

        function renderPhotos(photos) {
            const grid = document.getElementById('photosGrid');
            grid.innerHTML = '';

            photos.forEach(photo => {
                const photoItem = document.createElement('div');
                photoItem.className = 'manager-photo-item' + (photo.is_primary ? ' primary' : '');
                photoItem.innerHTML = `
                    <img src="${photo.url}" alt="${photo.original_name}" loading="lazy">
                    <div class="photo-actions">
                        ${!photo.is_primary ? `<button class="photo-action-btn" onclick="setPrimary(${photo.id})" title="Set as primary">⭐</button>` : ''}
                        <button class="photo-action-btn" onclick="deletePhoto(${photo.id})" title="Delete">🗑️</button>
                    </div>
                    ${photo.is_primary ? '<div class="primary-badge">Primary</div>' : ''}
                `;
                grid.appendChild(photoItem);
            });
        }

        function setPrimary(photoId) {
            const formData = new FormData();
            formData.append('photo_id', photoId);
            formData.append('csrf_token', csrfToken);

            fetch('/api/photos.php', {
                method: 'PUT',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadPhotos();
                    location.reload(); // Refresh page to update sidebar
                } else {
                    alert(data.error);
                }
            })
            .catch(error => console.error('Error setting primary:', error));
        }

        function deletePhoto(photoId) {
            if (!confirm('Are you sure you want to delete this photo?')) {
                return;
            }

            fetch(`/api/photos.php?id=${photoId}&csrf_token=${encodeURIComponent(csrfToken)}`, {
                method: 'DELETE'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadPhotos();
                    location.reload(); // Refresh page to update sidebar
                } else {
                    alert(data.error);
                }
            })
            .catch(error => console.error('Error deleting photo:', error));
        }

        // Handle file upload (Note: This is handled by the handlePhotoUpload function already)
        // The photo upload input uses onchange="handlePhotoUpload(this)" inline handler

        function uploadPhoto(file) {
            const formData = new FormData();
            formData.append('photo', file);
            formData.append('csrf_token', csrfToken);

            fetch('/api/photos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadPhotos();
                    location.reload(); // Refresh page to update sidebar
                } else {
                    alert(data.error);
                }
            })
            .catch(error => console.error('Error uploading photo:', error));
        }

        // Close modal when clicking outside
        document.getElementById('photoManagerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePhotoManager();
            }
        });
    </script>
</body>
</html>