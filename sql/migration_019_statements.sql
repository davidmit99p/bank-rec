-- ---------------------------------------------------------------------------
-- Run this ONCE, or press "Apply what is waiting" on the Database page.
--
-- THE RECONCILIATION STATEMENT, AND SNAPS OF IT.
--
-- The transactions screen compares the two sides item by item. This is the
-- other half of the story: as at a date, this balance against that balance,
-- the open items that explain the gap between them, and whatever is left over
-- - the figure that says something is wrong that nobody has identified.
--
-- A SNAP is a permanent, date-stamped copy of that page: the balances, every
-- reconciling item as it read at the time, and who took it. It is never
-- altered by anything that happens afterwards, which is the point of it, so
-- the lines are copies rather than references.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_statements (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    rec_id        INT            NULL,
    as_at         DATE           NOT NULL,
    left_balance  DECIMAL(18,2)  NOT NULL DEFAULT 0,
    right_balance DECIMAL(18,2)  NOT NULL DEFAULT 0,
    left_derived  TINYINT(1)     NOT NULL DEFAULT 0,   -- added up from the items rather than typed
    right_derived TINYINT(1)     NOT NULL DEFAULT 0,
    left_open     DECIMAL(18,2)  NOT NULL DEFAULT 0,   -- that side's open items, signed
    right_open    DECIMAL(18,2)  NOT NULL DEFAULT 0,
    left_adj      DECIMAL(18,2)  NOT NULL DEFAULT 0,   -- balance plus the OTHER side's open items
    right_adj     DECIMAL(18,2)  NOT NULL DEFAULT 0,
    unexplained   DECIMAL(18,2)  NOT NULL DEFAULT 0,
    left_label    VARCHAR(60)    NULL,                 -- the side names as they read that day
    right_label   VARCHAR(60)    NULL,
    note          TEXT           NULL,
    created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT            NULL,
    approved_at   DATETIME       NULL,                 -- for the review step, not yet built
    approved_by   INT            NULL,
    KEY ix_rec_statements_rec (rec_id, as_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rec_statement_lines (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    statement_id INT            NOT NULL,
    side         VARCHAR(10)    NOT NULL,              -- ledger or bank, as it was then
    txn_id       INT            NULL,                  -- where it came from, for curiosity only
    txn_date     DATE           NULL,
    description  VARCHAR(255)   NULL,
    value        DECIMAL(18,2)  NOT NULL DEFAULT 0,
    group_ref    VARCHAR(20)    NULL,                  -- G3, if it was in a group note
    group_note   TEXT           NULL,                  -- and what that note said at the time
    KEY ix_rec_statement_lines (statement_id, side)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
