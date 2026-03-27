-- =============================================================================
-- TeamHub v2.6.3 — Development Setup Script (PostgreSQL)
-- =============================================================================
--
-- PURPOSE
--   For local development only. Drops and recreates all TeamHub tables from
--   scratch so your schema is always in sync with the codebase, regardless of
--   migration history.
--
--   Production installs use lib/Migration/ via occ upgrade. This script is
--   NOT a replacement for migrations — it is a dev convenience tool.
--
-- USAGE
--   From your host machine (adjust container/user/db names to match your setup):
--
--     docker exec -i nextcloud-aio-database \
--       psql -U nextcloud -d nextcloud < sql/dev-setup-postgresql.sql
--
--   Or from inside the container:
--
--     psql -U nextcloud -d nextcloud < /path/to/sql/dev-setup-postgresql.sql
--
-- AFTER RUNNING THIS SCRIPT
--   You still need to let Nextcloud know the migrations have been applied so
--   occ upgrade / app:enable don't try to run them and fail on already-existing
--   tables. The dev-deploy.sh script handles this automatically.
--   If you run this script manually, follow with:
--
--     sudo -u www-data php occ app:disable teamhub
--     sudo -u www-data php occ app:enable teamhub
--
--   (app:enable re-registers migrations as done when it finds the tables present.)
--
-- =============================================================================

\echo '>>> TeamHub dev-setup: dropping existing tables...'

-- Drop in reverse dependency order (votes depend on messages, comments depend on messages)
DROP TABLE IF EXISTS oc_teamhub_poll_votes;
DROP TABLE IF EXISTS oc_teamhub_comments;
DROP TABLE IF EXISTS oc_teamhub_messages;
DROP TABLE IF EXISTS oc_teamhub_web_links;
DROP TABLE IF EXISTS oc_teamhub_team_apps;

\echo '>>> Creating tables...'

-- -----------------------------------------------------------------------------
-- oc_teamhub_messages
-- Stores all team messages, including polls (message_type='poll') and
-- questions (message_type='question').
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_messages (
    id          BIGSERIAL    PRIMARY KEY,
    team_id     VARCHAR(64)  NOT NULL,
    author_id   VARCHAR(64)  NOT NULL,
    subject     VARCHAR(512) NOT NULL,
    message     TEXT         NOT NULL,
    priority    VARCHAR(16)  NOT NULL DEFAULT 'normal',
    message_type VARCHAR(16) NOT NULL DEFAULT 'normal',
    poll_options TEXT        NULL,
    created_at  BIGINT       NOT NULL,
    updated_at  BIGINT       NOT NULL
);

CREATE INDEX teamhub_msg_team_idx   ON oc_teamhub_messages (team_id);
CREATE INDEX teamhub_msg_author_idx ON oc_teamhub_messages (author_id);

\echo '    [ok] oc_teamhub_messages'

-- -----------------------------------------------------------------------------
-- oc_teamhub_poll_votes
-- One row per (message, user) pair. UNIQUE constraint enforces one vote per user.
-- Deleting + re-inserting is how vote changes are handled (see MessageService).
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_poll_votes (
    id           BIGSERIAL   PRIMARY KEY,
    message_id   BIGINT      NOT NULL,
    user_id      VARCHAR(64) NOT NULL,
    option_index INTEGER     NOT NULL,
    created_at   BIGINT      NOT NULL
);

CREATE INDEX        teamhub_votes_msg_idx ON oc_teamhub_poll_votes (message_id);
CREATE UNIQUE INDEX teamhub_votes_unique  ON oc_teamhub_poll_votes (message_id, user_id);

\echo '    [ok] oc_teamhub_poll_votes'

-- -----------------------------------------------------------------------------
-- oc_teamhub_comments
-- Threaded comments on messages.
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_comments (
    id         BIGSERIAL   PRIMARY KEY,
    message_id BIGINT      NOT NULL,
    author_id  VARCHAR(64) NOT NULL,
    comment    TEXT        NOT NULL,
    created_at BIGINT      NOT NULL
);

CREATE INDEX teamhub_comment_msg_idx ON oc_teamhub_comments (message_id);

\echo '    [ok] oc_teamhub_comments'

-- -----------------------------------------------------------------------------
-- oc_teamhub_web_links
-- Bookmarks/web links per team, ordered by sort_order.
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_web_links (
    id         BIGSERIAL    PRIMARY KEY,
    team_id    VARCHAR(64)  NOT NULL,
    title      VARCHAR(256) NOT NULL,
    url        VARCHAR(2048) NOT NULL,
    sort_order INTEGER      NOT NULL DEFAULT 0,
    created_at BIGINT       NOT NULL
);

CREATE INDEX teamhub_links_team_idx ON oc_teamhub_web_links (team_id);

\echo '    [ok] oc_teamhub_web_links'

-- -----------------------------------------------------------------------------
-- oc_teamhub_team_apps
-- Per-team configuration for which app integrations are enabled.
-- UNIQUE on (team_id, app_id) supports upsert logic in TeamAppMapper.
-- -----------------------------------------------------------------------------
CREATE TABLE oc_teamhub_team_apps (
    id       BIGSERIAL   PRIMARY KEY,
    team_id  VARCHAR(64) NOT NULL,
    app_id   VARCHAR(64) NOT NULL,
    enabled  SMALLINT    NOT NULL DEFAULT 1,
    config   TEXT        NULL
);

CREATE UNIQUE INDEX teamhub_apps_team_idx ON oc_teamhub_team_apps (team_id, app_id);

\echo '    [ok] oc_teamhub_team_apps'

-- -----------------------------------------------------------------------------
-- Grants — adjust role name if your Nextcloud DB user differs from 'nextcloud'
-- -----------------------------------------------------------------------------
\echo '>>> Setting grants...'

GRANT ALL PRIVILEGES ON TABLE    oc_teamhub_messages   TO nextcloud;
GRANT ALL PRIVILEGES ON TABLE    oc_teamhub_poll_votes  TO nextcloud;
GRANT ALL PRIVILEGES ON TABLE    oc_teamhub_comments   TO nextcloud;
GRANT ALL PRIVILEGES ON TABLE    oc_teamhub_web_links  TO nextcloud;
GRANT ALL PRIVILEGES ON TABLE    oc_teamhub_team_apps  TO nextcloud;

GRANT ALL PRIVILEGES ON SEQUENCE oc_teamhub_messages_id_seq   TO nextcloud;
GRANT ALL PRIVILEGES ON SEQUENCE oc_teamhub_poll_votes_id_seq  TO nextcloud;
GRANT ALL PRIVILEGES ON SEQUENCE oc_teamhub_comments_id_seq   TO nextcloud;
GRANT ALL PRIVILEGES ON SEQUENCE oc_teamhub_web_links_id_seq  TO nextcloud;
GRANT ALL PRIVILEGES ON SEQUENCE oc_teamhub_team_apps_id_seq  TO nextcloud;

-- -----------------------------------------------------------------------------
-- Verify
-- -----------------------------------------------------------------------------
\echo '>>> Verifying tables created:'
SELECT tablename
FROM   pg_tables
WHERE  tablename LIKE 'oc_teamhub%'
ORDER  BY tablename;

\echo '>>> Done. Run dev-deploy.sh (or manually disable/enable the app) to finish.'
