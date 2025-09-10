<?php
// includes/Security.php - Security utilities and rate limiting
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class Security {
    
    public static function rateLimit(string $key, int $maxRequests = 10, int $windowSeconds = 3600): bool {
        $pdo = Database::getInstance();
        $windowStart = time() - $windowSeconds;
        
        // Clean up old entries first
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
            ->execute([$windowStart]);
        
        // Get current count for this key
        $stmt = $pdo->prepare('SELECT requests FROM rate_limits WHERE id = ? AND window_start >= ?');
        $stmt->execute([$key, $windowStart]);
        $current = $stmt->fetch();
        
        if ($current) {
            if ($current['requests'] >= $maxRequests) {
                return false; // Rate limited
            }
            
            // Increment counter
            $pdo->prepare('UPDATE rate_limits SET requests = requests + 1 WHERE id = ?')
                ->execute([$key]);
        } else {
            // Create new entry
            $pdo->prepare('INSERT INTO rate_limits (id, requests, window_start) VALUES (?, 1, ?)')
                ->execute([$key, time()]);
        }
        
        return true;
    }
    
    public static function getClientIP(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancers
            'HTTP_X_REAL_IP',           // Nginx
            'HTTP_CLIENT_IP',           // Proxy
            'REMOTE_ADDR'               // Standard
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    public static function sanitizeInput(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function isSecureRequest(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
    
    public static function requireSecure(): void {
        if (!self::isSecureRequest() && php_sapi_name() !== 'cli-server') {
            header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
    
    public static function preventClickjacking(): void {
        header('X-Frame-Options: DENY');
    }
    
    public static function preventMimeSniffing(): void {
        header('X-Content-Type-Options: nosniff');
    }
    
    public static function enableXSSProtection(): void {
        header('X-XSS-Protection: 1; mode=block');
    }
    
    public static function setSecurityHeaders(): void {
        self::preventClickjacking();
        self::preventMimeSniffing();
        self::enableXSSProtection();
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        if (self::isSecureRequest()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    
    public static function checkUserRateLimit(int $userId): bool {
        return self::rateLimit("user_$userId", 20, 3600); // 20 requests per hour per user
    }
    
    public static function checkIPRateLimit(): bool {
        $ip = self::getClientIP();
        return self::rateLimit("ip_$ip", 100, 3600); // 100 requests per hour per IP
    }
    
    public static function checkGenerationRateLimit(int $userId): bool {
        return self::rateLimit("gen_$userId", 5, 300); // 5 generations per 5 minutes per user
    }
    
    public static function logSecurityEvent(string $event, array $context = []): void {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => self::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'context' => $context
        ];
        
        error_log('SECURITY: ' . json_encode($logData));
    }
}
