-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, AFTER migration_002.
--
-- Lets the tool hold more than one reconciliation at a time - one per bank
-- account, say - and remembers which one you are working on.
--
-- Safe to run on a database with transactions already in it. Everything that
-- is already there is put into a first reconciliation called "Main
-- reconciliation", which you can rename afterwards.
-- ---------------------------------------------------------------------------

-- The header record. left_label and right_label are what the two sides are
-- called on screen, so this is not tied to banks: one reconciliation can read
-- "Ledger / Bank" and another "Purchase Ledger / Supplier Statement".
CREATE TABLE IF NOT EXISTS rec_recs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    left_label  VARCHAR(60)  NOT NULL DEFAULT 'Ledger',
    right_label VARCHAR(60)  NOT NULL DEFAULT 'Bank',
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 100,
    notes       TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (active), INDEX (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Everything that belongs to one reconciliation.
ALTER TABLE rec_ledger  ADD COLUMN rec_id INT NULL, ADD INDEX (rec_id);
ALTER TABLE rec_bank    ADD COLUMN rec_id INT NULL, ADD INDEX (rec_id);
ALTER TABLE rec_runs    ADD COLUMN rec_id INT NULL, ADD INDEX (rec_id);
ALTER TABLE rec_imports ADD COLUMN rec_id INT NULL, ADD INDEX (rec_id);

-- Rules are different: a rule with no reconciliation applies to ALL of them,
-- which is what you want for "same day, same amount". Set one to a particular
-- reconciliation when it only makes sense for that account.
ALTER TABLE rec_rules   ADD COLUMN rec_id INT NULL, ADD INDEX (rec_id);

-- Give everything already loaded a home.
INSERT INTO rec_recs (name, left_label, right_label, notes)
VALUES ('Main reconciliation', 'Ledger', 'Bank',
        'Everything that was in the system before reconciliations were introduced. Rename this to whatever it actually is.');

UPDATE rec_ledger  SET rec_id = (SELECT MIN(id) FROM rec_recs) WHERE rec_id IS NULL;
UPDATE rec_bank    SET rec_id = (SELECT MIN(id) FROM rec_recs) WHERE rec_id IS NULL;
UPDATE rec_runs    SET rec_id = (SELECT MIN(id) FROM rec_recs) WHERE rec_id IS NULL;
UPDATE rec_imports SET rec_id = (SELECT MIN(id) FROM rec_recs) WHERE rec_id IS NULL;

-- Rules are deliberately left alone: NULL means "applies to every
-- reconciliation", which is the right home for the starter set.
