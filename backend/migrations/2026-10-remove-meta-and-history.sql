-- CommentPull migration: remove Facebook/Instagram (Meta) API data and saved run history.
-- Run ONCE on an EXISTING database (phpMyAdmin -> SQL tab). New installs use schema.sql instead.
--
-- 1) TAKE A BACKUP FIRST (phpMyAdmin -> Export).
-- 2) Check what you actually have before running:
--      SHOW TABLES;
--      SHOW COLUMNS FROM users;
--      SHOW COLUMNS FROM scheduled_exports;
--    and adjust the names below if yours differ.
-- 3) This uses "IF EXISTS", which MariaDB (Hostinger default) supports. On MySQL 8 remove those words
--    and drop only the columns that exist.

-- Saved run history (the old history page) - all saved comments are deleted.
DROP TABLE IF EXISTS runs;

-- Stored Meta (Facebook/Instagram) access tokens.
ALTER TABLE users
    DROP COLUMN IF EXISTS meta_access_token,
    DROP COLUMN IF EXISTS meta_token_expires;

-- Scheduled exports are YouTube-only now: delete Facebook/Instagram jobs (they hold encrypted Meta tokens).
DELETE FROM scheduled_exports WHERE platform <> 'yt';
ALTER TABLE scheduled_exports MODIFY platform ENUM('yt') NOT NULL DEFAULT 'yt';
