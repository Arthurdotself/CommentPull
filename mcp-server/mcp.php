<?php
/**
 * commentpull MCP Server — PHP edition
 *
 * Supports two transports on a single file:
 *
 *   GET  /mcp/mcp.php          → SSE transport  (Claude Desktop)
 *   POST /mcp/mcp.php?s=ID     → SSE message handler (Claude Desktop)
 *   POST /mcp/mcp.php          → Streamable HTTP (Claude Code CLI)
 *
 * Deploy: upload mcp.php + config.php to public_html/mcp/ on Hostinger.
 */

// ── CORS ─────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

set_time_limit(0); // allow long-running SSE connections

// ── Transport router ──────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handle_sse();
} elseif ($method === 'POST' && isset($_GET['s'])) {
    handle_sse_message($_GET['s']);
} elseif ($method === 'POST') {
    handle_streamable_http();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  TRANSPORT HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * SSE transport — Claude Desktop connects here via GET.
 * Sends an `endpoint` event immediately, then polls for messages.
 */
function handle_sse()
{
    $sid      = bin2hex(random_bytes(8));
    $req_file = tmp_path($sid);

    // Build the POST endpoint URL Claude will use for messages
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $path     = strtok($_SERVER['REQUEST_URI'], '?');
    $endpoint = "{$scheme}://{$host}{$path}?s={$sid}";

    // SSE headers — disable every layer of buffering
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');        // nginx
    @ini_set('zlib.output_compression', 0); // gzip off
    while (ob_get_level() > 0) ob_end_flush();
    ob_implicit_flush(true);

    // Tell Claude where to POST messages (MCP SSE spec)
    sse_write('endpoint', $endpoint);

    // Poll for incoming messages (max 5 min, keep-alive ping every 20 s)
    $deadline   = time() + 300;
    $last_ping  = time();

    while (time() < $deadline) {
        if (connection_aborted()) break;

        // Keep-alive so proxies don't drop the connection
        if (time() - $last_ping >= 20) {
            echo ": ping\n\n";
            flush();
            $last_ping = time();
        }

        if (file_exists($req_file)) {
            $raw = file_get_contents($req_file);
            @unlink($req_file);

            $request  = json_decode($raw, true);
            $response = $request ? dispatch($request) : null;

            if ($response !== null) {
                sse_write('message', json_encode($response));
            }
        }

        usleep(200000); // 200 ms poll
    }

    exit;
}

/**
 * SSE message endpoint — Claude Desktop POSTs JSON-RPC here.
 * Just writes the request to a temp file; the SSE stream picks it up.
 */
function handle_sse_message($raw_sid)
{
    $sid = preg_replace('/[^a-f0-9]/', '', $raw_sid);
    if (strlen($sid) !== 16) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid session ID']);
        exit;
    }

    file_put_contents(tmp_path($sid), file_get_contents('php://input'));

    http_response_code(202);
    header('Content-Type: application/json');
    echo '{}';
    exit;
}

/**
 * Streamable HTTP transport — Claude Code CLI POSTs JSON-RPC here.
 * Responds with JSON directly (no persistent connection needed).
 */
function handle_streamable_http()
{
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || empty($body['method'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON-RPC request']);
        exit;
    }

    $response = dispatch($body);

    if ($response === null) {
        http_response_code(204);
        exit;
    }

    echo json_encode($response);
    exit;
}

// ─── helpers ─────────────────────────────────────────────────────────────────

function tmp_path($sid)
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "cp_mcp_{$sid}.req";
}

function sse_write($event, $data)
{
    echo "event: {$event}\ndata: {$data}\n\n";
    flush();
}

// ═══════════════════════════════════════════════════════════════════════════════
//  JSON-RPC DISPATCHER
// ═══════════════════════════════════════════════════════════════════════════════

