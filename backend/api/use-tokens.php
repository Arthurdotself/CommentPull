<?php
/**
 * Deprecated — token economy has been removed.
 * Returns 410 Gone.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../helpers.php';
json_error(410, 'Token economy has been removed');
