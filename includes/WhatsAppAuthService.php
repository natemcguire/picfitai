<?php
// WhatsAppAuthService.php - WhatsApp OTP authentication service
declare(strict_types=1);

class WhatsAppAuthService {
    private const OTP_LENGTH = 6;
    private const OTP_EXPIRY_MINUTES = 10;
    private const MAX_ATTEMPTS = 3;
    private const RATE_LIMIT_MINUTES = 5; // Rate limit: 1 OTP per 5 minutes per phone

    /**
     * Generate and send OTP to phone number
     */
    public static function sendOTP(string $phoneNumber): array {
        try {
            $pdo = Database::getInstance();

            // Normalize phone number
            $phoneNumber = self::normalizePhoneNumber($phoneNumber);

            // Check rate limiting
            if (!self::checkRateLimit($phoneNumber)) {
                return [
                    'success' => false,
                    'error' => 'Rate limit exceeded. Please wait before requesting another OTP.'
                ];
            }

            // Generate OTP
            $otpCode = self::generateOTP();
            $expiresAt = date('Y-m-d H:i:s', time() + (self::OTP_EXPIRY_MINUTES * 60));

            // Store OTP in database
            $stmt = $pdo->prepare('
                INSERT INTO whatsapp_otps (phone_number, otp_code, expires_at)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$phoneNumber, $otpCode, $expiresAt]);

            // Send via Twilio WhatsApp
            $twilioService = new TwilioWhatsAppService();
            $sent = $twilioService->sendOTP($phoneNumber, $otpCode);

            if (!$sent) {
                return [
                    'success' => false,
                    'error' => 'Failed to send OTP. Please try again.'
                ];
            }

            Logger::info('OTP sent successfully', [
                'phone' => self::maskPhoneNumber($phoneNumber)
            ]);

            return [
                'success' => true,
                'message' => 'OTP sent to your WhatsApp',
                'expires_in' => self::OTP_EXPIRY_MINUTES
            ];

        } catch (Exception $e) {
            Logger::error('OTP send error', [
                'phone' => self::maskPhoneNumber($phoneNumber),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send OTP. Please try again.'
            ];
        }
    }

    /**
     * Verify OTP and create/login user
     */
    public static function verifyOTP(string $phoneNumber, string $otpCode): array {
        try {
            $pdo = Database::getInstance();
            $phoneNumber = self::normalizePhoneNumber($phoneNumber);

            // Get the most recent valid OTP
            $stmt = $pdo->prepare('
                SELECT id, otp_code, expires_at, attempts, verified
                FROM whatsapp_otps
                WHERE phone_number = ?
                AND expires_at > datetime("now")
                AND verified = 0
                ORDER BY created_at DESC
                LIMIT 1
            ');
            $stmt->execute([$phoneNumber]);
            $otpRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$otpRecord) {
                return [
                    'success' => false,
                    'error' => 'Invalid or expired OTP'
                ];
            }

            // Check attempt limit
            if ($otpRecord['attempts'] >= self::MAX_ATTEMPTS) {
                return [
                    'success' => false,
                    'error' => 'Too many failed attempts. Please request a new OTP.'
                ];
            }

            // Increment attempts
            $stmt = $pdo->prepare('
                UPDATE whatsapp_otps
                SET attempts = attempts + 1
                WHERE id = ?
            ');
            $stmt->execute([$otpRecord['id']]);

            // Verify OTP
            if ($otpRecord['otp_code'] !== $otpCode) {
                return [
                    'success' => false,
                    'error' => 'Invalid OTP code'
                ];
            }

            // Mark OTP as verified
            $stmt = $pdo->prepare('
                UPDATE whatsapp_otps
                SET verified = 1
                WHERE id = ?
            ');
            $stmt->execute([$otpRecord['id']]);

            // Create or get user
            $user = self::createOrGetUser($phoneNumber);

            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'Failed to create user account'
                ];
            }

            // Log user in
            Session::loginUser($user);

            Logger::info('WhatsApp authentication successful', [
                'phone' => self::maskPhoneNumber($phoneNumber),
                'user_id' => $user['id']
            ]);

            return [
                'success' => true,
                'message' => 'Authentication successful',
                'user' => $user,
                'redirect' => '/generate.php'
            ];

        } catch (Exception $e) {
            Logger::error('OTP verification error', [
                'phone' => self::maskPhoneNumber($phoneNumber),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Verification failed. Please try again.'
            ];
        }
    }

    /**
     * Create or get existing user by phone number
     */
    private static function createOrGetUser(string $phoneNumber): ?array {
        $pdo = Database::getInstance();

        // Check if user already exists
        $stmt = $pdo->prepare('SELECT * FROM users WHERE phone_number = ?');
        $stmt->execute([$phoneNumber]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            return $user;
        }

        // Create new user
        $stmt = $pdo->prepare('
            INSERT INTO users (oauth_provider, phone_number, name, credits_remaining, free_credits_used)
            VALUES ("whatsapp", ?, ?, 1, 0)
        ');

        // Generate a default name from phone number
        $defaultName = 'User ' . substr($phoneNumber, -4);

        $stmt->execute([$phoneNumber, $defaultName]);
        $userId = $pdo->lastInsertId();

        // Get the created user
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Check rate limiting (1 OTP per 5 minutes per phone)
     */
    private static function checkRateLimit(string $phoneNumber): bool {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM whatsapp_otps
            WHERE phone_number = ?
            AND created_at > datetime("now", "-' . self::RATE_LIMIT_MINUTES . ' minutes")
        ');
        $stmt->execute([$phoneNumber]);
        $recentCount = (int)$stmt->fetchColumn();

        return $recentCount === 0;
    }

    /**
     * Generate random OTP code
     */
    private static function generateOTP(): string {
        return str_pad((string)random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize phone number to international format
     */
    private static function normalizePhoneNumber(string $phoneNumber): string {
        // Remove all non-digit characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // If it doesn't start with country code, assume US (+1)
        if (!str_starts_with($phoneNumber, '1') && strlen($phoneNumber) === 10) {
            $phoneNumber = '1' . $phoneNumber;
        }

        return '+' . $phoneNumber;
    }

    /**
     * Mask phone number for logging
     */
    private static function maskPhoneNumber(string $phoneNumber): string {
        if (strlen($phoneNumber) > 4) {
            return substr($phoneNumber, 0, 2) . str_repeat('*', strlen($phoneNumber) - 4) . substr($phoneNumber, -2);
        }
        return '***';
    }

    /**
     * Clean up expired OTPs (call this periodically)
     */
    public static function cleanupExpiredOTPs(): void {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('DELETE FROM whatsapp_otps WHERE expires_at < datetime("now")');
            $stmt->execute();

            $deletedCount = $stmt->rowCount();
            if ($deletedCount > 0) {
                Logger::info('Cleaned up expired OTPs', ['count' => $deletedCount]);
            }
        } catch (Exception $e) {
            Logger::error('Failed to cleanup expired OTPs', ['error' => $e->getMessage()]);
        }
    }
}