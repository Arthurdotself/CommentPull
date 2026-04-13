<?php
/**
 * POST /backend/api/meta-disconnect.php
 * Clears the stored Meta/Facebook token.
 * Works for both logged-in and anonymous users.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

// ── Clear session token (always) ──────────────────────────────────
unset($_SESSION['meta_token'], $_SESSION['meta_token_expires']);

// ── Clear DB token (logged-in users only) ─────────────────────────
$user = try_auth();
if ($user) {
    db()->prepare(
        'UPDATE users SET meta_access_token = NULL, meta_token_expires = NULL WHERE id = :id'
    )->execute([':id' => (int) $user['id']]);
}

json_out(['ok' => true]);
