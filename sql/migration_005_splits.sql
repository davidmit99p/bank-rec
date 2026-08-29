-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_004.
--
-- Lets one transaction be split into parts, for when a single bank line covers
-- more than one thing - an invoice of 161.10 and a 0.75 charge, say.
--
-- The original is NOT deleted. It is marked as split and drops out of the
-- working list, and the parts appear in its place, each pointing back at it.
-- So the history survives, the totals cannot drift, and a split can be undone.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_ledger
    ADD COLUMN parent_id INT      NULL,   -- the transaction this was split out of
    ADD COLUMN split_at  DATETIME NULL,   -- set on the ORIGINAL once it is split
    ADD INDEX (parent_id), ADD INDEX (split_at);

ALTER TABLE rec_bank
    ADD COLUMN parent_id INT      NULL,
    ADD COLUMN split_at  DATETIME NULL,
    ADD INDEX (parent_id), ADD INDEX (split_at);
