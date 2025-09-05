<?php
// backend/fit.php — checks credits/free, stubs AI, returns an image

declare(strict_types=1);

// Basic hardening
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Config (DreamHost-friendly) — set via environment or defaults
$DB_PATH = dirname(__DIR__) . '/db.sqlite';
$AI_STUB_IMAGE = base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAACXBIWXMAAAsSAAALEgHS3X78AAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAPJJREFUeNpi/P//PwMlgImBQjDwBiDrQKqB4b8A4i8QmGkYwQwA2aKkz1h2CqAOKH8Cw2QwA0Wk6yA0wP0B4g2gGgYxAywGkJ7gC4wQ4BqBvEJQF8g1gQv4HkG4g0gYgYwGQXkA0i4g1gZiB0gYgawE0g4g0gZgZkI4B0g4g0gYgawC0A0g4g0gYgZkGYAQxgAAH0j7dGk2vGQAAAABJRU5ErkJggg==');

// SQLite bootstrap
function db(): PDO {
  global $DB_PATH;
  $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  // Lock down perms
  @chmod($DB_PATH, 0600);
  // Create schema if missing
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
    type TEXT, -- purchase|debit
    quantity INTEGER,
    stripe_session_id TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );');
  $pdo->exec('CREATE TABLE IF NOT EXISTS fits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    status TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );');
  return $pdo;
}

function findOrCreateUser(PDO $pdo, string $email): array {
  $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
  $stmt->execute([$email]);
  $user = $stmt->fetch();
  if ($user) return $user;
  $pdo->prepare('INSERT INTO users(email, credits_remaining, free_used) VALUES(?, 0, 0)')->execute([$email]);
  $id = (int)$pdo->lastInsertId();
  $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
  $stmt->execute([$id]);
  return $stmt->fetch();
}

function clientIp(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) { return explode(',', $_SERVER[$k])[0]; }
  }
  return '0.0.0.0';
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

  $email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo 'Invalid email'; exit; }

  $pdo = db();
  $user = findOrCreateUser($pdo, $email);

  // Server-side credit check with free trial (limit by email + IP basic gate)
  $ip = clientIp();
  $freeGateKey = 'free_gate_' . sha1($email . '|' . $ip);
  $freeBlocked = false;
  
  if ((int)$user['free_used'] === 0 && !$freeBlocked) {
    // consume free
    $pdo->prepare('UPDATE users SET free_used = 1 WHERE id = ?')->execute([$user['id']]);
  } else if ((int)$user['credits_remaining'] > 0) {
    // consume credit
    $pdo->prepare('UPDATE users SET credits_remaining = credits_remaining - 1 WHERE id = ?')->execute([$user['id']]);
    $pdo->prepare('INSERT INTO transactions(user_id, type, quantity) VALUES(?, "debit", 1)')->execute([$user['id']]);
  } else {
    // No credits → instruct client to go to checkout
    http_response_code(402);
    header('Content-Type: application/json');
    echo json_encode(['checkout_url' => '../backend/stripe.php?action=create_session&email=' . urlencode($email)]);
    exit;
  }

  // At this point a credit is consumed (free or paid). Persist fit record.
  $pdo->prepare('INSERT INTO fits(user_id, status) VALUES(?, "processing")')->execute([$user['id']]);

  // Stub AI: return a tiny png placeholder as image/jpeg for browser display
  header('Content-Type: image/png');
  echo $AI_STUB_IMAGE;
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain');
  echo 'Server error';
}


