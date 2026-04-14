#!/usr/bin/env node
/**
 * commentpull MCP Server
 *
 * Exposes social-media comment extraction as MCP tools so Claude can:
 *   • fetch comments from YouTube, Facebook, or Instagram posts
 *   • filter them by keyword, mentions, length, date, author exclusions
 *   • pick random winners (giveaway-style)
 *   • export results as CSV or JSON text
 *
 * Usage (after npm install):
 *   node index.js
 *
 * Configure in claude_desktop_config.json or ~/.claude/settings.json (see README).
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

// ─── helpers ────────────────────────────────────────────────────────────────

function ytVideoId(url) {
  url = url.trim();
  let m = url.match(/youtu\.be\/([A-Za-z0-9_-]{11})/);
  if (m) return m[1];
  m = url.match(/[?&]v=([A-Za-z0-9_-]{11})/);
  if (m) return m[1];
  m = url.match(/youtube\.com\/(?:shorts|embed|v)\/([A-Za-z0-9_-]{11})/);
  if (m) return m[1];
  if (/^[A-Za-z0-9_-]{11}$/.test(url)) return url;
  return null;
}

function friendlyMetaError(err) {
  const code = err.code;
  const msg  = err.message || '';
  if (code === 190) return 'Token expired or invalid — generate a new token in Graph API Explorer.';
  if (code === 10)  return 'Missing permission: token needs "pages_read_engagement".';
  if (code === 200) return 'Permission denied — token does not have access to this page/post.';
  if (code === 100) return 'Invalid ID or parameter — check your Page ID and post URL.';
  if (code === 4 || code === 17 || code === 32) return 'Rate limit reached — wait a few minutes and retry.';
  if (code === 803) return 'Post not found — URL may be wrong or post was deleted.';
  return msg.replace(/^\(#\d+\)\s*/, '');
}

// Fetch all pages of YouTube comments for a video
async function fetchYouTubeComments(videoId, apiKey, includeReplies = true, maxComments = 500) {
  const BASE = 'https://www.googleapis.com/youtube/v3';
  const results = [];
  let pageToken = '';

  do {
    const params = new URLSearchParams({
      part: includeReplies ? 'snippet,replies' : 'snippet',
      videoId,
      key: apiKey,
      maxResults: '100',
      textFormat: 'plainText',
    });
    if (pageToken) params.set('pageToken', pageToken);

    const res  = await fetch(`${BASE}/commentThreads?${params}`);
    const data = await res.json();

    if (data.error) throw new Error(data.error.message || JSON.stringify(data.error));

    for (const thread of (data.items || [])) {
      if (results.length >= maxComments) break;
      const top = thread.snippet.topLevelComment.snippet;
      results.push({
        post_id:        videoId,
        commenter_name: top.authorDisplayName ?? 'Unknown',
        commenter_id:   top.authorChannelId?.value ?? '',
        comment_text:   top.textDisplay ?? '',
        timestamp:      top.publishedAt ?? '',
        comment_id:     thread.snippet.topLevelComment.id ?? '',
        reply_to:       '',
      });

      if (includeReplies && thread.replies?.comments) {
        for (const reply of thread.replies.comments) {
          if (results.length >= maxComments) break;
          const rs = reply.snippet;
          results.push({
            post_id:        videoId,
            commenter_name: rs.authorDisplayName ?? 'Unknown',
            commenter_id:   rs.authorChannelId?.value ?? '',
            comment_text:   rs.textDisplay ?? '',
            timestamp:      rs.publishedAt ?? '',
            comment_id:     reply.id ?? '',
            reply_to:       thread.snippet.topLevelComment.id,
          });
        }
      }
    }

    if (results.length >= maxComments) break;
    pageToken = data.nextPageToken ?? '';
  } while (pageToken);

  return results;
}

