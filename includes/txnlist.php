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
    $out = ['date'        => 'txn_date',
            'description' => 'description',
            'value'       => 'value',
            'abs'         => 'value'];   // by size, ignoring the sign
    foreach (spare_keys() as $k) $out[$k] = $k;
    return $out;
}

// --- sorting by more than one column ------------------------------------------
//
// A sort is a list of columns, each ascending or descending: by period, then
// within that by reference, then by value. It travels as two comma-separated
// settings - "date,description" and "asc,desc" - so a single column still
// reads exactly as it always did.
const SORT_LEVELS = 3;

// [[column, 'asc'|'desc'], ...] from the two settings. Anything not a real
// column is dropped, so nothing from the address bar reaches the query, and a
// column named twice keeps its first place.
function read_sort($keys, $dirs)
{
    $keys = array_map('trim', explode(',', (string)$keys));
    $dirs = array_map('trim', explode(',', (string)$dirs));
    $ok   = sort_columns();
    $out  = [];
    $seen = [];
    foreach ($keys as $i => $k) {
        if ($k === '' || !isset($ok[$k]) || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = [$k, ($dirs[$i] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
        if (count($out) >= SORT_LEVELS) break;
    }
    return $out ?: [['date', 'asc']];
}

// The same list back as the two settings.
function sort_settings(array $levels)
{
    return [implode(',', array_column($levels, 0)), implode(',', array_column($levels, 1))];
}

// The ORDER BY for a sort, one column or several.
//
// 'abs' sorts by size and ignores the sign, so 100.00 and -100.00 sit next to
// each other - which is how you spot a pair that cancels out. Within the same
// size the negative comes first, so a contra reads as a pair rather than
// arriving in whatever order the database felt like.
function order_expression($sortKey, $dir)
{
    $parts = [];
    foreach (read_sort($sortKey, $dir) as [$k, $d]) {
        $d = $d === 'desc' ? 'DESC' : 'ASC';
        if ($k === 'abs') { $parts[] = "ABS(t.value) {$d}"; $parts[] = "t.value ASC"; continue; }
        $parts[] = 't.' . (sort_columns()[$k] ?? 'txn_date') . ' ' . $d;
    }
    $parts[] = 't.id';                         // a steady order for anything left level
    return implode(', ', $parts);
}

// --- months ------------------------------------------------------------------
//
// Any number of months, not necessarily next to each other. They arrive as
// m[] from the month picker's tick boxes, or as one comma-separated "months"
// setting everywhere else (links, hidden fields, redirects) because a flat
// value travels more easily. The old single "month" still works too.
function read_months(array $src)
{
    if (isset($src['m']) && is_array($src['m']))  $raw = $src['m'];
    elseif (trim((string)($src['months'] ?? '')) !== '') $raw = explode(',', (string)$src['months']);
    elseif (trim((string)($src['month'] ?? '')) !== '')  $raw = [(string)$src['month']];
    else return [];
    $out = [];
    foreach ($raw as $ym) {
        $ym = trim((string)$ym);
        if (preg_match('/^\d{4}-\d{2}$/', $ym)) $out[$ym] = true;
    }
    $out = array_keys($out);
    sort($out);
    return $out;
}

// "January 2025, March 2025" - or a count once there are too many to list.
function months_label(array $months, $max = 3)
{
    if (!$months) return 'Any month';
    if (count($months) > $max) return count($months) . ' months';
    return implode(', ', array_map(fn($ym) => date('M Y', strtotime($ym . '-01')), $months));
}

// --- months, or periods, one side at a time ------------------------------------
//
// Each side can be narrowed by the month of its transaction date, or by one of
// its own spare fields - an accounting period, say, because something dated 31
// March can be posted to April. A bank statement has no period, so the ledger
// can go by period while the bank goes by date.
//
// Per side the settings travel as lby / bby (the spare field, or nothing for the
// date) and lm[] / bm[] from the tick boxes, or lmonths / bmonths flat. With no
// side setting of its own, a side by date falls back to the shared "months",
// which is what the pivot and older links send.

// Most values a field can have and still be offered - a period has a few dozen,
// a reference has thousands and is what the column filters are for.
const PERIOD_FIELD_MAX = 400;

// The spare fields one side can be narrowed by: named on its file, and with few
// enough different values to list. [key => ['label' => ..., 'values' => [...]]]
function period_fields($side)
{
    static $cache = [];
    $fid = side_file_id($side);
    if ($fid === null) return [];
    if (isset($cache[$fid])) return $cache[$fid];
    $out = [];
    foreach (file_extra_labels($fid) as $key => $label) {
        if (!preg_match('/^extra\d+$/', $key)) continue;
        $vals = db()->query("SELECT DISTINCT TRIM(t.{$key}) v FROM rec_txns t WHERE " . file_where($side, 't')
                            . " AND TRIM(t.{$key}) <> '' LIMIT " . (PERIOD_FIELD_MAX + 1))
                    ->fetchAll(PDO::FETCH_COLUMN);
        if (!$vals || count($vals) > PERIOD_FIELD_MAX) continue;
        // a comma would split it apart when the choice travels as one setting
        $vals = array_values(array_filter($vals, fn($v) => strpos($v, ',') === false));
        natcasesort($vals);
        $out[$key] = ['label' => $label, 'values' => array_values(array_reverse($vals))];   // newest first, like the months
    }
    return $cache[$fid] = $out;
}

// The months one side's transactions fall in, newest first.
function side_months($side)
{
    if (side_file_id($side) === null) return [];
    $rows = db()->query("SELECT DISTINCT DATE_FORMAT(t.txn_date, '%Y-%m') ym FROM rec_txns t WHERE "
                        . file_where($side, 't') . " ORDER BY ym DESC")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $ym) $out[$ym] = date('F Y', strtotime($ym . '-01'));
    return $out;
}

// What one side is narrowed to: ['by' => '' for the date or a spare field,
// 'vals' => the months (yyyy-mm) or the field's values].
function read_side_period($side, array $src)
{
    $p  = $side === 'ledger' ? 'l' : 'b';
    $by = (string)($src[$p . 'by'] ?? '');
    if ($by !== '' && !array_key_exists($by, file_extra_labels(side_file_id($side)))) $by = '';

    if (isset($src[$p . 'm']) && is_array($src[$p . 'm']))     $raw = $src[$p . 'm'];
    elseif (trim((string)($src[$p . 'months'] ?? '')) !== '')  $raw = explode(',', (string)$src[$p . 'months']);
    elseif ($by === '')                                        return ['by' => '', 'vals' => read_months($src)];
    else                                                       $raw = [];

    $vals = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if ($v === '' || ($by === '' && !preg_match('/^\d{4}-\d{2}$/', $v))) continue;
        $vals[$v] = true;
    }
    $vals = array_keys($vals);
    sort($vals, SORT_NATURAL | SORT_FLAG_CASE);
    return ['by' => $by, 'vals' => $vals];
}

// The same, flat, for carrying through links and hidden fields.
function side_period_params($side, array $sel)
{
    $p = $side === 'ledger' ? 'l' : 'b';
    return array_filter([$p . 'by' => $sel['by'], $p . 'months' => implode(',', $sel['vals'])],
                        fn($v) => $v !== '');
}

// "Mar 2026", "Period 2026/03, 2026/04", "any Period".
function side_period_label($side, array $sel, $max = 3)
{
    if ($sel['by'] === '') return months_label($sel['vals'], $max);
    $name = file_extra_labels(side_file_id($side))[$sel['by']] ?? 'field';
    if (!$sel['vals']) return 'any ' . $name;
    if (count($sel['vals']) > $max) return $name . ': ' . count($sel['vals']) . ' chosen';
    return $name . ' ' . implode(', ', $sel['vals']);
}

// True when both sides go by date and have the same months - then it reads as
// one setting rather than two.
function periods_shared(array $l, array $b)
{
    return $l['by'] === '' && $b['by'] === '' && $l['vals'] === $b['vals'];
}

// --- a filter above every column ----------------------------------------------
//
// Which columns a side can be filtered on: the date, the description, whichever
// spare fields its file has named, and the value.
function column_filter_keys($side)
{
    return array_merge(['date', 'description'],
                       array_keys(file_extra_labels(side_file_id($side))),
                       ['value']);
}

// The column filters for one side, read from the address bar. They travel as
// flat names - lf_description, bf_extra2 - so they pass through links, hidden
// fields and redirects like any other setting.
function read_column_filters($side, $prefix, array $src)
{
    $out = [];
    foreach (column_filter_keys($side) as $k) {
        $v = trim((string)($src[$prefix . $k] ?? ''));
        if ($v !== '') $out[$k] = $v;
    }
    return $out;
}

// One column filter as SQL. What you can type:
//   text        contains it                     (any column)
//   =text       is exactly it
//   !text       does not contain it
//   (blank)     has nothing in it
//   !(blank)    has something in it
//   100         the amount, either sign         (value column)
//   =-100       exactly that, sign and all
//   >100  <100  >=100  <=100
function column_filter_sql($col, $v)
{
    $c = 't.' . $col;

    if ($col === 'value') {
        $n = str_replace(',', '', $v);
        if (preg_match('/^(>=|<=|>|<|=)?\s*(-?\d*\.?\d+)$/', $n, $m)) {
            $num = (float)$m[2];
            switch ($m[1]) {
                case '':   return ['ABS(ABS(t.value) - ?) < 0.005', [abs($num)]];
                case '=':  return ['ABS(t.value - ?) < 0.005', [$num]];
                default:   return ["t.value {$m[1]} ?", [$num]];
            }
        }
        return ['CAST(t.value AS CHAR) LIKE ?', ['%' . $v . '%']];
    }

    // dates are held as yyyy-mm-dd; let 31/01/2025 find them too
    if ($col === 'date') {
        $c = 'CAST(t.txn_date AS CHAR)';
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
    }

    if (strcasecmp($v, '(blank)') === 0)  return ["({$c} IS NULL OR {$c} = '')", []];
    if (strcasecmp($v, '!(blank)') === 0) return ["({$c} IS NOT NULL AND {$c} <> '')", []];
    if ($v[0] === '=' && strlen($v) > 1)  return ["{$c} = ?", [substr($v, 1)]];
    if ($v[0] === '!' && strlen($v) > 1)  return ["({$c} IS NULL OR {$c} NOT LIKE ?)", ['%' . substr($v, 1) . '%']];
    return ["{$c} LIKE ?", ['%' . $v . '%']];
}

// The filters on one side, in words - "Journal type is GJ, Period contains P02".
// $p is the screen's settings as they travel in the address bar (or the
// "back" fields). Returns [] when nothing narrows that side.
function describe_side_filters($side, array $p)
{
    $pfx    = $side === 'ledger' ? 'l' : 'b';
    $labels = ['date' => 'Date', 'description' => 'Description', 'value' => 'Value']
            + file_extra_labels(side_file_id($side));
    $out = [];
    $q = trim((string)($p[$pfx . 'q'] ?? ''));
    if ($q !== '') $out[] = 'search "' . $q . '"';
    foreach (read_column_filters($side, $pfx . 'f_', $p) as $col => $v) {
        $name = $labels[$col] ?? $col;
        if (strcasecmp($v, '(blank)') === 0)           $out[] = "{$name} is blank";
        elseif (strcasecmp($v, '!(blank)') === 0)      $out[] = "{$name} is not blank";
        elseif ($v[0] === '=' && strlen($v) > 1)       $out[] = "{$name} is " . substr($v, 1);
        elseif ($v[0] === '!' && strlen($v) > 1)       $out[] = "{$name} does not contain " . substr($v, 1);
        elseif ($v[0] === '>' || $v[0] === '<')         $out[] = "{$name} {$v}";
        elseif ($col === 'value' && is_numeric(str_replace(',', '', $v))) $out[] = "{$name} is +/- {$v}";
        else                                            $out[] = "{$name} contains {$v}";
    }
    // months or periods, when this side's differ from the other's
    $mine  = read_side_period($side, $p);
    $other = read_side_period($side === 'ledger' ? 'bank' : 'ledger', $p);
    if ($mine['vals'] && !periods_shared($mine, $other)) {
        $out[] = ($mine['by'] === '' ? 'months ' : '') . side_period_label($side, $mine, 12);
    }
    return $out;
}

// The settings that apply to both sides at once - dates, money in or out.
function describe_shared_filters(array $p)
{
    $out  = [];
    $l = read_side_period('ledger', $p);
    if ($l['vals'] && periods_shared($l, read_side_period('bank', $p))) $out[] = 'months ' . months_label($l['vals'], 12);
    $from = trim((string)($p['from'] ?? ''));
    $to   = trim((string)($p['to'] ?? ''));
    if ($from !== '' && $to !== '') $out[] = "dated {$from} to {$to}";
    elseif ($from !== '')           $out[] = "dated from {$from}";
    elseif ($to !== '')             $out[] = "dated up to {$to}";
    $in  = !empty($p['in']);
    $outF = !empty($p['out']);
    if ($in && !$outF) $out[] = 'money in only';
    if ($outF && !$in) $out[] = 'money out only';
    return $out;
}

// Everything the screen filters on, built once and used by both the count and
// the listing, so the figures at the top of a panel always describe the list
// underneath it.
function item_filters($side, $q, $from, $to, $show, $sign, array $colf = [], array $months = [])
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

    // the side's search box looks in the description and every named spare field
    if ($q !== '') {
        $cols = array_merge(['description'], array_keys(file_extra_labels(side_file_id($side))));
        $where[] = '(' . implode(' OR ', array_map(fn($c) => "t.{$c} LIKE ?", $cols)) . ')';
        foreach ($cols as $c) $args[] = '%' . $q . '%';
    }
    $allowed = array_flip(column_filter_keys($side));
    foreach ($colf as $col => $v) {
        if (!isset($allowed[$col]) || $v === '') continue;   // nothing from the address bar reaches SQL unchecked
        [$sql, $a] = column_filter_sql($col, $v);
        $where[] = $sql;
        foreach ($a as $x) $args[] = $x;
    }
    // A side narrowed by a spare field - a period, say - rather than the date.
    // $months is then what read_side_period() gives; a plain list of months is
    // the date, as before.
    if (isset($months['by'])) {
        $by   = $months['by'];
        $pick = $months['vals'];
        $months = [];
        if ($by === '') {
            $months = $pick;
        } elseif ($pick && isset($allowed[$by]) && preg_match('/^extra\d+$/', $by)) {
            $where[] = "TRIM(t.{$by}) IN (" . implode(',', array_fill(0, count($pick), '?')) . ')';
            foreach ($pick as $v) $args[] = $v;
        }
    }
    // each month as a date range, so the date index still does the work
    if ($months) {
        $ranges = [];
        foreach ($months as $ym) {
            $first = $ym . '-01';
            $ranges[] = '(t.txn_date BETWEEN ? AND ?)';
            $args[] = $first;
            $args[] = date('Y-m-t', strtotime($first));
        }
        $where[] = '(' . implode(' OR ', $ranges) . ')';
    }
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
function count_items($side, $q, $from, $to, $show = 'open', $sign = 'both', array $colf = [],
                     array $months = [])
{
    [$table, $where, $args] = item_filters($side, $q, $from, $to, $show, $sign, $colf, $months);
    $st = db()->prepare("SELECT COUNT(*) n,
                                COALESCE(SUM(t.value), 0) total,
                                COALESCE(SUM(CASE WHEN " . open_where('t') . " THEN 1 ELSE 0 END), 0) open_n
                         FROM {$table} t" . $where);
    $st->execute($args);
    return $st->fetch();
}

// $limit of null means every row - which is what the download wants.
function list_items($side, $q, $from, $to, $show = 'open', $sortKey = 'date', $dir = 'asc',
                    $sign = 'both', $limit = null, $offset = 0, array $colf = [], array $months = [])
{
    [$table, $where, $args] = item_filters($side, $q, $from, $to, $show, $sign, $colf, $months);

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
