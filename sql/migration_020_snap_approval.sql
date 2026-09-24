-- ---------------------------------------------------------------------------
-- Run this ONCE, or press "Apply what is waiting" on the Database page.
--
-- APPROVING A SNAP: a second person opening it, looking at it, and signing.
--
-- Most of what is needed was put in with migration_019 - approved_at and
-- approved_by have been sitting there empty since. This only adds the one
-- thing that was missing: somewhere for the approver to say what they checked,
-- or what they are accepting, which is usually the useful part of a sign-off.
-- ---------------------------------------------------------------------------

ALTER TABLE rec_statements
    ADD COLUMN IF NOT EXISTS approved_note TEXT NULL;
