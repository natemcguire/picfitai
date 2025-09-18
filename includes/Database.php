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
                oauth_id TEXT,
                email TEXT,
                phone_number TEXT,
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
                UNIQUE(email),
                UNIQUE(phone_number)
            )',

            // WhatsApp OTP authentication
            'CREATE TABLE IF NOT EXISTS whatsapp_otps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_number TEXT NOT NULL,
                otp_code TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                verified INTEGER DEFAULT 0,
                attempts INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
                input_hash TEXT, -- Hash for cache lookup
                result_url TEXT,
                error_message TEXT,
                processing_time INTEGER, -- seconds
                is_public INTEGER DEFAULT 1, -- 1 for public (0.5 credits), 0 for private (1 credit)
                share_token TEXT UNIQUE, -- unique token for sharing public photos
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
            )',

            // Background jobs
            'CREATE TABLE IF NOT EXISTS background_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                job_type TEXT NOT NULL,
                job_data TEXT, -- JSON data
                status TEXT NOT NULL DEFAULT "queued", -- queued, processing, completed, failed
                progress INTEGER DEFAULT 0, -- Progress percentage 0-100
                progress_stage TEXT, -- UPLOADED, QUEUED, PROCESSING, POSTPROCESSING, COMPLETE
                input_hash TEXT, -- Hash of input for idempotency
                result_data TEXT, -- JSON result
                error_message TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                started_at TEXT,
                completed_at TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',

            // User photos
            'CREATE TABLE IF NOT EXISTS user_photos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                original_name TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                mime_type TEXT NOT NULL,
                is_primary INTEGER DEFAULT 0, -- 1 if this is the primary photo
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',

            // Photo ratings
            'CREATE TABLE IF NOT EXISTS photo_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                generation_id INTEGER NOT NULL,
                rating INTEGER NOT NULL, -- 1 for thumbs up, -1 for thumbs down
                ip_address TEXT, -- For anonymous rating tracking
                user_agent TEXT, -- Additional fingerprinting
                rated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (generation_id) REFERENCES generations(id) ON DELETE CASCADE,
                UNIQUE(generation_id, ip_address) -- One rating per IP per photo
            )',

            // Ad generation tables
            'CREATE TABLE IF NOT EXISTS ad_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                campaign_name TEXT NOT NULL,
                style_guide TEXT, -- JSON
                status TEXT DEFAULT "processing",
                error_message TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                completed_at TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',

            // Ad concepts table
            'CREATE TABLE IF NOT EXISTS ad_concepts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                concept_name TEXT NOT NULL,
                concept_data TEXT, -- JSON with colors, style, mood, etc
                image_url TEXT,
                prompt_used TEXT,
                uploaded_assets TEXT, -- JSON array of asset paths
                status TEXT DEFAULT "generating",
                is_archived INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',

            'CREATE TABLE IF NOT EXISTS ad_generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                ad_type TEXT NOT NULL, -- facebook_feed, instagram_story, etc
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                image_url TEXT,
                with_text_url TEXT,
                prompt_used TEXT,
                status TEXT DEFAULT "processing",
                processing_time INTEGER,
                is_private INTEGER DEFAULT 1, -- 1 for private (default), 0 for public
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (campaign_id) REFERENCES ad_campaigns(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',

            // User ad folders tracking
            'CREATE TABLE IF NOT EXISTS user_ad_folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                folder_path TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_cleanup TEXT,
                total_files INTEGER DEFAULT 0,
                total_size_bytes INTEGER DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',

            // Saved concepts for "Save for Later" functionality
            'CREATE TABLE IF NOT EXISTS saved_concepts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                brand_name TEXT,
                style_guide TEXT, -- JSON
                image_path TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        ];
        
        foreach ($schema as $sql) {
            self::$pdo->exec($sql);
        }
        
        // Create indexes for performance
        $indexes = [
            // Existing indexes
            'CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)',
            'CREATE INDEX IF NOT EXISTS idx_users_oauth ON users(oauth_provider, oauth_id)',
            'CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_generations_user ON generations(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_generations_status ON generations(status)',
            'CREATE INDEX IF NOT EXISTS idx_generations_public ON generations(is_public)',
            'CREATE INDEX IF NOT EXISTS idx_generations_share_token ON generations(share_token)',
            'CREATE INDEX IF NOT EXISTS idx_generations_hash ON generations(input_hash)',
            'CREATE INDEX IF NOT EXISTS idx_sessions_expires ON user_sessions(expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_users_phone ON users(phone_number)',
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_otps_phone ON whatsapp_otps(phone_number)',
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_otps_expires ON whatsapp_otps(expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start)',
            'CREATE INDEX IF NOT EXISTS idx_background_jobs_user ON background_jobs(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_background_jobs_status ON background_jobs(status)',
            'CREATE INDEX IF NOT EXISTS idx_background_jobs_job_id ON background_jobs(job_id)',
            'CREATE INDEX IF NOT EXISTS idx_background_jobs_hash ON background_jobs(input_hash)',
            'CREATE INDEX IF NOT EXISTS idx_user_photos_user ON user_photos(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_user_photos_primary ON user_photos(user_id, is_primary)',

            // New performance indexes for optimized queries
            'CREATE INDEX IF NOT EXISTS idx_generations_share_public ON generations(share_token, status, is_public)',
            'CREATE INDEX IF NOT EXISTS idx_generations_public_nav ON generations(is_public, status, share_token, id)',
            'CREATE INDEX IF NOT EXISTS idx_photo_ratings_generation ON photo_ratings(generation_id)',
            'CREATE INDEX IF NOT EXISTS idx_photo_ratings_ip ON photo_ratings(generation_id, ip_address)',
            'CREATE INDEX IF NOT EXISTS idx_generations_completed_public ON generations(status, is_public, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_background_jobs_type_status ON background_jobs(job_type, status, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_generations_cache_lookup ON generations(input_hash, status, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_generations_base_name ON generations(base_name)',

            // Composite indexes for gallery queries
            'CREATE INDEX IF NOT EXISTS idx_generations_gallery ON generations(status, is_public, share_token, id)',
            'CREATE INDEX IF NOT EXISTS idx_photo_ratings_agg ON photo_ratings(generation_id, rating)',

            // Indexes for cleanup operations
            'CREATE INDEX IF NOT EXISTS idx_background_jobs_cleanup ON background_jobs(status, started_at)',
            'CREATE INDEX IF NOT EXISTS idx_generations_cleanup ON generations(status, created_at)',

            // Ad generation indexes
            'CREATE INDEX IF NOT EXISTS idx_ad_campaigns_user ON ad_campaigns(user_id, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ad_campaigns_status ON ad_campaigns(status)',
            'CREATE INDEX IF NOT EXISTS idx_ad_generations_campaign ON ad_generations(campaign_id)',
            'CREATE INDEX IF NOT EXISTS idx_ad_generations_user ON ad_generations(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_ad_generations_private ON ad_generations(is_private)',
            'CREATE INDEX IF NOT EXISTS idx_user_ad_folders_user ON user_ad_folders(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_user_ad_folders_cleanup ON user_ad_folders(last_cleanup)',

            // Ad concepts indexes
            'CREATE INDEX IF NOT EXISTS idx_ad_concepts_user ON ad_concepts(user_id, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_saved_concepts_user ON saved_concepts(user_id, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ad_concepts_archived ON ad_concepts(is_archived)',
            'CREATE INDEX IF NOT EXISTS idx_ad_concepts_status ON ad_concepts(status)'
        ];
        
        foreach ($indexes as $sql) {
            try {
                self::$pdo->exec($sql);
            } catch (PDOException $e) {
                // Ignore index creation errors (columns might not exist yet)
                // They will be created by migration script
                error_log('Index creation skipped: ' . $e->getMessage());
            }
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

    public static function deductCredit(int $userId, bool $isPublic = true): void {
        $pdo = self::getInstance();
        $creditCost = $isPublic ? 0.5 : 1.0;

        $pdo->beginTransaction();
        try {
            // Deduct credit from user
            $pdo->prepare('UPDATE users SET credits_remaining = credits_remaining - ? WHERE id = ? AND credits_remaining >= ?')
                ->execute([$creditCost, $userId, $creditCost]);

            // Record the transaction
            $description = $isPublic ? "Public AI generation (0.5 credits)" : "Private AI generation (1 credit)";
            $pdo->prepare('INSERT INTO credit_transactions (user_id, type, credits, description) VALUES (?, "debit", ?, ?)')
                ->execute([$userId, -$creditCost, $description]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
    }
}
