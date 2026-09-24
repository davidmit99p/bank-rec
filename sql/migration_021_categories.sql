-- ---------------------------------------------------------------------------
-- Run this ONCE, or press "Apply what is waiting" on the Database page.
--
-- CATEGORIES ON RECONCILIATIONS.
--
-- Bank reconciliations, booking reconciliations, stock reconciliations. Once
-- there are more than a handful of reconciliations the list at the top right
-- and the Reconciliations page both turn into a wall of names, and the only
-- thing that fixes that is saying what KIND each one is.
--
-- A managed list rather than a typed-in label, because "Bank", "bank" and
-- "Bank recs" would otherwise be three different groups.
--
-- Nothing is put in a category by this. Everything starts uncategorised and
-- carries on working exactly as it does now.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(80) NOT NULL,
    sort_order INT         NOT NULL DEFAULT 100,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_rec_categories_order (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE rec_recs
    ADD COLUMN IF NOT EXISTS category_id INT NULL;

ALTER TABLE rec_recs
    ADD INDEX IF NOT EXISTS ix_rec_recs_category (category_id);
