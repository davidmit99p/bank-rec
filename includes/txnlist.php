<?php
// -----------------------------------------------------------------------------
// Listing transactions with the screen's filters applied.
//
// Shared by the transactions screen and the download, so that what comes out of
// a download is exactly what was on screen - same filters, same order.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/splits.php';

// Column names allowed in an ORDER BY, so nothing from the address bar
// reaches the query.
function sort_columns()
{
    return ['date'        => 'txn_date',
            'description' => 'description',
            'value'       => 'value',
            'abs'         => 'value'];   // by size, ignoring the sign
}

// The ORDER BY for a sort choice.
//
// 'abs' sorts by size and ignores the sign, so 100.00 and -100.00 sit next to
// each other - which is how you spot a pair that cancels out. Within the same
// size the negative comes first, so a contra reads as a pair rather than
// arriving in whatever order the database felt like.
function order_expression($sortKey, $dir)
{
    $d = $dir === 'desc' ? 'DESC' : 'ASC';
    if ($sortKey === 'abs') return "ABS(t.value) {$d}, t.value ASC, t.id";
    $col = sort_columns()[$sortKey] ?? 'txn_date';
    return "t.{$col} {$d}, t.id";
}

function list_items($side, $q, $from, $to, $skipIds, $show = 'open', $sortKey = 'date', $dir = 'asc',
                    $sign = 'both')
{
    $table = $side === 'ledger' ? 'rec_ledger' : 'rec_bank';
    $where = [];
    $args  = [];

    if ($show === 'open')    $where[] = 't.matched_at IS NULL';
    if ($show === 'matched') $where[] = 't.matched_at IS NOT NULL';

    if ($sign === 'in')  $where[] = 't.value > 0';
    if ($sign === 'out') $where[] = 't.value < 0';

    if ($q !== '')    { $where[] = 't.description LIKE ?'; $args[] = '%' . $q . '%'; }
    if ($from !== '') { $where[] = 't.txn_date >= ?';      $args[] = $from; }
    if ($to !== '')   { $where[] = 't.txn_date <= ?';      $args[] = $to; }
    // items already claimed by the draft run are not available to tick again,
    // but that only applies to the open ones
    if ($skipIds && $show !== 'matched') {
        $where[] = '(t.matched_at IS NOT NULL OR t.id NOT IN ('
                 . implode(',', array_map('intval', $skipIds)) . '))';
    }

    $where[] = rec_where('t');
    if (splits_ready()) $where[] = 't.split_at IS NULL';   // the parts stand in for it now
    $parentVal = splits_ready()
        ? ", (SELECT p.value FROM {$table} p WHERE p.id = t.parent_id) AS parent_value"
        : ", NULL AS parent_value";
    $sql = "SELECT t.*, r.run_ref{$parentVal},
                   (SELECT l.group_id FROM rec_match_lines l
                     WHERE l.side = '{$side}' AND l.txn_id = t.id LIMIT 1) AS group_id
            FROM {$table} t
            LEFT JOIN rec_runs r ON r.id = t.run_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY " . order_expression($sortKey, $dir);
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
