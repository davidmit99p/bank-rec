-- ---------------------------------------------------------------------------
-- Run this ONCE, or press "Apply what is waiting" on the Database page.
--
-- GROUP NOTES. A note written once against several items that belong to the
-- same issue, on either side or both, rather than typed out against each one.
-- Every item in it carries the group's reference, so the related items can be
-- seen together.
--
-- Called "issues" in the database to keep them apart from rec_match_groups,
-- which is a committed match. On screen they are group notes.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_issues (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    rec_id     INT          NULL,          -- the reconciliation it belongs to
    ref        VARCHAR(20)  NOT NULL,      -- G1, G2, ... within that reconciliation
    note       TEXT         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT          NULL,
    KEY ix_rec_issues_rec (rec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE rec_txns
    ADD COLUMN IF NOT EXISTS issue_id INT NULL;

ALTER TABLE rec_txns
    ADD INDEX IF NOT EXISTS ix_rec_txns_issue (issue_id);
