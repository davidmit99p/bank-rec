-- ---------------------------------------------------------------------------
-- Run this ONCE in phpMyAdmin against entigy_recon, after migration_003.
--
-- Moves the Shelf notes into the database so they can be edited on the page
-- itself. Previous versions are kept, so nothing is lost by editing.
--
-- Nothing is seeded here: the first time the Shelf page is opened it copies
-- the current SHELF.md in, so the notes carry over as they are.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rec_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    slug       VARCHAR(50)  NOT NULL UNIQUE,   -- 'shelf'
    title      VARCHAR(150) NOT NULL,
    body       LONGTEXT     NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every save keeps the version it replaced, so an edit can be undone.
CREATE TABLE IF NOT EXISTS rec_note_versions (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    note_id  INT      NOT NULL,
    body     LONGTEXT NOT NULL,
    saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ver_note FOREIGN KEY (note_id)
        REFERENCES rec_notes(id) ON DELETE CASCADE,
    INDEX (note_id, saved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
