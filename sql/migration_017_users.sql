-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_016.
--
-- Named users: everyone signs in as themselves, and the tool records who did
-- what. Until now one shared password covered the whole site, and a match
-- recorded the rule and the run but not the person.
--
-- Two tables and two columns:
--   rec_users   - the people who may sign in
--   rec_events  - who did what, and when
--   rec_runs    - who processed the run, and who finalised it
--
-- Nothing existing is touched. The first person to visit after this is run is
-- asked to create the administrator account.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'user',   -- 'admin' or 'user'
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    must_change   TINYINT(1)   NOT NULL DEFAULT 0,        -- set after a password reset
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME     NULL,
    UNIQUE KEY uq_rec_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rec_events (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT          NULL,
    who     VARCHAR(120) NULL,        -- the name as it was, so a deleted user still reads
    rec_id  INT          NULL,
    kind    VARCHAR(40)  NOT NULL,    -- 'process', 'finalise', 'manual match', ...
    detail  VARCHAR(255) NULL,
    KEY ix_rec_events_at (at),
    KEY ix_rec_events_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE rec_runs
    ADD COLUMN IF NOT EXISTS created_by   INT NULL,
    ADD COLUMN IF NOT EXISTS finalised_by INT NULL;