function dispatch($body)
{
    global $TOOLS;
    $method = $body['method'] ?? '';
    $id     = $body['id']     ?? null;
    $params = $body['params'] ?? [];

    switch ($method) {

        case 'initialize':
            return rpc_ok($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities'    => ['tools' => new stdClass()],
                'serverInfo'      => ['name' => 'commentpull', 'version' => '1.0.0'],
            ]);

        case 'notifications/initialized':
            return null; // notifications never get a response

        case 'tools/list':
            return rpc_ok($id, ['tools' => $TOOLS]);

        case 'tools/call':
            $result = call_tool($params['name'] ?? '', $params['arguments'] ?? []);
            return rpc_ok($id, $result);

        case 'ping':
            return rpc_ok($id, new stdClass());

        default:
            return rpc_err($id, -32601, "Method not found: {$method}");
    }
}

function call_tool($name, $args)
{
    switch ($name) {
        case 'extract_youtube_comments':  return tool_extract_youtube($args);
        case 'extract_facebook_comments': return tool_extract_meta('fb', $args);
        case 'extract_instagram_comments':return tool_extract_meta('ig', $args);
        case 'filter_comments':           return tool_filter($args);
        case 'pick_winners':              return tool_pick_winners($args);
        case 'export_comments':           return tool_export($args);
        default:                          return tool_fail("Unknown tool: {$name}");
    }
}

// ─── JSON-RPC helpers ─────────────────────────────────────────────────────────
function rpc_ok($id, $result)  { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; }
function rpc_err($id, $c, $m) { return ['jsonrpc' => '2.0', 'id' => $id, 'error'  => ['code' => $c, 'message' => $m]]; }
function tool_ok($text)        { return ['content' => [['type' => 'text', 'text' => $text]]]; }
function tool_fail($msg)       { return ['content' => [['type' => 'text', 'text' => 'Error: ' . $msg]], 'isError' => true]; }

// ═══════════════════════════════════════════════════════════════════════════════
//  TOOL DEFINITIONS
// ═══════════════════════════════════════════════════════════════════════════════

$TOOLS = [
    [
        'name'        => 'extract_youtube_comments',
        'description' => 'Fetch all comments (and optionally replies) from a YouTube video.',
        'inputSchema' => [
            'type' => 'object',
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
            'type' => 'object',
            'properties' => [
                'post_url'        => ['type' => 'string',  'description' => 'Full Facebook post URL.'],
                'page_id'         => ['type' => 'string',  'description' => 'Facebook Page ID (numeric string).'],
                'cp_token'        => ['type' => 'string',  'description' => 'Your commentpull.com Bearer token.'],
                'include_replies' => ['type' => 'boolean', 'description' => 'Include replies. Default: true.'],
            ],
            'required' => ['post_url', 'page_id', 'cp_token'],
        ],
    ],
    [
        'name'        => 'extract_instagram_comments',
        'description' => 'Fetch all comments from an Instagram post via commentpull.com.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'post_url'        => ['type' => 'string',  'description' => 'Full Instagram post/reel URL.'],
                'page_id'         => ['type' => 'string',  'description' => 'Facebook Page ID linked to your IG Business account.'],
                'cp_token'        => ['type' => 'string',  'description' => 'Your commentpull.com Bearer token.'],
                'include_replies' => ['type' => 'boolean', 'description' => 'Include replies. Default: true.'],
            ],
            'required' => ['post_url', 'page_id', 'cp_token'],
        ],
    ],
    [
        'name'        => 'filter_comments',
        'description' => 'Apply filter rules to a list of comments. Returns only matching comments.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'comments'      => ['type' => 'array',   'description' => 'Comments from any extract_* tool.', 'items' => ['type' => 'object']],
                'keyword'       => ['type' => 'string',  'description' => 'Keep comments containing this keyword/hashtag (case-insensitive).'],
                'min_mentions'  => ['type' => 'number',  'description' => 'Minimum number of @mentions required.'],
                'min_length'    => ['type' => 'number',  'description' => 'Minimum comment character length.'],
                'dedup'         => ['type' => 'boolean', 'description' => 'Keep only the first comment per unique user.'],
                'date_from'     => ['type' => 'string',  'description' => 'ISO date YYYY-MM-DD — keep comments on or after.'],
                'date_to'       => ['type' => 'string',  'description' => 'ISO date YYYY-MM-DD — keep comments on or before.'],
                'exclude_users' => ['type' => 'string',  'description' => 'Comma-separated usernames to exclude.'],
            ],
            'required' => ['comments'],
        ],
    ],
    [
        'name'        => 'pick_winners',
        'description' => 'Randomly pick one or more winners from a comments list (giveaway draw).',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'comments' => ['type' => 'array',  'description' => 'Comments to draw from.', 'items' => ['type' => 'object']],
                'count'    => ['type' => 'number', 'description' => 'Number of winners. Default: 1.'],
                'seed'     => ['type' => 'number', 'description' => 'Optional integer seed for a reproducible draw.'],
            ],
            'required' => ['comments'],
        ],
    ],
    [
        'name'        => 'export_comments',
        'description' => 'Convert a comments list to CSV or JSON text.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'comments' => ['type' => 'array',  'description' => 'Comments to export.', 'items' => ['type' => 'object']],
                'format'   => ['type' => 'string', 'enum' => ['csv', 'json'], 'description' => '"csv" or "json". Default: "csv".'],
            ],
            'required' => ['comments'],
        ],
    ],
];

