<?php
// includes/StripeService.php - Stripe integration service
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';

class StripeService {
    private string $secretKey;
    private string $webhookSecret;
    
    public function __construct() {
        $this->secretKey = Config::get('stripe_secret_key');
        $this->webhookSecret = Config::get('stripe_webhook_secret');
        
        if (empty($this->secretKey)) {
            throw new Exception('Stripe secret key not configured');
        }
    }
    
    public function createCheckoutSession(string $planKey, string $userEmail, int $userId): array {
        $plans = Config::get('stripe_plans');
        if (!isset($plans[$planKey])) {
            throw new Exception('Invalid plan');
        }
        
        $plan = $plans[$planKey];
        
        $payload = [
            'mode' => 'payment',
            'success_url' => Config::get('app_url') . '/success.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => Config::get('app_url') . '/pricing.php',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $plan['price'],
                    'product_data' => [
                        'name' => $plan['name'],
                        'description' => $plan['credits'] . ' AI-generated outfit previews'
                    ]
                ]
            ]],
            'metadata' => [
                'user_id' => (string)$userId,
                'plan_key' => $planKey,
                'credits' => (string)$plan['credits']
            ],
            'customer_email' => $userEmail,
            'payment_intent_data' => [
                'metadata' => [
                    'user_id' => (string)$userId,
                    'plan_key' => $planKey,
                    'credits' => (string)$plan['credits']
                ]
            ]
        ];
        
        $response = $this->makeStripeRequest('POST', 'checkout/sessions', $payload);
        
        if (!$response || empty($response['id'])) {
            throw new Exception('Failed to create checkout session');
        }
        
        return $response;
    }
    
    public function createCustomer(string $email, string $name): array {
        $payload = [
            'email' => $email,
            'name' => $name
        ];
        
        $response = $this->makeStripeRequest('POST', 'customers', $payload);
        
        if (!$response || empty($response['id'])) {
            throw new Exception('Failed to create customer');
        }
        
        return $response;
    }
    
    public function handleWebhook(string $payload, string $signature): bool {
        if (!$this->verifyWebhookSignature($payload, $signature)) {
            throw new Exception('Invalid webhook signature');
        }
        
        $event = json_decode($payload, true);
        if (!$event || empty($event['type'])) {
            throw new Exception('Invalid webhook payload');
        }
        
        // Check for duplicate events
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id FROM webhook_events WHERE id = ?');
        $stmt->execute([$event['id']]);
        if ($stmt->fetch()) {
            return true; // Already processed
        }
        
        // Process the event
        $processed = false;
        switch ($event['type']) {
            case 'checkout.session.completed':
                $processed = $this->handleCheckoutCompleted($event['data']['object']);
                break;
                
            case 'payment_intent.succeeded':
                $processed = $this->handlePaymentSucceeded($event['data']['object']);
                break;
                
            default:
                // Unknown event type, but not an error
                $processed = true;
                break;
        }
        
        if ($processed) {
            // Record that we processed this event
            $pdo->prepare('INSERT INTO webhook_events (id, type) VALUES (?, ?)')
                ->execute([$event['id'], $event['type']]);
        }
        
        return $processed;
    }
    
    private function handleCheckoutCompleted(array $session): bool {
        $userId = (int)($session['metadata']['user_id'] ?? 0);
        $credits = (int)($session['metadata']['credits'] ?? 0);
        $planKey = $session['metadata']['plan_key'] ?? '';
        
        if (!$userId || !$credits) {
            error_log('Invalid checkout session metadata: ' . json_encode($session['metadata']));
            return false;
        }
        
        $pdo = Database::getInstance();
        
        try {
            $pdo->beginTransaction();
            
            // Add credits to user account
            $pdo->prepare('
                UPDATE users 
                SET credits_remaining = credits_remaining + ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$credits, $userId]);
            
            // Record the transaction
            $pdo->prepare('
                INSERT INTO credit_transactions 
                (user_id, type, credits, description, stripe_session_id)
                VALUES (?, "purchase", ?, ?, ?)
            ')->execute([
                $userId,
                $credits,
                "Purchase: $planKey plan",
                $session['id']
            ]);
            
            $pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Failed to process checkout completion: ' . $e->getMessage());
            return false;
        }
    }
    
    private function handlePaymentSucceeded(array $paymentIntent): bool {
        // Additional handling if needed
        return true;
    }
    
    private function verifyWebhookSignature(string $payload, string $signature): bool {
        if (empty($this->webhookSecret)) {
            return false;
        }
        
        $elements = explode(',', $signature);
        $signatureData = [];
        
        foreach ($elements as $element) {
            $parts = explode('=', $element, 2);
            if (count($parts) === 2) {
                $signatureData[$parts[0]] = $parts[1];
            }
        }
        
        if (!isset($signatureData['t']) || !isset($signatureData['v1'])) {
            return false;
        }
        
        $timestamp = $signatureData['t'];
        $signature = $signatureData['v1'];
        
        // Check timestamp (prevent replay attacks)
        if (abs(time() - (int)$timestamp) > 300) { // 5 minutes tolerance
            return false;
        }
        
        // Verify signature
        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $this->webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    private function makeStripeRequest(string $method, string $endpoint, array $data = []): ?array {
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log('Stripe API curl error: ' . $error);
            return null;
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("Stripe API error: HTTP $httpCode - $response");
            return null;
        }
        
        return json_decode($response, true);
    }
    
    public static function debitUserCredits(int $userId, int $credits = 1, string $description = 'AI generation'): bool {
        $pdo = Database::getInstance();
        
        try {
            $pdo->beginTransaction();
            
            // Check if user has enough credits
            $stmt = $pdo->prepare('SELECT credits_remaining FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || $user['credits_remaining'] < $credits) {
                $pdo->rollBack();
                return false;
            }
            
            // Debit credits
            $pdo->prepare('
                UPDATE users 
                SET credits_remaining = credits_remaining - ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$credits, $userId]);
            
            // Record transaction
            $pdo->prepare('
                INSERT INTO credit_transactions (user_id, type, credits, description)
                VALUES (?, "debit", ?, ?)
            ')->execute([$userId, $credits, $description]);
            
            $pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Failed to debit credits: ' . $e->getMessage());
            return false;
        }
    }
    
    public static function addUserCredits(int $userId, int $credits, string $description): bool {
        $pdo = Database::getInstance();
        
        try {
            $pdo->beginTransaction();
            
            // Add credits
            $pdo->prepare('
                UPDATE users 
                SET credits_remaining = credits_remaining + ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$credits, $userId]);
            
            // Record transaction
            $pdo->prepare('
                INSERT INTO credit_transactions (user_id, type, credits, description)
                VALUES (?, "credit", ?, ?)
            ')->execute([$userId, $credits, $description]);
            
            $pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Failed to add credits: ' . $e->getMessage());
            return false;
        }
    }
}
