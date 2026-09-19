-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_011.
--
-- Keeps, with each manual match, the filters that were on the transactions
-- screen when it was made - so the match can later say what it was matched on,
-- spare fields included, rather than only showing date, description and value.
--
-- Held as a short description of each side, frozen at the time: renaming a
-- spare field later does not rewrite history. Existing matches stay empty.
-- Safe to run twice.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_match_groups
    ADD COLUMN IF NOT EXISTS criteria TEXT NULL;
