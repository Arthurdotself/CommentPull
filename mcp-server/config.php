<?php
/**
 * commentpull MCP Server — config
 *
 * Add one key per user/plan. Any random string works.
 * Generate one: php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
 */

// ── API key auth (Claude Code CLI / direct use) ───────────────────────────────
define('MCP_API_KEYS', [
    'a3f8c2d19e4b7065f1a2e3d4c5b6a7f8',
]);

// ── OAuth 2.0 clients (claude.ai connector) ───────────────────────────────────
// Format:  'client_id' => 'client_secret'
// You invent both values — enter them in claude.ai when adding the connector.
// Generate a secret: php -r "echo bin2hex(random_bytes(24)) . PHP_EOL;"
define('MCP_OAUTH_CLIENTS', [
    'commentpull' => 'REPLACE_WITH_YOUR_CLIENT_SECRET',
]);

// Leave both arrays empty to disable auth (local testing only).
