-- ---------------------------------------------------------------------------
-- The central database: who may sign in, and which client's database they work
-- in. Run this ONCE against the central database (it is created for you by the
-- Clients page, so you should not normally need to run it by hand).
--
-- Nothing about a reconciliation lives here. One client's work cannot appear on
-- another's screen because it is in a different database altogether, not
-- because a WHERE clause remembered to say so.
--
-- A client's database password is NOT held here. The central table names a key,
-- and the connection details sit in the config file above the web root.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cen_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'user',   -- owner, admin or user
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    must_change   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME     NULL,
    UNIQUE KEY uq_cen_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cen_clients (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    cfg_key    VARCHAR(60)  NOT NULL,     -- which entry in the config file's 'clients'
    notes      VARCHAR(255) NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cen_clients_key (cfg_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who may work on which client. The owner reaches every client without a row.
CREATE TABLE IF NOT EXISTS cen_access (
    user_id   INT NOT NULL,
    client_id INT NOT NULL,
    PRIMARY KEY (user_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Signing in, and anything done centrally. Work inside a client is recorded in
-- that client's own rec_events.
CREATE TABLE IF NOT EXISTS cen_events (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id   INT          NULL,
    who       VARCHAR(120) NULL,
    client_id INT          NULL,
    kind      VARCHAR(40)  NOT NULL,
    detail    VARCHAR(255) NULL,
    KEY ix_cen_events_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
