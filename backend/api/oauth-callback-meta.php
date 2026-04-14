<?php
/**
 * GET /backend/api/oauth-callback-meta.php
 * Meta redirects here after the user approves.
 * - Exchanges the code for a long-lived token (60 days).
 * - Always stores token in PHP session (works for anonymous users).
 * - If user is logged in, also stores encrypted token in the DB.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

session_start();

function fail(string $msg): never {
    header('Location: ' . SITE_URL . '/?meta_error=' . urlencode($msg));
    exit;
}

// ── 1. Validate CSRF state ────────────────────────────────────────
$state = $_GET['state'] ?? '';
if (!$state || !verify_oauth_state($state)) {
    fail('Invalid OAuth state. Please try again.');
}

// ── 2. Check for OAuth error from Meta ───────────────────────────
if (!empty($_GET['error'])) {
    fail($_GET['error_description'] ?? 'Facebook sign-in was cancelled.');
}

$code = $_GET['code'] ?? '';
if (!$code) fail('No authorisation code received from Facebook.');

$redirect = SITE_URL . '/backend/api/oauth-callback-meta.php';

// ── 3. Exchange code for short-lived user access token ───────────
$ch = curl_init('https://graph.facebook.com/v19.0/oauth/access_token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => META_APP_ID,
        'client_secret' => META_APP_SECRET,
        'redirect_uri'  => $redirect,
        'code'          => $code,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT    => 15,
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) fail('Could not connect to Facebook. Please try again.');

$shortData = json_decode($body, true);
if (empty($shortData['access_token'])) {
    fail($shortData['error']['message'] ?? 'Failed to obtain Facebook access token.');
}

$shortToken = $shortData['access_token'];

// ── 4. Exchange short-lived token for long-lived token (60 days) ─
$params = http_build_query([
    'grant_type'        => 'fb_exchange_token',
    'client_id'         => META_APP_ID,
    'client_secret'     => META_APP_SECRET,
    'fb_exchange_token' => $shortToken,
]);

$ch = curl_init('https://graph.facebook.com/v19.0/oauth/access_token?' . $params);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) fail('Could not exchange token with Facebook. Please try again.');

$longData = json_decode($body, true);
if (empty($longData['access_token'])) {
    fail($longData['error']['message'] ?? 'Failed to obtain long-lived Facebook token.');
}

$longToken  = $longData['access_token'];
$expiresIn  = (int) ($longData['expires_in'] ?? 5184000); // default 60 days
$expiresAt  = date('Y-m-d H:i:s', time() + $expiresIn);

// ── 5. Always store in PHP session (works for anonymous users) ────
$_SESSION['meta_token']         = $longToken;
$_SESSION['meta_token_expires'] = $expiresAt;

// ── 6. Fetch Facebook user profile (name + email) ────────────────
$ch = curl_init('https://graph.facebook.com/v19.0/me?fields=id,name,email&access_token=' . urlencode($longToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$profileBody = curl_exec($ch);
curl_close($ch);
$profile = json_decode($profileBody, true);
$fbId    = $profile['id']    ?? null;
$fbName  = $profile['name']  ?? 'Facebook User';
$fbEmail = $profile['email'] ?? ($fbId ? $fbId . '@facebook.placeholder' : null);

// ── 7. Auto sign-in: find or create user, then create session ─────
$user = try_auth();
if (!$user && $fbEmail) {
    $user = find_or_create_oauth_user($fbEmail, $fbName);
    create_session((int) $user['id']);
}

// ── 8. Persist Meta token to DB for authenticated users ──────────
if ($user) {
    try {
        $encrypted = encrypt_token($longToken);
        db()->prepare(
            'UPDATE users SET meta_access_token = :token, meta_token_expires = :exp WHERE id = :id'
        )->execute([
            ':token' => $encrypted,
            ':exp'   => $expiresAt,
            ':id'    => (int) $user['id'],
        ]);
    } catch (Throwable $e) {
        // Non-fatal: session still has the token
    }
}

// ── 7. Redirect back to frontend ─────────────────────────────────
header('Location: ' . SITE_URL . '/?meta_connected=1');
exit;
