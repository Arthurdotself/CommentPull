<?php
/**
 * CommentPull — Scheduled Export Processor
 *
 * Fetches pending scheduled exports whose scheduled_at has passed and runs them.
 *
 * Setup on Hostinger (hPanel → Cron Jobs):
 *   Command : php /home/u373169281/public_html/backend/cron/process-scheduled-exports.php
 *   Interval: Every minute  (* * * * *)
 *
 * Or call via HTTP with the secret key (useful if CLI cron is unavailable):
 *   https://commentpull.com/backend/cron/process-scheduled-exports.php?secret=CRON_SECRET
 */

// ── Bootstrap ──────────────────────────────────────────────────────
define('CRON_RUNNING', true);
$configPath = __DIR__ . '/../config.php';
$dbPath     = __DIR__ . '/../db.php';

if (!file_exists($configPath) || !file_exists($dbPath)) {
    echo "ERROR: config.php or db.php not found\n";
    exit(1);
}

require_once $configPath;
require_once $dbPath;

// ── HTTP security guard ────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    // Allow HTTP invocation only with a secret key defined in config
    $cronSecret = defined('CRON_SECRET') ? CRON_SECRET : null;
    if (!$cronSecret || ($_GET['secret'] ?? '') !== $cronSecret) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// ── Constants ──────────────────────────────────────────────────────
define('GRAPH_API', 'https://graph.facebook.com/v19.0');
define('YT_API',    'https://www.googleapis.com/youtube/v3');
define('MAX_BATCH', 10); // max exports processed per run

// ── Fetch pending exports ──────────────────────────────────────────
$stmt = db()->prepare(
    'SELECT * FROM scheduled_exports
     WHERE status = "pending" AND scheduled_at <= NOW()
     ORDER BY scheduled_at ASC
     LIMIT ' . MAX_BATCH
);
$stmt->execute();
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($exports)) {
    log_msg('No pending exports due.');
    exit(0);
}

log_msg('Processing ' . count($exports) . ' export(s)…');

foreach ($exports as $export) {
    log_msg("Export #{$export['id']} — platform={$export['platform']} url={$export['post_url']}");

    // Mark as processing (prevents duplicate runs if cron overlaps)
    db()->prepare('UPDATE scheduled_exports SET status = "processing" WHERE id = :id AND status = "pending"')
        ->execute([':id' => $export['id']]);

    try {
        $comments = runExport($export);
        $total    = count($comments);

        db()->prepare(
            'UPDATE scheduled_exports SET status = "completed", total_count = :total, comments = :comments WHERE id = :id'
        )->execute([
            ':id'       => $export['id'],
            ':total'    => $total,
            ':comments' => json_encode($comments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        log_msg("  ✓ Completed — {$total} comment(s)");

    } catch (Throwable $e) {
        $msg = substr($e->getMessage(), 0, 1000);
        db()->prepare(
            'UPDATE scheduled_exports SET status = "failed", error_message = :err WHERE id = :id'
        )->execute([':id' => $export['id'], ':err' => $msg]);

        log_msg("  ✕ Failed — {$msg}");
    }
}

log_msg('Done.');
exit(0);

// ── Helpers ────────────────────────────────────────────────────────
function log_msg(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}

function runExport(array $export): array {
    $platform    = $export['platform'];
    $postUrl     = $export['post_url'];
    $accessToken = $export['access_token'];
    $params      = $export['params'] ? json_decode($export['params'], true) : [];

    return match ($platform) {
        'fb' => fetchFbComments($postUrl, $accessToken, $params),
        'ig' => fetchIgComments($postUrl, $accessToken, $params),
        'yt' => fetchYtComments($postUrl, $accessToken),
        default => throw new RuntimeException("Unknown platform: {$platform}"),
    };
}

// ── Facebook Graph API cURL helper ─────────────────────────────────
function graphGet(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['User-Agent: CommentPull-Cron/1.0'],
    ]);
    $res   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) throw new RuntimeException("cURL error #{$errno}");
    $data = json_decode($res ?: '{}', true);
    if (!is_array($data)) throw new RuntimeException('Invalid JSON from Graph API');
    if (!empty($data['error'])) throw new RuntimeException($data['error']['message'] ?? 'Graph API error');
    return $data;
}

// ── Replicate JS buildFullPostId ───────────────────────────────────
function buildFullPostId(string $link, string $pageId): string {
    $link = trim($link);
    if (!str_starts_with($link, 'http') && str_contains($link, '_')) return $link;
    if (preg_match('/(pfbid[A-Za-z0-9]+)/', $link, $m)) return "{$pageId}_{$m[1]}";
    $patterns = ['/\/posts\/(\d+)/', '/story_fbid=(\d+)/', '/fbid=(\d+)/', '/\/videos\/(\d+)/', '/\/photos\/[^\/]+\/(\d+)/', '/\/(\d{10,})/'];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $link, $m)) {
            $r = $m[1];
            return str_starts_with($r, $pageId) ? $r : "{$pageId}_{$r}";
        }
    }
    if (ctype_digit($link)) return "{$pageId}_{$link}";
    return $link;
}