// ═══════════════════════════════════════════════════════════════════════════════
//  TOOL IMPLEMENTATIONS
// ═══════════════════════════════════════════════════════════════════════════════

function tool_extract_youtube($args)
{
    $url     = $args['url']     ?? '';
    $api_key = $args['api_key'] ?? '';
    $replies = $args['include_replies'] ?? true;
    $max     = (int)($args['max_comments'] ?? 500);

    $video_id = null;
    if      (preg_match('/youtu\.be\/([A-Za-z0-9_-]{11})/', $url, $m))                         $video_id = $m[1];
    elseif  (preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $url, $m))                             $video_id = $m[1];
    elseif  (preg_match('/youtube\.com\/(?:shorts|embed|v)\/([A-Za-z0-9_-]{11})/', $url, $m)) $video_id = $m[1];
    elseif  (preg_match('/^[A-Za-z0-9_-]{11}$/', $url))                                       $video_id = $url;

    if (!$video_id) return tool_fail("Cannot extract video ID from: {$url}");

    $results    = [];
    $page_token = '';
    $base       = 'https://www.googleapis.com/youtube/v3';

    do {
        $params = http_build_query(array_filter([
            'part'       => $replies ? 'snippet,replies' : 'snippet',
            'videoId'    => $video_id,
            'key'        => $api_key,
            'maxResults' => 100,
            'textFormat' => 'plainText',
            'pageToken'  => $page_token ?: null,
        ]));
        [$status, $data] = http_get("{$base}/commentThreads?{$params}");

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
                    if (count($results) >= $max) break;
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
        'platform' => 'youtube', 'video_id' => $video_id,
        'total_comments' => count($results), 'comments' => $results,
    ], JSON_PRETTY_PRINT));
}

function tool_extract_meta($platform, $args)
{
    [$status, $json] = http_post(
        'https://commentpull.com/backend/api/fetch-comments.php',
        [
            'platform'        => $platform,
            'post_url'        => $args['post_url'] ?? '',
            'page_id'         => $args['page_id']  ?? '',
            'include_replies' => $args['include_replies'] ?? true,
        ],
        ['Authorization: Bearer ' . ($args['cp_token'] ?? '')]
    );

    if ($status !== 200 || empty($json))
        return tool_fail($json['error'] ?? "Server error (HTTP {$status})");

    $comments = $json['comments'] ?? [];
    return tool_ok(json_encode([
        'platform' => $platform, 'post_url' => $args['post_url'] ?? '',
        'total_comments' => count($comments), 'comments' => $comments,
    ], JSON_PRETTY_PRINT));
}

