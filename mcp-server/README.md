# commentpull MCP Server

Connects Claude to [commentpull.com](https://commentpull.com) so you can extract, filter, and analyze social-media comments directly inside any Claude conversation.

Two deployment options are included:

| Option | Files | Hosting |
|--------|-------|---------|
| **PHP** (recommended) | `mcp.php` + `config.php` | Hostinger shared hosting — upload and done |
| **Node.js** | `index.js` + `fly.toml` + `Dockerfile` | Fly.io free tier (always-on) |

---

## Tools exposed

| Tool | What it does |
|------|-------------|
| `extract_youtube_comments` | Fetch comments from any YouTube video |
| `extract_facebook_comments` | Fetch comments from a Facebook post |
| `extract_instagram_comments` | Fetch comments from an Instagram post/reel |
| `filter_comments` | Filter by keyword, mentions, length, date range, duplicates, excluded users |
| `pick_winners` | Randomly pick one or more winners (optional seed for auditability) |
| `export_comments` | Export results as CSV or JSON text |

---

## Option A — PHP on Hostinger (shared hosting)

### 1. Add your API keys

Open `config.php` and add your keys:

```php
define('MCP_API_KEYS', [
    'key_alice_abc123',
    'key_bob_def456',
]);
```

Generate a key: `php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"`

### 2. Upload to Hostinger

Upload both files via Hostinger File Manager or FTP:

```
public_html/
└── mcp/
    ├── mcp.php
    └── config.php
```

Your endpoint will be:
```
https://commentpull.com/mcp/mcp.php
```

### 3. Connect from Claude Desktop

Open `~/Library/Application Support/Claude/claude_desktop_config.json` (Mac) or  
`%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "commentpull": {
      "type": "http",
      "url": "https://commentpull.com/mcp/mcp.php",
      "headers": {
        "x-api-key": "YOUR_KEY_HERE"
      }
    }
  }
}
```

### 4. Connect from Claude Code (CLI)

```bash
claude mcp add --transport http commentpull https://commentpull.com/mcp/mcp.php \
  --header "x-api-key: YOUR_KEY_HERE"
```

---

## Option B — Node.js on Fly.io (always-on free tier)

### Prerequisites

```bash
brew install flyctl   # Mac
fly auth signup       # or: fly auth login
```

### Deploy

```bash
cd mcp-server
fly launch            # detects Dockerfile + fly.toml
fly secrets set API_KEYS="key_user1,key_user2"
fly deploy
```

Your endpoint: `https://commentpull-mcp.fly.dev/sse`

### Connect from Claude Desktop

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
