<?php
/**
 * GET  /backend/api/me.php  — Returns the authenticated user's profile.
 * PATCH /backend/api/me.php — Updates name and/or password.
 *   Body: { "name": "...", "current_password": "...", "new_password": "..." }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = require_auth();
    json_out([
        'id'         => (int) $user['id'],
        'email'      => $user['email'],
        'name'       => $user['name'],
        'plan'       => $user['plan'],
        'created_at' => $user['created_at'],
    ]);
}

if ($method === 'PATCH') {
    $user = require_auth();
    $data = body();

    $updates = [];
    $params  = [':id' => (int) $user['id']];

    // ── Name update ──────────────────────────────────────────────
    if (isset($data['name'])) {
        $name = trim($data['name']);
        if ($name === '') {
            json_error(422, 'Name cannot be empty');
        }
        if (strlen($name) > 100) {
            json_error(422, 'Name is too long (max 100 characters)');
        }
        $updates[]        = 'name = :name';
        $params[':name']  = $name;
    }

    // ── Password update ──────────────────────────────────────────
    if (isset($data['new_password'])) {
        $currentPassword = $data['current_password'] ?? '';
        $newPassword     = $data['new_password'];

        if (strlen($newPassword) < 8) {
            json_error(422, 'New password must be at least 8 characters');
        }

        // Fetch the stored hash (require_auth only returns a limited set of columns)
        $row = db()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $row->execute([':id' => (int) $user['id']]);
        $row = $row->fetch();

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            json_error(403, 'Current password is incorrect');
        }

        $updates[]       = 'password_hash = :hash';
        $params[':hash'] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    if (empty($updates)) {
        json_error(422, 'Nothing to update');
    }

    db()->prepare(
        'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id'
    )->execute($params);

    // Return fresh profile
    $fresh = db()->prepare('SELECT id, email, name, plan, created_at FROM users WHERE id = :id LIMIT 1');
    $fresh->execute([':id' => (int) $user['id']]);
    $fresh = $fresh->fetch();

    json_out([
        'id'         => (int) $fresh['id'],
        'email'      => $fresh['email'],
        'name'       => $fresh['name'],
        'plan'       => $fresh['plan'],
        'created_at' => $fresh['created_at'],
    ]);
}

json_error(405, 'Method not allowed');