// Call the commentpull.com backend for FB / IG
async function fetchMetaComments(platform, postUrl, pageId, cpToken, includeReplies = true) {
  const res = await fetch('https://commentpull.com/backend/api/fetch-comments.php', {
    method:  'POST',
    headers: {
      'Content-Type':  'application/json',
      ...(cpToken ? { Authorization: `Bearer ${cpToken}` } : {}),
    },
    body: JSON.stringify({ platform, post_url: postUrl, page_id: pageId, include_replies: includeReplies }),
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.error || `Server error ${res.status}`);
  return json.comments || [];
}

// Apply filter rules to a comments array (mirrors frontend logic)
function applyFilters(comments, filters = {}) {
  let out = [...comments];

  if (filters.keyword) {
    const kw = filters.keyword.toLowerCase();
    out = out.filter(c => c.comment_text.toLowerCase().includes(kw));
  }

  if (filters.min_mentions != null) {
    const n = Number(filters.min_mentions);
    out = out.filter(c => (c.comment_text.match(/@\w+/g) || []).length >= n);
  }

  if (filters.min_length != null) {
    const n = Number(filters.min_length);
    out = out.filter(c => c.comment_text.length >= n);
  }

  if (filters.dedup) {
    const seen = new Set();
    out = out.filter(c => {
      const key = (c.commenter_id || c.commenter_name).toLowerCase();
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  if (filters.date_from) {
    const from = new Date(filters.date_from);
    out = out.filter(c => c.timestamp && new Date(c.timestamp) >= from);
  }

  if (filters.date_to) {
    const to = new Date(filters.date_to);
    to.setHours(23, 59, 59, 999);
    out = out.filter(c => c.timestamp && new Date(c.timestamp) <= to);
  }

  if (filters.exclude_users) {
    const excluded = filters.exclude_users
      .split(',')
      .map(s => s.trim().toLowerCase())
      .filter(Boolean);
    out = out.filter(c => !excluded.includes(c.commenter_name.toLowerCase()));
  }

  return out;
}

// Cryptographically fair Fisher-Yates shuffle using Math.random (good enough for giveaways)
function pickWinners(comments, count = 1, seed = null) {
  const pool = [...comments];
  // optional deterministic seed via mulberry32
  let rand = Math.random;
  if (seed != null) {
    let s = Number(seed) >>> 0;
    rand = () => {
      s += 0x6D2B79F5;
      let t = s;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  for (let i = pool.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [pool[i], pool[j]] = [pool[j], pool[i]];
  }
  return pool.slice(0, Math.min(count, pool.length));
}

// Convert comment array to CSV text
function toCSV(comments) {
  const header = ['#', 'commenter_name', 'commenter_id', 'comment_text', 'timestamp', 'post_id', 'comment_id', 'reply_to'];
  const rows = comments.map((c, i) =>
    [
      i + 1,
      c.commenter_name,
      c.commenter_id,
      c.comment_text,
      c.timestamp,
      c.post_id,
      c.comment_id,
      c.reply_to ?? '',
    ].map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')
  );
  return [header.join(','), ...rows].join('\n');
}

// ─── tool definitions ────────────────────────────────────────────────────────

const TOOLS = [
  {
    name: 'extract_youtube_comments',
    description: 'Fetch all comments (and optionally replies) from a YouTube video. Returns a structured list of comments.',
    inputSchema: {
      type: 'object',
      properties: {
        url: {
          type: 'string',
          description: 'Full YouTube video URL (e.g. https://www.youtube.com/watch?v=xxxx) or just the video ID.',
        },
        api_key: {
          type: 'string',
          description: 'YouTube Data API v3 key (starts with AIzaSy…). Get one at console.developers.google.com.',
        },
        include_replies: {
          type: 'boolean',
          description: 'Whether to include replies to top-level comments. Default: true.',
        },
        max_comments: {
          type: 'number',
          description: 'Maximum number of comments to fetch. Default: 500.',
        },
      },
      required: ['url', 'api_key'],
    },
  },
  {
    name: 'extract_facebook_comments',
    description: 'Fetch all comments from a Facebook post via the commentpull.com backend. Requires a commentpull account token.',
    inputSchema: {
      type: 'object',
      properties: {
        post_url: {
          type: 'string',
          description: 'Full URL of the Facebook post.',
        },
        page_id: {
          type: 'string',
          description: 'Facebook Page ID that owns the post (numeric string).',
        },
        cp_token: {
          type: 'string',
          description: 'Your commentpull.com Bearer token (sign in at commentpull.com to get one).',
        },
        include_replies: {
          type: 'boolean',
          description: 'Whether to include replies. Default: true.',
        },
      },
      required: ['post_url', 'page_id', 'cp_token'],
    },
  },
  {
    name: 'extract_instagram_comments',
    description: 'Fetch all comments from an Instagram post via the commentpull.com backend. Requires a commentpull account token.',
    inputSchema: {
      type: 'object',
      properties: {
        post_url: {
          type: 'string',
          description: 'Full URL of the Instagram post or reel.',
        },
        page_id: {
          type: 'string',
          description: 'Facebook Page ID linked to your Instagram Business account.',
        },
        cp_token: {
          type: 'string',
          description: 'Your commentpull.com Bearer token.',
        },
        include_replies: {
          type: 'boolean',
          description: 'Whether to include replies. Default: true.',
        },
      },
      required: ['post_url', 'page_id', 'cp_token'],
    },
  },
  {
    name: 'filter_comments',
    description: 'Apply filter rules to a list of comments (output from any extract_* tool). Returns only comments that pass all active rules.',
    inputSchema: {
      type: 'object',
      properties: {
        comments: {
          type: 'array',
          description: 'Array of comment objects returned by extract_* tools.',
          items: { type: 'object' },
        },
        keyword: {
          type: 'string',
          description: 'Only keep comments containing this keyword or hashtag (case-insensitive).',
        },
        min_mentions: {
          type: 'number',
          description: 'Only keep comments with at least this many @mentions.',
        },
        min_length: {
          type: 'number',
          description: 'Only keep comments with at least this many characters.',
        },
        dedup: {
          type: 'boolean',
          description: 'Keep only the first comment per unique user (one entry per person).',
        },
        date_from: {
          type: 'string',
          description: 'ISO date string (YYYY-MM-DD) — only keep comments on or after this date.',
        },
        date_to: {
          type: 'string',
          description: 'ISO date string (YYYY-MM-DD) — only keep comments on or before this date.',
        },
        exclude_users: {
          type: 'string',
          description: 'Comma-separated list of usernames to exclude.',
        },
      },
      required: ['comments'],
    },
  },
  {
    name: 'pick_winners',
    description: 'Randomly pick one or more winners from a list of comments (e.g. for a giveaway). Uses a fair shuffle.',
    inputSchema: {
      type: 'object',
      properties: {
        comments: {
          type: 'array',
          description: 'Array of comment objects to draw winners from.',
          items: { type: 'object' },
        },
        count: {
          type: 'number',
          description: 'Number of winners to pick. Default: 1.',
        },
        seed: {
          type: 'number',
          description: 'Optional integer seed for a reproducible (auditable) draw.',
        },
      },
      required: ['comments'],
    },
  },
  {
    name: 'export_comments',
    description: 'Convert a list of comments to CSV or JSON text so it can be saved or pasted into a spreadsheet.',
    inputSchema: {
      type: 'object',
      properties: {
        comments: {
          type: 'array',
          description: 'Array of comment objects.',
          items: { type: 'object' },
        },
        format: {
          type: 'string',
          enum: ['csv', 'json'],
          description: 'Output format: "csv" (spreadsheet-ready) or "json". Default: "csv".',
        },
      },
      required: ['comments'],
    },
  },
];

// ─── server ──────────────────────────────────────────────────────────────────

const server = new Server(
  { name: 'commentpull', version: '1.0.0' },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOLS }));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  try {
    switch (name) {

      case 'extract_youtube_comments': {
        const videoId = ytVideoId(args.url);
        if (!videoId) throw new Error(`Could not extract a video ID from URL: ${args.url}`);

        const comments = await fetchYouTubeComments(
          videoId,
          args.api_key,
          args.include_replies ?? true,
          args.max_comments ?? 500
        );

        return {
          content: [{
            type: 'text',
            text: JSON.stringify({
              platform:       'youtube',
              video_id:       videoId,
              total_comments: comments.length,
              comments,
            }, null, 2),
          }],
        };
      }

      case 'extract_facebook_comments': {
        const comments = await fetchMetaComments(
          'fb',
          args.post_url,
          args.page_id,
          args.cp_token,
          args.include_replies ?? true
        );

        return {
          content: [{
            type: 'text',
            text: JSON.stringify({
              platform:       'facebook',
              post_url:       args.post_url,
              total_comments: comments.length,
              comments,
            }, null, 2),
          }],
        };
      }

      case 'extract_instagram_comments': {
        const comments = await fetchMetaComments(
          'ig',
          args.post_url,
          args.page_id,
          args.cp_token,
          args.include_replies ?? true
        );

        return {
          content: [{
            type: 'text',
            text: JSON.stringify({
              platform:       'instagram',
              post_url:       args.post_url,
              total_comments: comments.length,
              comments,
            }, null, 2),
          }],
        };
      }

      case 'filter_comments': {
        const filtered = applyFilters(args.comments, args);
        return {
          content: [{
            type: 'text',
            text: JSON.stringify({
              original_count: args.comments.length,
              filtered_count: filtered.length,
              comments:       filtered,
            }, null, 2),
          }],
        };
      }

      case 'pick_winners': {
        const winners = pickWinners(args.comments, args.count ?? 1, args.seed);
        return {
          content: [{
            type: 'text',
            text: JSON.stringify({
              pool_size:   args.comments.length,
              winner_count: winners.length,
              seed:        args.seed ?? null,
              winners:     winners.map((w, i) => ({ rank: i + 1, ...w })),
            }, null, 2),
          }],
        };
      }

      case 'export_comments': {
        const format = args.format ?? 'csv';
        const output = format === 'json'
          ? JSON.stringify(args.comments, null, 2)
          : toCSV(args.comments);

        return {
          content: [{
            type: 'text',
            text: output,
          }],
        };
      }

      default:
        throw new Error(`Unknown tool: ${name}`);
    }
  } catch (err) {
    return {
      content: [{ type: 'text', text: `Error: ${err.message}` }],
      isError: true,
    };
  }
});

// ─── start ───────────────────────────────────────────────────────────────────

const transport = new StdioServerTransport();
await server.connect(transport);
