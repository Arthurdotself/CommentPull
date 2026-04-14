<?php
/**
 * GET /backend/api/oauth-meta.php
 * Redirects the user to Meta's OAuth consent screen.
 * Works for both logged-in and anonymous users.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

session_start();

$state    = generate_oauth_state();
$redirect = SITE_URL . '/backend/api/oauth-callback-meta.php';

$params = http_build_query([
    'client_id'     => META_APP_ID,
    'redirect_uri'  => $redirect,
    'response_type' => 'code',
    'scope'         => 'email,public_profile,pages_show_list,business_management,instagram_basic,pages_read_engagement,pages_manage_metadata,pages_read_user_content',
    'state'         => $state,
]);

header('Location: https://www.facebook.com/v19.0/dialog/oauth?' . $params);
exit;
