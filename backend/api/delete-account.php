<?php
/**
 * DELETE /backend/api/delete-account.php
 * Permanently deletes the authenticated user's account and all related data.
 * Body: { "password": "..." }  — required to confirm intent.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    json_error(405, 'Method not allowed');
}

$user = require_auth();
$data = body();

$password = $data['password'] ?? '';

// Verify password before deleting
$row = db()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
$row->execute([':id' => (int) $user['id']]);
$row = $row->fetch();

if (!$row || !password_verify($password, $row['password_hash'])) {
    json_error(403, 'Incorrect password');
}

// Delete user — cascades to sessions and scheduled_exports via FK ON DELETE CASCADE
db()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => (int) $user['id']]);

json_out(['ok' => true]);
