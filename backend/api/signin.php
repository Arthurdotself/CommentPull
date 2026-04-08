<?php
/**
 * POST /backend/api/signin.php
 * Body: { "email": "...", "password": "..." }
 * Returns: { "token": "...", "user": { ... } }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

$data = body();

$email    = trim(strtolower($data['email']    ?? ''));
$password = $data['password'] ?? '';

if (!$email || !$password) {
    json_error(422, 'Email and password are required');
}

// ── Look up user ────────────────────────────────────────────────
$stmt = db()->prepare(
    'SELECT id, email, name, plan, password_hash
     FROM users
     WHERE email = :email
     LIMIT 1'
);
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

// Use a constant-time comparison to avoid timing attacks
if (!$user || !password_verify($password, $user['password_hash'])) {
    // Generic message — don't reveal whether the email exists
    json_error(401, 'Invalid email or password');
}

// ── Create new session ──────────────────────────────────────────
$token     = generate_token();
$expiresAt = date('Y-m-d H:i:s', time() + SESSION_TTL);

db()->prepare(
    'INSERT INTO sessions (user_id, token, expires_at)
     VALUES (:user_id, :token, :expires_at)'
)->execute([
    ':user_id'    => $user['id'],
    ':token'      => $token,
    ':expires_at' => $expiresAt,
]);

setcookie('cp_token', $token, [
    'expires'  => time() + SESSION_TTL,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

json_out([
    'token' => $token,
    'user'  => [
        'id'    => (int) $user['id'],
        'email' => $user['email'],
        'name'  => $user['name'],
        'plan'  => $user['plan'],
    ],
]);
