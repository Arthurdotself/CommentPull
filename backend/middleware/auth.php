<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

/**
 * Verify the bearer token and return the authenticated user row.
 * Sends HTTP 401 and exits if not authenticated.
 */
function require_auth(): array {
    $token = bearer_token();
    if (!$token) {
        json_error(401, 'Unauthorized — no token provided');
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.email, u.name, u.plan, u.created_at
         FROM users u
         JOIN sessions s ON s.user_id = u.id
         WHERE s.token = :token
           AND s.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        json_error(401, 'Invalid or expired session');
    }

    return $user;
}

/**
 * Like require_auth() but returns null instead of sending 401.
 * Use when authentication is optional.
 */
function try_auth(): ?array {
    $token = bearer_token();
    if (!$token) return null;

    $stmt = db()->prepare(
        'SELECT u.id, u.email, u.name, u.plan, u.created_at
         FROM users u
         JOIN sessions s ON s.user_id = u.id
         WHERE s.token = :token
           AND s.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    return $user ?: null;
}

/** Extract bearer token from Authorization header or cp_token cookie. */
function bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return $m[1];
    }
    // Fallback: cookie set by the server on sign-in
    return $_COOKIE['cp_token'] ?? null;
}
