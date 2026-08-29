<?php
// -----------------------------------------------------------------------------
// Notes pages that can be edited on the site itself - the Shelf, for now.
//
// They live in the database rather than in the repository, because the point of
// the Shelf is to write something down the moment you think of it. Anything
// written into the deployed folder would be wiped by the next deployment.
//
// The version history is what we keep in exchange for losing git's.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';

function notes_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT id FROM rec_notes LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// Fetch a note, seeding it from a file the first time if it is not there yet.
function get_note($slug, $title, $seedFile = null)
{
    if (!notes_ready()) return null;

    $st = db()->prepare("SELECT * FROM rec_notes WHERE slug = ?");
    $st->execute([$slug]);
    if ($n = $st->fetch()) return $n;

    $body = ($seedFile && is_file($seedFile)) ? file_get_contents($seedFile) : '';
    db()->prepare("INSERT INTO rec_notes (slug, title, body) VALUES (?,?,?)")
        ->execute([$slug, $title, $body]);
    $st->execute([$slug]);
    return $st->fetch();
}

// Save, keeping what was there before.
function save_note($slug, $body)
{
    if (!notes_ready()) return [false, 'The database has not been updated for this yet.'];

    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM rec_notes WHERE slug = ?");
    $st->execute([$slug]);
    $note = $st->fetch();
    if (!$note) return [false, 'That note does not exist.'];

    if (rtrim($body) === rtrim($note['body'])) return [true, 'No changes to save.'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO rec_note_versions (note_id, body) VALUES (?,?)")
            ->execute([$note['id'], $note['body']]);
        $pdo->prepare("UPDATE rec_notes SET body = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$body, $note['id']]);
        // keep the last 30 versions, which is plenty for a notes page
        $pdo->prepare("DELETE FROM rec_note_versions
                       WHERE note_id = ? AND id NOT IN (
                           SELECT id FROM (
                               SELECT id FROM rec_note_versions
                               WHERE note_id = ? ORDER BY saved_at DESC, id DESC LIMIT 30
                           ) keep)")
            ->execute([$note['id'], $note['id']]);
        $pdo->commit();
        return [true, 'Saved.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Not saved: ' . $e->getMessage()];
    }
}

function note_versions($noteId, $limit = 30)
{
    if (!notes_ready()) return [];
    $st = db()->prepare("SELECT id, saved_at, CHAR_LENGTH(body) AS len FROM rec_note_versions
                         WHERE note_id = ? ORDER BY saved_at DESC, id DESC LIMIT " . (int)$limit);
    $st->execute([$noteId]);
    return $st->fetchAll();
}

// Put an earlier version back. The current text is kept as a version first, so
// restoring is itself undoable.
function restore_note_version($slug, $versionId)
{
    if (!notes_ready()) return [false, 'The database has not been updated for this yet.'];
    $st = db()->prepare("SELECT v.body, v.saved_at FROM rec_note_versions v
                         JOIN rec_notes n ON n.id = v.note_id
                         WHERE v.id = ? AND n.slug = ?");
    $st->execute([$versionId, $slug]);
    $v = $st->fetch();
    if (!$v) return [false, 'That version could not be found.'];

    [$ok, $msg] = save_note($slug, $v['body']);
    return $ok
        ? [true, 'Put back the version saved on ' . $v['saved_at'] . '.']
        : [false, $msg];
}
