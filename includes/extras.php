<?php
// -----------------------------------------------------------------------------
// The notes field, and the spare fields (up to six) each file can
// carry.
//
// The spare fields are deliberately just data: they are imported, shown and
// downloaded, and you judge a match by looking at them. The rules do not use
// them. That was David's call and it keeps this simple - if they turn out to be
// worth matching on, that can be added later.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/files.php';

function extras_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT notes, extra1, extra2, extra3 FROM rec_txns LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// The spare fields named on the FILE sitting on this side.
//
// They belong to the file because that is the thing with the columns. The same
// file can appear in several reconciliations and must not need naming again in
// each of them - which is what was wrong when these lived on the reconciliation.
function extra_labels($side)
{
    if (!extras_ready() || !files_ready()) return [];
    return file_extra_labels(side_file_id($side));
}

// Save a note against one transaction.
function save_note_on($side, $id, $text)
{
    if (!extras_ready()) {
        return [false, 'The database has not been updated for notes yet - run sql/migration_006_notes_and_extras.sql.'];
    }
    $text = trim((string)$text);
    $st = db()->prepare("UPDATE rec_txns SET notes = ? WHERE id = ? AND " . file_where($side, ''));
    $st->execute([$text === '' ? null : $text, (int)$id]);
    if (!$st->rowCount()) return [true, 'No change to save.'];
    return [true, $text === '' ? 'Note removed.' : 'Note saved.'];
}

// Correct the spare fields of one transaction.
//
// SPARE FIELDS ONLY, on purpose. The date, the description and the amount decide
// what matches what; changing an amount after a match would leave a match that
// no longer balances, which is the one thing the tool must never allow. A spare
// field is only ever data, so it is safe to put right.
//
// $vals is [column => value] and only the columns the file has named are taken.
function save_fields_on($side, $id, array $vals)
{
    if (!extras_ready()) {
        return [false, 'The database has not been updated for spare fields yet - run '
            . 'sql/migration_006_notes_and_extras.sql.'];
    }
    $labels = extra_labels($side);              // the named spare fields on this side's file
    if (!$labels) return [false, 'That file has no spare fields to edit.'];

    $set = $args = [];
    $changed = [];
    $before = get_txn_on($side, $id);
    if (!$before) return [false, 'That transaction is not on this side.'];

    foreach ($labels as $col => $label) {
        if (!array_key_exists($col, $vals)) continue;
        $new = trim((string)$vals[$col]);
        $new = $new === '' ? null : mb_substr($new, 0, 255);
        if ((string)($before[$col] ?? '') === (string)$new) continue;
        $set[]  = "{$col} = ?";
        $args[] = $new;
        $changed[] = $label;
    }
    if (!$set) return [true, 'No change to save.'];

    $args[] = (int)$id;
    $st = db()->prepare("UPDATE rec_txns SET " . implode(', ', $set) . " WHERE id = ? AND "
                        . file_where($side, ''));
    $st->execute($args);
    return [true, 'Saved ' . implode(' and ', $changed) . '.'];
}

// --- putting right a date or a description the source file had wrong ----------
//
// The amount is never touched: changing it after a match would leave a match
// that no longer balances. A date or a description carries no such risk, but it
// is still what the file said, so the loaded value is kept the first time a line
// is amended and every screen marks the line and can show what it was.

