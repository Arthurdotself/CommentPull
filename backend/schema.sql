-- CommentPull Database Schema
-- Run this once to create the required tables.
-- Or visit /backend/install.php in your browser (then delete it).

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(255)  NOT NULL UNIQUE,
    password_hash       VARCHAR(255)  NOT NULL,
    name                VARCHAR(100)  NOT NULL DEFAULT '',
    plan                ENUM('free','pro')  NOT NULL DEFAULT 'free',
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: run these if upgrading an existing database
-- ALTER TABLE users DROP COLUMN tokens;
-- ALTER TABLE users DROP COLUMN ads_watched_today;
-- ALTER TABLE users DROP COLUMN ads_last_reset;
-- ALTER TABLE users DROP COLUMN stripe_customer_id;
-- ALTER TABLE users DROP COLUMN stripe_subscription_id;
-- ALTER TABLE users DROP COLUMN plan_expires_at;
-- ALTER TABLE users MODIFY plan ENUM('free','pro') NOT NULL DEFAULT 'free';


CREATE TABLE IF NOT EXISTS runs (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED  NOT NULL,
    platform        ENUM('fb','ig','yt') NOT NULL,
    post_urls       JSON          NOT NULL,
    total_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    qualified_count INT UNSIGNED  NOT NULL DEFAULT 0,
    filters         JSON,
    comments        MEDIUMTEXT,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_runs_user_id  (user_id),
    INDEX idx_runs_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: add CRON_SECRET to config.php if upgrading (see config.php comments)
-- New table for Scheduled Exports (Pro feature — added after initial launch)
CREATE TABLE IF NOT EXISTS scheduled_exports (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED  NOT NULL,
    platform        ENUM('fb','ig','yt') NOT NULL,
    post_url        VARCHAR(2048) NOT NULL,
    access_token    TEXT          NOT NULL,
    params          JSON,                                          -- platform-specific extras (page_id, ig_user_id, include_replies)
    export_format   ENUM('csv','json','xlsx') NOT NULL DEFAULT 'xlsx',
    scheduled_at    DATETIME      NOT NULL,
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    total_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    comments        MEDIUMTEXT,                                    -- JSON-encoded results (filled after run)
    error_message   VARCHAR(1000),
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_sche_user    (user_id),
    INDEX idx_sche_pending (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
