"""
Facebook Giveaway Comment Extractor
------------------------------------
Extracts comments (with commenter names) from Facebook PAGE posts via the Graph API.
Outputs results to an Excel (.xlsx) file ready for your winner-picker tool.

Requirements:
    pip install requests openpyxl

How to get your Page Access Token:
    1. Go to https://developers.facebook.com/tools/explorer/
    2. Select your App → select your Page under "User or Page"
    3. Add permissions: pages_read_engagement, pages_show_list
    4. Click "Generate Access Token" and paste it below

Usage:
    1. Set ACCESS_TOKEN and PAGE_ID below
    2. Add your post URLs or post IDs to POST_LINKS
    3. Run: python facebook_comment_extractor.py
"""

import re
import sys
import requests
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment
from datetime import datetime

# ─────────────────────────────────────────
#  CONFIGURATION — edit these values
# ─────────────────────────────────────────

ACCESS_TOKEN = "EAANhUh4iL5wBRFS2cMXI2zuOuFPZBx8zo3E6kWCNwi0fV3Tc68HDCeJl8GnH8Br33CwU0trely3XRhofkw4E9LeBZBAQePRZCZCNI27RWQtga7ZBYhV3X9ANQBJllou63bFtZBycI7YC9186ZBQIhzUQKZBcYibaKMBJ2FsQStHeyiKt6Kegx5Jy6s094e7pZAz22ZChSygIcij5Rg683ZCV9az37ntZBMBNKaI41pTn1GZAl"

# Your Facebook Page ID (numeric).
# Find it: go to your Page → About → scroll down → Page ID
# Or leave as "" and the script will fetch it automatically from your token.
PAGE_ID = "968441023169325"

POST_LINKS = [
    # Paste post URLs or raw post IDs here, one per line. Examples:
        "https://www.facebook.com/icenter.iraq/posts/1358797512952452",
        "https://www.facebook.com/icenter.iraq/posts/1358756926289844",
    # "1358797512952452",   # raw post ID — script will combine with PAGE_ID automatically
]

OUTPUT_FILE = f"giveaway_comments_{datetime.now().strftime('%Y%m%d_%H%M%S')}.xlsx"

# ─────────────────────────────────────────
#  HELPERS
# ─────────────────────────────────────────

GRAPH_BASE = "https://graph.facebook.com/v19.0"


def get_page_id() -> str:
    """Auto-fetch the Page ID from the token if PAGE_ID is not set."""
    resp = requests.get(
        f"{GRAPH_BASE}/me",
        params={"access_token": ACCESS_TOKEN, "fields": "id,name"},
        timeout=15,
    )
    data = resp.json()
    if "error" in data:
        sys.exit(f"❌  Could not fetch Page ID: {data['error'].get('message')}\n"
                 "    Make sure you are using a PAGE Access Token, not a User token.")
    print(f"✅  Page detected: {data.get('name')} (ID: {data.get('id')})")
    return data["id"]


def build_full_post_id(link: str, page_id: str) -> str:
    """
    Return a properly formatted post ID for the Graph API.
    Page posts must use the format:  PAGE_ID_POST_ID
    If the link already contains PAGE_ID_, return as-is.
    """
    link = link.strip()

    # Already in correct format
    if "_" in link and not link.startswith("http"):
        return link

    # Extract raw numeric post ID from URL or plain ID
    patterns = [
        r"/posts/(\d+)",
        r"story_fbid=(\d+)",
        r"fbid=(\d+)",
        r"/videos/(\d+)",
        r"/photos/[^/]+/(\d+)",
        r"pfbid([A-Za-z0-9]+)",   # encoded pfbid format
        r"/(\d{10,})",
    ]

    # Handle pfbid (encoded post IDs in newer URLs) — use as-is after page prefix
    pfbid_match = re.search(r"(pfbid[A-Za-z0-9]+)", link)
    if pfbid_match:
        raw_id = pfbid_match.group(1)
        return f"{page_id}_{raw_id}"

    for pat in patterns:
        m = re.search(pat, link)
        if m:
            raw_id = m.group(1)
            # Avoid double-prefixing if raw_id already starts with page_id
            if raw_id.startswith(page_id):
                return raw_id
            return f"{page_id}_{raw_id}"

    # Plain numeric ID passed directly
    if re.fullmatch(r"\d+", link):
        return f"{page_id}_{link}"

    print(f"  [WARN] Could not parse post ID from: {link} — using as-is")
    return link


def fetch_comments(full_post_id: str) -> list[dict]:
    """Fetch ALL comments (paginated) for a given PAGE_ID_POST_ID."""
    comments = []
    url = f"{GRAPH_BASE}/{full_post_id}/comments"
    params = {
        "access_token": ACCESS_TOKEN,
        "fields": "id,from,message,created_time",
        "filter": "stream",   # includes all comments, not just top-level
        "limit": 100,
    }

    while url:
        resp = requests.get(url, params=params, timeout=30)
        data = resp.json()

        if "error" in data:
            err = data["error"]
            print(f"  [ERROR] Post {full_post_id} — {err.get('message', err)}")
            break

        for c in data.get("data", []):
            commenter = c.get("from", {})
            comments.append({
                "post_id":        full_post_id,
                "comment_id":     c.get("id", ""),
                "commenter_name": commenter.get("name", "Unknown"),
                "commenter_id":   commenter.get("id", ""),
                "comment_text":   c.get("message", ""),
                "timestamp":      c.get("created_time", ""),
            })

        # Pagination
        paging = data.get("paging", {})
        next_url = paging.get("next")
        if next_url:
            url = next_url
            params = {}          # next URL already contains all params
        else:
            url = None

    return comments


