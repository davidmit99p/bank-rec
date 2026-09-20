-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_015.
--
-- One more setting on a rule, for the shapes that gather everything sharing a
-- key, a day or a month: as well as pairing the two sides, clear a group that
-- cancels itself out on one side alone - a posting and its reversal sitting in
-- the ledger with nothing on the other side to match them against.
--
-- Nothing existing is touched: it starts off, and an existing rule behaves
-- exactly as it does now.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_rules
    ADD COLUMN IF NOT EXISTS self_contra TINYINT(1) NOT NULL DEFAULT 0;
