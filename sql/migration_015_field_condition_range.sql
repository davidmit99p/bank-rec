-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_014.
--
-- A second value for each of a rule's field conditions, so one condition can
-- cover a stretch - periods 2026/01 to 2026/06, say - rather than a single
-- value. Only the "is between" test uses it.
--
-- Nothing existing is touched: the new columns start empty.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_rules
    ADD COLUMN IF NOT EXISTS l_f1_val2 VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS l_f2_val2 VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS b_f1_val2 VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS b_f2_val2 VARCHAR(190) NULL;
