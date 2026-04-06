<?php
/**
 * GET /backend/api/me.php
 * Returns the currently authenticated user's profile.
 * Returns: { "id": 1, "email": "...", "name": "...", "plan": "free"|"pro" }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Method not allowed');
}

$user = require_auth();

// New day: reset ad counter and top up tokens to 250 if below
$today = date('Y-m-d');
if ($user['ads_last_reset'] !== $today) {
    db()->prepare(
        'UPDATE users
         SET ads_watched_today = 0,
             ads_last_reset    = :today,
             tokens            = GREATEST(tokens, :min)
         WHERE id = :id'
    )->execute([':today' => $today, ':min' => FREE_PLAN_TOKENS, ':id' => $user['id']]);
    $user['ads_watched_today'] = 0;
    if ((int)$user['tokens'] < FREE_PLAN_TOKENS) {
        $user['tokens'] = FREE_PLAN_TOKENS;
    }
}

json_out([
    'id'                => (int) $user['id'],
    'email'             => $user['email'],
    'name'              => $user['name'],
    'plan'              => $user['plan'],
    'tokens'            => (int) $user['tokens'],
    'ads_watched_today' => (int) $user['ads_watched_today'],
    'ads_remaining'     => max(0, AD_DAILY_LIMIT - (int) $user['ads_watched_today']),
    'created_at'        => $user['created_at'],
]);
