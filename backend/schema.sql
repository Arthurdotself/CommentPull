-- CommentPull Database Schema
-- Run this once on a fresh database to create all required tables.

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(255)  NOT NULL UNIQUE,
    password_hash       VARCHAR(255)  NOT NULL,
    name                VARCHAR(100)  NOT NULL DEFAULT '',
    plan                ENUM('free','pro')  NOT NULL DEFAULT 'free',
    meta_access_token    TEXT          DEFAULT NULL,   -- AES-256-GCM encrypted
    meta_token_expires   DATETIME      DEFAULT NULL,   -- when the long-lived token expires
    facebook_picture_url TEXT          DEFAULT NULL,   -- profile picture URL from Facebook OAuth
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration for existing databases (run once):
-- ALTER TABLE users ADD COLUMN meta_access_token TEXT DEFAULT NULL;
-- ALTER TABLE users ADD COLUMN meta_token_expires DATETIME DEFAULT NULL;
-- ALTER TABLE users ADD COLUMN facebook_picture_url TEXT DEFAULT NULL;


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

    INDEX idx_runs_created (created_at),

    CONSTRAINT fk_runs_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- access_token is stored AES-256-GCM encrypted (see helpers.php encrypt_token/decrypt_token)
CREATE TABLE IF NOT EXISTS scheduled_exports (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED  NOT NULL,
    platform        ENUM('fb','ig','yt') NOT NULL,
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
