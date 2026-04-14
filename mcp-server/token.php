<?php
/**
 * commentpull OAuth 2.0 Token Endpoint
 * POST /mcp-server/token.php
 *
 * Accepts client_credentials grant and returns a Bearer token.
 * claude.ai calls this automatically using the Client ID + Secret
 * you entered in the connector setup.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/config.php';

// ── Parse credentials ─────────────────────────────────────────────────────────
// Support three formats claude.ai might use:
//   1. application/x-www-form-urlencoded body (most common)
//   2. application/json body
//   3. HTTP Basic Authorization header

$grant_type    = '';
$client_id     = '';
$client_secret = '';

$content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

if (str_contains($content_type, 'application/json')) {
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $grant_type    = $body['grant_type']    ?? '';
    $client_id     = $body['client_id']     ?? '';
    $client_secret = $body['client_secret'] ?? '';
} else {
    // form-urlencoded (default)
    $grant_type    = $_POST['grant_type']    ?? '';
    $client_id     = $_POST['client_id']     ?? '';
    $client_secret = $_POST['client_secret'] ?? '';
}

// Fallback: HTTP Basic auth header
if (empty($client_id)) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6));
        [$client_id, $client_secret] = array_pad(explode(':', $decoded, 2), 2, '');
    }
}

// ── Validate grant type ───────────────────────────────────────────────────────
if ($grant_type !== 'client_credentials') {
    http_response_code(400);
    echo json_encode([
        'error'             => 'unsupported_grant_type',
        'error_description' => 'Only client_credentials grant is supported.',
    ]);
    exit;
}

// ── Validate client ───────────────────────────────────────────────────────────
$clients = MCP_OAUTH_CLIENTS;

if (empty($client_id) || !isset($clients[$client_id]) || $clients[$client_id] !== $client_secret) {
    http_response_code(401);
    echo json_encode([
        'error'             => 'invalid_client',
        'error_description' => 'Client ID or secret is incorrect.',
    ]);
    exit;
}

// ── Issue token ───────────────────────────────────────────────────────────────
$token      = bin2hex(random_bytes(32));
$expires_in = 3600; // 1 hour
$expires_at = time() + $expires_in;

// Store token in temp file (hashed so the raw token never touches disk)
$token_data = json_encode([
    'client_id'  => $client_id,
    'expires_at' => $expires_at,
]);
$token_file = token_path($token);
file_put_contents($token_file, $token_data);

echo json_encode([
    'access_token' => $token,
    'token_type'   => 'bearer',
    'expires_in'   => $expires_in,
]);

// ── Helper ────────────────────────────────────────────────────────────────────
function token_path($token)
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cp_tok_' . hash('sha256', $token) . '.json';
}
