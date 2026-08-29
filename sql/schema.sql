-- ---------------------------------------------------------------------------
-- Bank Reconciliation - run this ONCE against your MariaDB database
-- (Plesk > Databases > phpMyAdmin > SQL tab > paste > Go).
--
-- Every table name starts with rec_ so these six sit safely alongside tables
-- you already have - currently the Accountant Toolkit's database.
-- ---------------------------------------------------------------------------

-- One row per reconciliation - a bank account, a supplier statement, whatever.
-- left_label and right_label are what the two sides are called on screen, so
-- this is not tied to banks.
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

-- Table 1: the ledger (your accounting system) --------------------------------
CREATE TABLE IF NOT EXISTS rec_ledger (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    txn_date    DATE          NOT NULL,
    description VARCHAR(500)  NOT NULL DEFAULT '',
    value       DECIMAL(15,2) NOT NULL,
    source_file VARCHAR(255)  NULL,
    import_id   INT           NULL,
    rec_id      INT           NULL,
    imported_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- filled in when the item is matched and finalised
    run_id      INT           NULL,
    rule_ref    VARCHAR(20)   NULL,
    group_no    INT           NULL,
    matched_at  DATETIME      NULL,
    INDEX (txn_date), INDEX (value), INDEX (run_id), INDEX (matched_at), INDEX (import_id), INDEX (rec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table 2: the bank statement -------------------------------------------------
CREATE TABLE IF NOT EXISTS rec_bank (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    txn_date    DATE          NOT NULL,
    description VARCHAR(500)  NOT NULL DEFAULT '',
    value       DECIMAL(15,2) NOT NULL,
    source_file VARCHAR(255)  NULL,
    import_id   INT           NULL,
    rec_id      INT           NULL,
    imported_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    run_id      INT           NULL,
    rule_ref    VARCHAR(20)   NULL,
    group_no    INT           NULL,
    matched_at  DATETIME      NULL,
    INDEX (txn_date), INDEX (value), INDEX (run_id), INDEX (matched_at), INDEX (import_id), INDEX (rec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per file that has been loaded, so an import can be removed again ----
CREATE TABLE IF NOT EXISTS rec_imports (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    side        VARCHAR(10)   NOT NULL,          -- ledger | bank
    filename    VARCHAR(255)  NOT NULL,
    row_count   INT           NOT NULL DEFAULT 0,
    total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    date_from   DATE          NULL,
    date_to     DATE          NULL,
    imported_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rec_id      INT           NULL,
    INDEX (side), INDEX (imported_at), INDEX (rec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The rule library ------------------------------------------------------------
-- One row = one rule. The l_ columns are the LEFT (ledger) form on the rule
-- screen, the b_ columns are the RIGHT (bank) form.
CREATE TABLE IF NOT EXISTS rec_rules (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150)  NOT NULL,
    active       TINYINT(1)    NOT NULL DEFAULT 1,
    sort_order   INT           NOT NULL DEFAULT 100,

    -- LEFT side: which ledger lines this rule looks at
    l_desc_op    VARCHAR(20)   NOT NULL DEFAULT 'any',
    l_desc_val   VARCHAR(255)  NULL,
    l_value_op   VARCHAR(20)   NOT NULL DEFAULT 'any',
    l_value_val  DECIMAL(15,2) NULL,
    l_value_val2 DECIMAL(15,2) NULL,
    l_date_op    VARCHAR(20)   NOT NULL DEFAULT 'any',
    l_date_val   DATE          NULL,
    l_date_val2  DATE          NULL,

    -- RIGHT side: which bank lines this rule looks at
    b_desc_op    VARCHAR(20)   NOT NULL DEFAULT 'any',
    b_desc_val   VARCHAR(255)  NULL,
    b_value_op   VARCHAR(20)   NOT NULL DEFAULT 'any',
    b_value_val  DECIMAL(15,2) NULL,
    b_value_val2 DECIMAL(15,2) NULL,
    b_date_op    VARCHAR(20)   NOT NULL DEFAULT 'any',
    b_date_val   DATE          NULL,
    b_date_val2  DATE          NULL,

    -- how the two sides are paired up
    date_tol     INT           NOT NULL DEFAULT 3,      -- days apart allowed
    sign_mode    VARCHAR(10)   NOT NULL DEFAULT 'same', -- same | opposite
    grouping     VARCHAR(20)   NOT NULL DEFAULT 'one',  -- one | many_left | many_right
    max_group    INT           NOT NULL DEFAULT 4,
    link_desc    TINYINT(1)    NOT NULL DEFAULT 0,      -- also require descriptions to look alike

    rec_id       INT           NULL,   -- NULL means every reconciliation
    notes        TEXT          NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (active), INDEX (sort_order), INDEX (rec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per matching run ----------------------------------------------------
CREATE TABLE IF NOT EXISTS rec_runs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    run_ref      VARCHAR(30)  NOT NULL UNIQUE,          -- e.g. RUN-20260828-4KQ2
    status       VARCHAR(15)  NOT NULL DEFAULT 'draft', -- draft | finalised | discarded
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalised_at DATETIME     NULL,
    note         VARCHAR(255) NULL,
    rec_id       INT          NULL,
    INDEX (status), INDEX (rec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A suggested (or committed) match: one group, both sides must total the same --
CREATE TABLE IF NOT EXISTS rec_match_groups (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    run_id       INT           NOT NULL,
    group_no     INT           NOT NULL,
    rule_ref     VARCHAR(20)   NOT NULL,   -- the rule id as text, or 'manual'
    rule_name    VARCHAR(150)  NULL,
    ledger_total DECIMAL(15,2) NOT NULL,
    bank_total   DECIMAL(15,2) NOT NULL,
    sign_mode    VARCHAR(10)   NOT NULL DEFAULT 'same', -- same | opposite
    accepted     TINYINT(1)    NOT NULL DEFAULT 1,
    INDEX (run_id), INDEX (rule_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The individual transactions inside a match group ----------------------------
CREATE TABLE IF NOT EXISTS rec_match_lines (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT           NOT NULL,
    side     VARCHAR(10)   NOT NULL,       -- ledger | bank
    txn_id   INT           NOT NULL,
    value    DECIMAL(15,2) NOT NULL,
    CONSTRAINT fk_line_group FOREIGN KEY (group_id)
        REFERENCES rec_match_groups(id) ON DELETE CASCADE,
    INDEX (group_id), INDEX (side, txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
