<?php
/**
 * POST /backend/api/fetch-comments.php
 * Server-side proxy: fetches Facebook/Instagram comments using the stored
 * OAuth token — the access token never touches the client.
 *
 * Request body (JSON):
 *   { "platform": "fb"|"ig", "post_url": "...", "page_id": "...", "include_replies": true|false }
 *
 * Response:
 *   { "comments": [ { "post_id", "commenter_name", "commenter_id", "comment_text",
 *                     "timestamp", "comment_id", "reply_to" }, ... ] }
 */
require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

session_start();

define('GRAPH_API', 'https://graph.facebook.com/v19.0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed');
}

$data = body();

$platform       = $data['platform']        ?? '';
$postUrl        = trim($data['post_url']   ?? '');
$pageId         = trim($data['page_id']    ?? '');
$includeReplies = (bool) ($data['include_replies'] ?? false);

if (!in_array($platform, ['fb', 'ig'], true)) {
    json_error(422, 'platform must be fb or ig');
}
if (!$postUrl) {
    json_error(422, 'post_url is required');
}
if (!$pageId) {
    json_error(422, 'page_id is required');
}

// ── Retrieve stored user token (DB → session fallback) ─────────────
$userToken = null;
$user = try_auth();
if ($user) {
    $stmt = db()->prepare(
        'SELECT meta_access_token, meta_token_expires FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => (int) $user['id']]);
    $row = $stmt->fetch();
    if ($row && $row['meta_access_token'] && strtotime($row['meta_token_expires']) > time()) {
        try {
            $userToken = decrypt_token($row['meta_access_token']);
        } catch (Throwable $_) {}
    }
}
if (!$userToken) {
    $sessionToken   = $_SESSION['meta_token']         ?? null;
    $sessionExpires = $_SESSION['meta_token_expires']  ?? null;
    if ($sessionToken && $sessionExpires && strtotime($sessionExpires) > time()) {
        $userToken = $sessionToken;
    }
}
if (!$userToken) {
    json_error(401, 'Not connected to Facebook. Please connect your account first.');
}

// ── Get page-specific access token ─────────────────────────────────
$pageData = graphGet(
    GRAPH_API . '/' . rawurlencode($pageId)
    . '?fields=access_token&access_token=' . urlencode($userToken)
);
$pageToken = $pageData['access_token'] ?? null;
if (!$pageToken) {
    json_error(422, 'Could not retrieve page access token. Make sure you manage this Facebook Page.');
}

// ── Fetch comments ──────────────────────────────────────────────────
try {
    if ($platform === 'fb') {
        $comments = fetchFbComments($postUrl, $pageId, $pageToken, $includeReplies);
    } else {
        // Resolve Instagram Business Account ID from the Facebook Page
        $igData   = graphGet(
            GRAPH_API . '/' . rawurlencode($pageId)
            . '?fields=instagram_business_account&access_token=' . urlencode($pageToken)
        );
        $igUserId = $igData['instagram_business_account']['id'] ?? null;
        if (!$igUserId) {
            json_error(422, 'No Instagram Business Account linked to this Facebook Page. Make sure your Instagram is connected as a Business/Creator account.');
        }
        $comments = fetchIgComments($postUrl, $igUserId, $pageToken, $includeReplies);
    }
} catch (RuntimeException $e) {
    json_error(422, $e->getMessage());
}

json_out(['comments' => $comments]);


// ── Graph API cURL helper ───────────────────────────────────────────
function graphGet(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['User-Agent: CommentPull/1.0'],
    ]);
    $res   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) throw new RuntimeException("Connection error (cURL #{$errno}). Please try again.");
    $data = json_decode($res ?: '{}', true);
    if (!is_array($data)) throw new RuntimeException('Invalid response from Facebook API.');
    if (!empty($data['error'])) {
        $msg  = $data['error']['message'] ?? 'Facebook API error';
        $code = $data['error']['code']    ?? 0;
        // Translate common codes to friendly messages
        if ($code === 190) $msg = 'Your Facebook connection has expired. Please reconnect your account.';
        if ($code === 10)  $msg = 'Missing permission: pages_read_engagement. Please reconnect with the required permissions.';
        if ($code === 200) $msg = 'Permission denied — you may not have access to this page or post.';
        if ($code === 100) $msg = 'Invalid post URL or Page ID — please check the URL.';
        if ($code === 4 || $code === 17 || $code === 32) $msg = 'Rate limit reached — please wait a few minutes and try again.';
        if ($code === 803) $msg = 'Post not found — the URL may be wrong or the post was deleted.';
        throw new RuntimeException($msg);
    }
    return $data;
}

// ── Port of JS buildFullPostId ──────────────────────────────────────
function buildFullPostId(string $link, string $pageId): string {
    $link = trim($link);
    if (!str_starts_with($link, 'http') && str_contains($link, '_')) return $link;
    if (preg_match('/(pfbid[A-Za-z0-9]+)/', $link, $m)) return "{$pageId}_{$m[1]}";
    $patterns = [
        '/\/posts\/(\d+)/',
        '/story_fbid=(\d+)/',
        '/fbid=(\d+)/',
        '/\/videos\/(\d+)/',
        '/\/photos\/[^\/]+\/(\d+)/',
        '/\/(\d{10,})/',
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $link, $m)) {
            $r = $m[1];
            return str_starts_with($r, $pageId) ? $r : "{$pageId}_{$r}";
        }
    }
    if (ctype_digit($link)) return "{$pageId}_{$link}";
    return $link;
}

// ── Fetch Facebook comments ─────────────────────────────────────────
function fetchFbComments(string $postUrl, string $pageId, string $token, bool $includeReplies): array {
    $filter     = $includeReplies ? 'stream' : 'toplevel';
    $fullPostId = buildFullPostId($postUrl, $pageId);

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
                'reply_to'       => '',
            ];
        }
        $url = $data['paging']['next'] ?? null;
    }

    return $results;
}

// ── Fetch Instagram comments ────────────────────────────────────────
function fetchIgComments(string $postUrl, string $igUserId, string $token, bool $includeReplies): array {
    // Extract shortcode from URL
    if (!preg_match('/instagram\.com\/(?:p|reel|tv|reels)\/([A-Za-z0-9_-]+)/', $postUrl, $m)) {
        throw new RuntimeException('Could not extract Instagram shortcode from URL. Please paste a valid Instagram post/reel URL.');
    }
    $shortcode = $m[1];

    // Resolve media ID from shortcode (paginate through user's media)
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
    if (!$mediaId) {
        throw new RuntimeException("Could not find this Instagram post in your account. Make sure the post belongs to the selected page's linked Instagram account.");
    }

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
