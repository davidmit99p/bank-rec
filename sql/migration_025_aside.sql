-- ---------------------------------------------------------------------------
-- Applied by the Database page ("Apply what is waiting"), like every migration.
--
-- SET ASIDE: a third answer, beside matched and unmatched.
--
-- At volume a reconciliation leaves a handful of items that cannot be settled
-- here at all, because the source data is wrong. Left in the lists they are
-- noise, and the difference never reaches zero however much real work is done.
--
-- So an item can be set aside: looked at, understood, and out of the way with a
-- reason written against it. Hidden while you work, the lists hold only what
-- can still be done; brought back, they are the list to go and fix.
--
-- Per reconciliation, like rec_matched, because a file shared by two
-- reconciliations can be settled in one and still a problem in the other.
--
-- What this does NOT change: the reconciliation statement. A set-aside item is
-- still an unsettled difference, so it still appears there as a reconciling
-- item, with its reason. Hiding it is a convenience for working, never a claim
-- that it has gone away - which is why the difference on screen always says
-- what it is excluding.
--
-- Check afterwards - should return 0:
--   SELECT COUNT(*) FROM rec_aside;
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_aside (
    txn_id    INT          NOT NULL,
    rec_id    INT          NOT NULL,
    reason    VARCHAR(500) NULL,
    aside_by  VARCHAR(120) NULL,
    aside_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (txn_id, rec_id),
    INDEX (rec_id),
    INDEX (aside_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A snap is a copy, not a view, so it carries the reason with it. Without this
-- an approved snap would lose why an item was set aside the moment the reason
-- was edited.
ALTER TABLE rec_statement_lines
    ADD COLUMN IF NOT EXISTS aside_why VARCHAR(500) NULL;