function tool_filter($args)
{
    $out = $args['comments'] ?? [];

    if (!empty($args['keyword'])) {
        $kw  = strtolower($args['keyword']);
        $out = array_values(array_filter($out,
            fn($c) => str_contains(strtolower($c['comment_text'] ?? ''), $kw)));
    }
    if (isset($args['min_mentions'])) {
        $n   = (int)$args['min_mentions'];
        $out = array_values(array_filter($out,
            fn($c) => preg_match_all('/@\w+/', $c['comment_text'] ?? '') >= $n));
    }
    if (isset($args['min_length'])) {
        $n   = (int)$args['min_length'];
        $out = array_values(array_filter($out,
            fn($c) => mb_strlen($c['comment_text'] ?? '') >= $n));
    }
    if (!empty($args['dedup'])) {
        $seen = [];
        $out  = array_values(array_filter($out, function ($c) use (&$seen) {
            $k = strtolower($c['commenter_id'] ?: ($c['commenter_name'] ?? ''));
            if (isset($seen[$k])) return false;
            return $seen[$k] = true;
        }));
    }
    if (!empty($args['date_from'])) {
        $from = strtotime($args['date_from']);
        $out  = array_values(array_filter($out,
            fn($c) => !empty($c['timestamp']) && strtotime($c['timestamp']) >= $from));
    }
    if (!empty($args['date_to'])) {
        $to  = strtotime($args['date_to'] . ' 23:59:59');
        $out = array_values(array_filter($out,
            fn($c) => !empty($c['timestamp']) && strtotime($c['timestamp']) <= $to));
    }
    if (!empty($args['exclude_users'])) {
        $ex  = array_map('strtolower', array_filter(array_map('trim', explode(',', $args['exclude_users']))));
        $out = array_values(array_filter($out,
            fn($c) => !in_array(strtolower($c['commenter_name'] ?? ''), $ex, true)));
    }

    return tool_ok(json_encode([
        'original_count' => count($args['comments'] ?? []),
        'filtered_count' => count($out),
        'comments'       => $out,
    ], JSON_PRETTY_PRINT));
}

function tool_pick_winners($args)
{
    $pool  = $args['comments'] ?? [];
    $count = max(1, (int)($args['count'] ?? 1));
    $seed  = isset($args['seed']) ? (int)$args['seed'] : null;

    if (empty($pool)) return tool_fail('No comments to draw from.');

    if ($seed !== null) {
        srand($seed);
        usort($pool, fn() => rand(-1, 1));
    } else {
        shuffle($pool);
    }

    $winners = array_slice($pool, 0, min($count, count($pool)));
    $ranked  = array_map(fn($w, $i) => array_merge(['rank' => $i + 1], $w),
                         $winners, array_keys($winners));

    return tool_ok(json_encode([
        'pool_size'    => count($args['comments']),
        'winner_count' => count($ranked),
        'seed'         => $seed,
        'winners'      => $ranked,
    ], JSON_PRETTY_PRINT));
}

function tool_export($args)
{
    $comments = $args['comments'] ?? [];
    $format   = $args['format']   ?? 'csv';

    if ($format === 'json') {
        return tool_ok(json_encode($comments, JSON_PRETTY_PRINT));
    }

    $buf = fopen('php://temp', 'r+');
    fputcsv($buf, ['#', 'commenter_name', 'commenter_id', 'comment_text', 'timestamp', 'post_id', 'comment_id', 'reply_to']);
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

// ═══════════════════════════════════════════════════════════════════════════════
//  HTTP HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function http_get($url)
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

function http_post($url, $data, $extra_headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'commentpull-mcp/1.0',
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $extra_headers),
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body, true)];
}
