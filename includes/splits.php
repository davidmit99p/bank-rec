<?php
// -----------------------------------------------------------------------------
// Splitting one transaction into parts.
//
// A single bank line often covers more than one thing - an invoice and a charge
// on the same payment. Splitting it lets each part be matched separately.
//
// The original is kept and marked as split, so:
//   - the parts always add back to exactly what came off the statement,
//   - you can see where a part came from,
//   - and the split can be undone.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';

function splits_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT parent_id, split_at FROM rec_txns LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// A split original is no longer a transaction in its own right - its parts are.
// Add this wherever open items are counted or listed.
function not_split($alias = '')
{
    if (!splits_ready()) return '';
    $p = $alias === '' ? '' : $alias . '.';
    return " AND {$p}split_at IS NULL";
}

// Every transaction lives in one table now; the side only says which half of a
// reconciliation it sits on.
function split_table($side = null)
{
    return 'rec_txns';
}

// Split $id into $parts, each ['value' => x, 'description' => '...'].
function split_transaction($side, $id, array $parts)
{
    if (!splits_ready()) {
        return [false, 'The database has not been updated for splitting yet - run sql/migration_005_splits.sql.'];
    }
    $table = split_table($side);
    $pdo   = db();

    $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
    $st->execute([(int)$id]);
    $t = $st->fetch();
    if (!$t)                        return [false, 'That transaction could not be found.'];
    if ($t['matched_at'] !== null)  return [false, 'That one is already matched. Unmatch it first, then split it.'];
    if ($t['split_at'] !== null)    return [false, 'That one has already been split.'];

    // tidy up what came in
    $clean = [];
    foreach ($parts as $p) {
        $v = trim((string)($p['value'] ?? ''));
        if ($v === '') continue;
        $v = (float)str_replace(',', '', $v);
        if (abs($v) < 0.005) continue;                 // a nil part is not a part
        $clean[] = [
            'value' => round($v, 2),
            'description' => trim((string)($p['description'] ?? '')) ?: $t['description'],
        ];
    }
    if (count($clean) < 2) return [false, 'A split needs at least two parts with a value in them.'];

    // THE GOLDEN RULE, applied to the split: the parts must come to exactly
    // what was there before, or the totals on that side would move.
    $sum = array_sum(array_column($clean, 'value'));
    if (abs($sum - (float)$t['value']) >= 0.005) {
        return [false, 'The parts come to ' . money($sum) . ' but the transaction is '
            . money($t['value']) . ' - a difference of ' . money($sum - (float)$t['value'])
            . '. Nothing has been changed.'];
    }

    // is it spoken for by a run that has not been finalised?
    $st = $pdo->prepare("SELECT g.run_id FROM rec_match_lines l
                         JOIN rec_match_groups g ON g.id = l.group_id
                         WHERE l.side = ? AND l.txn_id = ?");
    $st->execute([$side, (int)$id]);
    if ($st->fetch()) {
        return [false, 'That one is part of a suggested match waiting to be finalised. '
            . 'Deal with that run first, then split it.'];
    }

    $cols = ['txn_date', 'description', 'value', 'source_file', 'parent_id', 'file_id',
             'import_id', 'extra1', 'extra2', 'extra3'];

    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ("
             . implode(',', array_fill(0, count($cols), '?')) . ")";
        $ins = $pdo->prepare($sql);
        // the parts inherit everything about the original except the amount
        foreach ($clean as $p) {
            $ins->execute([$t['txn_date'], mb_substr($p['description'], 0, 500), $p['value'],
                           $t['source_file'], (int)$id, $t['file_id'], $t['import_id'],
                           $t['extra1'], $t['extra2'], $t['extra3']]);
        }
        $pdo->prepare("UPDATE {$table} SET split_at = NOW() WHERE id = ?")->execute([(int)$id]);
        $pdo->commit();
        return [true, 'Split ' . money($t['value']) . ' into ' . count($clean) . ' parts.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Nothing was split: ' . $e->getMessage()];
    }
}

// Put a split back together. Only possible while none of the parts are matched
// or sitting in a run.
function unsplit_transaction($side, $parentId)
{
    if (!splits_ready()) return [false, 'The database has not been updated for splitting yet.'];
    $table = split_table($side);
    $pdo   = db();

    $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND split_at IS NOT NULL");
    $st->execute([(int)$parentId]);
    $parent = $st->fetch();
    if (!$parent) return [false, 'That does not look like a transaction that was split.'];

    $st = $pdo->prepare("SELECT id, matched_at FROM {$table} WHERE parent_id = ?");
    $st->execute([(int)$parentId]);
    $kids = $st->fetchAll();
    if (!$kids) return [false, 'That split has no parts left to put back.'];

    foreach ($kids as $k) {
        if ($k['matched_at'] !== null) {
            return [false, 'One of the parts is already matched. Unmatch it first, then undo the split.'];
        }
    }
    $ids = implode(',', array_map(fn($k) => (int)$k['id'], $kids));
    $st = $pdo->prepare("SELECT COUNT(*) FROM rec_match_lines WHERE side = ? AND txn_id IN ($ids)");
    $st->execute([$side]);
    if ((int)$st->fetchColumn()) {
        return [false, 'One of the parts is in a suggested match waiting to be finalised. '
            . 'Deal with that run first.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM {$table} WHERE id IN ($ids)");
        $pdo->prepare("UPDATE {$table} SET split_at = NULL WHERE id = ?")->execute([(int)$parentId]);
        $pdo->commit();
        return [true, 'Split undone. ' . money($parent['value']) . ' is back as one transaction.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'The split was not undone: ' . $e->getMessage()];
    }
}

// Small helper so the code works whether or not the earlier migrations have run.
function has_column($table, $column)
{
    static $seen = [];
    $key = $table . '.' . $column;
    if (!isset($seen[$key])) {
        try {
            db()->query("SELECT {$column} FROM {$table} LIMIT 1");
            $seen[$key] = true;
        } catch (Throwable $e) {
            $seen[$key] = false;
        }
    }
    return $seen[$key];
}
