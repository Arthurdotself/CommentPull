import re
import sys
import unicodedata
from dataclasses import dataclass
from typing import List, Tuple

import pandas as pd


# -----------------------------
# CONFIG (edit these)
# -----------------------------

# No question or keywords needed for multi-post validation

# Input CSV expected columns:
# username, comment, post_id
# (date/id optional)
USERNAME_COL = "Username"
COMMENT_COL = "Comment"
POST_ID_COL = "Post_ID"
WIDER_FILTER_MAX_MENTIONS = 15
WIDER_FILTER_MIN_MENTIONS = 10
WIDER_FILTER_MIN_POSTS = 15


# -----------------------------
# TEXT NORMALIZATION
# -----------------------------

ARABIC_DIACRITICS = re.compile(r"[\u0610-\u061A\u064B-\u065F\u0670\u06D6-\u06ED]")

def normalize_text(s: str) -> str:
    if s is None:
        return ""
    s = str(s)

    # Unicode normalize
    s = unicodedata.normalize("NFKC", s)

    # Lowercase for Latin
    s = s.lower()

    # Remove Arabic diacritics (tashkeel)
    s = re.sub(ARABIC_DIACRITICS, "", s)

    # Normalize Arabic letters variants
    s = s.replace("أ", "ا").replace("إ", "ا").replace("آ", "ا")
    s = s.replace("ى", "ي").replace("ة", "ه")
    s = s.replace("ؤ", "و").replace("ئ", "ي")

    # Normalize Kurdish/Arabic digits to spaces
    s = re.sub(r"[\d٠-٩۰-۹]", " ", s)

    # Remove punctuation (keep letters + spaces)
    s = re.sub(r"[^\w\s\u0600-\u06FF]", " ", s)

    # Collapse whitespace
    s = re.sub(r"\s+", " ", s).strip()

    return s


# -----------------------------
# VALIDATION LOGIC
# -----------------------------

def contains_mention(text: str) -> bool:
    if text is None:
        return False

    # Check the raw comment text so normalization does not strip '@'.
    return bool(re.search(r"@[A-Za-z0-9._]+", str(text)))


def validate_users(df: pd.DataFrame) -> pd.DataFrame:
    # Get list of unique posts
    posts = df[POST_ID_COL].unique()
    total_posts = len(posts)

    # Group by username and post_id
    user_post_groups = df.groupby([USERNAME_COL, POST_ID_COL])

    # For each user and post, check if they have at least one comment
    user_post_counts = user_post_groups.size().unstack(fill_value=0)

    # For each user and post, check if they have at least one comment with mention
    user_post_mentions = user_post_groups[COMMENT_COL].apply(
        lambda comments: any(contains_mention(c) for c in comments)
    ).unstack(fill_value=False)

    # Users who commented on every post
    users_commented_all = user_post_counts.apply(lambda row: all(row > 0), axis=1)

    # Users who mentioned someone in at least one comment per post
    users_mentioned_all = user_post_mentions.apply(lambda row: all(row), axis=1)

    # Eligible users must comment on every post and mention someone in at least one comment per post
    eligible_users_mask = users_commented_all & users_mentioned_all

    eligible_users = eligible_users_mask[eligible_users_mask].index

    # Count number of posts participated and number of posts with mention per user
    posts_participated = user_post_counts.loc[eligible_users].gt(0).sum(axis=1)
    posts_with_mentions = user_post_mentions.loc[eligible_users].sum(axis=1)

    result_df = pd.DataFrame({
        USERNAME_COL: eligible_users,
        "Posts_Participated": posts_participated,
        "Posts_With_Mention": posts_with_mentions
    })

    return result_df


def summarize_all_users(df: pd.DataFrame) -> pd.DataFrame:
    user_post_groups = df.groupby([USERNAME_COL, POST_ID_COL])

    user_post_counts = user_post_groups.size().unstack(fill_value=0)
    user_post_mentions = user_post_groups[COMMENT_COL].apply(
        lambda comments: any(contains_mention(c) for c in comments)
    ).unstack(fill_value=False)

    result_df = pd.DataFrame({
        USERNAME_COL: user_post_counts.index,
        "Posts_Participated": user_post_counts.gt(0).sum(axis=1),
        "Posts_With_Mention": user_post_mentions.sum(axis=1)
    }).reset_index(drop=True)

    return result_df


def collect_comments_for_users(df: pd.DataFrame, users_df: pd.DataFrame) -> pd.DataFrame:
    selected_users = users_df[USERNAME_COL].dropna().unique()
    return df[df[USERNAME_COL].isin(selected_users)].copy()


# -----------------------------
# MAIN
# -----------------------------

def main(input_csv: str):
    df = pd.read_csv(input_csv)

    if USERNAME_COL not in df.columns or COMMENT_COL not in df.columns or POST_ID_COL not in df.columns:
        raise ValueError(
            f"CSV must contain columns: '{USERNAME_COL}', '{COMMENT_COL}', and '{POST_ID_COL}'. "
            f"Found: {list(df.columns)}"
        )

    eligible_df = validate_users(df)
    all_users_df = summarize_all_users(df)
    wider_filter_df = all_users_df[
        all_users_df["Posts_Participated"] >= WIDER_FILTER_MIN_POSTS
    ].copy()
    wider_filter_comments_df = collect_comments_for_users(df, wider_filter_df)

    eligible_out = "eligible_participants.csv"
    wider_filter_out = "wider_filter.csv"
    wider_filter_comments_out = "wider_filter_all_comments.csv"
    eligible_df.to_csv(eligible_out, index=False)
    wider_filter_df.to_csv(wider_filter_out, index=False)
    wider_filter_comments_df.to_csv(wider_filter_comments_out, index=False)

    print(f"Eligible users count: {len(eligible_df)}")
    print(
        f"Wider filter count (Posts_Participated >= {WIDER_FILTER_MIN_POSTS}): "
        f"{len(wider_filter_df)}"
    )
    print(f"Wider filter comments count: {len(wider_filter_comments_df)}")

    if len(eligible_df) == 0:
        print("No eligible participants found.")
        return

    winner_row = eligible_df.sample(n=1, random_state=None).iloc[0]
    winner = winner_row[USERNAME_COL]

    print("---- GIVEAWAY RESULTS ----")
    print(f"Eligible participants: {len(eligible_df)}")
    print(f"Winner: {winner}")
    print("--------------------------")
    print(f"Saved: {eligible_out}")
    print(f"Saved: {wider_filter_out}")
    print(f"Saved: {wider_filter_comments_out}")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python giveaway_validator.py comments.csv")
        sys.exit(1)
    main(sys.argv[1])
