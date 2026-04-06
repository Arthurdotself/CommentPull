<?php
// ═══════════════════════════════════════════════════════════════
//  CommentPull — Backend Configuration
//  Fill in your real values before deploying.
//  NEVER commit this file with real secrets to version control.
// ═══════════════════════════════════════════════════════════════

// ── Database (Hostinger → hPanel → Databases → MySQL) ──────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'u373169281_hq');       // your MySQL database name
define('DB_USER',    'u373169281_arthur');     // your MySQL username
define('DB_PASS',    'tidwyT-zoddi1-qywzas'); // your MySQL password
define('DB_CHARSET', 'utf8mb4');

// ── Stripe Keys (https://dashboard.stripe.com/apikeys) ─────────
define('STRIPE_SECRET_KEY',     'sk_test_51TIcpXCdONdlVs8RfVPuTkdIqM5sj1DDtyntOmFtVpnOWElOy2vDVgcHr657XF0fZe4I6Qtdvd0CvCSryWWiScyy00AnsmS09E');
define('STRIPE_PUBLISHABLE_KEY','pk_test_51TIcpXCdONdlVs8RSaGPxs39FxmFUbNQhdrpuVA0UcqCEBO49J7GyIIeKZ1pGFmsLWrjU9WMnFXtCIZTButlSrkA00RHz8ZU0u');
// Webhook secret — Stripe Dashboard → Webhooks → your endpoint → Signing secret
define('STRIPE_WEBHOOK_SECRET', 'whsec_...');

// ── Stripe Price IDs ─────────────────────────────────────────────
// ⚠ These are PRICE IDs (price_...), NOT product IDs (prod_...).
// To get them: Stripe Dashboard → Products → click your product → copy the Price ID
// Your products: prod_UHTzDKjJ5NdU3o (monthly $9) | prod_UHU3WvWx2GAHzf (yearly $79)
define('PRICE_PRO_MONTHLY', 'price_1TIv1hCdONdlVs8RFAY0D7mk');
define('PRICE_PRO_YEARLY',  'price_1TIv5CCdONdlVs8RhTy3dRnV');

// ── Site ────────────────────────────────────────────────────────
define('SITE_URL', 'https://commentpull.com');

// ── Session TTL — 30 days ───────────────────────────────────────
define('SESSION_TTL', 60 * 60 * 24 * 30);

// ── OAuth — Google (console.cloud.google.com → Credentials → OAuth 2.0) ──
define('GOOGLE_CLIENT_ID',     '599843545445-5f7e8f4qqcjft3kh7jcm2b5e43kpuhk0.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// ── OAuth — GitHub (github.com → Settings → Developer settings → OAuth Apps) ──
define('GITHUB_CLIENT_ID',     'Ov23livkLQ2BiHvyAW6W');
define('GITHUB_CLIENT_SECRET', 'c2a642e3fc38932688d3e855ce990aa6378ba6c9');

// ── OAuth state signing secret (any long random string) ──────────
define('OAUTH_STATE_SECRET', 'change-this-to-a-long-random-secret-string');

// ── Token economy ────────────────────────────────────────────────
define('FREE_PLAN_TOKENS',  250);   // tokens granted on signup
define('AD_TOKENS_REWARD',  500);   // tokens per ad watched
define('AD_DAILY_LIMIT',    10);    // max ads a user can watch per day

// ── BETA: remove this block when beta ends ───────────────────────
define('BETA_MODE', true);          // set false (or delete block) to end beta
define('BETA_TOKENS', 999999);      // unlimited tokens for beta signups
