<?php
/**
 * GET /backend/api/oauth-callback-github.php
 * GitHub redirects here after the user approves.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

function fail(string $msg): never {
    header('Location: ' . SITE_URL . '/?oauth=error&message=' . urlencode($msg));
    exit;
}

// ── 1. Validate state ─────────────────────────────────────────────
$state = $_GET['state'] ?? '';
if (!$state || !verify_oauth_state($state)) {
    fail('Invalid OAuth state. Please try again.');
}

if (!empty($_GET['error'])) {
    fail($_GET['error_description'] ?? 'GitHub sign-in was cancelled.');
}

$code = $_GET['code'] ?? '';
if (!$code) fail('No authorisation code received from GitHub.');

// ── 2. Exchange code for access token ────────────────────────────
$redirect = SITE_URL . '/backend/api/oauth-callback-github.php';

$ch = curl_init('https://github.com/login/oauth/access_token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => GITHUB_CLIENT_ID,
        'client_secret' => GITHUB_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => $redirect,
    ]),
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) fail('Could not connect to GitHub. Please try again.');

$tokenData   = json_decode($body, true);
$accessToken = $tokenData['access_token'] ?? '';
if (!$accessToken) fail($tokenData['error_description'] ?? 'Failed to obtain GitHub access token.');

// ── 3. Fetch user profile ─────────────────────────────────────────
$ch = curl_init('https://api.github.com/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/vnd.github+json',
        'User-Agent: CommentPull',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$body    = curl_exec($ch);
$errno   = curl_errno($ch);
curl_close($ch);

if ($errno) fail('Could not fetch GitHub profile. Please try again.');
$profile = json_decode($body, true);

$email = filter_var($profile['email'] ?? '', FILTER_VALIDATE_EMAIL);

// ── 4. If email is private, fetch from /user/emails ──────────────
if (!$email) {
    $ch = curl_init('https://api.github.com/user/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/vnd.github+json',
            'User-Agent: CommentPull',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body    = curl_exec($ch);
    curl_close($ch);
    $emails  = json_decode($body, true) ?: [];
    // Prefer primary verified email
    foreach ($emails as $e) {
        if (!empty($e['primary']) && !empty($e['verified'])) {
            $email = filter_var($e['email'], FILTER_VALIDATE_EMAIL);
            break;
        }
    }
    // Fallback: any verified email
    if (!$email) {
        foreach ($emails as $e) {
            if (!empty($e['verified'])) {
                $email = filter_var($e['email'], FILTER_VALIDATE_EMAIL);
                break;
            }
        }
    }
}

if (!$email) fail('Could not retrieve a verified email address from GitHub. Make sure your GitHub account has a verified email.');

$name = trim($profile['name'] ?? $profile['login'] ?? '');

// ── 5. Find or create user, create session ────────────────────────
$isNew   = false;
$user    = find_or_create_oauth_user($email, $name, $isNew);
$session = create_session((int) $user['id']);

// ── 6. Redirect to frontend ───────────────────────────────────────
$betaGift = $isNew && defined('BETA_MODE') && BETA_MODE ? '&beta_gift=1' : ''; // BETA
header('Location: ' . SITE_URL . '/?oauth=success&token=' . urlencode($session) . '&name=' . urlencode($user['name']) . $betaGift);
exit;
