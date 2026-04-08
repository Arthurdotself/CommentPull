<?php
/**
 * GET /backend/api/me.php
 * Returns the currently authenticated user's profile.
 * Returns: { "id": 1, "email": "...", "name": "...", "plan": "free"|"pro" }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Method not allowed');
}

$user = require_auth();

json_out([
    'id'         => (int) $user['id'],
    'email'      => $user['email'],
    'name'       => $user['name'],
    'plan'       => $user['plan'],
    'created_at' => $user['created_at'],
]);
