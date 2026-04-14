#!/usr/bin/env node
/**
 * commentpull MCP Server — Railway / SSE edition
 *
 * Transport : HTTP + Server-Sent Events (SSEServerTransport)
 * Auth      : x-api-key header (set API_KEYS env var, comma-separated)
 * Deploy    : Railway (see railway.toml and README)
 *
 * Local dev:
 *   API_KEYS=testkey node index.js
 *   curl -H "x-api-key: testkey" http://localhost:3000/sse
 */

import express from 'express';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { SSEServerTransport } from '@modelcontextprotocol/sdk/server/sse.js';
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
        post_id: videoId, commenter_name: top.authorDisplayName ?? 'Unknown',
        commenter_id: top.authorChannelId?.value ?? '', comment_text: top.textDisplay ?? '',
        timestamp: top.publishedAt ?? '', comment_id: thread.snippet.topLevelComment.id ?? '',
        reply_to: '',
      });
      if (includeReplies && thread.replies?.comments) {
        for (const reply of thread.replies.comments) {
          if (results.length >= maxComments) break;
          const rs = reply.snippet;
          results.push({
            post_id: videoId, commenter_name: rs.authorDisplayName ?? 'Unknown',
            commenter_id: rs.authorChannelId?.value ?? '', comment_text: rs.textDisplay ?? '',
            timestamp: rs.publishedAt ?? '', comment_id: reply.id ?? '',
            reply_to: thread.snippet.topLevelComment.id,
          });
        }
      }
    }
    if (results.length >= maxComments) break;
    pageToken = data.nextPageToken ?? '';
  } while (pageToken);

  return results;
}

async function fetchMetaComments(platform, postUrl, pageId, cpToken, includeReplies = true) {
  const res = await fetch('https://commentpull.com/backend/api/fetch-comments.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(cpToken ? { Authorization: `Bearer ${cpToken}` } : {}),
    },
    body: JSON.stringify({ platform, post_url: postUrl, page_id: pageId, include_replies: includeReplies }),
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.error || `Server error ${res.status}`);
  return json.comments || [];
}

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
    out = out.filter(c => c.comment_text.length >= Number(filters.min_length));
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
    const excluded = filters.exclude_users.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
    out = out.filter(c => !excluded.includes(c.commenter_name.toLowerCase()));
  }
  return out;
}

