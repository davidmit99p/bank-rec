-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_008.
--
-- Adds two settings to a rule, for the shape that groups everything sharing a
-- key - a booking reference, say - and checks whether the two sides come to the
-- same.
--
-- Two settings rather than one, because the same reference can be spare field 1
-- on one side's file and spare field 2 on the other's. Each file names its own.
--
-- Nothing existing is touched; both columns start empty and only mean anything
-- to a rule using the "same key" shape.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_rules
    ADD COLUMN IF NOT EXISTS key_left  VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS key_right VARCHAR(20) NULL;
