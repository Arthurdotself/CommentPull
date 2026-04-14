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
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

session_start();

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
    $scheduledAtDb = gmdate('Y-m-d H:i:s', $schedTs);

    // ── Platform-specific params + server-side token retrieval ────────
    $params      = [];
    $accessToken = null;

    if ($platform === 'yt') {
        // YouTube: API key comes from the request (no OAuth for YouTube)
        $accessToken = trim($data['access_token'] ?? '');
        if (!$accessToken) json_error(422, 'access_token (YouTube API key) is required for YouTube');
    } else {
        // FB / IG: retrieve stored OAuth token from DB or session — never from request body
        $userToken = null;
        $userRow   = db()->prepare('SELECT meta_access_token, meta_token_expires FROM users WHERE id = :id LIMIT 1');
        $userRow->execute([':id' => (int) $user['id']]);
        $tokenRow = $userRow->fetch();
        if ($tokenRow && $tokenRow['meta_access_token'] && strtotime($tokenRow['meta_token_expires']) > time()) {
            try { $userToken = decrypt_token($tokenRow['meta_access_token']); } catch (Throwable $_) {}
        }
        if (!$userToken) {
            $st = $_SESSION['meta_token'] ?? null;
            $se = $_SESSION['meta_token_expires'] ?? null;
            if ($st && $se && strtotime($se) > time()) $userToken = $st;
        }
        if (!$userToken) {
            json_error(401, 'Not connected to Facebook. Please connect your account and try again.');
        }

        // Require page_id from the request (selected from page selector — not sensitive)
        $pageId = trim($data['page_id'] ?? '');
        if (!$pageId) json_error(422, 'page_id is required');

        // Get page-specific access token server-side
        $ptRes = schedGraphGet(
            'https://graph.facebook.com/v19.0/' . rawurlencode($pageId)
            . '?fields=access_token&access_token=' . urlencode($userToken)
        );
        $accessToken = $ptRes['access_token'] ?? null;
        if (!$accessToken) json_error(422, 'Could not retrieve page access token. Make sure you manage this Facebook Page.');

        if ($platform === 'fb') {
            $params['page_id']         = $pageId;
            $params['include_replies'] = (bool) ($data['include_replies'] ?? false);
        } else {
            // IG: resolve Instagram Business Account ID server-side
            $igRes    = schedGraphGet(
                'https://graph.facebook.com/v19.0/' . rawurlencode($pageId)
                . '?fields=instagram_business_account&access_token=' . urlencode($accessToken)
            );
            $igUserId = $igRes['instagram_business_account']['id'] ?? null;
            if (!$igUserId) json_error(422, 'No Instagram Business Account linked to this Facebook Page.');
            $params['ig_user_id']      = $igUserId;
            $params['include_replies'] = (bool) ($data['include_replies'] ?? false);
        }

        // ── Token expiry check vs scheduled time ───────────────────
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
            json_error(422, "Your Facebook connection expires on {$expiryStr} UTC, before the scheduled export time. Please reconnect your account to refresh the token.");
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

// ── Graph API helper (used during POST validation) ──────────────────
function schedGraphGet(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: CommentPull/1.0'],
    ]);
    $res   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) json_error(503, "Could not connect to Facebook (cURL #{$errno})");
    $data = json_decode($res ?: '{}', true);
    if (!is_array($data)) json_error(502, 'Invalid response from Facebook API');
    if (!empty($data['error'])) json_error(422, $data['error']['message'] ?? 'Facebook API error');
    return $data;
}
