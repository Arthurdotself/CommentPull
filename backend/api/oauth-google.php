<?php
/**
 * GET /backend/api/oauth-google.php
 * Redirects the user to Google's OAuth consent screen.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$state    = generate_oauth_state();
$redirect = SITE_URL . '/backend/api/oauth-callback-google.php';

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => $redirect,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
