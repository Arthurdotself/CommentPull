# CommentPull Lab extension (v0.3.0)

Experimental extension that collects Facebook comments **inside your own logged-in browser** - no PHP, no server-side scraping, no Facebook API.

## Install
Step-by-step instructions with screenshots-free walkthrough: https://commentpull.com/guide

1. Open `chrome://extensions` in a Chromium browser and enable Developer mode.
2. Choose Load unpacked and select this folder. After any code change (including manifest changes), press the reload icon on the extension, then refresh the Facebook and commentpull.com tabs.

## Use from commentpull.com (main flow)
1. Paste a Facebook post URL on the home page and press **Extract Comments**.
2. The site asks the extension to open the post in a new tab. The extension expands comments/replies and scrolls (progress badge on the Facebook page). Keep that tab open and stay logged in to Facebook.
3. When it finishes, the Facebook tab closes, you land back on commentpull.com, and the results appear in the normal preview table (filters, winner picker, CSV/Excel/JSON export all work as before).

## Manual use (popup)
Open a Facebook post, click the extension, press **Start collecting** (or **Snapshot visible**), then import the saved results from the "Manual mode" section of https://commentpull.com/guide.

## Output per comment
`username, comment, profileUrl, timestampText, isReply`
The site converts `timestampText` ("6h", "2d") into an approximate date from the capture time.

## Files
- `facebook-content.js` - collector (runs on facebook.com)
- `background.js` - opens/closes the Facebook tab for site-started runs
- `test-page-bridge.js` - message bridge for commentpull.com pages (the home page and the guide's manual import panel)
- `popup.html/js` - manual controls and Debug info

## If it returns 0 comments
Press **Debug info** in the popup (copies a report with no comment text) and adjust `COMMENT_LABEL`, `EXPAND_RE` and the extractors in `facebook-content.js`.

## Notes
- Reads only what Facebook shows your account; does not bypass login or privacy controls.
- Automated collection may violate Facebook's terms. Keep this experimental.
- To go back to the Graph API flow, set `window.CP_USE_EXTENSION = false` in `index.html`.
