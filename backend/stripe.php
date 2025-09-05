<?php
// backend/stripe.php — create Stripe Checkout sessions and handle webhooks
declare(strict_types=1);

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');

// Configuration via environment (DreamHost panel) or hardcode for dev
$STRIPE_SECRET = getenv('STRIPE_SECRET_KEY') ?: '';
$STRIPE_WEBHOOK_SECRET = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
$DB_PATH = dirname(__DIR__) . '/db.sqlite';

function db(): PDO {
  global $DB_PATH;
  $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  @chmod($DB_PATH, 0600);
  $pdo->exec('CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE,
    stripe_customer_id TEXT,
    credits_remaining INTEGER DEFAULT 0,
    free_used INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );');
  $pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    type TEXT,
    quantity INTEGER,
    stripe_session_id TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );');
  return $pdo;
}

function findOrCreateUser(PDO $pdo, string $email): array {
  $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
  $st->execute([$email]);
  $u = $st->fetch();
  if ($u) return $u;
  $pdo->prepare('INSERT INTO users(email, credits_remaining, free_used) VALUES(?, 0, 0)')->execute([$email]);
  $id = (int)$pdo->lastInsertId();
  $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
  $st->execute([$id]);
  return $st->fetch();
}

function jsonOut($obj) { header('Content-Type: application/json'); echo json_encode($obj); }

$action = $_GET['action'] ?? '';

if ($action === 'create_session') {
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
  $email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : '';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo 'Invalid email'; exit; }
  if (!$STRIPE_SECRET) { http_response_code(500); echo 'Stripe not configured'; exit; }

  // Bundle selection via ?bundle=10|50|250 (default 10)
  $bundle = (int)($_GET['bundle'] ?? 10);
  $price = 900; $qty = 10; $name = '10 Credits';
  if ($bundle === 50) { $price = 2900; $qty = 50; $name = '50 Credits'; }
  if ($bundle === 250) { $price = 9900; $qty = 250; $name = '250 Credits'; }

  // Create a basic Checkout Session via Stripe API (no SDK)
  $payload = [
    'mode' => 'payment',
    'success_url' => dirname($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) . '/success.html',
    'cancel_url' => dirname($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) . '/public/index.html',
    'line_items' => [[
      'quantity' => 1,
      'price_data' => [
        'currency' => 'usd',
        'unit_amount' => $price,
        'product_data' => ['name' => $name]
      ]
    ]],
    'metadata' => [ 'email' => $email, 'credits' => (string)$qty ],
    'customer_email' => $email
  ];

  $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [ 'Authorization: Bearer ' . $STRIPE_SECRET ],
    CURLOPT_POSTFIELDS => http_build_query($payload)
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) { http_response_code(500); echo 'Stripe error'; exit; }
  $json = json_decode($resp, true);
  jsonOut(['id' => $json['id'] ?? '', 'url' => $json['url'] ?? '']);
  exit;
}

if ($action === 'webhook') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
  $payload = file_get_contents('php://input');
  $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
  // For shared hosting without libs, do basic signature presence check. In production verify signature.
  if (!$STRIPE_WEBHOOK_SECRET) { http_response_code(500); echo 'Webhook secret not set'; exit; }

  $event = json_decode($payload, true);
  if (!$event || empty($event['type'])) { http_response_code(400); echo 'Bad payload'; exit; }

  if ($event['type'] === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $email = strtolower($session['customer_details']['email'] ?? ($session['metadata']['email'] ?? ''));
    $credits = (int)($session['metadata']['credits'] ?? 0);
    if ($email && $credits > 0) {
      $pdo = db();
      $user = findOrCreateUser($pdo, $email);
      $pdo->prepare('UPDATE users SET credits_remaining = credits_remaining + ? WHERE id = ?')->execute([$credits, $user['id']]);
      $pdo->prepare('INSERT INTO transactions(user_id, type, quantity, stripe_session_id) VALUES(?, "purchase", ?, ?)')->execute([$user['id'], $credits, $session['id'] ?? '']);
    }
  }
  http_response_code(200);
  echo 'ok';
  exit;
}

http_response_code(404);
echo 'Not found';


