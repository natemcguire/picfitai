<?php
// file-browser.php - Simple file browser for generated images
declare(strict_types=1);

// Enable error reporting for debugging (only for admin)
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once 'bootstrap.php';
    require_once 'includes/CDNService.php';
} catch (Exception $e) {
    die('Bootstrap error: ' . $e->getMessage());
}

// Check authentication
Session::requireLogin();
$user = Session::getCurrentUser();
$userId = $user['id'];

// Only allow Nate to access this page
if ($user['email'] !== 'nate.mcguire@gmail.com') {
    header('HTTP/1.1 403 Forbidden');
    die('Access denied. This page is restricted to administrators only.');
}

$isAdmin = true; // Nate is admin

// Base directories
$generatedDir = $_SERVER['DOCUMENT_ROOT'] . '/generated';
$userAdsDir = $generatedDir . '/ads/user_' . $userId;
$userPhotosDir = $_SERVER['DOCUMENT_ROOT'] . '/user_photos';

// Get current directory from query param (with security checks)
$requestedPath = $_GET['path'] ?? '';
$currentPath = $generatedDir;

// Security: Only allow browsing within generated directory and user's own folders
if ($requestedPath) {
    $requestedFullPath = realpath($generatedDir . '/' . $requestedPath);
    if ($requestedFullPath && strpos($requestedFullPath, $generatedDir) === 0) {
        // Check if user has permission to view this path
        if ($isAdmin ||
            strpos($requestedFullPath, $userAdsDir) === 0 ||
            strpos($requestedFullPath, $generatedDir) === 0) {
            $currentPath = $requestedFullPath;
        }
    }
}

// Get relative path for display
$relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $currentPath);
$relativeFromGenerated = str_replace($generatedDir, '', $currentPath);

// Function to get file info
function getFileInfo($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }

    $info = [
        'name' => basename($filePath),
        'size' => is_file($filePath) ? filesize($filePath) : 0,
        'modified' => filemtime($filePath),
        'type' => is_dir($filePath) ? 'directory' : 'file',
        'is_dir' => is_dir($filePath),
        'is_image' => false
    ];

    if (!$info['is_dir']) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $info['is_image'] = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);

        // Try to get mime type safely
        if (function_exists('mime_content_type')) {
            try {
                $info['type'] = mime_content_type($filePath) ?: 'file';
            } catch (Exception $e) {
                $info['type'] = 'file';
            }
        }
    }

    return $info;
}

// Function to format file size
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Get directory contents
$files = [];
$directories = [];
$images = [];

if (is_dir($currentPath) && is_readable($currentPath)) {
    try {
        $items = scandir($currentPath);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;

                $itemPath = $currentPath . '/' . $item;
                $info = getFileInfo($itemPath);

                if ($info === null) continue; // Skip if file doesn't exist or can't be read

                if ($info['is_dir']) {
                    $directories[] = $info;
                } elseif ($info['is_image']) {
                    $info['url'] = $relativePath . '/' . $item;
                    $info['cdn_url'] = CDNService::getImageUrl($info['url']);
                    $images[] = $info;
                } else {
                    $files[] = $info;
                }
            }
        }
    } catch (Exception $e) {
        // Handle permission errors or other issues
        error_log("File browser error: " . $e->getMessage());
    }
}

// Sort directories and files by name
sort($directories);
sort($files);
usort($images, function($a, $b) {
    return $b['modified'] - $a['modified']; // Sort images by date, newest first
});

