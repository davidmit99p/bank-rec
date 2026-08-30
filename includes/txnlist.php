<?php
// -----------------------------------------------------------------------------
// Listing transactions with the screen's filters applied.
//
// Shared by the transactions screen and the download, so that what comes out of
// a download is exactly what was on screen - same filters, same order.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/splits.php';
require_once __DIR__ . '/matchstate.php';

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

// Everything the screen filters on, built once and used by both the count and
// the listing, so the figures at the top of a panel always describe the list
// underneath it.
function item_filters($side, $q, $from, $to, $show, $sign)
{
    $table = 'rec_txns';
    $where = [file_where($side, 't')];
    $args  = [];

    // matched IN THIS RECONCILIATION - the same line can be settled against one
    // file and still outstanding against another
    if ($show === 'open')    $where[] = open_where('t');
    if ($show === 'matched') $where[] = matched_where('t');

    if ($sign === 'in')  $where[] = 't.value > 0';
    if ($sign === 'out') $where[] = 't.value < 0';

    if ($q !== '')    { $where[] = 't.description LIKE ?'; $args[] = '%' . $q . '%'; }
    if ($from !== '') { $where[] = 't.txn_date >= ?';      $args[] = $from; }
    if ($to !== '')   { $where[] = 't.txn_date <= ?';      $args[] = $to; }

    if (splits_ready()) $where[] = 't.split_at IS NULL';   // the parts stand in for it now

    // Items already sitting in a run that has not been finalised are not
    // available to tick again. Asked as a question about the run rather than by
    // listing every claimed id, which would be thousands of them at volume.
    if ($show !== 'matched') {
        $where[] = "(" . matched_where('t') . " OR NOT EXISTS (
                        SELECT 1 FROM rec_match_lines ml
                        JOIN rec_match_groups mg ON mg.id = ml.group_id
                        JOIN rec_runs mr ON mr.id = mg.run_id AND mr.status = 'draft'"
                        . rec_and('mr') . "
                        WHERE ml.txn_id = t.id))";
    }

    return [$table, ' WHERE ' . implode(' AND ', $where), $args];
}

// How many, and what they come to - across everything matching, not just the
// page on screen. The totals are what a reconciliation turns on, so they must
// never describe only part of the list.
function count_items($side, $q, $from, $to, $show = 'open', $sign = 'both')
{
    [$table, $where, $args] = item_filters($side, $q, $from, $to, $show, $sign);
    $st = db()->prepare("SELECT COUNT(*) n,
                                COALESCE(SUM(t.value), 0) total,
                                COALESCE(SUM(CASE WHEN " . open_where('t') . " THEN 1 ELSE 0 END), 0) open_n
                         FROM {$table} t" . $where);
    $st->execute($args);
    return $st->fetch();
}

// $limit of null means every row - which is what the download wants.
function list_items($side, $q, $from, $to, $show = 'open', $sortKey = 'date', $dir = 'asc',
                    $sign = 'both', $limit = null, $offset = 0)
{
    [$table, $where, $args] = item_filters($side, $q, $from, $to, $show, $sign);

    // matched_here, matched_rule and run_ref all describe this reconciliation
    // only, which is why they come from the join rather than from the row
    // With no reconciliation chosen there is nothing to join to, so the columns
    // that describe "matched here" come back empty rather than the query failing.
    $mj = matched_join('t');
    $cols = $mj
        ? "m.matched_at AS matched_here, m.rule_ref AS matched_rule, m.group_id AS group_id, r.run_ref"
        : "NULL AS matched_here, NULL AS matched_rule, NULL AS group_id, NULL AS run_ref";

    $sql = "SELECT t.*, {$cols},
                   (SELECT p.value FROM rec_txns p WHERE p.id = t.parent_id) AS parent_value
            FROM {$table} t"
         . $mj
         . ($mj ? " LEFT JOIN rec_runs r ON r.id = m.run_id" : "")
         . $where
         . " ORDER BY " . order_expression($sortKey, $dir);

    if ($limit !== null) {
        $sql .= " LIMIT " . max(1, (int)$limit) . " OFFSET " . max(0, (int)$offset);
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
