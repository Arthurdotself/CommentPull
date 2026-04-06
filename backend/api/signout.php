<?php
/**
 * POST /backend/api/signout.php
 * Deletes the current session (bearer token or cookie).
 * Returns: { "ok": true }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

$token = bearer_token();

if ($token) {
    db()->prepare('DELETE FROM sessions WHERE token = :token')
        ->execute([':token' => $token]);
}

// Clear cookie
setcookie('cp_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

json_out(['ok' => true]);
