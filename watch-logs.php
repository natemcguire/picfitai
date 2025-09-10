#!/usr/bin/env php
<?php
// watch-logs.php - Real-time log viewer for terminal
declare(strict_types=1);

$logFile = __DIR__ . '/logs/app.log';

echo "🔍 PicFit.ai Log Viewer\n";
echo "📁 Watching: $logFile\n";
echo "⏹️  Press Ctrl+C to stop\n\n";

if (!file_exists($logFile)) {
    echo "❌ Log file not found. Creating it...\n";
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    touch($logFile);
}

$lastSize = 0;

while (true) {
    if (file_exists($logFile)) {
        $currentSize = filesize($logFile);
        
        if ($currentSize > $lastSize) {
            $handle = fopen($logFile, 'r');
            fseek($handle, $lastSize);
            
            while (($line = fgets($handle)) !== false) {
                // Colorize log levels
                $line = preg_replace('/\[ERROR\]/', "\033[31m[ERROR]\033[0m", $line);
                $line = preg_replace('/\[WARNING\]/', "\033[33m[WARNING]\033[0m", $line);
                $line = preg_replace('/\[INFO\]/', "\033[32m[INFO]\033[0m", $line);
                $line = preg_replace('/\[DEBUG\]/', "\033[36m[DEBUG]\033[0m", $line);
                
                echo $line;
            }
            
            fclose($handle);
            $lastSize = $currentSize;
        }
    }
    
    usleep(100000); // 100ms
}
?>
