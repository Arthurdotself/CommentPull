<?php
/**
 * POST /backend/api/earn-tokens.php
 * Called after a user watches a rewarded ad.
 * Awards AD_TOKENS_REWARD tokens up to AD_DAILY_LIMIT times per day.
 * Returns: { tokens, ads_watched_today, ads_remaining, reward }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

if ((int) $user['ads_watched_today'] >= AD_DAILY_LIMIT) {
    json_error(429, 'Daily ad limit reached. Come back tomorrow for more tokens.');
}

// Award tokens and increment counter
db()->prepare(
    'UPDATE users
     SET tokens = tokens + :reward,
         ads_watched_today = ads_watched_today + 1,
         ads_last_reset = :today
     WHERE id = :id'
)->execute([
    ':reward' => AD_TOKENS_REWARD,
    ':today'  => $today,
    ':id'     => $user['id'],
]);

$row = db()->prepare('SELECT tokens, ads_watched_today FROM users WHERE id = :id LIMIT 1');
$row->execute([':id' => $user['id']]);
$updated = $row->fetch();

json_out([
    'tokens'            => (int) $updated['tokens'],
    'ads_watched_today' => (int) $updated['ads_watched_today'],
    'ads_remaining'     => max(0, AD_DAILY_LIMIT - (int) $updated['ads_watched_today']),
    'reward'            => AD_TOKENS_REWARD,
]);