function pickWinners(comments, count = 1, seed = null) {
  const pool = [...comments];
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

function toCSV(comments) {
  const header = ['#', 'commenter_name', 'commenter_id', 'comment_text', 'timestamp', 'post_id', 'comment_id', 'reply_to'];
  const rows = comments.map((c, i) =>
    [i + 1, c.commenter_name, c.commenter_id, c.comment_text, c.timestamp, c.post_id, c.comment_id, c.reply_to ?? '']
      .map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')
  );
  return [header.join(','), ...rows].join('\n');
}

// ─── tool definitions ────────────────────────────────────────────────────────

const TOOLS = [
  {
    name: 'extract_youtube_comments',
    description: 'Fetch all comments (and optionally replies) from a YouTube video.',
    inputSchema: {
      type: 'object',
      properties: {
        url:             { type: 'string',  description: 'YouTube video URL or ID.' },
        api_key:         { type: 'string',  description: 'YouTube Data API v3 key (AIzaSy…).' },
        include_replies: { type: 'boolean', description: 'Include replies. Default: true.' },
        max_comments:    { type: 'number',  description: 'Max comments to fetch. Default: 500.' },
      },
      required: ['url', 'api_key'],
    },
  },
  {
    name: 'extract_facebook_comments',
    description: 'Fetch all comments from a Facebook post via commentpull.com.',
    inputSchema: {
      type: 'object',
      properties: {
        post_url:        { type: 'string',  description: 'Full Facebook post URL.' },
        page_id:         { type: 'string',  description: 'Facebook Page ID (numeric).' },
        cp_token:        { type: 'string',  description: 'commentpull.com Bearer token.' },
        include_replies: { type: 'boolean', description: 'Include replies. Default: true.' },
      },
      required: ['post_url', 'page_id', 'cp_token'],
    },
  },
  {
    name: 'extract_instagram_comments',
    description: 'Fetch all comments from an Instagram post via commentpull.com.',
    inputSchema: {
      type: 'object',
      properties: {
        post_url:        { type: 'string',  description: 'Full Instagram post/reel URL.' },
        page_id:         { type: 'string',  description: 'Facebook Page ID linked to your IG Business account.' },
        cp_token:        { type: 'string',  description: 'commentpull.com Bearer token.' },
        include_replies: { type: 'boolean', description: 'Include replies. Default: true.' },
      },
      required: ['post_url', 'page_id', 'cp_token'],
    },
  },
  {
    name: 'filter_comments',
    description: 'Apply filter rules to a comments array. Returns only matching comments.',
    inputSchema: {
      type: 'object',
      properties: {
        comments:      { type: 'array',   description: 'Comments from any extract_* tool.', items: { type: 'object' } },
        keyword:       { type: 'string',  description: 'Keep only comments containing this keyword/hashtag.' },
        min_mentions:  { type: 'number',  description: 'Minimum @mentions required.' },
        min_length:    { type: 'number',  description: 'Minimum comment character length.' },
        dedup:         { type: 'boolean', description: 'Keep only first comment per user.' },
        date_from:     { type: 'string',  description: 'ISO date (YYYY-MM-DD) — comments on or after.' },
        date_to:       { type: 'string',  description: 'ISO date (YYYY-MM-DD) — comments on or before.' },
        exclude_users: { type: 'string',  description: 'Comma-separated usernames to exclude.' },
      },
      required: ['comments'],
    },
  },
  {
    name: 'pick_winners',
    description: 'Randomly pick one or more winners from a comments list (giveaway draw).',
    inputSchema: {
      type: 'object',
      properties: {
        comments: { type: 'array',  description: 'Comments to draw from.', items: { type: 'object' } },
        count:    { type: 'number', description: 'Number of winners. Default: 1.' },
        seed:     { type: 'number', description: 'Optional integer seed for a reproducible draw.' },
      },
      required: ['comments'],
    },
  },
  {
    name: 'export_comments',
    description: 'Convert comments to CSV or JSON text.',
    inputSchema: {
      type: 'object',
      properties: {
        comments: { type: 'array', description: 'Comments to export.', items: { type: 'object' } },
        format:   { type: 'string', enum: ['csv', 'json'], description: '"csv" or "json". Default: "csv".' },
      },
      required: ['comments'],
    },
  },
];

// ─── server factory ──────────────────────────────────────────────────────────
// Called once per SSE connection so each session has its own Server instance.

function buildServer() {
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
          if (!videoId) throw new Error(`Cannot extract video ID from: ${args.url}`);
          const comments = await fetchYouTubeComments(videoId, args.api_key, args.include_replies ?? true, args.max_comments ?? 500);
          return { content: [{ type: 'text', text: JSON.stringify({ platform: 'youtube', video_id: videoId, total_comments: comments.length, comments }, null, 2) }] };
        }

        case 'extract_facebook_comments': {
          const comments = await fetchMetaComments('fb', args.post_url, args.page_id, args.cp_token, args.include_replies ?? true);
          return { content: [{ type: 'text', text: JSON.stringify({ platform: 'facebook', post_url: args.post_url, total_comments: comments.length, comments }, null, 2) }] };
        }

        case 'extract_instagram_comments': {
          const comments = await fetchMetaComments('ig', args.post_url, args.page_id, args.cp_token, args.include_replies ?? true);
          return { content: [{ type: 'text', text: JSON.stringify({ platform: 'instagram', post_url: args.post_url, total_comments: comments.length, comments }, null, 2) }] };
        }

        case 'filter_comments': {
          const filtered = applyFilters(args.comments, args);
          return { content: [{ type: 'text', text: JSON.stringify({ original_count: args.comments.length, filtered_count: filtered.length, comments: filtered }, null, 2) }] };
        }

        case 'pick_winners': {
          const winners = pickWinners(args.comments, args.count ?? 1, args.seed);
          return { content: [{ type: 'text', text: JSON.stringify({ pool_size: args.comments.length, winner_count: winners.length, seed: args.seed ?? null, winners: winners.map((w, i) => ({ rank: i + 1, ...w })) }, null, 2) }] };
        }

        case 'export_comments': {
          const fmt = args.format ?? 'csv';
          return { content: [{ type: 'text', text: fmt === 'json' ? JSON.stringify(args.comments, null, 2) : toCSV(args.comments) }] };
        }

        default:
          throw new Error(`Unknown tool: ${name}`);
      }
    } catch (err) {
      return { content: [{ type: 'text', text: `Error: ${err.message}` }], isError: true };
    }
  });

  return server;
}

// ─── API key middleware ───────────────────────────────────────────────────────

const VALID_KEYS = new Set(
  (process.env.API_KEYS || '').split(',').map(k => k.trim()).filter(Boolean)
);

function requireApiKey(req, res, next) {
  if (VALID_KEYS.size === 0) { next(); return; }  // dev mode: no keys configured = open
  const key = req.headers['x-api-key'] || req.query['api_key'];
  if (VALID_KEYS.has(key)) { next(); return; }
  res.status(401).json({ error: 'Invalid or missing API key' });
}

// ─── HTTP + SSE server ───────────────────────────────────────────────────────

const app = express();
app.use(express.json());

const sessions = new Map(); // sessionId → SSEServerTransport

// Claude connects here (GET /sse) — one SSE stream per session
app.get('/sse', requireApiKey, async (req, res) => {
  const transport = new SSEServerTransport('/message', res);
  const mcpServer = buildServer();
  sessions.set(transport.sessionId, transport);
  res.on('close', () => sessions.delete(transport.sessionId));
  await mcpServer.connect(transport);
});

// Claude sends tool calls here (POST /message?sessionId=…)
app.post('/message', requireApiKey, async (req, res) => {
  const transport = sessions.get(req.query.sessionId);
  if (!transport) { res.status(404).json({ error: 'Session not found' }); return; }
  await transport.handlePostMessage(req, res);
});

// Health check for Railway uptime monitoring
app.get('/health', (_req, res) => res.json({ ok: true, sessions: sessions.size }));

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`commentpull MCP server listening on :${PORT}`));
