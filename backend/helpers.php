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
 * Encrypt a sensitive string (e.g. OAuth access token) with AES-256-GCM.
 * Returns a base64-encoded blob: IV (12 bytes) + tag (16 bytes) + ciphertext.
 */
function encrypt_token(string $plaintext): string {
    $key = hex2bin(TOKEN_ENCRYPT_KEY);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('Token encryption failed');
    return base64_encode($iv . $tag . $ct);
}

/**
 * Decrypt a token encrypted by encrypt_token().
 */
function decrypt_token(string $encrypted): string {
    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Invalid encrypted token');
    $key = hex2bin(TOKEN_ENCRYPT_KEY);
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) throw new RuntimeException('Token decryption failed — key mismatch or data corrupted');
    return $pt;
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

    $isNew = true;
    $hash  = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);
    $ins   = db()->prepare(
        'INSERT INTO users (email, password_hash, name, plan)
         VALUES (:email, :hash, :name, "free")'
    );
    $ins->execute([
        ':email' => $email,
        ':hash'  => $hash,
        ':name'  => $name ?: explode('@', $email)[0],
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
