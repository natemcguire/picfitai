<?php
// includes/Database.php - Database management for PicFit
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

class Database {
    private static ?PDO $pdo = null;
    
    public static function getInstance(): PDO {
        if (self::$pdo === null) {
            self::connect();
        }
        return self::$pdo;
    }
    
    private static function connect(): void {
        $dbPath = Config::get('db_path');
        
        // Ensure data directory exists
        $dataDir = dirname($dbPath);
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        
        try {
            self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 30
            ]);
            
            // Set secure permissions
            @chmod($dbPath, 0600);
            
            // Enable foreign keys and WAL mode for better concurrency
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            
            // Create schema
            self::createSchema();
            
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }
    
    private static function createSchema(): void {
        $schema = [
            // Users table with OAuth support
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                oauth_provider TEXT NOT NULL DEFAULT "google",
                oauth_id TEXT NOT NULL,
                email TEXT NOT NULL,
                name TEXT,
                avatar_url TEXT,
                credits_remaining INTEGER DEFAULT 0,
                free_credits_used INTEGER DEFAULT 0,
                stripe_customer_id TEXT,
                subscription_status TEXT DEFAULT "none",
                subscription_plan TEXT,
                subscription_id TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(oauth_provider, oauth_id),
                UNIQUE(email)
            )',
            
            // Credit transactions
            'CREATE TABLE IF NOT EXISTS credit_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL, -- purchase, debit, refund, bonus
                credits INTEGER NOT NULL,
                description TEXT,
                stripe_session_id TEXT,
                stripe_payment_intent_id TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            
            // Generation requests
            'CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "pending", -- pending, processing, completed, failed
                input_data TEXT, -- JSON of input parameters
                result_url TEXT,
                error_message TEXT,
                processing_time INTEGER, -- seconds
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                completed_at TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            
            // User sessions
            'CREATE TABLE IF NOT EXISTS user_sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            
            // Rate limiting
            'CREATE TABLE IF NOT EXISTS rate_limits (
                id TEXT PRIMARY KEY, -- IP or user_id
                requests INTEGER DEFAULT 1,
                window_start INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            
            // Stripe webhook events (for idempotency)
            'CREATE TABLE IF NOT EXISTS webhook_events (
                id TEXT PRIMARY KEY, -- Stripe event ID
                type TEXT NOT NULL,
                processed_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        ];
        
        foreach ($schema as $sql) {
            self::$pdo->exec($sql);
        }
        
        // Create indexes for performance
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)',
            'CREATE INDEX IF NOT EXISTS idx_users_oauth ON users(oauth_provider, oauth_id)',
            'CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_generations_user ON generations(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_generations_status ON generations(status)',
            'CREATE INDEX IF NOT EXISTS idx_sessions_expires ON user_sessions(expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start)'
        ];
        
        foreach ($indexes as $sql) {
            self::$pdo->exec($sql);
        }
    }
    
    public static function cleanupExpiredSessions(): void {
        $pdo = self::getInstance();
        $pdo->prepare('DELETE FROM user_sessions WHERE expires_at < ?')
            ->execute([time()]);
    }
    
    public static function cleanupRateLimits(): void {
        $pdo = self::getInstance();
        // Clean up rate limit entries older than 1 hour
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
            ->execute([time() - 3600]);
    }
}
