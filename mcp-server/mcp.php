<?php
/**
 * commentpull MCP Server — PHP edition
 *
 * Implements the MCP Streamable HTTP transport (spec 2025-03-26).
 * Runs on any PHP 7.4+ shared hosting (Hostinger, cPanel, etc.)
 *
 * Deploy:
 *   1. Upload mcp.php + config.php to a folder on your Hostinger site
 *      e.g.  commentpull.com/mcp/mcp.php
 *   2. Edit config.php — add your API keys
 *   3. Point Claude at:  https://commentpull.com/mcp/mcp.php
 */

// ── CORS — required so Claude Desktop can reach this endpoint ────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-api-key, Authorization, Mcp-Session-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Config ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!empty(MCP_API_KEYS)) {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    if (!in_array($key, MCP_API_KEYS, true)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or missing API key']);
        exit;
    }
}

header('Content-Type: application/json');
set_time_limit(120); // allow up to 2 min for large fetches

// ── Parse JSON-RPC request ───────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || empty($body['method'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON-RPC request']);
    exit;
}

$method = $body['method'];
$id     = $body['id'] ?? null;
$params = $body['params'] ?? [];

// ── JSON-RPC helpers ──────────────────────────────────────────────────────────
function rpc_ok($id, $result)
{
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}
function rpc_err($id, $code, $msg)
{
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id,
        'error' => ['code' => $code, 'message' => $msg]]);
}
function tool_ok($text)
{
    return ['content' => [['type' => 'text', 'text' => $text]]];
}
function tool_fail($msg)
{
    return ['content' => [['type' => 'text', 'text' => 'Error: ' . $msg]], 'isError' => true];
}

// ── Tool definitions ──────────────────────────────────────────────────────────
$TOOLS = [
    [
        'name'        => 'extract_youtube_comments',
        'description' => 'Fetch all comments (and optionally replies) from a YouTube video.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'url'             => ['type' => 'string',  'description' => 'YouTube video URL or bare video ID.'],
                'api_key'         => ['type' => 'string',  'description' => 'YouTube Data API v3 key (AIzaSy…).'],
                'include_replies' => ['type' => 'boolean', 'description' => 'Fetch replies too. Default: true.'],
                'max_comments'    => ['type' => 'number',  'description' => 'Cap on comments fetched. Default: 500.'],
            ],
            'required' => ['url', 'api_key'],
        ],
    ],
    [
        'name'        => 'extract_facebook_comments',
        'description' => 'Fetch all comments from a Facebook post via commentpull.com.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'post_url'        => ['type' => 'string',  'description' => 'Full Facebook post URL.'],
                'page_id'         => ['type' => 'string',  'description' => 'Facebook Page ID (numeric string).'],
                'cp_token'        => ['type' => 'string',  'description' => 'Your commentpull.com Bearer token.'],
                'include_replies' => ['type' => 'boolean', 'description' => 'Fetch replies too. Default: true.'],
            ],
            'required' => ['post_url', 'page_id', 'cp_token'],
        ],
    ],
    [
        'name'        => 'extract_instagram_comments',
        'description' => 'Fetch all comments from an Instagram post via commentpull.com.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'post_url'        => ['type' => 'string',  'description' => 'Full Instagram post/reel URL.'],
                'page_id'         => ['type' => 'string',  'description' => 'Facebook Page ID linked to your IG Business account.'],
                'cp_token'        => ['type' => 'string',  'description' => 'Your commentpull.com Bearer token.'],
                'include_replies' => ['type' => 'boolean', 'description' => 'Fetch replies too. Default: true.'],
            ],
            'required' => ['post_url', 'page_id', 'cp_token'],
        ],
    ],
    [
        'name'        => 'filter_comments',
        'description' => 'Apply filter rules to a list of comments. Returns only matching comments.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'comments'      => ['type' => 'array',   'description' => 'Comments from any extract_* tool.', 'items' => ['type' => 'object']],
                'keyword'       => ['type' => 'string',  'description' => 'Keep comments containing this keyword/hashtag (case-insensitive).'],
                'min_mentions'  => ['type' => 'number',  'description' => 'Minimum number of @mentions required.'],
                'min_length'    => ['type' => 'number',  'description' => 'Minimum comment character length.'],
                'dedup'         => ['type' => 'boolean', 'description' => 'Keep only the first comment per unique user.'],
                'date_from'     => ['type' => 'string',  'description' => 'ISO date YYYY-MM-DD — keep comments on or after this date.'],
                'date_to'       => ['type' => 'string',  'description' => 'ISO date YYYY-MM-DD — keep comments on or before this date.'],
                'exclude_users' => ['type' => 'string',  'description' => 'Comma-separated usernames to exclude.'],
            ],
            'required' => ['comments'],
        ],
    ],
    [
        'name'        => 'pick_winners',
        'description' => 'Randomly pick one or more winners from a comments list (giveaway draw).',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'comments' => ['type' => 'array',  'description' => 'Comments to draw from.', 'items' => ['type' => 'object']],
                'count'    => ['type' => 'number', 'description' => 'Number of winners to pick. Default: 1.'],
                'seed'     => ['type' => 'number', 'description' => 'Optional integer seed for a reproducible, auditable draw.'],
            ],
            'required' => ['comments'],
        ],
    ],
    [
        'name'        => 'export_comments',
        'description' => 'Convert a comments list to CSV or JSON text.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'comments' => ['type' => 'array', 'description' => 'Comments to export.', 'items' => ['type' => 'object']],
                'format'   => ['type' => 'string', 'enum' => ['csv', 'json'], 'description' => '"csv" or "json". Default: "csv".'],
            ],
            'required' => ['comments'],
        ],
    ],
];

