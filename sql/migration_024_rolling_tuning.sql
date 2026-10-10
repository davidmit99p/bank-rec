-- ---------------------------------------------------------------------------
-- Applied by the Database page ("Apply what is waiting"), like every migration.
--
-- TUNING A ROLLING LOAD.
--
-- A key is only as good as the fields it is built from, and you cannot always
-- tell which fields are stable until you see a real load. So the fields that
-- identify a record stop being a fixed setting on the file and become
-- something you adjust while looking at the load itself: compare, look at the
-- counts, change the key, compare again. The file keeps a starting point.
--
-- Three things make that possible:
--
--  - the load stays in staging until you have finished with it, so a record
--    that has already been let through is marked rather than thrown away, and
--    the rest can be judged again;
--  - rec_stage_load holds the key and the ignored fields IN USE for the load
--    being looked at, separately from the file's own starting point;
--  - a record that looks new can carry a suggestion of the record it might be
--    an amendment of, which you accept or ignore.
--
-- ignore_fields is the other half of it: a field named there is left out of the
-- comparison altogether, so a change in it never raises an amendment. That is
-- different from refusing a field at the moment of applying, which leaves the
-- difference showing until it is dealt with.
--
-- Check afterwards - both should return 0:
--   SELECT COUNT(*) FROM rec_stage WHERE applied_at IS NOT NULL;
--   SELECT COUNT(*) FROM rec_stage_load;
-- ---------------------------------------------------------------------------

ALTER TABLE rec_files
    ADD COLUMN IF NOT EXISTS ignore_fields VARCHAR(120) NULL;

ALTER TABLE rec_stage
    ADD COLUMN IF NOT EXISTS applied_at     DATETIME        NULL,
    ADD COLUMN IF NOT EXISTS suggest_txn_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS paired_by_hand TINYINT(1)      NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS rec_stage_load (
    file_id       INT UNSIGNED NOT NULL,
    source_file   VARCHAR(255) NULL,
    loaded_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    key_fields    VARCHAR(120) NULL,   -- in use for this load, not the file's default
    ignore_fields VARCHAR(120) NULL,
    PRIMARY KEY (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
