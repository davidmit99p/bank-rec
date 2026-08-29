-- ---------------------------------------------------------------------------
-- Run this ONCE against the same database as schema.sql, in phpMyAdmin.
-- (Plesk > Databases > entigy_recon > phpMyAdmin > SQL tab > paste > Go.)
--
-- Adds a record of each file that has been loaded, so a whole import can be
-- removed again if the wrong file went in, or the same one went in twice.
--
-- Safe to run on a database that already has transactions in it. Anything
-- imported before this point simply has no import record, which is fine -
-- those rows just cannot be removed as a batch.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_imports (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    side        VARCHAR(10)   NOT NULL,          -- ledger | bank
    filename    VARCHAR(255)  NOT NULL,
    row_count   INT           NOT NULL DEFAULT 0,
    total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    date_from   DATE          NULL,
    date_to     DATE          NULL,
    imported_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (side), INDEX (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE rec_ledger ADD COLUMN import_id INT NULL, ADD INDEX (import_id);
ALTER TABLE rec_bank   ADD COLUMN import_id INT NULL, ADD INDEX (import_id);
