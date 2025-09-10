<?php
// includes/ErrorHandler.php - Error handling and logging
declare(strict_types=1);

class ErrorHandler {
    
    public static function init(): void {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    public static function handleError(int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $errorData = [
            'type' => 'error',
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
        
        error_log('ERROR: ' . json_encode($errorData));
        
        // In production, don't show errors to users
        if (Config::get('environment', 'production') === 'production') {
            return true; // Don't execute PHP internal error handler
        }
        
        return false; // Execute PHP internal error handler
    }
    
    public static function handleException(Throwable $exception): void {
        $errorData = [
            'type' => 'exception',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
        
        error_log('EXCEPTION: ' . json_encode($errorData));
        
        // Show user-friendly error page
        self::showErrorPage(500, 'Internal Server Error');
    }
    
    public static function handleShutdown(): void {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $errorData = [
                'type' => 'fatal_error',
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'timestamp' => date('Y-m-d H:i:s'),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ];
            
            error_log('FATAL_ERROR: ' . json_encode($errorData));
            
            // Show user-friendly error page
            self::showErrorPage(500, 'Internal Server Error');
        }
    }
    
    public static function showErrorPage(int $code, string $message): void {
        http_response_code($code);
        
        $title = match($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized', 
            403 => 'Forbidden',
            404 => 'Page Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'Error'
        };
        
        $description = match($code) {
            400 => 'The request was invalid or malformed.',
            401 => 'You need to log in to access this page.',
            403 => 'You don\'t have permission to access this resource.',
            404 => 'The page you\'re looking for doesn\'t exist.',
            429 => 'Too many requests. Please try again later.',
            500 => 'Something went wrong on our end. We\'re working to fix it.',
            default => $message
        };
        
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?= htmlspecialchars($title) ?> - PicFit.ai</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                .modern-button {
                    @apply bg-white text-black font-bold border-2 border-black transition-all duration-200;
                }
                .modern-button:hover {
                    @apply bg-black text-white;
                }
                .modern-card {
                    @apply bg-white text-black border-2 border-black;
                }
            </style>
        </head>
        <body class="min-h-screen bg-white text-black flex items-center justify-center p-4">
            <div class="modern-card p-8 max-w-md w-full text-center">
                <div class="text-6xl mb-6">
                    <?= $code === 404 ? '🔍' : ($code === 429 ? '⏱️' : '⚠️') ?>
                </div>
                <h1 class="text-3xl font-black mb-4"><?= htmlspecialchars($title) ?></h1>
                <p class="text-gray-700 mb-6"><?= htmlspecialchars($description) ?></p>
                
                <div class="space-y-3">
                    <a href="/" class="w-full inline-block py-3 modern-button">
                        Go Home
                    </a>
                    <?php if ($code !== 401): ?>
                        <button onclick="history.back()" class="w-full inline-block py-2 border border-gray-300 text-gray-600 hover:bg-gray-100">
                            Go Back
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if ($code === 500): ?>
                    <p class="text-sm text-gray-500 mt-6">
                        Error ID: <?= uniqid() ?>
                    </p>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    public static function logActivity(string $action, array $context = []): void {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'context' => $context
        ];
        
        error_log('ACTIVITY: ' . json_encode($logData));
    }
}
