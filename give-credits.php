<?php
// give-credits.php - Give all users 100 credits for development
require_once 'bootstrap.php';

echo "🎁 Giving all users 100 credits for development...\n";

$pdo = Database::getInstance();

try {
    // Get all users
    $stmt = $pdo->query('SELECT id, email, credits_remaining FROM users');
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "❌ No users found in database.\n";
        exit(1);
    }
    
    echo "👥 Found " . count($users) . " users:\n";
    
    $pdo->beginTransaction();
    
    foreach ($users as $user) {
        $newCredits = 100;
        $oldCredits = $user['credits_remaining'];
        
        // Update user credits
        $updateStmt = $pdo->prepare('
            UPDATE users 
            SET credits_remaining = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $updateStmt->execute([$newCredits, $user['id']]);
        
        // Record transaction
        $transactionStmt = $pdo->prepare('
            INSERT INTO credit_transactions (user_id, type, credits, description)
            VALUES (?, "credit", ?, ?)
        ');
        $creditsAdded = $newCredits - $oldCredits;
        $transactionStmt->execute([
            $user['id'], 
            $creditsAdded, 
            "Development credits - updated from $oldCredits to $newCredits"
        ]);
        
        echo "  ✅ {$user['email']}: {$oldCredits} → {$newCredits} credits (+{$creditsAdded})\n";
    }
    
    $pdo->commit();
    echo "\n🎉 Successfully updated all users with 100 credits!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