// ── Facebook: fetch all comments ───────────────────────────────────
function fetchFbComments(string $postUrl, string $token, array $params): array {
    $pageId         = $params['page_id'] ?? '';
    $includeReplies = $params['include_replies'] ?? false;
    $filter         = $includeReplies ? 'stream' : 'toplevel';
    $fullPostId     = buildFullPostId($postUrl, $pageId);

    $results = [];
    $url = GRAPH_API . '/' . rawurlencode($fullPostId) . '/comments?' . http_build_query([
        'access_token' => $token,
        'fields'       => 'id,from,message,created_time',
        'filter'       => $filter,
        'limit'        => '100',
    ]);

    while ($url) {
        $data = graphGet($url);
        foreach ($data['data'] ?? [] as $c) {
            $results[] = [
                'post_id'        => $fullPostId,
                'commenter_name' => $c['from']['name'] ?? 'Unknown',
                'commenter_id'   => $c['from']['id']   ?? '',
                'comment_text'   => $c['message']      ?? '',
                'timestamp'      => $c['created_time'] ?? '',
                'comment_id'     => $c['id']           ?? '',
            ];
        }
        $url = $data['paging']['next'] ?? null;
    }

    return $results;
}

// ── Instagram: fetch all comments ─────────────────────────────────
function fetchIgComments(string $postUrl, string $token, array $params): array {
    $igUserId       = $params['ig_user_id'] ?? '';
    $includeReplies = $params['include_replies'] ?? false;

    // Extract shortcode from URL
    if (!preg_match('/instagram\.com\/(?:p|reel|tv|reels)\/([A-Za-z0-9_-]+)/', $postUrl, $m)) {
        throw new RuntimeException('Could not extract Instagram shortcode from URL');
    }
    $shortcode = $m[1];

    // Resolve media ID from shortcode
    $mediaId = null;
    $url = GRAPH_API . '/' . rawurlencode($igUserId) . '/media?' . http_build_query([
        'access_token' => $token,
        'fields'       => 'id,shortcode,permalink',
        'limit'        => '50',
    ]);
    while ($url && !$mediaId) {
        $data = graphGet($url);
        foreach ($data['data'] ?? [] as $item) {
            if (($item['shortcode'] ?? '') === $shortcode ||
                (isset($item['permalink']) && str_contains($item['permalink'], "/{$shortcode}/"))) {
                $mediaId = $item['id'];
                break;
            }
        }
        $url = $data['paging']['next'] ?? null;
    }
    if (!$mediaId) throw new RuntimeException("Could not find Instagram media with shortcode: {$shortcode}");

    // Fetch comments
    $results = [];
    $url = GRAPH_API . '/' . rawurlencode($mediaId) . '/comments?' . http_build_query([
        'access_token' => $token,
        'fields'       => 'id,username,text,timestamp,replies{id,username,text,timestamp}',
        'limit'        => '100',
    ]);

    while ($url) {
        $data = graphGet($url);
        foreach ($data['data'] ?? [] as $c) {
            $results[] = [
                'post_id'        => $mediaId,
                'commenter_name' => $c['username']  ?? 'Unknown',
                'commenter_id'   => $c['id']        ?? '',
                'comment_text'   => $c['text']      ?? '',
                'timestamp'      => $c['timestamp'] ?? '',
                'comment_id'     => $c['id']        ?? '',
                'reply_to'       => '',
            ];
            if ($includeReplies && !empty($c['replies']['data'])) {
                foreach ($c['replies']['data'] as $r) {
                    $results[] = [
                        'post_id'        => $mediaId,
                        'commenter_name' => $r['username']  ?? 'Unknown',
                        'commenter_id'   => $r['id']        ?? '',
                        'comment_text'   => $r['text']      ?? '',
                        'timestamp'      => $r['timestamp'] ?? '',
                        'comment_id'     => $r['id']        ?? '',
                        'reply_to'       => $c['id'],
                    ];
                }
            }
        }
        $url = $data['paging']['next'] ?? null;
    }

    return $results;
}

// ── YouTube: fetch all comments ────────────────────────────────────
function fetchYtComments(string $videoUrl, string $apiKey): array {
    // Extract video ID
    $videoId = null;
    if      (preg_match('/youtu\.be\/([A-Za-z0-9_-]{11})/', $videoUrl, $m))                       $videoId = $m[1];
    elseif  (preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $videoUrl, $m))                            $videoId = $m[1];
    elseif  (preg_match('/youtube\.com\/(?:shorts|embed|v)\/([A-Za-z0-9_-]{11})/', $videoUrl, $m)) $videoId = $m[1];
    elseif  (preg_match('/^[A-Za-z0-9_-]{11}$/', trim($videoUrl)))                                $videoId = trim($videoUrl);

    if (!$videoId) throw new RuntimeException('Could not extract YouTube video ID from URL');

    $results   = [];
    $pageToken = null;

    do {
        $qp = ['part' => 'snippet', 'videoId' => $videoId, 'key' => $apiKey, 'maxResults' => '100', 'textFormat' => 'plainText'];
        if ($pageToken) $qp['pageToken'] = $pageToken;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => YT_API . '/commentThreads?' . http_build_query($qp),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $res   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) throw new RuntimeException("cURL error #{$errno}");
        $data = json_decode($res ?: '{}', true);
        if (!empty($data['error'])) throw new RuntimeException($data['error']['message'] ?? 'YouTube API error');

        foreach ($data['items'] ?? [] as $item) {
            $top = $item['snippet']['topLevelComment']['snippet'] ?? [];
            $results[] = [
                'post_id'        => $videoId,
                'commenter_name' => $top['authorDisplayName']            ?? 'Unknown',
                'commenter_id'   => $top['authorChannelId']['value']     ?? '',
                'comment_text'   => $top['textDisplay']                  ?? '',
                'timestamp'      => $top['publishedAt']                  ?? '',
                'comment_id'     => $item['snippet']['topLevelComment']['id'] ?? '',
            ];
        }

        $pageToken = $data['nextPageToken'] ?? null;
    } while ($pageToken);

    return $results;
}
