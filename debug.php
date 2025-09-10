<?php
// debug.php - Debug information for PicFit.ai
require_once 'bootstrap.php';

echo "<h1>PicFit.ai Debug Information</h1>";

echo "<h2>Configuration Status</h2>";
echo "<p><strong>Gemini API Key:</strong> " . (Config::get('gemini_api_key') ? 'CONFIGURED' : 'NOT CONFIGURED') . "</p>";
echo "<p><strong>OpenAI API Key:</strong> " . (Config::get('openai_api_key') ? 'CONFIGURED' : 'NOT CONFIGURED') . "</p>";

echo "<h2>PHP Upload Settings</h2>";
echo "<p><strong>upload_max_filesize:</strong> " . ini_get('upload_max_filesize') . "</p>";
echo "<p><strong>post_max_size:</strong> " . ini_get('post_max_size') . "</p>";
echo "<p><strong>max_file_uploads:</strong> " . ini_get('max_file_uploads') . "</p>";
echo "<p><strong>file_uploads:</strong> " . (ini_get('file_uploads') ? 'ON' : 'OFF') . "</p>";

echo "<h2>Environment Variables</h2>";
echo "<p><strong>GEMINI_API_KEY:</strong> " . (getenv('GEMINI_API_KEY') ? 'SET' : 'NOT SET') . "</p>";
echo "<p><strong>OPENAI_API_KEY:</strong> " . (getenv('OPENAI_API_KEY') ? 'SET' : 'NOT SET') . "</p>";

echo "<h2>File Upload Test</h2>";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>POST Data Received:</h3>";
    echo "<pre>" . print_r($_POST, true) . "</pre>";
    
    echo "<h3>FILES Data Received:</h3>";
    echo "<pre>" . print_r($_FILES, true) . "</pre>";
    
    if (!empty($_FILES)) {
        echo "<h3>File Validation Test:</h3>";
        $errors = AIService::validateUploadedFiles($_FILES['standing_photos'] ?? [], $_FILES['outfit_photo'] ?? []);
        if (empty($errors)) {
            echo "<p style='color: green;'>✅ File validation passed!</p>";
        } else {
            echo "<p style='color: red;'>❌ File validation failed:</p>";
            echo "<ul>";
            foreach ($errors as $error) {
                echo "<li>" . htmlspecialchars($error) . "</li>";
            }
            echo "</ul>";
        }
    }
} else {
    echo "<form method='POST' enctype='multipart/form-data'>";
    echo "<p><label>Standing Photos (multiple): <input type='file' name='standing_photos[]' multiple accept='image/*'></label></p>";
    echo "<p><label>Outfit Photo: <input type='file' name='outfit_photo' accept='image/*'></label></p>";
    echo "<p><button type='submit'>Test Upload</button></p>";
    echo "</form>";
}

echo "<h2>Recent Error Logs</h2>";
$logFile = '/tmp/php_errors.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $recentLogs = array_slice(explode("\n", $logs), -20);
    echo "<pre>" . htmlspecialchars(implode("\n", $recentLogs)) . "</pre>";
} else {
    echo "<p>No error log found at $logFile</p>";
}
?>
