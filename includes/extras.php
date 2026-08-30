<?php
// -----------------------------------------------------------------------------
// The notes field, and the three spare fields each side of a reconciliation can
// carry.
//
// The spare fields are deliberately just data: they are imported, shown and
// downloaded, and you judge a match by looking at them. The rules do not use
// them. That was David's call and it keeps this simple - if they turn out to be
// worth matching on, that can be added later.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';

function extras_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT notes, extra1, extra2, extra3 FROM rec_ledger LIMIT 1");
            db()->query("SELECT notes, extra1, extra2, extra3 FROM rec_bank LIMIT 1");
            db()->query("SELECT l_extra1, b_extra1 FROM rec_recs LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// What this reconciliation calls its spare fields on one side.
// Returns [columnName => label] for the ones that have been named, in order.
function extra_labels($side)
{
    if (!extras_ready()) return [];
    $rec = current_rec();
    if (!$rec) return [];
    $p = $side === 'ledger' ? 'l_' : 'b_';
    $out = [];
    for ($i = 1; $i <= 3; $i++) {
        $label = trim((string)($rec[$p . 'extra' . $i] ?? ''));
        if ($label !== '') $out['extra' . $i] = $label;
    }
    return $out;
}

// Save a note against one transaction.
function save_note_on($side, $id, $text)
{
    if (!extras_ready()) {
        return [false, 'The database has not been updated for notes yet - run sql/migration_006_notes_and_extras.sql.'];
    }
    $table = $side === 'ledger' ? 'rec_ledger' : 'rec_bank';
    $text  = trim((string)$text);
    $st = db()->prepare("UPDATE {$table} SET notes = ? WHERE id = ?" . rec_and());
    $st->execute([$text === '' ? null : $text, (int)$id]);
    if (!$st->rowCount()) return [true, 'No change to save.'];
    return [true, $text === '' ? 'Note removed.' : 'Note saved.'];
}

// The months present in this reconciliation, newest first, for the month picker.
// Derived rather than stored - a stored copy would only be another thing that
// could disagree with the date it came from.
function available_months()
{
    $sql = [];
    foreach (['rec_ledger', 'rec_bank'] as $t) {
        $sql[] = "SELECT DISTINCT DATE_FORMAT(t.txn_date, '%Y-%m') ym FROM {$t} t WHERE " . rec_where('t');
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
