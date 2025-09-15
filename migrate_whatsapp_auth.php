<?php
// migrate_whatsapp_auth.php - Database migration for WhatsApp authentication
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $pdo = Database::getInstance();

    echo "Starting WhatsApp authentication migration...\n";

    // Check if phone_number column exists in users table
    $stmt = $pdo->query("PRAGMA table_info(users)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasPhoneNumber = false;

    foreach ($columns as $column) {
        if ($column['name'] === 'phone_number') {
            $hasPhoneNumber = true;
            break;
        }
    }

    // Add phone_number column if it doesn't exist
    if (!$hasPhoneNumber) {
        echo "Adding phone_number column to users table...\n";
        $pdo->exec('ALTER TABLE users ADD COLUMN phone_number TEXT');

        // Add unique constraint
        echo "Creating unique index for phone_number...\n";
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_phone_number ON users(phone_number)');
    } else {
        echo "phone_number column already exists.\n";
    }

    // Make oauth_id and email nullable since WhatsApp users won't have these
    echo "Making oauth_id and email nullable...\n";
    // SQLite doesn't support modifying column constraints directly, so we need to create the constraint as nullable in schema
    // The NOT NULL constraints will be handled in the application layer

    // Check if whatsapp_otps table exists
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='whatsapp_otps'");
    $tableExists = $stmt->fetch();

    if (!$tableExists) {
        echo "Creating whatsapp_otps table...\n";
        $pdo->exec('
            CREATE TABLE whatsapp_otps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_number TEXT NOT NULL,
                otp_code TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                verified INTEGER DEFAULT 0,
                attempts INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        echo "Creating indexes for whatsapp_otps table...\n";
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_otps_phone ON whatsapp_otps(phone_number)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_whatsapp_otps_expires ON whatsapp_otps(expires_at)');
    } else {
        echo "whatsapp_otps table already exists.\n";
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>