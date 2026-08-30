-- ---------------------------------------------------------------------------
-- The copying half of migration_007, on its own.
--
-- Run this whenever transactions have gone into the old tables since the last
-- time - which happens if anything is imported while the old code is still
-- deployed. It brings across whatever is not already there and leaves the rest
-- alone, so it is safe to run as often as you like.
--
-- No columns or tables are created here, so there is nothing to complain about
-- being there already.
-- ---------------------------------------------------------------------------

-- 1. A file for each side of each reconciliation, if not already made.
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

-- 2. Copy over any transactions not carried across yet.
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

-- 3. Split parts point at their parent's new id.
UPDATE rec_txns c
JOIN rec_txns p ON p.legacy_side = c.legacy_side AND p.legacy_id = c.legacy_parent_id
SET c.parent_id = p.id
WHERE c.legacy_parent_id IS NOT NULL;

-- 4. Committed matches point at the new ids.
UPDATE rec_match_lines SET legacy_txn_id = txn_id WHERE legacy_txn_id IS NULL;

UPDATE rec_match_lines l
JOIN rec_txns t ON t.legacy_side = l.side AND t.legacy_id = l.legacy_txn_id
SET l.txn_id = t.id;

-- 5. Each import belongs to a file.
UPDATE rec_imports i
JOIN rec_files f ON f.legacy_rec_id = i.rec_id AND f.legacy_side = i.side
SET i.file_id = f.id WHERE i.file_id IS NULL;
