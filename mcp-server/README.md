# commentpull MCP Server

Connects Claude to [commentpull.com](https://commentpull.com) so you can extract, filter, and analyze social-media comments directly inside a conversation.

## Tools exposed

| Tool | What it does |
|------|-------------|
| `extract_youtube_comments` | Fetch comments from any YouTube video |
| `extract_facebook_comments` | Fetch comments from a Facebook post |
| `extract_instagram_comments` | Fetch comments from an Instagram post/reel |
| `filter_comments` | Filter by keyword, mentions, length, date range, duplicates |
| `pick_winners` | Randomly pick one or more winners (fair Fisher-Yates shuffle) |
| `export_comments` | Export results as CSV or JSON |

---

## Setup

### 1. Install dependencies

```bash
cd mcp-server
npm install
```

### 2. Add to Claude Desktop

Open (or create) `~/Library/Application Support/Claude/claude_desktop_config.json` on Mac, or `%APPDATA%\Claude\claude_desktop_config.json` on Windows, and add:

```json
{
  "mcpServers": {
    "commentpull": {
      "command": "node",
      "args": ["/ABSOLUTE/PATH/TO/commentpull.com/mcp-server/index.js"]
    }
  }
}
```

Replace the path with the actual absolute path on your machine.

### 3. Add to Claude Code (CLI)

Run once to register:

```bash
claude mcp add commentpull -- node /ABSOLUTE/PATH/TO/commentpull.com/mcp-server/index.js
```

Or add it manually to `~/.claude/settings.json`:

```json
{
  "mcpServers": {
    "commentpull": {
      "command": "node",
      "args": ["/ABSOLUTE/PATH/TO/commentpull.com/mcp-server/index.js"]
    }
  }
}
```

---

## Usage examples

### YouTube giveaway

> "Fetch all comments from https://www.youtube.com/watch?v=VIDEO_ID using my YouTube API key `AIzaSy…`, keep only comments that mention at least 2 people, then pick 3 winners."

Claude will chain:
1. `extract_youtube_comments`
2. `filter_comments` (min_mentions: 2)
3. `pick_winners` (count: 3)

### Facebook post analysis

> "Pull comments from this Facebook post [URL]. My commentpull token is `…` and page ID is `123456`. Show me a CSV."

Claude will chain:
1. `extract_facebook_comments`
2. `export_comments` (format: "csv")

### Reproducible giveaway (auditable)

> "Pick a winner with seed 42 so everyone can verify the result."

Pass `seed: 42` to `pick_winners`. Anyone can re-run with the same seed and get the same winner.

---

## Credentials needed

| Platform | What you need |
|----------|--------------|
| YouTube | [YouTube Data API v3 key](https://console.developers.google.com/) |
| Facebook | commentpull.com account token + Facebook Page ID |
| Instagram | commentpull.com account token + Facebook Page ID linked to IG |

Get your commentpull token by signing in at [commentpull.com](https://commentpull.com) and checking your account.
