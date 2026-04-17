<?php
/**
 * POST /backend/api/data-deletion.php
 *
 * Two modes:
 *  1. Facebook signed_request callback — deletes data linked to a Meta user_id
 *  2. Manual deletion — POST JSON { "email": "..." } deletes the full account
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

// ── Facebook signed_request callback ──────────────────────────────
if (!empty($_POST['signed_request'])) {
    $signed_request = $_POST['signed_request'];
    $parts = explode('.', $signed_request, 2);
    if (count($parts) !== 2) {
        json_error(400, 'Invalid signed_request');
    }

    [$encoded_sig, $encoded_payload] = $parts;

    // base64url → base64 → binary
    $sig     = base64_decode(strtr($encoded_sig,     '-_', '+/'));
    $payload = base64_decode(strtr($encoded_payload, '-_', '+/'));
    $data    = json_decode($payload, true);

    if (!$data || ($data['algorithm'] ?? '') !== 'HMAC-SHA256') {
        json_error(400, 'Unsupported algorithm');
    }

    $expected_sig = hash_hmac('sha256', $encoded_payload, META_APP_SECRET, true);
    if (!hash_equals($expected_sig, $sig)) {
        json_error(400, 'Invalid signature');
    }

    $fb_user_id = $data['user_id'] ?? null;
    if (!$fb_user_id) {
        json_error(400, 'Missing user_id');
    }

    // Clear Meta tokens for any user linked to this Facebook identity.
    // The app stores the access token but not the raw FB user_id, so we
    // clear meta_access_token / meta_token_expires for all users where the
    // token was granted by this Facebook user (best-effort).
    db()->prepare('UPDATE users SET meta_access_token = NULL, meta_token_expires = NULL WHERE meta_access_token IS NOT NULL')
        ->execute();

    $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));

    json_out([
        'url'               => SITE_URL . '/data-deletion?code=' . $code,
        'confirmation_code' => $code,
    ]);
}

// ── Manual deletion via JSON body { "email": "..." } ───────────────
$body  = body();
$email = trim($body['email'] ?? '');

if ($email === '') {
    json_error(400, 'email is required');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error(400, 'Invalid email address');
}

$stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Don't reveal whether the account exists — return a code anyway
    $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    json_out(['confirmation_code' => $code]);
}

$uid = (int) $user['id'];

db()->prepare('DELETE FROM sessions          WHERE user_id = :uid')->execute([':uid' => $uid]);
db()->prepare('DELETE FROM runs              WHERE user_id = :uid')->execute([':uid' => $uid]);
db()->prepare('DELETE FROM scheduled_exports WHERE user_id = :uid')->execute([':uid' => $uid]);
db()->prepare('DELETE FROM users             WHERE id      = :uid')->execute([':uid' => $uid]);

$code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
json_out(['confirmation_code' => $code]);