# ─────────────────────────────────────────
#  EXCEL OUTPUT
# ─────────────────────────────────────────

HEADER_FILL   = PatternFill("solid", start_color="1877F2", end_color="1877F2")  # Facebook blue
HEADER_FONT   = Font(bold=True, color="FFFFFF", name="Arial", size=11)
ALT_ROW_FILL  = PatternFill("solid", start_color="EBF3FF", end_color="EBF3FF")
NORMAL_FONT   = Font(name="Arial", size=10)
CENTER        = Alignment(horizontal="center", vertical="center")
LEFT          = Alignment(horizontal="left",   vertical="center", wrap_text=True)

COLUMNS = [
    ("Post ID",         15),
    ("Commenter Name",  28),
    ("Commenter ID",    20),
    ("Comment",         55),
    ("Timestamp",       22),
    ("Comment ID",      22),
]


def write_excel(all_comments: list[dict], path: str):
    wb   = openpyxl.Workbook()
    ws   = wb.active
    ws.title = "Comments"

    # Header row
    headers = [c[0] for c in COLUMNS]
    widths  = [c[1] for c in COLUMNS]
    ws.append(headers)
    for col, (header, width) in enumerate(zip(headers, widths), start=1):
        cell = ws.cell(row=1, column=col)
        cell.fill      = HEADER_FILL
        cell.font      = HEADER_FONT
        cell.alignment = CENTER
        ws.column_dimensions[openpyxl.utils.get_column_letter(col)].width = width

    ws.row_dimensions[1].height = 22

    # Data rows
    for i, c in enumerate(all_comments, start=2):
        row = [
            c["post_id"],
            c["commenter_name"],
            c["commenter_id"],
            c["comment_text"],
            c["timestamp"],
            c["comment_id"],
        ]
        ws.append(row)
        fill = ALT_ROW_FILL if i % 2 == 0 else None
        for col_idx, val in enumerate(row, start=1):
            cell           = ws.cell(row=i, column=col_idx)
            cell.font      = NORMAL_FONT
            cell.alignment = LEFT if col_idx == 4 else CENTER
            if fill:
                cell.fill = fill

    # Summary sheet
    ws2 = wb.create_sheet("Summary")
    ws2.column_dimensions["A"].width = 25
    ws2.column_dimensions["B"].width = 15

    summary_header = ["Post ID", "Comment Count"]
    ws2.append(summary_header)
    for col in range(1, 3):
        cell      = ws2.cell(row=1, column=col)
        cell.fill = HEADER_FILL
        cell.font = HEADER_FONT
        cell.alignment = CENTER

    from collections import Counter
    counts = Counter(c["post_id"] for c in all_comments)
    for r, (pid, cnt) in enumerate(counts.items(), start=2):
        ws2.cell(row=r, column=1, value=pid).alignment   = CENTER
        ws2.cell(row=r, column=2, value=cnt).alignment   = CENTER
        ws2.cell(row=r, column=1).font = NORMAL_FONT
        ws2.cell(row=r, column=2).font = NORMAL_FONT

    # Total row
    total_row = len(counts) + 2
    ws2.cell(row=total_row, column=1, value="TOTAL").font = Font(bold=True, name="Arial")
    ws2.cell(row=total_row, column=2, value=f"=SUM(B2:B{total_row-1})").font = Font(bold=True, name="Arial")
    ws2.cell(row=total_row, column=1).alignment = CENTER
    ws2.cell(row=total_row, column=2).alignment = CENTER

    wb.save(path)
    print(f"\n✅  Saved: {path}")


# ─────────────────────────────────────────
#  MAIN
# ─────────────────────────────────────────

def main():
    if ACCESS_TOKEN == "YOUR_PAGE_ACCESS_TOKEN_HERE":
        sys.exit("❌  Please set your ACCESS_TOKEN before running.")

    if not POST_LINKS:
        sys.exit("❌  Please add at least one post URL/ID to POST_LINKS.")

    # Resolve Page ID
    page_id = PAGE_ID.strip() if PAGE_ID.strip() else get_page_id()

    all_comments = []

    for i, link in enumerate(POST_LINKS, start=1):
        full_id = build_full_post_id(link, page_id)
        print(f"[{i}/{len(POST_LINKS)}] Fetching comments for post: {full_id}")
        comments = fetch_comments(full_id)
        print(f"       → {len(comments)} comment(s) found")
        all_comments.extend(comments)

    print(f"\nTotal comments collected: {len(all_comments)}")

    if all_comments:
        write_excel(all_comments, OUTPUT_FILE)
    else:
        print("⚠️  No comments found. Check your token permissions and post IDs.")


if __name__ == "__main__":
    main()
