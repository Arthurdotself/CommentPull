<?php
/**
 * POST /backend/api/signup.php
 * Body: { "email": "...", "password": "...", "name": "..." }
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
$name     = trim($data['name'] ?? '');

// ── Validation ──────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error(422, 'Invalid email address');
}
if (strlen($password) < 8) {
    json_error(422, 'Password must be at least 8 characters');
}
if ($name === '') {
    json_error(422, 'Name is required');
}

// ── Check for duplicate email ───────────────────────────────────
$check = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$check->execute([':email' => $email]);
if ($check->fetch()) {
    json_error(409, 'An account with that email already exists');
}

// ── Create user ─────────────────────────────────────────────────
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$insert = db()->prepare(
    'INSERT INTO users (email, password_hash, name)
     VALUES (:email, :hash, :name)'
);
$insert->execute([
    ':email' => $email,
    ':hash'  => $hash,
    ':name'  => $name,
]);
$userId = (int) db()->lastInsertId();

// ── Create session token ────────────────────────────────────────
$token     = generate_token();
$expiresAt = date('Y-m-d H:i:s', time() + SESSION_TTL);

db()->prepare(
    'INSERT INTO sessions (user_id, token, expires_at)
     VALUES (:user_id, :token, :expires_at)'
)->execute([
    ':user_id'    => $userId,
    ':token'      => $token,
    ':expires_at' => $expiresAt,
]);

// Set HTTP-only cookie as well (browser convenience)
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
        'id'    => $userId,
        'email' => $email,
        'name'  => $name,
    ],
], 201);
