-- CommentPull Database Schema
-- Run this once on a fresh database to create all required tables.
-- (Existing databases: run migrations/2026-10-remove-meta-and-history.sql instead.)

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(255)  NOT NULL UNIQUE,
    password_hash       VARCHAR(255)  NOT NULL,
    name                VARCHAR(100)  NOT NULL DEFAULT '',
    plan                ENUM('free','pro')  NOT NULL DEFAULT 'free',
    stripe_customer_id      VARCHAR(255)  NULL,
    stripe_subscription_id  VARCHAR(255)  NULL,
    plan_expires_at         DATETIME      NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS sessions (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED  NOT NULL,
    token       CHAR(64)      NOT NULL UNIQUE,
    expires_at  DATETIME      NOT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_token   (token),
    INDEX idx_expires (expires_at),

    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Scheduled exports (YouTube only, Pro plan).
-- access_token holds the user's YouTube API key, AES-256-GCM encrypted (see helpers.php).
CREATE TABLE IF NOT EXISTS scheduled_exports (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED  NOT NULL,
    platform        ENUM('yt') NOT NULL DEFAULT 'yt',
    post_url        VARCHAR(2048) NOT NULL,
    access_token    TEXT          NOT NULL,
    params          JSON,
    export_format   ENUM('csv','json','xlsx') NOT NULL DEFAULT 'xlsx',
    scheduled_at    DATETIME      NOT NULL,
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    total_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    comments        MEDIUMTEXT,
    error_message   VARCHAR(1000),
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_sche_pending (status, scheduled_at),

    CONSTRAINT fk_sched_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
