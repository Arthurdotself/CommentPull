# commentpull MCP Server

Connects Claude to [commentpull.com](https://commentpull.com) so you can extract, filter, and analyze social-media comments directly inside any Claude conversation.

Hosted on **Fly.io** (free tier, always-on) — no installation needed for end users.

> **Architecture note:** The MCP server is a thin protocol layer. The heavy work (Facebook/Instagram Graph API calls) is handled by your existing PHP backend on Hostinger at `commentpull.com/backend/api/fetch-comments.php`. YouTube calls go directly to Google's API.

## Tools exposed

| Tool | What it does |
|------|-------------|
| `extract_youtube_comments` | Fetch comments from any YouTube video |
| `extract_facebook_comments` | Fetch comments from a Facebook post |
| `extract_instagram_comments` | Fetch comments from an Instagram post/reel |
| `filter_comments` | Filter by keyword, mentions, length, date range, duplicates, excluded users |
| `pick_winners` | Randomly pick one or more winners (fair shuffle, optional seed for auditability) |
| `export_comments` | Export results as CSV or JSON text |

---

## Deploy to Fly.io (free — always-on)

### Prerequisites

```bash
# Install the Fly CLI (Mac/Linux)
brew install flyctl

# Or via script
curl -L https://fly.io/install.sh | sh
```

Then sign up / log in (credit card required for verification, no charge on free tier):

```bash
fly auth signup   # new account
# or
fly auth login    # existing account
```

### 1. Launch the app

```bash
cd mcp-server
fly launch
```

When prompted:
- **App name:** `commentpull-mcp` (or any name you like)
- **Region:** pick the closest to you
- **Would you like to set up a Postgresql/Redis database?** → No
- **Deploy now?** → No (set the secret first)

`fly launch` detects the `Dockerfile` and `fly.toml` automatically.

### 2. Set the API_KEYS secret

```bash
fly secrets set API_KEYS="key_user1,key_user2,key_user3"
```

Generate a unique key per user (e.g. `openssl rand -hex 16`).

### 3. Deploy

```bash
fly deploy
```

Your app is now live at:
```
https://commentpull-mcp.fly.dev
```

### Useful commands

```bash
fly status          # check deployment status
fly logs            # stream live logs
fly secrets list    # list secret names (not values)
fly secrets set API_KEYS="newkey1,newkey2"   # rotate keys
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
      "url": "https://commentpull-mcp.fly.dev/sse",
      "headers": {
        "x-api-key": "YOUR_KEY_HERE"
      }
    }
  }
}
```

Restart Claude Desktop — the commentpull tools appear automatically.

## Connect from Claude Code (CLI)

```bash
claude mcp add --transport sse commentpull https://commentpull-mcp.fly.dev/sse \
  --header "x-api-key: YOUR_KEY_HERE"
```

---

## Local development

```bash
cd mcp-server
npm install

# No auth (dev mode)
node index.js

# With auth
API_KEYS=testkey node index.js
```

Test the server:
```bash
# Health check
curl http://localhost:3000/health

# SSE connection
curl -H "x-api-key: testkey" http://localhost:3000/sse

# Without key → 401
curl http://localhost:3000/sse
```

---

## Usage examples in Claude

**YouTube giveaway:**
> "Fetch comments from `https://youtube.com/watch?v=VIDEO_ID` with API key `AIzaSy…`. Keep only people who tagged 2+ friends, then pick 3 winners."

**Facebook export:**
> "Pull all comments from this Facebook post [URL]. My commentpull token is `…` and page ID is `123456`. Give me a CSV."

**Auditable draw:**
> "Pick a winner with seed 42 — I want anyone to be able to verify the result."

---

## Credentials needed

| Platform | What you need |
|----------|--------------|
| YouTube | [YouTube Data API v3 key](https://console.developers.google.com/) |
| Facebook | commentpull.com Bearer token + Facebook Page ID |
| Instagram | commentpull.com Bearer token + Facebook Page ID linked to IG Business |

Get your commentpull token by signing in at [commentpull.com](https://commentpull.com).