// ── HTTP helpers (curl) ───────────────────────────────────────────────────────
function curl_get($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'commentpull-mcp/1.0',
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body, true)];
}

function curl_post_json($url, $data, $extra_headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'commentpull-mcp/1.0',
        CURLOPT_HTTPHEADER     => array_merge(
            ['Content-Type: application/json'],
            $extra_headers
        ),
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body, true)];
}

// ── Tool implementations ──────────────────────────────────────────────────────

function tool_extract_youtube($args)
{
    $url     = $args['url'] ?? '';
    $api_key = $args['api_key'] ?? '';
    $replies = $args['include_replies'] ?? true;
    $max     = (int)($args['max_comments'] ?? 500);

    // Extract video ID
    $video_id = null;
    if (preg_match('/youtu\.be\/([A-Za-z0-9_-]{11})/', $url, $m))         $video_id = $m[1];
    elseif (preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $url, $m))          $video_id = $m[1];
    elseif (preg_match('/youtube\.com\/(?:shorts|embed|v)\/([A-Za-z0-9_-]{11})/', $url, $m)) $video_id = $m[1];
    elseif (preg_match('/^[A-Za-z0-9_-]{11}$/', $url))                    $video_id = $url;

    if (!$video_id) return tool_fail("Cannot extract video ID from: $url");

    $results    = [];
    $page_token = '';
    $base       = 'https://www.googleapis.com/youtube/v3';

    do {
        $params = http_build_query([
            'part'       => $replies ? 'snippet,replies' : 'snippet',
            'videoId'    => $video_id,
            'key'        => $api_key,
            'maxResults' => 100,
            'textFormat' => 'plainText',
            'pageToken'  => $page_token,
        ]);
        [$status, $data] = curl_get("$base/commentThreads?$params");

        if (!empty($data['error']))
            return tool_fail($data['error']['message'] ?? 'YouTube API error');

        foreach (($data['items'] ?? []) as $thread) {
            if (count($results) >= $max) break 2;
            $top = $thread['snippet']['topLevelComment']['snippet'];
            $results[] = [
                'post_id'        => $video_id,
                'commenter_name' => $top['authorDisplayName'] ?? 'Unknown',
                'commenter_id'   => $top['authorChannelId']['value'] ?? '',
                'comment_text'   => $top['textDisplay'] ?? '',
                'timestamp'      => $top['publishedAt'] ?? '',
                'comment_id'     => $thread['snippet']['topLevelComment']['id'] ?? '',
                'reply_to'       => '',
            ];
            if ($replies && !empty($thread['replies']['comments'])) {
                foreach ($thread['replies']['comments'] as $reply) {
                    if (count($results) >= $max) break 2;
                    $rs = $reply['snippet'];
                    $results[] = [
                        'post_id'        => $video_id,
                        'commenter_name' => $rs['authorDisplayName'] ?? 'Unknown',
                        'commenter_id'   => $rs['authorChannelId']['value'] ?? '',
                        'comment_text'   => $rs['textDisplay'] ?? '',
                        'timestamp'      => $rs['publishedAt'] ?? '',
                        'comment_id'     => $reply['id'] ?? '',
                        'reply_to'       => $thread['snippet']['topLevelComment']['id'],
                    ];
                }
            }
        }
        $page_token = $data['nextPageToken'] ?? '';
    } while ($page_token && count($results) < $max);

    return tool_ok(json_encode([
        'platform'       => 'youtube',
        'video_id'       => $video_id,
        'total_comments' => count($results),
        'comments'       => $results,
    ], JSON_PRETTY_PRINT));
}

