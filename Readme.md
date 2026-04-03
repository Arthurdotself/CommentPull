# CommentPull 🗂️

> Extract comments & engagement from Facebook and Instagram posts — free, client-side, no backend required.

![CommentPull screenshot](https://i.imgur.com/placeholder.png)

---

## ✨ Features

- **Facebook** — extract all comments from any Page post (supports `pfbid` encoded URLs, multi-post, full pagination)
- **Instagram** — extract all comments from any Business/Creator account post (`/p/`, `/reel/`, `/tv/`)
- **Multi-post** — extract from multiple posts at once in a single run
- **Replies** — optionally include nested replies/threads
- **Export formats** — Excel (`.xlsx` with Summary sheet), CSV, JSON
- **Zero backend** — runs entirely in your browser; your token and data never touch any server
- **Free & open source** — no accounts, no ads, no paywalls

---

## 🚀 Live Demo

👉 **[commentpull.com](commentpull.com)**

---

## 📸 How It Works

### Facebook
1. Go to [Meta Graph API Explorer](https://developers.facebook.com/tools/explorer/)
2. Select your App → your Facebook Page
3. Add permissions: `pages_read_engagement`, `pages_show_list`
4. Generate token → paste into CommentPull
5. Enter your Page ID (or click Auto-detect)
6. Paste post URL(s) → Extract & Download

### Instagram
1. Same token setup as above, additionally add:
   - `instagram_basic`
   - `instagram_manage_comments`
2. Your Instagram must be a **Business or Creator account** linked to a Facebook Page
3. Enter the Facebook Page ID linked to your Instagram (or Auto-detect)
4. Paste Instagram post URL(s) → Extract & Download

---

## 🗂️ Project Structure

```
commentpull/
├── index.html        # Single-file app — the entire tool
└── README.md
```

This is intentionally a **single HTML file** with no build step, no dependencies to install, and no server required. Just open `index.html` in a browser or drop it on any static host.

---

## 🛠️ Deployment

### Option 1 — Static hosting (recommended)

Upload `index.html` to any static host:

| Host | Steps |
|------|-------|
| **GitHub Pages** | Push to repo → Settings → Pages → Deploy from branch |
| **Netlify** | Drag & drop `index.html` into [app.netlify.com](https://app.netlify.com) |
| **Vercel** | `vercel --prod` in the project folder |
| **Your own server** | Copy `index.html` to your web root |

### Option 2 — Run locally

```bash
git clone https://github.com/YOUR_USERNAME/commentpull.git
cd commentpull
# Open directly in browser
open index.html
# Or serve with Python
python3 -m http.server 8080
```

---

## 🔑 Getting a Facebook / Instagram Access Token

1. Go to [developers.facebook.com/tools/explorer](https://developers.facebook.com/tools/explorer/)
2. Click **"Add a Tool"** → select **Graph API Explorer**
3. Under **"Meta App"**, select your app (or create one at [developers.facebook.com](https://developers.facebook.com))
4. Under **"User or Page"**, select your **Facebook Page**
5. Add the required permissions:

| Platform | Required Permissions |
|----------|----------------------|
| Facebook | `pages_read_engagement`, `pages_show_list` |
| Instagram | + `instagram_basic`, `instagram_manage_comments` |

6. Click **"Generate Access Token"** → copy and paste into CommentPull

> ⚠️ Tokens expire. For long-lived tokens, exchange your short-lived token using the [Token Debugger](https://developers.facebook.com/tools/debug/accesstoken/).

---

## 📊 Export Columns

| Column | Description |
|--------|-------------|
| `post_id` | Full post or media ID |
| `commenter_name` | Display name (FB) or username (IG) |
| `commenter_id` | Facebook user ID or Instagram comment ID |
| `comment_text` | Full comment text |
| `timestamp` | ISO 8601 datetime |
| `comment_id` | Unique comment identifier |
| `reply_to` | Parent comment ID (replies only, Instagram) |

Excel exports also include a **Summary** sheet with comment counts per post.

---

## ⚙️ Tech Stack

| Layer | Technology |
|-------|------------|
| Frontend | Vanilla HTML / CSS / JavaScript |
| API | [Meta Graph API v19.0](https://developers.facebook.com/docs/graph-api/) |
| Excel export | [SheetJS (xlsx)](https://sheetjs.com/) via CDN |
| Fonts | [DM Mono + Fraunces](https://fonts.google.com/) via Google Fonts |

No frameworks. No build tools. No Node.js. Just one file.

---

## 🔒 Privacy & Security

- **Your token stays in your browser.** It is never sent to any server other than `graph.facebook.com`.
- **No analytics, no tracking, no ads.**
- **No data is stored** — everything is processed in memory and downloaded directly to your device.
- We recommend using **Page Access Tokens** (not User tokens) to limit scope.

---

## 🗺️ Roadmap

- [x] Facebook Page posts
- [x] Instagram Business/Creator posts
- [x] Multi-post extraction
- [x] Excel / CSV / JSON export
- [x] Replies support
- [ ] TikTok
- [ ] YouTube
- [ ] Twitter / X
- [ ] Scheduled / automated extraction
- [ ] Dark/light theme toggle

---

## 🤝 Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss what you'd like to change.

```bash
# Fork the repo, then:
git clone https://github.com/Arthurdotself/commentpull.git
cd commentpull
# Make your changes to index.html
git commit -m "feat: your change"
git push origin main
# Open a Pull Request
```

---

## 📄 License

[MIT](LICENSE) — free to use, modify, and distribute.

---

## 🙏 Acknowledgements

Built with the [Meta Graph API](https://developers.facebook.com/docs/graph-api/) and [SheetJS](https://sheetjs.com/).  
Inspired by the need for a simple, free, no-account-required comment extraction tool.

---

<p align="center">Made with ❤️ · Free forever · No ads · No data stored</p>
