<?php
/**
 * GET /backend/api/oauth-callback-google.php
 * Google redirects here after the user approves.
 * Exchanges the code for a token, fetches user info,
 * finds or creates the user, creates a session, then
 * redirects to the frontend.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

function fail(string $msg): never {
    header('Location: ' . SITE_URL . '/?oauth=error&message=' . urlencode($msg));
    exit;
}

// ── 1. Validate state (CSRF protection) ──────────────────────────
$state = $_GET['state'] ?? '';
if (!$state || !verify_oauth_state($state)) {
    fail('Invalid OAuth state. Please try again.');
}

// ── 2. Check for OAuth error from Google ─────────────────────────
if (!empty($_GET['error'])) {
    fail($_GET['error_description'] ?? 'Google sign-in was cancelled.');
}

$code = $_GET['code'] ?? '';
if (!$code) fail('No authorisation code received from Google.');

// ── 3. Exchange code for access token ────────────────────────────
$redirect = SITE_URL . '/backend/api/oauth-callback-google.php';

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => $redirect,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT    => 15,
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) fail('Could not connect to Google. Please try again.');

$tokenData = json_decode($body, true);
if (empty($tokenData['access_token'])) {
    fail($tokenData['error_description'] ?? 'Failed to obtain Google access token.');
}

// ── 4. Fetch user profile ─────────────────────────────────────────
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']],
    CURLOPT_TIMEOUT        => 15,
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) fail('Could not fetch Google profile. Please try again.');

$profile = json_decode($body, true);
$email   = filter_var($profile['email'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$email) fail('Could not retrieve a valid email address from Google.');
if (empty($profile['email_verified'])) fail('Your Google email address is not verified.');

$name = trim($profile['name'] ?? $profile['given_name'] ?? '');

// ── 5. Find or create user, create session ────────────────────────
$isNew   = false;
$user    = find_or_create_oauth_user($email, $name, $isNew);
$session = create_session((int) $user['id']);

// ── 6. Redirect to frontend ───────────────────────────────────────
header('Location: ' . SITE_URL . '/?oauth=success&token=' . urlencode($session) . '&name=' . urlencode($user['name']));
exit;
