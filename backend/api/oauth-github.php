<?php
/**
 * GET /backend/api/oauth-github.php
 * Redirects the user to GitHub's OAuth consent screen.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$state    = generate_oauth_state();
$redirect = SITE_URL . '/backend/api/oauth-callback-github.php';

$params = http_build_query([
    'client_id'    => GITHUB_CLIENT_ID,
    'redirect_uri' => $redirect,
    'scope'        => 'user:email',
    'state'        => $state,
]);

header('Location: https://github.com/login/oauth/authorize?' . $params);
exit;
