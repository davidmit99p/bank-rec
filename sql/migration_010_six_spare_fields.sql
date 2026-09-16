-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_009.
--
-- Three more spare fields - 4, 5 and 6 - on every file and every transaction,
-- so a file can carry up to six extra columns (accounting period, reference,
-- journal type and so on) beyond date, description and value.
--
-- Nothing existing is touched: the new columns start empty and a file only
-- uses the ones it has been given a name for. Safe to run twice.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_files
    ADD COLUMN IF NOT EXISTS extra4 VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS extra5 VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS extra6 VARCHAR(60) NULL;

ALTER TABLE rec_txns
    ADD COLUMN IF NOT EXISTS extra4 VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS extra5 VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS extra6 VARCHAR(255) NULL;
