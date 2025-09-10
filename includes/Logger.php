<?php
// includes/Logger.php - Centralized logging system
declare(strict_types=1);

class Logger {
    private static string $logFile;
    private static bool $terminalOutput = false;
    
    public static function init(string $logFile = null, bool $terminalOutput = true): void {
        self::$logFile = $logFile ?: dirname(__DIR__) . '/logs/app.log';
        self::$terminalOutput = $terminalOutput;
        
        // Create logs directory if it doesn't exist
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
    
    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }
    
    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }
    
    public static function debug(string $message, array $context = []): void {
        self::log('DEBUG', $message, $context);
    }
    
    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }
    
    private static function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logEntry = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
        
        // Output to terminal if enabled
        if (self::$terminalOutput) {
            $color = match($level) {
                'ERROR' => "\033[31m", // Red
                'WARNING' => "\033[33m", // Yellow
                'INFO' => "\033[32m", // Green
                'DEBUG' => "\033[36m", // Cyan
                default => "\033[0m" // Reset
            };
            echo $color . $logEntry . "\033[0m";
        }
        
        // Write to log file
        if (self::$logFile) {
            @file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
    
    public static function logRequest(string $method, string $uri, array $data = []): void {
        self::info("HTTP $method $uri", [
            'post_data' => $data['post'] ?? [],
            'files_count' => count($data['files'] ?? []),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    }
    
    public static function logFileUpload(array $files): void {
        foreach ($files as $field => $file) {
            if (is_array($file) && isset($file['name'])) {
                if (is_array($file['name'])) {
                    // Multiple files
                    for ($i = 0; $i < count($file['name']); $i++) {
                        if (!empty($file['name'][$i])) {
                            self::info("File uploaded: $field[$i]", [
                                'name' => $file['name'][$i],
                                'size' => $file['size'][$i] ?? 0,
                                'type' => $file['type'][$i] ?? 'unknown',
                                'error' => $file['error'][$i] ?? 'unknown'
                            ]);
                        }
                    }
                } else {
                    // Single file
                    self::info("File uploaded: $field", [
                        'name' => $file['name'],
                        'size' => $file['size'] ?? 0,
                        'type' => $file['type'] ?? 'unknown',
                        'error' => $file['error'] ?? 'unknown'
                    ]);
                }
            }
        }
    }
    
    public static function logApiCall(string $service, string $endpoint, array $requestData = [], array $responseData = []): void {
        self::info("API Call: $service", [
            'endpoint' => $endpoint,
            'request_size' => strlen(json_encode($requestData)),
            'response_size' => strlen(json_encode($responseData)),
            'success' => !isset($responseData['error'])
        ]);
    }
}
