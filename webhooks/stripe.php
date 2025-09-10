<?php
// webhooks/stripe.php - Secure Stripe webhook handler
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Get the payload and signature
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (empty($payload) || empty($signature)) {
    http_response_code(400);
    exit('Missing payload or signature');
}

try {
    $stripeService = new StripeService();
    $processed = $stripeService->handleWebhook($payload, $signature);
    
    if ($processed) {
        http_response_code(200);
        echo 'OK';
    } else {
        http_response_code(400);
        echo 'Failed to process webhook';
    }
    
} catch (Exception $e) {
    error_log('Stripe webhook error: ' . $e->getMessage());
    http_response_code(400);
    echo 'Webhook error: ' . $e->getMessage();
}
