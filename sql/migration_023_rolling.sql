-- ---------------------------------------------------------------------------
-- Applied by the Database page ("Apply what is waiting"), like every migration.
--
-- ROLLING FILES: files that are loaded again and again, where a record loaded
-- before can come back changed.
--
-- Each transaction gets a fingerprint of everything it holds and a key value
-- built from the fields the file says identify a record. A fresh load lands in
-- rec_stage first, is fingerprinted the same way, and is compared:
--
--   same fingerprint                  -> unchanged, ignored
--   same key, different fingerprint   -> amended
--   no key match                      -> new
--   amended amount on a matched line  -> blocked, because applying it would
--                                        leave a match that no longer balances
--
-- Nothing here changes how an ordinary file behaves: rolling starts off.
--
-- Check afterwards - both should return 0:
--   SELECT COUNT(*) FROM rec_files WHERE rolling = 1;
--   SELECT COUNT(*) FROM rec_stage;
-- ---------------------------------------------------------------------------

ALTER TABLE rec_files
    ADD COLUMN IF NOT EXISTS rolling    TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS key_fields VARCHAR(120) NULL;

ALTER TABLE rec_txns
    ADD COLUMN IF NOT EXISTS fingerprint CHAR(32)      NULL,
    ADD COLUMN IF NOT EXISTS row_key     VARCHAR(255)  NULL,
    ADD COLUMN IF NOT EXISTS prev_value  DECIMAL(15,2) NULL,
    ADD COLUMN IF NOT EXISTS restated_at DATETIME      NULL;

-- The runner forgives "duplicate key name", so these are safe on a database
-- that already has them.
ALTER TABLE rec_txns ADD INDEX rec_txns_rowkey (file_id, row_key);
ALTER TABLE rec_txns ADD INDEX rec_txns_fprint (file_id, fingerprint);

CREATE TABLE IF NOT EXISTS rec_stage (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id      INT UNSIGNED  NOT NULL,
    loaded_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_file  VARCHAR(255)  NULL,
    txn_date     DATE          NULL,
    description  VARCHAR(500)  NULL,
    value        DECIMAL(15,2) NULL,
    extra1       VARCHAR(255)  NULL,
    extra2       VARCHAR(255)  NULL,
    extra3       VARCHAR(255)  NULL,
    extra4       VARCHAR(255)  NULL,
    extra5       VARCHAR(255)  NULL,
    extra6       VARCHAR(255)  NULL,
    fingerprint  CHAR(32)      NULL,
    row_key      VARCHAR(255)  NULL,
    verdict      VARCHAR(12)   NOT NULL DEFAULT 'new',   -- new, amended, blocked, same
    txn_id       BIGINT UNSIGNED NULL,                   -- the line it is amending
    note         VARCHAR(255)  NULL,
    PRIMARY KEY (id),
    INDEX (file_id),
    INDEX (file_id, verdict)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
