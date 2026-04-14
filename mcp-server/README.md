# commentpull MCP Server

Connects Claude to [commentpull.com](https://commentpull.com) so you can extract, filter, and analyze social-media comments directly inside any Claude conversation.

Hosted on Railway — no installation needed for end users. Just add the URL to your Claude config.

## Tools exposed

| Tool | What it does |
|------|-------------|
| `extract_youtube_comments` | Fetch comments from any YouTube video |
| `extract_facebook_comments` | Fetch comments from a Facebook post |
| `extract_instagram_comments` | Fetch comments from an Instagram post/reel |
| `filter_comments` | Filter by keyword, mentions, length, date range, duplicates, excluded users |
| `pick_winners` | Randomly pick one or more winners (fair Fisher-Yates shuffle, optional seed) |
| `export_comments` | Export results as CSV or JSON text |

---

## Deploy to Railway (one-time setup)

### 1. Push to GitHub
Make sure the `mcp-server/` folder is in a GitHub repo (the root commentpull.com repo works fine).

### 2. Create a Railway project

1. Go to [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub Repo**
2. Select your repo
3. Under **Settings → Build**, set **Root Directory** to `mcp-server`
4. Railway picks up `railway.toml` automatically — no extra config needed

### 3. Set environment variables in Railway

In your Railway service → **Variables** tab, add:

```
API_KEYS=key_user1,key_user2,key_user3
```

Generate a unique key per user/plan (any random string, e.g. `openssl rand -hex 16`).
Do **not** set `PORT` — Railway manages that automatically.

### 4. Get your public URL

Railway gives you a URL like:
```
https://commentpull-mcp.up.railway.app
```

---

## Connect from Claude Desktop

Open `~/Library/Application Support/Claude/claude_desktop_config.json` (Mac) or  
`%APPDATA%\Claude\claude_desktop_config.json` (Windows) and add:

```json
{
  "mcpServers": {
    "commentpull": {
      "type": "sse",
      "url": "https://commentpull-mcp.up.railway.app/sse",
      "headers": {
        "x-api-key": "YOUR_KEY_HERE"
      }
    }
  }
}
```

Restart Claude Desktop — the commentpull tools will appear automatically.

## Connect from Claude Code (CLI)

```bash
claude mcp add --transport sse commentpull https://commentpull-mcp.up.railway.app/sse \
  --header "x-api-key: YOUR_KEY_HERE"
```

---

## Local development

```bash
cd mcp-server
npm install

# Start with no auth (dev mode)
node index.js

# Start with auth
API_KEYS=testkey node index.js
```

Test endpoints:
```bash
# Health check
curl http://localhost:3000/health

# SSE connection (should stream events)
curl -H "x-api-key: testkey" http://localhost:3000/sse

# Without key (should return 401)
curl http://localhost:3000/sse
```

---

## Usage examples in Claude

**YouTube giveaway:**
> "Fetch comments from `https://youtube.com/watch?v=VIDEO_ID` using API key `AIzaSy…`. Keep only comments with 2+ @mentions, then pick 3 winners."

**Facebook comment export:**
> "Pull comments from this Facebook post [URL]. My commentpull token is `…` and page ID is `123456`. Give me a CSV."

**Auditable giveaway:**
> "Pick a winner with seed 42 — I want to be able to show the result is reproducible."

---

## Credentials needed

| Platform | What you need |
|----------|--------------|
| YouTube | [YouTube Data API v3 key](https://console.developers.google.com/) |
| Facebook | commentpull.com account token + Facebook Page ID |
| Instagram | commentpull.com account token + Facebook Page ID linked to IG |

Get your commentpull token by signing in at [commentpull.com](https://commentpull.com).
