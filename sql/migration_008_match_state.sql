-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_007.
--
-- TAKE A DATABASE EXPORT FIRST.
--
-- WHAT IT DOES
--
-- Whether a transaction is matched currently lives ON THE TRANSACTION -
-- rec_txns.matched_at. One transaction, one answer. That is fine while a file
-- belongs to a single reconciliation, and wrong the moment it belongs to
-- several: file B has to be settled against A and still outstanding against C
-- at the same time, and one column cannot say both.
--
-- So the answer moves to rec_matched, which holds one row per transaction PER
-- RECONCILIATION. A transaction with no row for a reconciliation is open in it.
--
-- Nothing is removed. rec_txns.matched_at stays exactly as it is, so the old
-- answer and the new one can be compared before anything relies on the new one.
-- The checks at the bottom do exactly that.
--
-- Running it twice is harmless.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_matched (
    txn_id     INT         NOT NULL,
    rec_id     INT         NOT NULL,
    group_id   INT         NOT NULL,
    run_id     INT         NOT NULL,
    rule_ref   VARCHAR(20) NULL,
    group_no   INT         NULL,
    matched_at DATETIME    NOT NULL,
    PRIMARY KEY (txn_id, rec_id),
    INDEX (rec_id), INDEX (group_id), INDEX (run_id), INDEX (matched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The joins below and the code afterwards look transactions up by id alone,
-- not by side, so give them an index that suits.
ALTER TABLE rec_match_lines ADD INDEX IF NOT EXISTS ix_lines_txn (txn_id);

-- ---------------------------------------------------------------------------
-- Fill it in from the matches that have actually been committed.
--
-- A match belongs to a run, and a run belongs to a reconciliation, so the
-- reconciliation a transaction was matched in is already known - this only
-- writes it down somewhere it can be asked about quickly.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO rec_matched (txn_id, rec_id, group_id, run_id, rule_ref, group_no, matched_at)
SELECT l.txn_id, r.rec_id, g.id, r.id, g.rule_ref, g.group_no,
       COALESCE(r.finalised_at, NOW())
FROM rec_match_lines l
JOIN rec_match_groups g ON g.id = l.group_id
JOIN rec_runs        r ON r.id = g.run_id AND r.status = 'finalised'
WHERE r.rec_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- CHECK IT WORKED. Run these afterwards; each should return zero.
--
-- 1. Everything the old column says is matched has a row in the new table:
--
--    SELECT COUNT(*) AS matched_but_missing_from_new
--    FROM rec_txns t
--    LEFT JOIN rec_matched m ON m.txn_id = t.id
--    WHERE t.matched_at IS NOT NULL AND m.txn_id IS NULL;
--
-- 2. And nothing in the new table is open according to the old column:
--
--    SELECT COUNT(*) AS new_says_matched_but_old_says_open
--    FROM rec_matched m
--    JOIN rec_txns t ON t.id = m.txn_id
--    WHERE t.matched_at IS NULL;
--
-- 3. The two agree on the total:
--
--    SELECT (SELECT COUNT(*) FROM rec_txns WHERE matched_at IS NOT NULL)
--         - (SELECT COUNT(*) FROM rec_matched) AS difference;
--
-- The third can legitimately be NEGATIVE later on, once a transaction is
-- matched in more than one reconciliation - but right now, before any file is
-- shared, it should be exactly zero.
-- ---------------------------------------------------------------------------
