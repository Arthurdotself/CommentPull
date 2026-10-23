<?php
/**
 * Scheduled Exports API — Pro plan only (YouTube)
 *
 * GET    /backend/api/scheduled-exports.php        → list user's scheduled exports
 * POST   /backend/api/scheduled-exports.php        → create a new scheduled export
 * DELETE /backend/api/scheduled-exports.php?id=X   → cancel a pending scheduled export
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$user = require_auth();

if ($user['plan'] !== 'pro') {
    json_error(403, 'Scheduled Exports is a Pro plan feature');
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // GET /scheduled-exports.php?id=X  → single export (with comments payload)
    if (isset($_GET['id'])) {
        $id   = (int) $_GET['id'];
        $stmt = db()->prepare(
            'SELECT id, platform, post_url, export_format, scheduled_at, status, total_count, comments, error_message, created_at
             FROM scheduled_exports WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => (int) $user['id']]);
        $exp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exp) json_error(404, 'Scheduled export not found');

        $exp['id']          = (int) $exp['id'];
        $exp['total_count'] = (int) $exp['total_count'];
        $exp['comments']    = $exp['comments'] ? json_decode($exp['comments'], true) : [];
        $exp['params']      = null;

        json_out($exp);
    }

    // GET /scheduled-exports.php  → list (no comments payload)
    $stmt = db()->prepare(
        'SELECT id, platform, post_url, export_format, scheduled_at, status, total_count, error_message, created_at
         FROM scheduled_exports
         WHERE user_id = :uid
         ORDER BY scheduled_at DESC
         LIMIT 50'
    );
    $stmt->execute([':uid' => (int) $user['id']]);
    $exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($exports as &$exp) {
        $exp['id']          = (int) $exp['id'];
        $exp['total_count'] = (int) $exp['total_count'];
    }
    unset($exp);

    json_out(['exports' => $exports]);
}

// ── POST ───────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data = body();

    // Only YouTube is supported (Facebook is collected by the browser extension)
    $platform = $data['platform'] ?? '';
    if ($platform !== 'yt') {
        json_error(422, 'Scheduled exports are only available for YouTube');
    }

    $postUrl = trim($data['post_url'] ?? '');
    if (!$postUrl) {
        json_error(422, 'post_url is required');
    }

    $exportFormat = $data['export_format'] ?? 'xlsx';
    if (!in_array($exportFormat, ['csv', 'json', 'xlsx'], true)) {
        $exportFormat = 'xlsx';
    }

    $scheduledRaw = trim($data['scheduled_at'] ?? '');
    if (!$scheduledRaw) {
        json_error(422, 'scheduled_at is required');
    }
    $schedTs = strtotime($scheduledRaw);
    if (!$schedTs || $schedTs <= time() + 60) {
        json_error(422, 'scheduled_at must be at least 1 minute in the future');
    }
    $scheduledAtDb = gmdate('Y-m-d H:i:s', $schedTs);

    // YouTube API key comes from the request and is stored encrypted
    $apiKey = trim($data['access_token'] ?? '');
    if (!$apiKey) json_error(422, 'access_token (YouTube API key) is required');

    $stmt = db()->prepare(
        'INSERT INTO scheduled_exports (user_id, platform, post_url, access_token, params, export_format, scheduled_at)
         VALUES (:uid, :platform, :post_url, :token, NULL, :format, :sched)'
    );
    $stmt->execute([
        ':uid'      => (int) $user['id'],
        ':platform' => 'yt',
        ':post_url' => $postUrl,
        ':token'    => encrypt_token($apiKey),
        ':format'   => $exportFormat,
        ':sched'    => $scheduledAtDb,
    ]);

    $id = (int) db()->lastInsertId();
    json_out(['id' => $id, 'status' => 'pending', 'scheduled_at' => $scheduledAtDb], 201);
}

// ── DELETE ─────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error(422, 'id parameter required');
    }

    // Only pending exports can be cancelled
    $stmt = db()->prepare(
        'DELETE FROM scheduled_exports WHERE id = :id AND user_id = :uid AND status = "pending"'
    );
    $stmt->execute([':id' => $id, ':uid' => (int) $user['id']]);

    if ($stmt->rowCount() === 0) {
        $check = db()->prepare('SELECT status FROM scheduled_exports WHERE id = :id AND user_id = :uid');
        $check->execute([':id' => $id, ':uid' => (int) $user['id']]);
        $row = $check->fetch();
        if (!$row) json_error(404, 'Scheduled export not found');
        json_error(409, 'Only pending exports can be cancelled (current status: ' . $row['status'] . ')');
    }

    json_out(['deleted' => true]);
}

json_error(405, 'Method not allowed');
