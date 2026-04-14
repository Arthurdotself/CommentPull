<?php
/**
 * commentpull MCP Server — config
 *
 * Add one key per user/plan. Any random string works.
 * Generate one: php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
 */

define('MCP_API_KEYS', [
    'a3f8c2d19e4b7065f1a2e3d4c5b6a7f8',
    'b4g9d3e20f5c8176g2b3f4e5d6c7b8g9',
]);

// Leave the array empty to disable auth (local testing only).