// Has migration_022 been run?
function amend_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT orig_txn_date, amended_at FROM rec_txns LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// Amend the date, the description or both. $why is kept with the line.
function amend_core_on($side, $id, $date, $desc, $why = '')
{
    if (!amend_ready()) {
        return [false, 'The database has not been updated for amendments yet - run '
            . 'sql/migration_022_amendments.sql.'];
    }
    $before = get_txn_on($side, $id);
    if (!$before) return [false, 'That transaction is not on this side.'];

    $date = trim((string)$date);
    $desc = trim(preg_replace('/\s+/', ' ', (string)$desc));
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [false, 'That is not a date the tool understands.'];
    }

    $set = $args = [];
    $changed = [];
    // [column, original-keeping column, new value, what it is called]
    $pairs = [['txn_date', 'orig_txn_date', $date === '' ? null : $date, 'date'],
              ['description', 'orig_description', $desc === '' ? null : mb_substr($desc, 0, 500), 'description']];

    foreach ($pairs as [$col, $origCol, $new, $what]) {
        if ($new === null) continue;                                  // left alone
        if ((string)$before[$col] === (string)$new) continue;         // no change
        $orig = $before[$origCol];
        if ($orig === null) {
            // first amendment: keep what was loaded
            $set[] = "{$origCol} = ?";
            $args[] = $before[$col];
            $orig = $before[$col];
        } elseif ((string)$orig === (string)$new) {
            // put back to what the file said - it is no longer an amendment
            $set[] = "{$origCol} = NULL";
        }
        $set[]  = "{$col} = ?";
        $args[] = $new;
        $changed[] = $what;
    }
    if (!$set) return [true, 'No change to save.'];

    $why = trim((string)$why);
    $u   = function_exists('current_user') ? current_user() : null;
    $still = "(orig_txn_date IS NOT NULL OR orig_description IS NOT NULL)";
    $set[] = "amended_at = CASE WHEN {$still} THEN NOW() ELSE NULL END";
    $set[] = "amended_by = CASE WHEN {$still} THEN ? ELSE NULL END";
    $args[] = $u['name'] ?? null;
    $set[] = "amend_why = CASE WHEN {$still} THEN ? ELSE NULL END";
    $args[] = $why === '' ? null : mb_substr($why, 0, 255);

    $args[] = (int)$id;
    db()->prepare("UPDATE rec_txns SET " . implode(', ', $set) . " WHERE id = ? AND "
                  . file_where($side, ''))->execute($args);

    if (function_exists('log_event')) {
        log_event('amended a transaction', 'id ' . (int)$id . ': ' . implode(' and ', $changed)
            . ($why === '' ? '' : ' - ' . $why));
    }
    return [true, 'Saved the ' . implode(' and ', $changed) . '. The line is marked as amended.'];
}

// Has this line been amended, and in which field?
function amended_field(array $t, $which)
{
    $col = $which === 'date' ? 'orig_txn_date' : 'orig_description';
    return ($t[$col] ?? null) !== null;
}

// The little star beside an amended value, saying what the file said.
function amend_mark(array $t, $which)
{
    if (!amended_field($t, $which)) return '';
    $was = $which === 'date' ? $t['orig_txn_date'] : $t['orig_description'];
    $tip = 'The file said: ' . $was;
    if (!empty($t['amended_at'])) {
        $tip .= "\nAmended " . date('j M Y', strtotime((string)$t['amended_at']));
        if (!empty($t['amended_by'])) $tip .= ' by ' . $t['amended_by'];
    }
    if (!empty($t['amend_why'])) $tip .= "\n" . $t['amend_why'];
    return '<span class="amended" title="' . h($tip) . '">*</span>';
}

// One transaction, if it belongs to this side's file.
function get_txn_on($side, $id)
{
    $st = db()->prepare("SELECT * FROM rec_txns WHERE id = ? AND " . file_where($side, ''));
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

// The months present in this reconciliation, newest first, for the month picker.
// Derived rather than stored - a stored copy would only be another thing that
// could disagree with the date it came from.
function available_months()
{
    $sql = [];
    foreach (['ledger', 'bank'] as $side) {
        $sql[] = "SELECT DISTINCT DATE_FORMAT(t.txn_date, '%Y-%m') ym FROM rec_txns t WHERE "
               . file_where($side, 't');
    }
    $rows = db()->query(implode(' UNION ', $sql) . " ORDER BY ym DESC")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $ym) $out[$ym] = date('F Y', strtotime($ym . '-01'));
    return $out;
}

// A month as a from/to pair, so it reuses the date filtering already there.
function month_bounds($ym)
{
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) return null;
    $first = $ym . '-01';
    return [$first, date('Y-m-t', strtotime($first))];
}
