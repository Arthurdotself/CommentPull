<?php
/**
 * GET /backend/api/meta-pages.php
 * Returns the Facebook Pages the connected user manages, each with its own page access token.
 * Uses the stored user access token to call /me/accounts.
 *
 * Response:
 *   { "pages": [{ "id": "...", "name": "...", "access_token": "..." }, ...] }
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

// ── Retrieve stored user token (same priority as meta-token.php) ─────
$token = null;

$user = try_auth();
if ($user) {
    $stmt = db()->prepare(
        'SELECT meta_access_token, meta_token_expires FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int) $user['id']]);
    $row = $stmt->fetch();
    if ($row && $row['meta_access_token'] && strtotime($row['meta_token_expires']) > time()) {
        try {
            $token = decrypt_token($row['meta_access_token']);
        } catch (Throwable $e) {}
    }
}

if (!$token) {
    $sessionToken   = $_SESSION['meta_token']         ?? null;
    $sessionExpires = $_SESSION['meta_token_expires']  ?? null;
    if ($sessionToken && $sessionExpires && strtotime($sessionExpires) > time()) {
        $token = $sessionToken;
    }
}

if (!$token) {
    json_error(401, 'Not connected to Facebook');
}

// ── Fetch managed pages from Meta Graph API ──────────────────────────
$url = 'https://graph.facebook.com/v19.0/me/accounts?' . http_build_query([
    'fields'       => 'id,name,access_token',
    'access_token' => $token,
    'limit'        => '100',
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) {
    json_error(502, 'Could not connect to Facebook');
}

$data = json_decode($body, true);
if (isset($data['error'])) {
    json_error(400, $data['error']['message'] ?? 'Facebook API error');
}

json_out(['pages' => $data['data'] ?? []]);
