-- =============================================================================
-- TeamHub v2.6.3 — Development Setup Script (MySQL / MariaDB)
-- =============================================================================
--
-- PURPOSE
--   For local development only. Drops and recreates all TeamHub tables.
--   See dev-setup-postgresql.sql header for full explanation.
--
-- USAGE
--   mysql -u nextcloud -p nextcloud < sql/dev-setup-mysql.sql
--
-- =============================================================================

SELECT 'TeamHub dev-setup: dropping existing tables...' AS status;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS oc_teamhub_poll_votes;
DROP TABLE IF EXISTS oc_teamhub_comments;
DROP TABLE IF EXISTS oc_teamhub_messages;
DROP TABLE IF EXISTS oc_teamhub_web_links;
DROP TABLE IF EXISTS oc_teamhub_team_apps;
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Creating tables...' AS status;

-- -----------------------------------------------------------------------------
-- oc_teamhub_messages
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_messages (
    id           BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id      VARCHAR(64)  NOT NULL,
    author_id    VARCHAR(64)  NOT NULL,
    subject      VARCHAR(512) NOT NULL,
    message      LONGTEXT     NOT NULL,
    priority     VARCHAR(16)  NOT NULL DEFAULT 'normal',
    message_type VARCHAR(16)  NOT NULL DEFAULT 'normal',
    poll_options LONGTEXT     NULL,
    created_at   BIGINT       NOT NULL,
    updated_at   BIGINT       NOT NULL,
    INDEX teamhub_msg_team_idx   (team_id),
    INDEX teamhub_msg_author_idx (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -----------------------------------------------------------------------------
-- oc_teamhub_poll_votes
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_poll_votes (
    id           BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id   BIGINT       NOT NULL,
    user_id      VARCHAR(64)  NOT NULL,
    option_index INT          NOT NULL,
    created_at   BIGINT       NOT NULL,
    INDEX        teamhub_votes_msg_idx (message_id),
    UNIQUE INDEX teamhub_votes_unique  (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -----------------------------------------------------------------------------
-- oc_teamhub_comments
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_comments (
    id         BIGINT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT      NOT NULL,
    author_id  VARCHAR(64) NOT NULL,
    comment    LONGTEXT    NOT NULL,
    created_at BIGINT      NOT NULL,
    INDEX teamhub_comment_msg_idx (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -----------------------------------------------------------------------------
-- oc_teamhub_web_links
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_web_links (
    id         BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id    VARCHAR(64)   NOT NULL,
    title      VARCHAR(256)  NOT NULL,
    url        VARCHAR(2048) NOT NULL,
    sort_order INT           NOT NULL DEFAULT 0,
    created_at BIGINT        NOT NULL,
    INDEX teamhub_links_team_idx (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -----------------------------------------------------------------------------
-- oc_teamhub_team_apps
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_team_apps (
    id       BIGINT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id  VARCHAR(64) NOT NULL,
    app_id   VARCHAR(64) NOT NULL,
    enabled  TINYINT(1)  NOT NULL DEFAULT 1,
    config   LONGTEXT    NULL,
    UNIQUE INDEX teamhub_apps_team_idx (team_id, app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -----------------------------------------------------------------------------
-- Verify
-- -----------------------------------------------------------------------------
SELECT 'Tables created:' AS status;
SHOW TABLES LIKE 'oc_teamhub%';
