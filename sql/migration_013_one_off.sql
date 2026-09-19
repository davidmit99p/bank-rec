-- Migration 013: marking the reconciliations made by the quick route.
--
-- The quick route (public/quick.php) creates a reconciliation and its two files
-- in one go, for a one-off check. They are ordinary records in every way - the
-- only difference is this flag, which lets the screens say "one-off" and offer
-- to remove the lot in one step when you are done.
--
-- Safe to run more than once. Until it has been run the quick route still
-- works; its results just look like any other reconciliation, with no one-off
-- tag and no one-step delete.

ALTER TABLE rec_recs  ADD COLUMN IF NOT EXISTS one_off TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE rec_files ADD COLUMN IF NOT EXISTS one_off TINYINT(1) NOT NULL DEFAULT 0;
