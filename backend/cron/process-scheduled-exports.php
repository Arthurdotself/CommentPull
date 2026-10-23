<?php
/**
 * CommentPull — Scheduled Export Processor (YouTube)
 *
 * Fetches pending scheduled exports whose scheduled_at has passed and runs them.
 * CLI ONLY — this script refuses to run over HTTP (and /backend is blocked by .htaccess anyway).
 *
 * Setup on Hostinger (hPanel → Cron Jobs):
 *   Command : php /home/<your-user>/public_html/backend/cron/process-scheduled-exports.php
 *   Interval: Every minute  (* * * * *)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

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
require_once __DIR__ . '/../helpers.php';

// ── Constants ──────────────────────────────────────────────────────
define('YT_API',    'https://www.googleapis.com/youtube/v3');
define('MAX_BATCH', 10); // max exports processed per run

// ── Clean up expired sessions ──────────────────────────────────────
$deleted = db()->exec('DELETE FROM sessions WHERE expires_at < NOW()');
if ($deleted > 0) log_msg("Cleaned up {$deleted} expired session(s).");

// ── Fetch pending exports ──────────────────────────────────────────
$stmt = db()->prepare(
    'SELECT * FROM scheduled_exports
     WHERE status = "pending" AND scheduled_at <= UTC_TIMESTAMP()
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
        $comments     = runExport($export);
        $total        = count($comments);
        $commentsJson = json_encode($comments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        db()->prepare(
            'UPDATE scheduled_exports SET status = "completed", total_count = :total, comments = :comments WHERE id = :id'
        )->execute([
            ':id'       => $export['id'],
            ':total'    => $total,
            ':comments' => $commentsJson,
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
    if ($export['platform'] !== 'yt') {
        throw new RuntimeException('Unsupported platform: ' . $export['platform']);
    }
    return fetchYtComments($export['post_url'], decrypt_token($export['access_token']));
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
