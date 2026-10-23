# CommentPull

> Extract, filter and export comments from Facebook, Instagram and YouTube posts — and pick a giveaway winner.

**Live site:** [commentpull.com](https://commentpull.com) · **Guide:** [commentpull.com/guide](https://commentpull.com/guide)

> ⚠️ **Beta.** CommentPull is under active development. Features can change or break, the service may be unavailable at times, and results can be incomplete (Facebook changes its page layout often). Don't rely on it for anything critical yet.

## Open source — check the code yourself

This repository contains everything: the website, the PHP backend and the browser extension. You don't have to take our word for what the service does — read the code, or ask someone you trust to.

Note: publishing the code lets you verify what the software is *designed* to do; it can't prove what is running on the live server at any moment. The [Privacy Policy](https://commentpull.com/privacy.html) and [Terms](https://commentpull.com/terms.html) describe how the live service operates. Found a security issue? Please email **privacy@commentpull.com** (or open an issue for non-sensitive bugs).

## How it works

| Platform | How comments are collected | What touches our server |
|----------|---------------------------|-------------------------|
| **Facebook** | The **browser extension** reads the comments Facebook already shows to *your* logged-in account, inside *your* browser, and hands them to the CommentPull page. | Nothing. Comments are not sent to our server. |
| **Instagram** | *Coming soon* — planned to work the same way as Facebook, through the browser extension. (The old Meta Graph API integration has been removed.) | — |
| **YouTube** | You paste your own YouTube Data API key; your browser requests comments from Google directly. | Nothing, unless you use Pro Scheduled Exports (the key is stored encrypted to run the export later). |

CommentPull does **not** use the Facebook or Instagram APIs, and does not store Meta access tokens.

Features: filters (keyword, mentions, length, dates, one entry per person), winner picker, export to CSV / Excel / JSON, plus the Pro Scheduled Exports feature (YouTube).

## Install the browser extension (Facebook)

The extension is not on the Chrome Web Store yet, so you load it manually. Works in Chrome, Edge, Brave and other Chromium browsers (Safari and Firefox are not supported yet).

1. Download this repository (**Code → Download ZIP**, or `git clone https://github.com/Arthurdotself/CommentPull.git`) and unzip it somewhere you'll keep it.
2. Open `chrome://extensions` (`edge://extensions`, `brave://extensions`).
3. Turn on **Developer mode**.
4. Click **Load unpacked** and select the **`browser-extension`** folder (the one containing `manifest.json`).
5. Pin **CommentPull Lab** to the toolbar.
6. Go to [commentpull.com](https://commentpull.com), choose Facebook, paste a post URL and press **Extract Comments**. Keep the Facebook tab open until it finishes.

More detail and troubleshooting: [commentpull.com/guide](https://commentpull.com/guide) and [`browser-extension/README.md`](browser-extension/README.md).

**What the extension does:** runs only on `facebook.com` and `commentpull.com`, requests only the `activeTab` and `storage` permissions, makes no network requests of its own, and keeps collected comments in its local storage until you clear them. It scrolls the page and clicks Facebook's "view more comments / replies" controls to load more. Automated collection may go against Facebook's terms — use it only on content you're allowed to view and process.

## Project structure

```
├── *.html, lang.js, components.js   # Frontend (Tailwind via CDN, vanilla JS, EN/AR)
├── build.js                         # Generates /ar/*.html from the English sources
├── backend/                         # PHP API (auth, Stripe billing, scheduled exports)
│   ├── .htaccess                    # Denies direct web access to everything in /backend
│   ├── config.example.php           # Copy to config.php and fill in your own values
│   ├── schema.sql                   # MySQL schema (fresh installs)
│   ├── migrations/                  # SQL for upgrading an existing database
│   ├── api/  middleware/  cron/
├── browser-extension/               # Chromium (Manifest V3) extension
└── Asset/, Favicon/
```

## Self-hosting / development

Requirements: PHP 8.1+, MySQL/MariaDB, Apache with `mod_rewrite` (see `.htaccess`), Node.js (only for `build.js`).

1. Create a database and run `backend/schema.sql`.
2. Copy `backend/config.example.php` to `backend/config.php` and fill in your own database, OAuth (Google, GitHub), Stripe and encryption values. **Never commit `config.php`** — it is in `.gitignore`. Upgrading an old database? Run `backend/migrations/2026-10-remove-meta-and-history.sql` once (back up first).
3. Edit the English pages and `lang.js`, then run `node build.js` to regenerate the Arabic pages in `/ar/` and the extension zip.
4. Set up a cron job (CLI only) for `backend/cron/process-scheduled-exports.php` (every minute) if you use Scheduled Exports.

## Contributing

Issues and pull requests are welcome. For larger changes, please open an issue first.

## License

The README previously referenced the MIT license. Make sure a `LICENSE` file is present in the repository root; the license in that file governs use of the source code. The CommentPull name and logo are not licensed for reuse.

---

<p align="center">CommentPull is a beta project. Questions: <a href="mailto:privacy@commentpull.com">privacy@commentpull.com</a></p>
