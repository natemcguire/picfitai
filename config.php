<?php
// config.php - Centralized configuration for DreamHost shared hosting
declare(strict_types=1);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: same-origin');

// Error reporting (off in production)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

class Config {
    private static $config = null;
    
    public static function get(string $key, $default = null) {
        if (self::$config === null) {
            self::load();
        }
        return self::$config[$key] ?? $default;
    }
    
    private static function load() {
        // Load from environment first (DreamHost panel)
        self::$config = [
            // Database
            'db_path' => dirname(__FILE__) . '/data/app.sqlite',
            
            // OAuth (Google)
            'google_client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
            'google_client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
            'oauth_redirect_uri' => self::getBaseUrl() . '/auth/callback.php',
            
            // Stripe
            'stripe_secret_key' => getenv('STRIPE_SECRET_KEY') ?: '',
            'stripe_publishable_key' => getenv('STRIPE_PUBLISHABLE_KEY') ?: '',
            'stripe_webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: '',
            
            // AI Generation
            'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
            'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
            
            // App settings
            'app_name' => 'PicFit.ai',
            'app_url' => self::getBaseUrl(),
            'session_lifetime' => 86400 * 30, // 30 days
            'max_file_size' => 10 * 1024 * 1024, // 10MB
            'max_standing_photos' => 5,
            'free_credits_per_user' => 100,
            
            // Plans (credits and prices in cents)
            'stripe_plans' => [
                'starter' => ['credits' => 10, 'price' => 900, 'name' => '10 Credits - Starter'],
                'popular' => ['credits' => 50, 'price' => 2900, 'name' => '50 Credits - Popular'],
                'pro' => ['credits' => 250, 'price' => 9900, 'name' => '250 Credits - Pro']
            ]
        ];
        
        // Load from .env file if it exists (for local development)
        $envFile = dirname(__FILE__) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && !str_starts_with($line, '#')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    
                    // Map env vars to config keys
                    $configKey = match($key) {
                        'GOOGLE_CLIENT_ID' => 'google_client_id',
                        'GOOGLE_CLIENT_SECRET' => 'google_client_secret',
                        'STRIPE_SECRET_KEY' => 'stripe_secret_key',
                        'STRIPE_PUBLISHABLE_KEY' => 'stripe_publishable_key',
                        'STRIPE_WEBHOOK_SECRET' => 'stripe_webhook_secret',
                        'GEMINI_API_KEY' => 'gemini_api_key',
                        'OPENAI_API_KEY' => 'openai_api_key',
                        default => null
                    };
                    
                    if ($configKey) {
                        self::$config[$configKey] = $value;
                    }
                }
            }
        }
    }
    
    private static function getBaseUrl(): string {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        return $protocol . '://' . $host;
    }
    
    public static function isConfigured(): array {
        $required = [
            'google_client_id' => 'Google OAuth Client ID',
            'google_client_secret' => 'Google OAuth Client Secret',
            'stripe_secret_key' => 'Stripe Secret Key',
            'stripe_publishable_key' => 'Stripe Publishable Key',
            'stripe_webhook_secret' => 'Stripe Webhook Secret'
        ];
        
        $missing = [];
        foreach ($required as $key => $name) {
            if (empty(self::get($key))) {
                $missing[] = $name;
            }
        }
        
        return $missing;
    }
}

// Create data directory if it doesn't exist
$dataDir = dirname(__FILE__) . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

// Ensure data directory is protected
$htaccessFile = $dataDir . '/.htaccess';
if (!file_exists($htaccessFile)) {
    file_put_contents($htaccessFile, "Deny from all\n");
}
