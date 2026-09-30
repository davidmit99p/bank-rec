-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against the client database, after migration_021.
--
-- Lets a date or a description be put right when the source file had it wrong,
-- WITHOUT losing what was loaded. The original is kept the first time a line is
-- amended, so the screen can show the corrected value, mark it, and still say
-- what the file said.
--
-- Amounts are deliberately not covered: changing an amount after a match would
-- leave a match that no longer balances.
--
-- All four columns start empty, so nothing existing is treated as amended.
-- Safe to run twice.
--
-- Check afterwards - this should return 0:
--   SELECT COUNT(*) FROM rec_txns WHERE amended_at IS NOT NULL;
-- ---------------------------------------------------------------------------

ALTER TABLE rec_txns
    ADD COLUMN IF NOT EXISTS orig_txn_date    DATE          NULL,
    ADD COLUMN IF NOT EXISTS orig_description VARCHAR(500)  NULL,
    ADD COLUMN IF NOT EXISTS amended_at       DATETIME      NULL,
    ADD COLUMN IF NOT EXISTS amended_by       VARCHAR(100)  NULL,
    ADD COLUMN IF NOT EXISTS amend_why        VARCHAR(255)  NULL;
