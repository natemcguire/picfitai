<?php
// dashboard.php - User dashboard
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/UserPhotoService.php';

Session::requireLogin();
$user = Session::getCurrentUser();

// Pagination setup
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$pdo = Database::getInstance();

// Get total count of generations
$stmt = $pdo->prepare('SELECT COUNT(*) FROM generations WHERE user_id = ?');
$stmt->execute([$user['id']]);
$totalGenerations = (int)$stmt->fetchColumn();

// Calculate pagination info
$totalPages = max(1, ceil($totalGenerations / $perPage));
$page = min($page, $totalPages); // Ensure page doesn't exceed total pages

// Get recent generations with share tokens and likes count (paginated)
$stmt = $pdo->prepare('
    SELECT g.*,
           COALESCE(SUM(CASE WHEN pr.rating = 1 THEN 1 ELSE 0 END), 0) as likes_count,
           COALESCE(SUM(CASE WHEN pr.rating = -1 THEN 1 ELSE 0 END), 0) as dislikes_count
    FROM generations g
    LEFT JOIN photo_ratings pr ON g.id = pr.generation_id
    WHERE g.user_id = ?
    GROUP BY g.id
    ORDER BY g.created_at DESC
    LIMIT ? OFFSET ?
');
$stmt->execute([$user['id'], $perPage, $offset]);
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
            background: linear-gradient(45deg, #ff6b9d 0%, #ff8fab 50%, #ff6b9d 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(255, 107, 157, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 107, 157, 0.4);
            background: linear-gradient(45deg, #ff8fab 0%, #ffafc9 50%, #ff8fab 100%);
        }

        .btn-secondary {
            background: linear-gradient(45deg, #4ecdc4 0%, #44a08d 50%, #4ecdc4 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.3);
        }

        .btn-secondary:hover {
            background: linear-gradient(45deg, #5fd8cf 0%, #4ecdc4 50%, #5fd8cf 100%);
            box-shadow: 0 8px 25px rgba(78, 205, 196, 0.4);
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
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            gap: 15px;
            align-items: center;
            transition: all 0.3s ease;
        }

        .generation-info {
            flex: 1;
        }

        .generation-info h4 {
            color: #2c3e50;
            margin-bottom: 5px;
            font-size: 1em;
        }

        .generation-info .date {
            color: #7f8c8d;
            font-size: 0.85em;
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
            width: 80px;
            height: 80px;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid #667eea;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
            flex-shrink: 0;
        }

        .result-thumbnail:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.5);
        }

        .result-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
            display: block;
        }

        .generation-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .copy-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 8px;
            cursor: pointer;
            color: #6c757d;
            font-size: 16px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
        }

        .copy-btn:hover {
            background: #e9ecef;
            color: #495057;
        }

        .copy-btn.copied {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .delete-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 8px;
            cursor: pointer;
            color: #dc3545;
            font-size: 16px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
        }

        .delete-btn:hover {
            background: #f5c6cb;
            color: #721c24;
            border-color: #f1b0b7;
        }

        .delete-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .generations-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .generations-header h3 {
            margin: 0;
        }

        .generations-info {
            color: #7f8c8d;
            font-size: 0.9em;
            font-weight: 500;
        }

        .pagination-container {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pagination-btn, .pagination-number {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background: white;
            color: #495057;
            text-decoration: none;
            font-size: 0.9em;
            font-weight: 500;
            transition: all 0.2s ease;
            min-width: 40px;
            text-align: center;
        }

        .pagination-btn:hover, .pagination-number:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
            color: #212529;
        }

        .pagination-number.active {
            background: #667eea;
            border-color: #667eea;
            color: white;
        }

        .pagination-number.active:hover {
            background: #5a6fd8;
            border-color: #5a6fd8;
        }

        .pagination-dots {
            padding: 8px 4px;
            color: #6c757d;
            font-weight: bold;
        }

        .pagination-prev, .pagination-next {
            font-weight: 600;
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
                flex-direction: row;
                justify-content: space-between;
            }

            .generation-item {
                padding: 12px;
                gap: 12px;
            }

            .result-thumbnail {
                width: 70px;
                height: 70px;
            }

            .generation-info h4 {
                font-size: 0.95em;
            }

            .generation-info .date {
                font-size: 0.8em;
            }

            .status-badge {
                font-size: 0.75em;
                padding: 3px 8px;
            }

            .copy-btn {
                min-width: 32px;
                height: 32px;
                padding: 6px;
                font-size: 14px;
            }

            .generations-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .generations-info {
                font-size: 0.8em;
            }

            .pagination {
                gap: 4px;
            }

            .pagination-btn, .pagination-number {
                padding: 6px 8px;
                font-size: 0.8em;
                min-width: 32px;
            }

            .pagination-prev, .pagination-next {
                padding: 6px 12px;
            }
        }
    </style>
