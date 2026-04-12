<?php
/**
 * Scheduled Exports API — Pro plan only
 *
 * GET    /backend/api/scheduled-exports.php        → list user's scheduled exports
 * POST   /backend/api/scheduled-exports.php        → create a new scheduled export
 * DELETE /backend/api/scheduled-exports.php?id=X   → cancel a pending scheduled export
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
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
        $exp['params']      = null; // params not exposed (contains internal IDs)

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

    // Platform
    $platform = $data['platform'] ?? '';
    if (!in_array($platform, ['fb', 'ig', 'yt'], true)) {
        json_error(422, 'Invalid platform — must be fb, ig, or yt');
    }

    // Post URL
    $postUrl = trim($data['post_url'] ?? '');
    if (!$postUrl) {
        json_error(422, 'post_url is required');
    }

    // Access token
    $accessToken = trim($data['access_token'] ?? '');
    if (!$accessToken) {
        json_error(422, 'access_token is required');
    }

    // Export format
    $exportFormat = $data['export_format'] ?? 'xlsx';
    if (!in_array($exportFormat, ['csv', 'json', 'xlsx'], true)) {
        $exportFormat = 'xlsx';
    }

    // Scheduled datetime
    $scheduledRaw = trim($data['scheduled_at'] ?? '');
    if (!$scheduledRaw) {
        json_error(422, 'scheduled_at is required');
    }
    $schedTs = strtotime($scheduledRaw);
    if (!$schedTs || $schedTs <= time() + 60) {
        json_error(422, 'scheduled_at must be at least 1 minute in the future');
    }
    $scheduledAtDb = date('Y-m-d H:i:s', $schedTs);

    // Platform-specific params
    $params = [];
    if ($platform === 'fb') {
        $pageId = trim($data['page_id'] ?? '');
        if (!$pageId) json_error(422, 'page_id is required for Facebook');
        $params['page_id']         = $pageId;
        $params['include_replies'] = (bool) ($data['include_replies'] ?? false);
    } elseif ($platform === 'ig') {
        $igUserId = trim($data['ig_user_id'] ?? '');
        if (!$igUserId) json_error(422, 'ig_user_id is required for Instagram');
        $params['ig_user_id']      = $igUserId;
        $params['include_replies'] = (bool) ($data['include_replies'] ?? false);
    }
    // YouTube: access_token holds the API key; no extra params needed

    // ── Token validation (FB / IG only) ────────────────────────────
    if ($platform !== 'yt') {
        // Basic validity check
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://graph.facebook.com/v19.0/me?fields=id&access_token=' . urlencode($accessToken),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            json_error(503, 'Could not validate access token: network error');
        }
        $json = json_decode($res, true) ?? [];
        if (!empty($json['error'])) {
            json_error(422, 'Access token is invalid: ' . ($json['error']['message'] ?? 'unknown error'));
        }

        // Expiry check — compare token expiry against scheduled time
        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL            => 'https://graph.facebook.com/v19.0/debug_token?input_token=' . urlencode($accessToken) . '&access_token=' . urlencode($accessToken),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $debugRes = curl_exec($ch2);
        curl_close($ch2);

        $debugJson = json_decode($debugRes, true) ?? [];
        $expiresAt = isset($debugJson['data']['expires_at']) ? (int) $debugJson['data']['expires_at'] : null;

        if ($expiresAt !== null && $expiresAt > 0 && $expiresAt < $schedTs) {
            $expiryStr = date('Y-m-d H:i', $expiresAt);
            json_error(422, "Your access token expires on {$expiryStr} UTC, before the scheduled export time. Please generate a long-lived token.");
        }
    }

    // ── Save to DB ─────────────────────────────────────────────────
    $stmt = db()->prepare(
        'INSERT INTO scheduled_exports (user_id, platform, post_url, access_token, params, export_format, scheduled_at)
         VALUES (:uid, :platform, :post_url, :token, :params, :format, :sched)'
    );
    $stmt->execute([
        ':uid'      => (int) $user['id'],
        ':platform' => $platform,
        ':post_url' => $postUrl,
        ':token'    => encrypt_token($accessToken),
        ':params'   => $params ? json_encode($params) : null,
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
