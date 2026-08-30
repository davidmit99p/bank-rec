-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_006.
--
-- TAKE A DATABASE EXPORT FIRST. This one moves your transactions rather than
-- just adding columns. The old tables are left exactly as they are, so nothing
-- is lost, but an export is the thing that lets you undo it in one step.
--
-- WHAT IT DOES
--
-- Until now a transaction lived in either rec_ledger or rec_bank, and those two
-- tables WERE the two sides of a reconciliation. That meant a file had to know
-- what it was being reconciled against before it could be loaded, and a file
-- could never appear in more than one reconciliation.
--
-- After this a transaction belongs to a FILE, and a reconciliation simply says
-- which two files it pairs. Files carry their own spare-field names, so those
-- are named once on the file that has the columns.
--
-- Everything already loaded is carried across: each reconciliation's two sides
-- become two files, named after it, keeping their spare field names.
--
-- Running it twice is harmless - every step checks whether it has already been
-- done. If anything is imported after this has been run but before the new code
-- is deployed, run sql/migration_007b_copy_again.sql to bring those rows across
-- too.
-- ---------------------------------------------------------------------------

-- A file: something you loaded, with its own name and its own spare fields.
CREATE TABLE IF NOT EXISTS rec_files (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    notes         TEXT         NULL,
    extra1        VARCHAR(60)  NULL,
    extra2        VARCHAR(60)  NULL,
    extra3        VARCHAR(60)  NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- only used by this migration, to tie a file back to where it came from
    legacy_rec_id INT          NULL,
    legacy_side   VARCHAR(10)  NULL,
    INDEX (active), INDEX (legacy_rec_id, legacy_side)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One table for every transaction, whichever file it came from.
CREATE TABLE IF NOT EXISTS rec_txns (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    file_id      INT           NOT NULL,
    txn_date     DATE          NOT NULL,
    description  VARCHAR(500)  NOT NULL DEFAULT '',
    value        DECIMAL(15,2) NOT NULL,
    extra1       VARCHAR(255)  NULL,
    extra2       VARCHAR(255)  NULL,
    extra3       VARCHAR(255)  NULL,
    notes        TEXT          NULL,
    source_file  VARCHAR(255)  NULL,
    import_id    INT           NULL,
    parent_id    INT           NULL,
    split_at     DATETIME      NULL,
    imported_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    run_id       INT           NULL,
    rule_ref     VARCHAR(20)   NULL,
    group_no     INT           NULL,
    matched_at   DATETIME      NULL,
    -- only used by this migration, so it can be re-run and so the old rows can
    -- still be found if anything needs checking
    legacy_side       VARCHAR(10) NULL,
    legacy_id         INT         NULL,
    legacy_parent_id  INT         NULL,
    INDEX (file_id), INDEX (txn_date), INDEX (value), INDEX (matched_at),
    INDEX (split_at), INDEX (import_id), INDEX (parent_id),
    INDEX (legacy_side, legacy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A reconciliation now points at two files.
-- IF NOT EXISTS so this whole file can be run again without complaining that
-- a column it added last time is already there.
ALTER TABLE rec_recs
    ADD COLUMN IF NOT EXISTS left_file_id  INT NULL,
    ADD COLUMN IF NOT EXISTS right_file_id INT NULL,
    ADD INDEX IF NOT EXISTS ix_recs_left_file  (left_file_id),
    ADD INDEX IF NOT EXISTS ix_recs_right_file (right_file_id);

ALTER TABLE rec_imports
    ADD COLUMN IF NOT EXISTS file_id INT NULL,
    ADD INDEX IF NOT EXISTS ix_imports_file (file_id);

-- Match lines keep a note of the id they used to point at, so this can be
-- re-run without pointing them at the wrong thing the second time.
ALTER TABLE rec_match_lines
    ADD COLUMN IF NOT EXISTS legacy_txn_id INT NULL,
    ADD INDEX IF NOT EXISTS ix_lines_legacy (legacy_txn_id);

-- ---------------------------------------------------------------------------
-- 1. Each existing reconciliation's two sides become two files.
-- ---------------------------------------------------------------------------
INSERT INTO rec_files (name, extra1, extra2, extra3, legacy_rec_id, legacy_side)
SELECT CONCAT(r.name, ' - ', r.left_label), r.l_extra1, r.l_extra2, r.l_extra3, r.id, 'ledger'
FROM rec_recs r
WHERE NOT EXISTS (SELECT 1 FROM rec_files f WHERE f.legacy_rec_id = r.id AND f.legacy_side = 'ledger');

INSERT INTO rec_files (name, extra1, extra2, extra3, legacy_rec_id, legacy_side)
SELECT CONCAT(r.name, ' - ', r.right_label), r.b_extra1, r.b_extra2, r.b_extra3, r.id, 'bank'
FROM rec_recs r
WHERE NOT EXISTS (SELECT 1 FROM rec_files f WHERE f.legacy_rec_id = r.id AND f.legacy_side = 'bank');

UPDATE rec_recs r JOIN rec_files f ON f.legacy_rec_id = r.id AND f.legacy_side = 'ledger'
SET r.left_file_id  = f.id WHERE r.left_file_id IS NULL;
UPDATE rec_recs r JOIN rec_files f ON f.legacy_rec_id = r.id AND f.legacy_side = 'bank'
SET r.right_file_id = f.id WHERE r.right_file_id IS NULL;

-- ---------------------------------------------------------------------------
-- 2. Copy the transactions across, remembering where each came from.
-- ---------------------------------------------------------------------------
INSERT INTO rec_txns (file_id, txn_date, description, value, extra1, extra2, extra3, notes,
                      source_file, import_id, split_at, imported_at,
                      run_id, rule_ref, group_no, matched_at,
                      legacy_side, legacy_id, legacy_parent_id)
SELECT f.id, t.txn_date, t.description, t.value, t.extra1, t.extra2, t.extra3, t.notes,
       t.source_file, t.import_id, t.split_at, t.imported_at,
       t.run_id, t.rule_ref, t.group_no, t.matched_at,
       'ledger', t.id, t.parent_id
FROM rec_ledger t
JOIN rec_files f ON f.legacy_rec_id = t.rec_id AND f.legacy_side = 'ledger'
WHERE NOT EXISTS (SELECT 1 FROM rec_txns x WHERE x.legacy_side = 'ledger' AND x.legacy_id = t.id);

INSERT INTO rec_txns (file_id, txn_date, description, value, extra1, extra2, extra3, notes,
                      source_file, import_id, split_at, imported_at,
                      run_id, rule_ref, group_no, matched_at,
                      legacy_side, legacy_id, legacy_parent_id)
SELECT f.id, t.txn_date, t.description, t.value, t.extra1, t.extra2, t.extra3, t.notes,
       t.source_file, t.import_id, t.split_at, t.imported_at,
       t.run_id, t.rule_ref, t.group_no, t.matched_at,
       'bank', t.id, t.parent_id
FROM rec_bank t
JOIN rec_files f ON f.legacy_rec_id = t.rec_id AND f.legacy_side = 'bank'
WHERE NOT EXISTS (SELECT 1 FROM rec_txns x WHERE x.legacy_side = 'bank' AND x.legacy_id = t.id);

-- ---------------------------------------------------------------------------
-- 3. Point the split parts at their parent's NEW id.
-- ---------------------------------------------------------------------------
UPDATE rec_txns c
JOIN rec_txns p ON p.legacy_side = c.legacy_side AND p.legacy_id = c.legacy_parent_id
SET c.parent_id = p.id
WHERE c.legacy_parent_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 4. Point every committed match at the new ids.
-- ---------------------------------------------------------------------------
UPDATE rec_match_lines SET legacy_txn_id = txn_id WHERE legacy_txn_id IS NULL;

UPDATE rec_match_lines l
JOIN rec_txns t ON t.legacy_side = l.side AND t.legacy_id = l.legacy_txn_id
SET l.txn_id = t.id;

-- ---------------------------------------------------------------------------
-- 5. Tie each import to its file.
-- ---------------------------------------------------------------------------
UPDATE rec_imports i
JOIN rec_files f ON f.legacy_rec_id = i.rec_id AND f.legacy_side = i.side
SET i.file_id = f.id WHERE i.file_id IS NULL;

-- ---------------------------------------------------------------------------
-- CHECK IT WORKED. Run these afterwards; each should return zero.
--
--   SELECT (SELECT COUNT(*) FROM rec_ledger) + (SELECT COUNT(*) FROM rec_bank)
--        - (SELECT COUNT(*) FROM rec_txns) AS should_be_zero;
--
--   SELECT COUNT(*) AS matches_pointing_nowhere
--   FROM rec_match_lines l
--   LEFT JOIN rec_txns t ON t.id = l.txn_id
--   WHERE t.id IS NULL;
--
--   SELECT COUNT(*) AS split_parts_orphaned
--   FROM rec_txns c LEFT JOIN rec_txns p ON p.id = c.parent_id
--   WHERE c.parent_id IS NOT NULL AND p.id IS NULL;
--
-- rec_ledger and rec_bank are NOT touched or dropped. They stay exactly as they
-- were, so the old figures can always be compared against the new ones.
-- ---------------------------------------------------------------------------
