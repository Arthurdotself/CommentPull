<?php
/**
 * POST /backend/api/use-tokens.php
 * Deducts tokens after a successful comment extraction.
 * Body: { "count": 150 }
 * Returns: { "tokens": 100, "deducted": 150 }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

$user  = require_auth();
$count = (int) (body()['count'] ?? 0);

if ($count <= 0) {
    json_error(422, 'count must be a positive integer');
}

// Deduct tokens, floor at 0
$deduct = min($count, (int) $user['tokens']);
db()->prepare(
    'UPDATE users SET tokens = GREATEST(0, tokens - :deduct) WHERE id = :id'
)->execute([':deduct' => $deduct, ':id' => $user['id']]);

$row = db()->prepare('SELECT tokens FROM users WHERE id = :id LIMIT 1');
$row->execute([':id' => $user['id']]);
$newTokens = (int) $row->fetchColumn();

json_out([
    'tokens'   => $newTokens,
    'deducted' => $deduct,
]);
