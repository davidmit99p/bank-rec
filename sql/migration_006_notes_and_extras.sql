-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_005.
--
-- Two things at once, so it is only one trip in here:
--
--   1. A notes field on every transaction, for anything worth writing down
--      against it - why it is still open, what it is waiting on.
--
--   2. Three spare fields per transaction, for data from the file that is not
--      the date, description or value but helps you judge a match by eye -
--      a reference, a cost centre, an invoice number. Each reconciliation names
--      its own, separately for each side, because the ledger and the bank
--      rarely carry the same extras.
--
-- Nothing existing is touched, and every new column starts empty.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_ledger
    ADD COLUMN notes  TEXT         NULL,
    ADD COLUMN extra1 VARCHAR(255) NULL,
    ADD COLUMN extra2 VARCHAR(255) NULL,
    ADD COLUMN extra3 VARCHAR(255) NULL;

ALTER TABLE rec_bank
    ADD COLUMN notes  TEXT         NULL,
    ADD COLUMN extra1 VARCHAR(255) NULL,
    ADD COLUMN extra2 VARCHAR(255) NULL,
    ADD COLUMN extra3 VARCHAR(255) NULL;

-- What each reconciliation calls its spare fields. Empty means "not used", and
-- a column with no name is not shown or asked for on import.
ALTER TABLE rec_recs
    ADD COLUMN l_extra1 VARCHAR(60) NULL,
    ADD COLUMN l_extra2 VARCHAR(60) NULL,
    ADD COLUMN l_extra3 VARCHAR(60) NULL,
    ADD COLUMN b_extra1 VARCHAR(60) NULL,
    ADD COLUMN b_extra2 VARCHAR(60) NULL,
    ADD COLUMN b_extra3 VARCHAR(60) NULL;
