<?php
// ═══════════════════════════════════════════════════════════════
//  CommentPull — Backend Configuration (TEMPLATE)
//
//  Copy this file to `config.php` and fill in your own values.
//  `config.php` is listed in .gitignore — NEVER commit real secrets.
// ═══════════════════════════════════════════════════════════════

// ── Database (MySQL / MariaDB) ───────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'your_database_name');
define('DB_USER',    'your_database_user');
define('DB_PASS',    'your_database_password');
define('DB_CHARSET', 'utf8mb4');

// ── Site ────────────────────────────────────────────────────────
define('SITE_URL', 'https://example.com');

// ── Session TTL — 30 days ───────────────────────────────────────
define('SESSION_TTL', 60 * 60 * 24 * 30);

// ── OAuth — Google (console.cloud.google.com → Credentials) ────
define('GOOGLE_CLIENT_ID',     'your-google-client-id');
define('GOOGLE_CLIENT_SECRET', 'your-google-client-secret');

// ── OAuth — GitHub (Settings → Developer settings → OAuth Apps) ─
define('GITHUB_CLIENT_ID',     'your-github-client-id');
define('GITHUB_CLIENT_SECRET', 'your-github-client-secret');

// ── Stripe (dashboard.stripe.com → Developers) ─────────────────
define('STRIPE_SECRET_KEY',     'sk_live_your-stripe-secret-key');
define('STRIPE_WEBHOOK_SECRET', 'whsec_your-webhook-signing-secret');
define('PRICE_PRO_MONTHLY',     'price_your-monthly-price-id');
define('PRICE_PRO_YEARLY',      'price_your-yearly-price-id');

// ── OAuth state signing secret, used by Google/GitHub sign-in (any long random string)
define('OAUTH_STATE_SECRET', 'change-me-long-random-string');

// ── Encryption key for stored YouTube API keys (Scheduled Exports) — AES-256-GCM ──
// Must be exactly 64 hex characters (= 32 bytes). Generate with:
//   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
define('TOKEN_ENCRYPT_KEY', '0000000000000000000000000000000000000000000000000000000000000000');