function tool_extract_meta($platform, $args)
{
    $headers = ['Authorization: Bearer ' . ($args['cp_token'] ?? '')];
    [$status, $json] = curl_post_json(
        'https://commentpull.com/backend/api/fetch-comments.php',
        [
            'platform'        => $platform,
            'post_url'        => $args['post_url'] ?? '',
            'page_id'         => $args['page_id'] ?? '',
            'include_replies' => $args['include_replies'] ?? true,
        ],
        $headers
    );
    if ($status !== 200 || empty($json))
        return tool_fail($json['error'] ?? "Server error (HTTP $status)");

    $comments = $json['comments'] ?? [];
    return tool_ok(json_encode([
        'platform'       => $platform,
        'post_url'       => $args['post_url'] ?? '',
        'total_comments' => count($comments),
        'comments'       => $comments,
    ], JSON_PRETTY_PRINT));
}

function tool_filter($args)
{
    $comments = $args['comments'] ?? [];
    $out      = $comments;

    if (!empty($args['keyword'])) {
        $kw  = strtolower($args['keyword']);
        $out = array_values(array_filter($out, fn($c) =>
            str_contains(strtolower($c['comment_text'] ?? ''), $kw)
        ));
    }

    if (isset($args['min_mentions'])) {
        $n   = (int)$args['min_mentions'];
        $out = array_values(array_filter($out, fn($c) =>
            preg_match_all('/@\w+/', $c['comment_text'] ?? '') >= $n
        ));
    }

    if (isset($args['min_length'])) {
        $n   = (int)$args['min_length'];
        $out = array_values(array_filter($out, fn($c) =>
            mb_strlen($c['comment_text'] ?? '') >= $n
        ));
    }

    if (!empty($args['dedup'])) {
        $seen = [];
        $out  = array_values(array_filter($out, function ($c) use (&$seen) {
            $key = strtolower($c['commenter_id'] ?: $c['commenter_name'] ?? '');
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    }

    if (!empty($args['date_from'])) {
        $from = strtotime($args['date_from']);
        $out  = array_values(array_filter($out, fn($c) =>
            !empty($c['timestamp']) && strtotime($c['timestamp']) >= $from
        ));
    }

    if (!empty($args['date_to'])) {
        $to  = strtotime($args['date_to'] . ' 23:59:59');
        $out = array_values(array_filter($out, fn($c) =>
            !empty($c['timestamp']) && strtotime($c['timestamp']) <= $to
        ));
    }

    if (!empty($args['exclude_users'])) {
        $excluded = array_map('strtolower', array_filter(array_map('trim', explode(',', $args['exclude_users']))));
        $out      = array_values(array_filter($out, fn($c) =>
            !in_array(strtolower($c['commenter_name'] ?? ''), $excluded, true)
        ));
    }

    return tool_ok(json_encode([
        'original_count' => count($comments),
        'filtered_count' => count($out),
        'comments'       => $out,
    ], JSON_PRETTY_PRINT));
}

function tool_pick_winners($args)
{
    $comments = $args['comments'] ?? [];
    $count    = max(1, (int)($args['count'] ?? 1));
    $seed     = isset($args['seed']) ? (int)$args['seed'] : null;

    if (empty($comments)) return tool_fail('No comments to draw from.');

    $pool = $comments;

    if ($seed !== null) {
        srand($seed);
        usort($pool, fn() => rand(-1, 1));  // seeded shuffle
    } else {
        shuffle($pool);
    }

    $winners = array_slice($pool, 0, min($count, count($pool)));
    $ranked  = array_map(fn($w, $i) => array_merge(['rank' => $i + 1], $w),
                         $winners, array_keys($winners));

    return tool_ok(json_encode([
        'pool_size'    => count($comments),
        'winner_count' => count($ranked),
        'seed'         => $seed,
        'winners'      => $ranked,
    ], JSON_PRETTY_PRINT));
}

function tool_export($args)
{
    $comments = $args['comments'] ?? [];
    $format   = $args['format'] ?? 'csv';

    if ($format === 'json') {
        return tool_ok(json_encode($comments, JSON_PRETTY_PRINT));
    }

    // CSV
    $cols = ['#', 'commenter_name', 'commenter_id', 'comment_text', 'timestamp', 'post_id', 'comment_id', 'reply_to'];
    $buf  = fopen('php://temp', 'r+');
    fputcsv($buf, $cols);
    foreach ($comments as $i => $c) {
        fputcsv($buf, [
            $i + 1,
            $c['commenter_name'] ?? '',
            $c['commenter_id']   ?? '',
            $c['comment_text']   ?? '',
            $c['timestamp']      ?? '',
            $c['post_id']        ?? '',
            $c['comment_id']     ?? '',
            $c['reply_to']       ?? '',
        ]);
    }
    rewind($buf);
    $csv = stream_get_contents($buf);
    fclose($buf);

    return tool_ok($csv);
}

// ── MCP method router ─────────────────────────────────────────────────────────
switch ($method) {

    case 'initialize':
        rpc_ok($id, [
            'protocolVersion' => '2025-03-26',
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'commentpull', 'version' => '1.0.0'],
        ]);
        break;

    case 'notifications/initialized':
        // Notification — no response expected
        http_response_code(204);
        break;

    case 'tools/list':
        global $TOOLS;
        rpc_ok($id, ['tools' => $TOOLS]);
        break;

    case 'tools/call':
        $name      = $params['name']      ?? '';
        $tool_args = $params['arguments'] ?? [];

        switch ($name) {
            case 'extract_youtube_comments':
                rpc_ok($id, tool_extract_youtube($tool_args));
                break;
            case 'extract_facebook_comments':
                rpc_ok($id, tool_extract_meta('fb', $tool_args));
                break;
            case 'extract_instagram_comments':
                rpc_ok($id, tool_extract_meta('ig', $tool_args));
                break;
            case 'filter_comments':
                rpc_ok($id, tool_filter($tool_args));
                break;
            case 'pick_winners':
                rpc_ok($id, tool_pick_winners($tool_args));
                break;
            case 'export_comments':
                rpc_ok($id, tool_export($tool_args));
                break;
            default:
                rpc_err($id, -32601, "Unknown tool: $name");
        }
        break;

    case 'ping':
        rpc_ok($id, new stdClass());
        break;

    default:
        // Unknown methods return -32601 per JSON-RPC spec
        rpc_err($id, -32601, "Method not found: $method");
}