// Calculate totals
$totalImages = count($images);
$totalSize = array_reduce($images, function($sum, $img) { return $sum + $img['size']; }, 0);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Browser - Generated Images</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #000;
            color: #00ff00;
            margin: 0;
            padding: 20px;
            font-size: 14px;
            line-height: 1.4;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            color: #00ff00;
            font-size: 18px;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .header {
            border: 1px solid #00ff00;
            padding: 10px;
            margin-bottom: 20px;
            background: #001100;
        }

        .path {
            color: #ffff00;
            word-break: break-all;
        }

        .stats {
            margin-top: 10px;
            color: #00ffff;
        }

        .file-list {
            border: 1px solid #00ff00;
            padding: 0;
            background: #001100;
        }

        .file-header {
            display: grid;
            grid-template-columns: 40px 1fr 100px 80px 150px;
            padding: 10px;
            border-bottom: 1px solid #00ff00;
            font-weight: bold;
            background: #002200;
        }

        .file-row {
            display: grid;
            grid-template-columns: 40px 1fr 100px 80px 150px;
            padding: 8px 10px;
            border-bottom: 1px dotted #00ff00;
            transition: background 0.2s;
            cursor: pointer;
        }

        .file-row:hover {
            background: #003300;
        }

        .file-row.directory {
            color: #ffff00;
            font-weight: bold;
        }

        .file-row.image {
            color: #00ffff;
        }

        .file-icon {
            text-align: center;
        }

        .file-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding-right: 10px;
        }

        .file-type, .file-size, .file-date {
            text-align: right;
        }

        .navigation {
            margin-bottom: 20px;
        }

        .nav-link {
            color: #00ff00;
            text-decoration: none;
            padding: 5px 10px;
            border: 1px solid #00ff00;
            background: #001100;
            display: inline-block;
            margin-right: 10px;
            transition: all 0.2s;
        }

        .nav-link:hover {
            background: #00ff00;
            color: #000;
        }

        .breadcrumb {
            margin: 20px 0;
            padding: 10px;
            background: #001100;
            border: 1px solid #00ff00;
        }

        .breadcrumb a {
            color: #00ffff;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .image-preview {
            margin-top: 20px;
            border: 1px solid #00ff00;
            padding: 10px;
            background: #001100;
            display: none;
        }

        .image-preview.show {
            display: block;
        }

        .image-preview img {
            max-width: 100%;
            max-height: 400px;
            display: block;
            margin: 10px auto;
            border: 1px solid #00ff00;
        }

        .preview-info {
            text-align: center;
            margin-top: 10px;
            color: #ffff00;
        }

        .actions {
            margin-top: 10px;
            text-align: center;
        }

        .action-btn {
            color: #000;
            background: #00ff00;
            border: none;
            padding: 5px 15px;
            margin: 0 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-family: 'Courier New', monospace;
        }

        .action-btn:hover {
            background: #00ff00;
            box-shadow: 0 0 10px #00ff00;
        }

        @media (max-width: 768px) {
            .file-header, .file-row {
                grid-template-columns: 30px 1fr 80px;
            }
            .file-type, .file-date {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📁 FILE BROWSER - GENERATED IMAGES</h1>
            <div class="path">PATH: <?= htmlspecialchars($relativePath ?: '/generated') ?></div>
            <div class="stats">
                <?= $totalImages ?> images | Total size: <?= formatFileSize($totalSize) ?>
                <span style="float: right; color: #ff0000;">
                    ADMIN: <?= htmlspecialchars($user['email']) ?>
                </span>
            </div>
            <div style="font-size: 12px; color: #666; margin-top: 5px;">
                DEBUG: Current: <?= htmlspecialchars($currentPath) ?> |
                Readable: <?= is_readable($currentPath) ? 'YES' : 'NO' ?> |
                Exists: <?= file_exists($currentPath) ? 'YES' : 'NO' ?>
            </div>
        </div>

        <div class="navigation">
            <a href="/dashboard" class="nav-link">← Dashboard</a>
            <a href="/file-browser" class="nav-link">📁 /generated</a>
            <?php if (is_dir($userAdsDir)): ?>
                <a href="/file-browser?path=ads/user_<?= $userId ?>" class="nav-link">🎨 My Ads</a>
            <?php endif; ?>
            <a href="/ad-dashboard" class="nav-link">📊 Ad Dashboard</a>
        </div>

        <?php
        // Build breadcrumb
        $pathParts = array_filter(explode('/', $relativeFromGenerated));
        if (!empty($pathParts)):
        ?>
        <div class="breadcrumb">
            <a href="/file-browser">generated</a>
            <?php
            $currentBreadcrumb = '';
            foreach ($pathParts as $part):
                $currentBreadcrumb .= '/' . $part;
            ?>
                / <a href="/file-browser?path=<?= urlencode(ltrim($currentBreadcrumb, '/')) ?>"><?= htmlspecialchars($part) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="file-list">
            <div class="file-header">
                <div class="file-icon">TYPE</div>
                <div class="file-name">NAME</div>
                <div class="file-type">FORMAT</div>
                <div class="file-size">SIZE</div>
                <div class="file-date">MODIFIED</div>
            </div>

            <?php if ($relativeFromGenerated): ?>
                <div class="file-row directory" onclick="navigateUp()">
                    <div class="file-icon">📁</div>
                    <div class="file-name">..</div>
                    <div class="file-type">DIR</div>
                    <div class="file-size">-</div>
                    <div class="file-date">-</div>
                </div>
            <?php endif; ?>

            <?php foreach ($directories as $dir): ?>
                <div class="file-row directory" onclick="navigateToDir('<?= htmlspecialchars($dir['name']) ?>')">
                    <div class="file-icon">📁</div>
                    <div class="file-name"><?= htmlspecialchars($dir['name']) ?></div>
                    <div class="file-type">DIR</div>
                    <div class="file-size">-</div>
                    <div class="file-date"><?= date('Y-m-d H:i', $dir['modified']) ?></div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($images as $img): ?>
                <div class="file-row image" onclick="previewImage('<?= htmlspecialchars($img['cdn_url']) ?>', '<?= htmlspecialchars($img['name']) ?>', '<?= formatFileSize($img['size']) ?>')">
                    <div class="file-icon">🖼️</div>
                    <div class="file-name"><?= htmlspecialchars($img['name']) ?></div>
                    <div class="file-type"><?= strtoupper(pathinfo($img['name'], PATHINFO_EXTENSION)) ?></div>
                    <div class="file-size"><?= formatFileSize($img['size']) ?></div>
                    <div class="file-date"><?= date('Y-m-d H:i', $img['modified']) ?></div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($files as $file): ?>
                <div class="file-row">
                    <div class="file-icon">📄</div>
                    <div class="file-name"><?= htmlspecialchars($file['name']) ?></div>
                    <div class="file-type"><?= strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION)) ?></div>
                    <div class="file-size"><?= formatFileSize($file['size']) ?></div>
                    <div class="file-date"><?= date('Y-m-d H:i', $file['modified']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="imagePreview" class="image-preview">
            <div class="preview-info">
                <span id="previewName"></span> | <span id="previewSize"></span>
            </div>
            <img id="previewImg" src="" alt="">
            <div class="actions">
                <a id="downloadBtn" href="" download="" class="action-btn">DOWNLOAD</a>
                <a id="cdnBtn" href="" target="_blank" class="action-btn">OPEN CDN</a>
                <button onclick="closePreview()" class="action-btn">CLOSE</button>
            </div>
        </div>
    </div>

    <script>
        function navigateToDir(dirname) {
            const currentPath = '<?= htmlspecialchars($relativeFromGenerated) ?>';
            const newPath = currentPath ? currentPath + '/' + dirname : dirname;
            window.location.href = '/file-browser?path=' + encodeURIComponent(newPath.replace(/^\//, ''));
        }

        function navigateUp() {
            const currentPath = '<?= htmlspecialchars($relativeFromGenerated) ?>';
            const parts = currentPath.split('/').filter(p => p);
            parts.pop();
            const newPath = parts.join('/');
            window.location.href = '/file-browser' + (newPath ? '?path=' + encodeURIComponent(newPath) : '');
        }

        function previewImage(url, name, size) {
            const preview = document.getElementById('imagePreview');
            const img = document.getElementById('previewImg');
            const nameSpan = document.getElementById('previewName');
            const sizeSpan = document.getElementById('previewSize');
            const downloadBtn = document.getElementById('downloadBtn');
            const cdnBtn = document.getElementById('cdnBtn');

            img.src = url;
            nameSpan.textContent = name;
            sizeSpan.textContent = size;
            downloadBtn.href = url;
            downloadBtn.download = name;
            cdnBtn.href = url;

            preview.classList.add('show');
        }

        function closePreview() {
            document.getElementById('imagePreview').classList.remove('show');
        }

        // ASCII art on load
        console.log(`
╔══════════════════════════════════════╗
║     FILE BROWSER v1.0                ║
║     PICFIT.AI SYSTEMS                ║
║     AUTHORIZED ACCESS ONLY           ║
╚══════════════════════════════════════╝
        `);
    </script>
</body>
</html>