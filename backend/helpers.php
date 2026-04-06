<?php

/** Send JSON response and exit. */
function json_out(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send a JSON error response and exit. */
function json_error(int $status, string $message): never {
    json_out(['error' => $message], $status);
}

/** Read and decode the JSON request body. */
function body(): array {
    static $data = null;
    if ($data === null) {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true) ?? [];
    }
    return $data;
}

/** Cryptographically secure random hex token. */
function generate_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

/**
 * Generate a signed OAuth state token (CSRF protection).
 * Valid for 10 minutes.
 */
function generate_oauth_state(): string {
    $payload = base64_encode(json_encode([
        'nonce' => bin2hex(random_bytes(16)),
        'ts'    => time(),
    ]));
    $sig = hash_hmac('sha256', $payload, OAUTH_STATE_SECRET);
    return $payload . '.' . $sig;
}

/**
 * Verify an OAuth state token.
 * Returns true if valid and not expired.
 */
function verify_oauth_state(string $state): bool {
    $parts = explode('.', $state, 2);
    if (count($parts) !== 2) return false;
    [$payload, $sig] = $parts;
    $expected = hash_hmac('sha256', $payload, OAUTH_STATE_SECRET);
    if (!hash_equals($expected, $sig)) return false;
    $data = json_decode(base64_decode($payload), true);
    return $data && (time() - (int)$data['ts']) <= 600;
}

/**
 * Find a user by email or create a new one (for OAuth sign-in).
 * Returns the user row.
 */
function find_or_create_oauth_user(string $email, string $name, bool &$isNew = false): array {
    // Look up existing user
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();
    if ($user) { $isNew = false; return $user; }

    // New user — BETA: grant unlimited tokens if beta mode is on
    $isNew        = true;
    $startTokens  = defined('BETA_MODE') && BETA_MODE ? BETA_TOKENS : FREE_PLAN_TOKENS;
    $hash         = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);
    $ins          = db()->prepare(
        'INSERT INTO users (email, password_hash, name, plan, tokens)
         VALUES (:email, :hash, :name, "free", :tokens)'
    );
    $ins->execute([
        ':email'  => $email,
        ':hash'   => $hash,
        ':name'   => $name ?: explode('@', $email)[0],
        ':tokens' => $startTokens,
    ]);
    $id = (int) db()->lastInsertId();
    $row = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $row->execute([':id' => $id]);
    return $row->fetch();
}

/**
 * Create a session for a user and return the session token.
 */
function create_session(int $userId): string {
    $token     = generate_token();
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_TTL);
    db()->prepare(
        'INSERT INTO sessions (user_id, token, expires_at) VALUES (:uid, :token, :exp)'
    )->execute([':uid' => $userId, ':token' => $token, ':exp' => $expiresAt]);
    setcookie('cp_token', $token, [
        'expires'  => time() + SESSION_TTL,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $token;
}

/**
 * Make a request to the Stripe API.
 * Uses cURL — no Composer/SDK required.
 */
function stripe(string $method, string $path, array $params = []): array {
    $ch  = curl_init();
    $url = 'https://api.stripe.com/v1' . $path;

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
    } elseif ($method === 'GET' && $params) {
        $opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    if ($errno) {
        return ['error' => ['message' => 'cURL error ' . $errno]];
    }

    return json_decode($response, true) ?? [];
}
