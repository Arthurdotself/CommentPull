<?php
/**
 * GET /backend/api/plans.php
 * Returns available subscription plans.
 * Public endpoint — no auth required.
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Method not allowed');
}

json_out([
    'plans' => [
        [
            'id'       => 'free',
            'name'     => 'Free',
            'price'    => 0,
            'features' => [
                'Unlimited extractions',
                'Up to 5 posts per extraction',
                'Facebook, Instagram & YouTube',
                'Export to CSV & JSON',
                'Winner picker',
            ],
        ],
    ],
]);
