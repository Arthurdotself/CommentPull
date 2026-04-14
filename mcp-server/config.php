<?php
/**
 * commentpull MCP Server — config
 *
 * Add one key per user/plan. Any random string works.
 * Generate one: php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
 */

define('MCP_API_KEYS', [
    // 'key_replace_me_1',
    // 'key_replace_me_2',
]);

// Leave the array empty to disable auth (local testing only).
