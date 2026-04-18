<?php
/**
 * GET /backend/api/meta-token.php
 * Returns the stored Meta/Facebook access token for the current visitor.
 *
 * - Logged-in users: token loaded from DB (persists across sessions).
 * - Anonymous users: token loaded from PHP session (lost when browser session ends).
 *
 * Response:
 *   { "connected": false }
 *   { "connected": true, "expires_at": "...", "saved": true|false }
 *     saved=true  → stored in DB (logged-in user)
 *     saved=false → session only (anonymous user)
 * The access token is never returned to the client.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Method not allowed');
}

// ── Try logged-in user first ──────────────────────────────────────
$user = try_auth();

if ($user) {
    $stmt = db()->prepare(
        'SELECT meta_access_token, meta_token_expires FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int) $user['id']]);
    $row = $stmt->fetch();

    if ($row && $row['meta_access_token'] && $row['meta_token_expires']) {
        // Check not expired
        if (strtotime($row['meta_token_expires']) > time()) {
            try {
                decrypt_token($row['meta_access_token']); // verify token is valid/decryptable
                json_out([
                    'connected'  => true,
                    'expires_at' => $row['meta_token_expires'],
                    'saved'      => true,
                ]);
            } catch (Throwable $e) {
                // Decryption failed — treat as not connected
            }
        }
    }
}

// ── Fall back to PHP session (anonymous or DB miss) ───────────────
$sessionToken   = $_SESSION['meta_token'] ?? null;
$sessionExpires = $_SESSION['meta_token_expires'] ?? null;

if ($sessionToken && $sessionExpires && strtotime($sessionExpires) > time()) {
    json_out([
        'connected'  => true,
        'expires_at' => $sessionExpires,
        'saved'      => false,
    ]);
}

json_out(['connected' => false]);
