-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_010.
--
-- Lets a rule insist that fields agree across the two sides as well as the
-- amount - accounting period, reference, journal type and so on.
--
--   agree_left1 .. 4    a field on the left-hand file (extra1..extra6 or description)
--   agree_right1 .. 4   the field it must agree with on the right-hand file
--   ignore_date         1 = dates play no part in the rule at all
--
-- Everything starts empty / 0, so every existing rule behaves exactly as
-- before. Safe to run twice.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_rules
    ADD COLUMN IF NOT EXISTS agree_left1  VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS agree_right1 VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS agree_left2  VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS agree_right2 VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS agree_left3  VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS agree_right3 VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS agree_left4  VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS agree_right4 VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS ignore_date  TINYINT(1) NOT NULL DEFAULT 0;
