<?php
// debug-logs.php - Stream logs to frontend
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/bootstrap.php';

// Get recent logs from the log file
$logs = [];

try {
    // Read from the actual log file
    $logFile = __DIR__ . '/logs/app.log';

    if (file_exists($logFile)) {
        // Get the last 100 lines from the log file
        $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -100);

        foreach ($lines as $line) {
            if (trim($line)) {
                if (preg_match('/^\[([^\]]+)\] \[([^\]]+)\] (.+)$/', trim($line), $matches)) {
                    $timestamp = $matches[1];
                    $level = $matches[2];
                    $message = $matches[3];

                    $context = null;
                    if (preg_match('/^(.+?) (\{.+\})$/', $message, $contextMatches)) {
                        $message = $contextMatches[1];
                        $contextJson = $contextMatches[2];
                        $context = json_decode($contextJson, true);
                    }

                    $logs[] = [
                        'timestamp' => $timestamp,
                        'level' => $level,
                        'message' => $message,
                        'context' => $context,
                        'raw_line' => $line
                    ];
                } else {
                    $logs[] = [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'level' => 'RAW',
                        'message' => trim($line),
                        'context' => null,
                        'raw_line' => $line
                    ];
                }
            }
        }
    } else {
        $logs[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'ERROR',
            'message' => 'Log file not found at: ' . $logFile,
            'context' => ['file_exists' => false],
            'raw_line' => ''
        ];
    }

} catch (Exception $e) {
    $logs[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => 'ERROR',
        'message' => 'Failed to read logs: ' . $e->getMessage(),
        'context' => ['exception' => $e->getTrace()],
        'raw_line' => ''
    ];
}

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'count' => count($logs),
    'timestamp' => date('Y-m-d H:i:s'),
    'log_file_path' => __DIR__ . '/logs/app.log'
]);
?>