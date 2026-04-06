-- CommentPull Database Schema
-- Run this once to create the required tables.
-- Or visit /backend/install.php in your browser (then delete it).

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(255)  NOT NULL UNIQUE,
    password_hash       VARCHAR(255)  NOT NULL,
    name                VARCHAR(100)  NOT NULL DEFAULT '',
    plan                ENUM('free')  NOT NULL DEFAULT 'free',
    tokens              INT UNSIGNED  NOT NULL DEFAULT 250,
    ads_watched_today   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ads_last_reset      DATE          NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: run these if upgrading an existing database
-- ALTER TABLE users ADD COLUMN tokens INT UNSIGNED NOT NULL DEFAULT 250;
-- ALTER TABLE users ADD COLUMN ads_watched_today TINYINT UNSIGNED NOT NULL DEFAULT 0;
-- ALTER TABLE users ADD COLUMN ads_last_reset DATE NULL;
-- ALTER TABLE users DROP COLUMN stripe_customer_id;
-- ALTER TABLE users DROP COLUMN stripe_subscription_id;
-- ALTER TABLE users DROP COLUMN plan_expires_at;


CREATE TABLE IF NOT EXISTS sessions (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED  NOT NULL,
    token       CHAR(64)      NOT NULL UNIQUE,
    expires_at  DATETIME      NOT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_id    (user_id),
    INDEX idx_token      (token),
    INDEX idx_expires    (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