</head>
<body>
    <?php
    // Dashboard-specific nav without logo
    Session::start();
    $user = Session::getCurrentUser();
    ?>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka+One&display=swap');

        .header-nav {
            position: fixed;
            top: 15px;
            left: 15px;
            right: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 12px 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .nav-buttons {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .nav-btn {
            padding: 8px 16px;
            background: linear-gradient(45deg, #ff6b9d 0%, #ff8fab 50%, #ff6b9d 100%);
            color: white;
            text-decoration: none;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
            box-shadow: 0 2px 8px rgba(255, 107, 157, 0.3);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 107, 157, 0.4);
        }

        .nav-btn.secondary {
            background: linear-gradient(45deg, #4ecdc4 0%, #44a08d 50%, #4ecdc4 100%);
            box-shadow: 0 2px 8px rgba(78, 205, 196, 0.3);
        }

        .nav-btn.secondary:hover {
            box-shadow: 0 4px 15px rgba(78, 205, 196, 0.4);
        }

        .nav-btn.tertiary {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
            box-shadow: 0 2px 8px rgba(108, 117, 125, 0.1);
        }

        .nav-btn.tertiary:hover {
            background: rgba(108, 117, 125, 0.2);
            color: #495057;
        }

        .logout-link a {
            color: #6c757d;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .logout-link a:hover {
            color: #495057;
            background: rgba(108, 117, 125, 0.1);
        }

        body {
            padding-top: 80px;
        }

        @media (max-width: 768px) {
            .header-nav {
                top: 10px;
                left: 10px;
                right: 10px;
                padding: 8px 15px;
                justify-content: space-between;
            }

            .nav-buttons {
                gap: 6px;
                flex: 0 0 auto;
            }

            .nav-btn {
                padding: 10px 20px;
                font-size: 13px;
                min-width: auto;
            }

            .logout-link {
                flex: 0 0 auto;
            }

            .logout-link a {
                padding: 10px 15px;
                font-size: 12px;
                white-space: nowrap;
            }

            body {
                padding-top: 70px;
            }
        }
    </style>

    <div class="header-nav">
        <div class="nav-buttons">
            <a href="/generate.php" class="nav-btn">Generate</a>
        </div>
        <div class="logout-link">
            <a href="/auth/logout.php">Logout</a>
        </div>
    </div>

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
                        <div class="generations-header">
                            <h3>Recent Generations</h3>
                            <?php if ($totalGenerations > 0): ?>
                                <div class="generations-info">
                                    Showing <?= count($generations) ?> of <?= $totalGenerations ?> generations
                                    <?php if ($totalPages > 1): ?>
                                        (Page <?= $page ?> of <?= $totalPages ?>)
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($generations)): ?>
                            <div class="empty-state">
                                <div class="icon">👗</div>
                                <p>No generations yet. <a href="/generate.php" style="color: #667eea; text-decoration: none;">Create your first one!</a></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($generations as $index => $gen): ?>
                                <div class="generation-item">
                                    <?php if ($gen['status'] === 'completed' && $gen['result_url']): ?>
                                        <a href="<?= !empty($gen['share_url']) ? htmlspecialchars($gen['share_url']) : htmlspecialchars($gen['result_url']) ?>"
                                           class="result-thumbnail"
                                           <?= !empty($gen['share_url']) ? '' : 'target="_blank"' ?>
                                           title="View result">
                                            <img src="<?= htmlspecialchars($gen['result_url']) ?>" alt="Generated result" />
                                        </a>
                                    <?php else: ?>
                                        <div class="result-thumbnail" style="background: #f8f9fa; display: flex; align-items: center; justify-content: center; color: #adb5bd; font-size: 24px;">
                                            👗
                                        </div>
                                    <?php endif; ?>

                                    <div class="generation-info">
                                        <h4>Outfit #<?= $totalGenerations - (($page - 1) * $perPage + $index) ?></h4>
                                        <div class="date"><?= date('M j, g:i A', strtotime($gen['created_at'])) ?></div>
                                        <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                                            <span class="status-badge status-<?= $gen['status'] ?>">
                                                <?= ucfirst($gen['status']) ?>
                                            </span>
                                            <?php if ($gen['status'] === 'completed' && $gen['is_public'] && ((int)$gen['likes_count'] > 0 || (int)$gen['dislikes_count'] > 0)): ?>
                                                <div style="display: flex; align-items: center; gap: 8px; font-size: 0.85em; color: #7f8c8d;">
                                                    <?php if ((int)$gen['likes_count'] > 0): ?>
                                                        <span style="display: flex; align-items: center; gap: 3px;">
                                                            <span style="color: #27ae60;">👍</span>
                                                            <span style="color: #27ae60; font-weight: 600;"><?= (int)$gen['likes_count'] ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ((int)$gen['dislikes_count'] > 0): ?>
                                                        <span style="display: flex; align-items: center; gap: 3px;">
                                                            <span style="color: #e74c3c;">👎</span>
                                                            <span style="color: #e74c3c; font-weight: 600;"><?= (int)$gen['dislikes_count'] ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($gen['status'] === 'failed'): ?>
                                            <div style="color: #e74c3c; font-size: 0.85em; margin-top: 5px;">
                                                <?= htmlspecialchars($gen['error_message'] ?? 'Unknown error') ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="generation-actions">
                                        <?php if ($gen['status'] === 'completed' && !empty($gen['share_url'])): ?>
                                            <button class="copy-btn" onclick="copyShareLink('<?= htmlspecialchars($gen['share_url']) ?>', this)" title="Copy share link">
                                                📋
                                            </button>
                                        <?php endif; ?>
                                        <button class="delete-btn" onclick="deleteGeneration(<?= $gen['id'] ?>, this)" title="Delete this generation">
                                            🗑️
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination-container">
                                    <div class="pagination">
                                        <?php if ($page > 1): ?>
                                            <a href="?page=<?= $page - 1 ?>" class="pagination-btn pagination-prev">
                                                ← Previous
                                            </a>
                                        <?php endif; ?>

                                        <div class="pagination-numbers">
                                            <?php
                                            $startPage = max(1, $page - 2);
                                            $endPage = min($totalPages, $page + 2);

                                            if ($startPage > 1): ?>
                                                <a href="?page=1" class="pagination-number">1</a>
                                                <?php if ($startPage > 2): ?>
                                                    <span class="pagination-dots">...</span>
                                                <?php endif; ?>
                                            <?php endif;

                                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                                <a href="?page=<?= $i ?>" class="pagination-number <?= $i == $page ? 'active' : '' ?>">
                                                    <?= $i ?>
                                                </a>
                                            <?php endfor;

                                            if ($endPage < $totalPages): ?>
                                                <?php if ($endPage < $totalPages - 1): ?>
                                                    <span class="pagination-dots">...</span>
                                                <?php endif; ?>
                                                <a href="?page=<?= $totalPages ?>" class="pagination-number"><?= $totalPages ?></a>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($page < $totalPages): ?>
                                            <a href="?page=<?= $page + 1 ?>" class="pagination-btn pagination-next">
                                                Next →
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
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
                            <span class="info-value"><?= $totalGenerations ?></span>
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

        function copyShareLink(shareUrl, button) {
            const fullUrl = window.location.origin + shareUrl;

            navigator.clipboard.writeText(fullUrl).then(function() {
                const originalContent = button.innerHTML;
                button.innerHTML = '✅';
                button.classList.add('copied');

                setTimeout(() => {
                    button.innerHTML = originalContent;
                    button.classList.remove('copied');
                }, 2000);
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = fullUrl;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);

                const originalContent = button.innerHTML;
                button.innerHTML = '✅';
                button.classList.add('copied');

                setTimeout(() => {
                    button.innerHTML = originalContent;
                    button.classList.remove('copied');
                }, 2000);
            });
        }

        function deleteGeneration(generationId, button) {
            if (!confirm('Are you sure you want to delete this generation? This action cannot be undone.')) {
                return;
            }

            // Disable button during deletion
            button.disabled = true;
            const originalContent = button.innerHTML;
            button.innerHTML = '⏳';

            fetch('/api/delete_generation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    generation_id: generationId,
                    csrf_token: csrfToken
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Find and remove the generation item from the DOM
                    const generationItem = button.closest('.generation-item');
                    if (generationItem) {
                        generationItem.style.opacity = '0.5';
                        generationItem.style.transform = 'translateX(-100%)';
                        setTimeout(() => {
                            generationItem.remove();

                            // Check if there are no more generations
                            const remainingGenerations = document.querySelectorAll('.generation-item');
                            if (remainingGenerations.length === 0) {
                                location.reload(); // Reload to show empty state
                            }
                        }, 300);
                    }
                } else {
                    alert('Failed to delete generation: ' + (data.error || 'Unknown error'));
                    button.disabled = false;
                    button.innerHTML = originalContent;
                }
            })
            .catch(error => {
                alert('Failed to delete generation: ' + error.message);
                button.disabled = false;
                button.innerHTML = originalContent;
            });
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