<?php
/**
 * History API — Pro plan only
 *
 * GET    /backend/api/history.php           → list runs (newest first, paginated)
 * GET    /backend/api/history.php?id=X      → get single run with comments
 * POST   /backend/api/history.php           → save a new run
 * DELETE /backend/api/history.php?id=X      → delete a run
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$user = require_auth();

if ($user['plan'] !== 'pro') {
    json_error(403, 'History is a Pro plan feature');
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

    if ($id) {
        // Single run with full comment payload
        $stmt = db()->prepare(
            'SELECT id, platform, post_urls, total_count, qualified_count, filters, comments, created_at
             FROM runs
             WHERE id = :id AND user_id = :uid
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $user['id']]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$run) {
            json_error(404, 'Run not found');
        }

        $run['id']              = (int) $run['id'];
        $run['total_count']     = (int) $run['total_count'];
        $run['qualified_count'] = (int) $run['qualified_count'];
        $run['post_urls']       = json_decode($run['post_urls'], true) ?? [];
        $run['filters']         = $run['filters'] ? json_decode($run['filters'], true) : null;
        $run['comments']        = $run['comments'] ? json_decode($run['comments'], true) : [];

        json_out($run);
    }

    // List runs — no comments payload, just metadata
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $stmt = db()->prepare(
        'SELECT id, platform, post_urls, total_count, qualified_count, filters, created_at
         FROM runs
         WHERE user_id = :uid
         ORDER BY created_at DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':uid', (int) $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit,            PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,           PDO::PARAM_INT);
    $stmt->execute();
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($runs as &$run) {
        $run['id']              = (int) $run['id'];
        $run['total_count']     = (int) $run['total_count'];
        $run['qualified_count'] = (int) $run['qualified_count'];
        $run['post_urls']       = json_decode($run['post_urls'], true) ?? [];
        $run['filters']         = $run['filters'] ? json_decode($run['filters'], true) : null;
    }
    unset($run);

    $countStmt = db()->prepare('SELECT COUNT(*) FROM runs WHERE user_id = :uid');
    $countStmt->execute([':uid' => $user['id']]);
    $total = (int) $countStmt->fetchColumn();

    json_out(['runs' => $runs, 'total' => $total, 'page' => $page, 'per_page' => $limit]);
}

// ── POST ───────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data = body();

    $platform = $data['platform'] ?? '';
    if (!in_array($platform, ['fb', 'ig', 'yt'], true)) {
        json_error(422, 'Invalid platform — must be fb, ig, or yt');
    }

    $postUrls = $data['post_urls'] ?? [];
    if (!is_array($postUrls) || empty($postUrls)) {
        json_error(422, 'post_urls must be a non-empty array');
    }

    $totalCount     = max(0, (int) ($data['total_count']     ?? 0));
    $qualifiedCount = max(0, (int) ($data['qualified_count'] ?? 0));
    $filters        = isset($data['filters']) && is_array($data['filters']) ? $data['filters'] : null;
    $comments       = isset($data['comments']) && is_array($data['comments']) ? $data['comments'] : [];

    $stmt = db()->prepare(
        'INSERT INTO runs (user_id, platform, post_urls, total_count, qualified_count, filters, comments)
         VALUES (:uid, :platform, :post_urls, :total, :qualified, :filters, :comments)'
    );
    $stmt->execute([
        ':uid'       => (int) $user['id'],
        ':platform'  => $platform,
        ':post_urls' => json_encode($postUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':total'     => $totalCount,
        ':qualified' => $qualifiedCount,
        ':filters'   => $filters ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null,
        ':comments'  => json_encode($comments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $runId = (int) db()->lastInsertId();
    json_out(['id' => $runId, 'created_at' => date('Y-m-d H:i:s')], 201);
}

// ── DELETE ─────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error(422, 'id parameter required');
    }

    $stmt = db()->prepare('DELETE FROM runs WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $id, ':uid' => $user['id']]);

    if ($stmt->rowCount() === 0) {
        json_error(404, 'Run not found');
    }

    json_out(['deleted' => true]);
}

json_error(405, 'Method not allowed');
