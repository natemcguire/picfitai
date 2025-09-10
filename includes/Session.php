<?php
// includes/Session.php - Secure session management
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class Session {
    private static bool $started = false;
    private static ?array $user = null;
    
    public static function start(): void {
        if (self::$started) return;
        
        // Secure session configuration
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Lax');
        
        session_start();
        self::$started = true;
        
        // Clean up expired sessions periodically
        if (rand(1, 100) === 1) {
            Database::cleanupExpiredSessions();
        }
    }
    
    public static function login(array $userData): string {
        self::start();
        
        $pdo = Database::getInstance();
        
        // Create or update user
        $user = self::createOrUpdateUser($userData);
        
        // Generate secure session ID
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = time() + Config::get('session_lifetime', 86400 * 30);
        
        // Store session in database
        $pdo->prepare('INSERT INTO user_sessions (id, user_id, expires_at) VALUES (?, ?, ?)')
            ->execute([$sessionId, $user['id'], $expiresAt]);
        
        // Set session data
        $_SESSION['session_id'] = $sessionId;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['expires_at'] = $expiresAt;
        
        self::$user = $user;
        
        return $sessionId;
    }
    
    public static function logout(): void {
        self::start();
        
        if (isset($_SESSION['session_id'])) {
            $pdo = Database::getInstance();
            $pdo->prepare('DELETE FROM user_sessions WHERE id = ?')
                ->execute([$_SESSION['session_id']]);
        }
        
        $_SESSION = [];
        session_destroy();
        self::$user = null;
    }
    
    public static function getCurrentUser(): ?array {
        if (self::$user !== null) {
            return self::$user;
        }
        
        self::start();
        
        if (!isset($_SESSION['session_id']) || !isset($_SESSION['user_id'])) {
            return null;
        }
        
        // Check if session is expired
        if (isset($_SESSION['expires_at']) && $_SESSION['expires_at'] < time()) {
            self::logout();
            return null;
        }
        
        $pdo = Database::getInstance();
        
        // Verify session exists and is valid
        $stmt = $pdo->prepare('
            SELECT s.*, u.* 
            FROM user_sessions s 
            JOIN users u ON s.user_id = u.id 
            WHERE s.id = ? AND s.expires_at > ?
        ');
        $stmt->execute([$_SESSION['session_id'], time()]);
        $sessionData = $stmt->fetch();
        
        if (!$sessionData) {
            self::logout();
            return null;
        }
        
        // Cache user data
        self::$user = [
            'id' => $sessionData['user_id'],
            'oauth_provider' => $sessionData['oauth_provider'],
            'oauth_id' => $sessionData['oauth_id'],
            'email' => $sessionData['email'],
            'name' => $sessionData['name'],
            'avatar_url' => $sessionData['avatar_url'],
            'credits_remaining' => (int)$sessionData['credits_remaining'],
            'free_credits_used' => (int)$sessionData['free_credits_used'],
            'stripe_customer_id' => $sessionData['stripe_customer_id'],
            'subscription_status' => $sessionData['subscription_status'],
            'subscription_plan' => $sessionData['subscription_plan'],
            'created_at' => $sessionData['created_at']
        ];
        
        return self::$user;
    }
    
    public static function isLoggedIn(): bool {
        return self::getCurrentUser() !== null;
    }
    
    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: /auth/login.php');
            exit;
        }
    }
    
    public static function generateCSRFToken(): string {
        self::start();
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    public static function validateCSRFToken(string $token): bool {
        self::start();
        
        return isset($_SESSION['csrf_token']) && 
               hash_equals($_SESSION['csrf_token'], $token);
    }
    
    private static function createOrUpdateUser(array $userData): array {
        $pdo = Database::getInstance();
        
        // Try to find existing user
        $stmt = $pdo->prepare('
            SELECT * FROM users 
            WHERE oauth_provider = ? AND oauth_id = ?
        ');
        $stmt->execute([$userData['provider'], $userData['id']]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Update existing user
            $pdo->prepare('
                UPDATE users 
                SET name = ?, avatar_url = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([
                $userData['name'],
                $userData['avatar_url'],
                $user['id']
            ]);
            
            // Refresh user data
            $stmt->execute([$userData['provider'], $userData['id']]);
            return $stmt->fetch();
        } else {
            // Create new user
            $freeCredits = Config::get('free_credits_per_user', 1);
            
            $pdo->prepare('
                INSERT INTO users (
                    oauth_provider, oauth_id, email, name, avatar_url, 
                    credits_remaining, free_credits_used
                ) VALUES (?, ?, ?, ?, ?, ?, 0)
            ')->execute([
                $userData['provider'],
                $userData['id'],
                $userData['email'],
                $userData['name'],
                $userData['avatar_url'],
                $freeCredits
            ]);
            
            $userId = $pdo->lastInsertId();
            
            // Log the free credit bonus
            $pdo->prepare('
                INSERT INTO credit_transactions (user_id, type, credits, description)
                VALUES (?, "bonus", ?, "Welcome bonus")
            ')->execute([$userId, $freeCredits]);
            
            // Return new user
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            return $stmt->fetch();
        }
    }
    
    public static function refreshUserData(): void {
        if (self::$user) {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([self::$user['id']]);
            $userData = $stmt->fetch();
            
            if ($userData) {
                self::$user = [
                    'id' => $userData['id'],
                    'oauth_provider' => $userData['oauth_provider'],
                    'oauth_id' => $userData['oauth_id'],
                    'email' => $userData['email'],
                    'name' => $userData['name'],
                    'avatar_url' => $userData['avatar_url'],
                    'credits_remaining' => (int)$userData['credits_remaining'],
                    'free_credits_used' => (int)$userData['free_credits_used'],
                    'stripe_customer_id' => $userData['stripe_customer_id'],
                    'subscription_status' => $userData['subscription_status'],
                    'subscription_plan' => $userData['subscription_plan'],
                    'created_at' => $userData['created_at']
                ];
            }
        }
    }
}
