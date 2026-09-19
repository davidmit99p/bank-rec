-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_013.
--
-- Lets a rule be held to particular values of a file's own fields - only code
-- 4010, say, or only period 2026/03 - as well as to the description, the value
-- and the date it already had.
--
-- Two conditions per side, each one a field, a test and a value. The field is
-- named per side because the same thing can be spare field 1 on one file and
-- spare field 3 on the other.
--
-- Nothing existing is touched: every condition starts on "anything", which
-- leaves rules behaving exactly as they do now.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_rules
    ADD COLUMN IF NOT EXISTS l_f1_key VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS l_f1_op  VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS l_f1_val VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS l_f2_key VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS l_f2_op  VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS l_f2_val VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS b_f1_key VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS b_f1_op  VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS b_f1_val VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS b_f2_key VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS b_f2_op  VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS b_f2_val VARCHAR(190) NULL;
