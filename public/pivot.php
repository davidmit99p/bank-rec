<?php
// A small pivot of differences, to find WHICH slice a gap is in before looking
// for it line by line.
//
// Pick what goes down the side, what goes across the top and, optionally, one
// field to narrow everything by. Each cell is one side's total less the other's
// for that slice. Click a cell and the transactions screen opens filtered to
// exactly that slice on both sides, ready to look at or match.
//
// THREE VIEWS. The difference is the one that finds a gap, but once you know
// where it is you want to read a side on its own - what this account actually
// holds, month by month - so each side gets a pivot of its own. A one-side
// pivot can also go by any field of THAT file, not only the fields both files
// happen to share, because nothing has to line up for it to mean something.
require_once __DIR__ . '/../includes/layout.php';

$pdo = db();

// What a browser can usefully draw. Rows are cheap and a long list of accounts
// that disagree is exactly what you came for, so the row limit is generous;
// what actually hurts is the number of CELLS, which is what the third one
// guards. 434 accounts by 12 months is fine. 1,000 by 40 is not.
const PIVOT_MAX_ROWS  = 1000;
const PIVOT_MAX_COLS  = 40;
const PIVOT_MAX_CELLS = 15000;

// --- what can be pivoted on --------------------------------------------------
//
// Always the date in a few shapes, and money in/out. Spare fields only when BOTH
// sides' files have one of that name - a slice has to mean the same thing on
// each side. Matched by name, not number, so "Account" can be spare field 2 on
// one file and 5 on the other.
// $view is 'diff' (both sides, so only fields they share), or 'left'/'right'
// for one side, where any field of that file will do.
function pivot_dims($view = 'diff')
{
    $dims = [
        'month'     => ['label' => 'Month',
                        'l' => "DATE_FORMAT(t.txn_date, '%Y-%m')", 'b' => "DATE_FORMAT(t.txn_date, '%Y-%m')"],
        'year'      => ['label' => 'Year',
                        'l' => "DATE_FORMAT(t.txn_date, '%Y')", 'b' => "DATE_FORMAT(t.txn_date, '%Y')"],
        'date'      => ['label' => 'Date',
                        'l' => "DATE_FORMAT(t.txn_date, '%Y-%m-%d')", 'b' => "DATE_FORMAT(t.txn_date, '%Y-%m-%d')"],
        'direction' => ['label' => 'Money in / out',
                        'l' => "CASE WHEN t.value < 0 THEN 'Money out' ELSE 'Money in' END",
                        'b' => "CASE WHEN t.value < 0 THEN 'Money out' ELSE 'Money in' END"],
    ];
    if ($view === 'left' || $view === 'right') {
        $side = $view === 'left' ? 'ledger' : 'bank';
        $p    = $view === 'left' ? 'lcol' : 'rcol';
        $expr = fn($c) => "COALESCE(NULLIF(UPPER(TRIM(t.{$c})), ''), '(blank)')";
        foreach (file_extra_labels(side_file_id($side)) as $col => $label) {
            $dims['f:' . $col . ':' . $col] = ['label' => $label, 'l' => $expr($col), 'b' => $expr($col),
                                               $p => $col];
        }
        return $dims;
    }

    $left  = file_extra_labels(side_file_id('ledger'));
    $right = file_extra_labels(side_file_id('bank'));
    $byName = [];
    foreach ($right as $col => $label) $byName[mb_strtolower(trim($label))] = $col;
    foreach ($left as $lcol => $label) {
        $rcol = $byName[mb_strtolower(trim($label))] ?? null;
        if ($rcol === null) continue;
        // trimmed and upper-cased, as the rules compare them; empty is its own slice
        $expr = fn($c) => "COALESCE(NULLIF(UPPER(TRIM(t.{$c})), ''), '(blank)')";
        $dims['f:' . $lcol . ':' . $rcol] = ['label' => $label, 'l' => $expr($lcol), 'b' => $expr($rcol),
                                             'lcol' => $lcol, 'rcol' => $rcol];
    }
    return $dims;
}

// --- narrowing the rows -------------------------------------------------------
//
// The "Filter on" box deliberately leaves out whatever is down the side, because
// filtering Account to one account when Account IS the side leaves a pivot of
// one row. What you want there is fewer accounts, not one - so this is a
// separate box that narrows the row values themselves.
//
// It reads the way the rule conditions do:
//   5035            contains 5035
//   5035,5040,5100  any of those
//   5000..5999      anything from 5000 to 5999, as text
//   !CASH           anything that does not contain CASH
//
// The row expression is already UPPER(TRIM(...)), so this matches whatever the
// case of what was typed.
function pivot_row_where($expr, $raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;

    $not = false;
    if ($raw[0] === '!') { $not = true; $raw = trim(substr($raw, 1)); }
    if ($raw === '') return null;

    if (strpos($raw, ',') !== false) {
        $vals = array_values(array_filter(array_map(
            fn($v) => strtoupper(trim($v)), explode(',', $raw)), fn($v) => $v !== ''));
        if (!$vals) return null;
        $in = implode(',', array_fill(0, count($vals), '?'));
        return [($not ? "{$expr} NOT IN ({$in})" : "{$expr} IN ({$in})"), $vals];
    }
    if (strpos($raw, '..') !== false) {
        [$a, $b] = array_map('trim', explode('..', $raw, 2));
        if ($a === '' || $b === '') return null;
        if (strnatcasecmp($a, $b) > 0) [$a, $b] = [$b, $a];
        return [($not ? "{$expr} NOT BETWEEN ? AND ?" : "{$expr} BETWEEN ? AND ?"),
                [strtoupper($a), strtoupper($b)]];
    }
    return [($not ? "{$expr} NOT LIKE ?" : "{$expr} LIKE ?"), ['%' . strtoupper($raw) . '%']];
}

// How a slice's key reads on screen.
function pivot_label($dim, $key)
{
    if ($dim === 'month' && preg_match('/^\d{4}-\d{2}$/', $key)) return date('M Y', strtotime($key . '-01'));
    if ($dim === 'date'  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) return date('j M Y', strtotime($key));
    return $key;
}

// Transactions-screen settings that pick out one slice of one dimension.
//
// $only is 'l' or 'b' when just one side is being looked at: filtering the other
// side by a field it may not even have would hide everything there.
function pivot_link_parts($dim, $key, array $dims, $only = null)
{
    if ($dim === '' || $key === null) return [];
    if ($dim === 'month') return ['months' => $key];
    if ($dim === 'year')  return ['from' => $key . '-01-01', 'to' => $key . '-12-31'];
    if ($dim === 'date')  return ['from' => $key, 'to' => $key];
    if ($dim === 'direction') return $key === 'Money out' ? ['out' => 1] : ['in' => 1];
    $d = $dims[$dim] ?? null;
    if (!$d) return [];
    $v = $key === '(blank)' ? '(blank)' : '=' . $key;
    $out = [];
    if ($only !== 'b' && !empty($d['lcol'])) $out['lf_' . $d['lcol']] = $v;
    if ($only !== 'l' && !empty($d['rcol'])) $out['bf_' . $d['rcol']] = $v;
    return $out;
}

// Put two sets of transactions-screen settings together. Two date ranges
// narrow to where they overlap; two directions that disagree leave nothing,
// which is honest - there is nothing in that cell.
function pivot_merge(array $a, array $b)
{
    foreach ($b as $k => $v) {
        if ($k === 'from' && isset($a['from'])) $v = max($a['from'], $v);
        if ($k === 'to'   && isset($a['to']))   $v = min($a['to'], $v);
        $a[$k] = $v;
    }
    return $a;
}

// --- settings -----------------------------------------------------------------
$view = in_array($_GET['v'] ?? '', ['diff', 'left', 'right'], true) ? $_GET['v'] : 'diff';
$oneSide = $view === 'left' ? 'ledger' : ($view === 'right' ? 'bank' : null);
$only    = $view === 'left' ? 'l' : ($view === 'right' ? 'b' : null);
$dims = pivot_dims($view);
$r    = isset($dims[$_GET['r'] ?? '']) ? $_GET['r'] : null;
$c    = isset($dims[$_GET['c'] ?? '']) ? $_GET['c'] : '';
$f    = isset($dims[$_GET['f'] ?? '']) ? $_GET['f'] : '';
$fv   = trim((string)($_GET['fv'] ?? ''));
$rf   = trim((string)($_GET['rf'] ?? ''));
$show = in_array($_GET['show'] ?? '', ['open', 'matched', 'both', 'aside'], true) ? $_GET['show'] : 'open';
$opp  = !empty($_GET['opp']);
// hiding what agrees is the useful default; "all" asks to see everything.
// Reading one side there is nothing to agree with, so everything is shown.
$hide = $oneSide ? false : empty($_GET['all']);

// a first visit: something sensible down the side and across the top
if ($r === null) {
    $firstSpare = null;
    foreach ($dims as $k => $d) if (str_starts_with($k, 'f:')) { $firstSpare = $k; break; }
    $r = $firstSpare ?? 'month';
    if ($c === '' && !isset($_GET['c'])) $c = $firstSpare ? 'month' : '';
}
if ($c === $r) $c = '';
if ($f === $r || $f === $c) { $f = ''; }
if ($f === '') $fv = '';

// --- totals, one side at a time ----------------------------------------------
function pivot_side($side, array $dims, $r, $c, $f, $fv, $show, $rf = '')
{
    $p = $side === 'ledger' ? 'l' : 'b';
    $rx = $dims[$r][$p];
    $cx = $c !== '' ? $dims[$c][$p] : "'all'";
    $where = [file_where($side, 't')];
    if ($show === 'open')    $where[] = open_where('t') . ' AND ' . not_aside_where('t');
    if ($show === 'matched') $where[] = matched_where('t');
    if ($show === 'aside')   $where[] = aside_where('t');
    $args = [];
    if ($f !== '' && $fv !== '') { $where[] = $dims[$f][$p] . ' = ?'; $args[] = $fv; }
    if ($rowCond = pivot_row_where($rx, $rf)) {
        $where[] = $rowCond[0];
        foreach ($rowCond[1] as $a) $args[] = $a;
    }
    $st = db()->prepare("SELECT {$rx} rk, {$cx} ck, COALESCE(SUM(t.value), 0) total, COUNT(*) n
                         FROM rec_txns t
                         WHERE " . implode(' AND ', $where) . not_split('t') . "
                         GROUP BY rk, ck");
    $st->execute($args);
    return $st->fetchAll();
}

// the values the filter field takes, for its drop-down
$filterValues = [];
if ($f !== '') {
    $vals = [];
    foreach (['ledger' => 'l', 'bank' => 'b'] as $side => $p) {
        $sql = "SELECT DISTINCT {$dims[$f][$p]} v FROM rec_txns t WHERE " . file_where($side, 't')
             . not_split('t') . " LIMIT 1000";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $v) $vals[(string)$v] = true;
    }
    $filterValues = array_keys($vals);
    natcasesort($filterValues);
}

$grid = [];          // [row][col] => ['l' => total, 'b' => total, 'ln' => n, 'bn' => n]
$noFiles = $oneSide
    ? side_file_id($oneSide) === null
    : (side_file_id('ledger') === null || side_file_id('bank') === null);
if (!$noFiles) {
    $gather = $oneSide ? [$oneSide => $only] : ['ledger' => 'l', 'bank' => 'b'];
    foreach ($gather as $side => $p) {
        foreach (pivot_side($side, $dims, $r, $c, $f, $fv, $show, $rf) as $row) {
            $cell = &$grid[(string)$row['rk']][(string)$row['ck']];
            $cell[$p] = ($cell[$p] ?? 0) + (float)$row['total'];
            $cell[$p . 'n'] = ($cell[$p . 'n'] ?? 0) + (int)$row['n'];
            unset($cell);
        }
    }
}

// What a cell shows: the difference between the sides, or - reading one side -
// simply what that side holds.
$diffOf = $oneSide
    ? fn($cell) => (float)($cell[$only] ?? 0)
    : fn($cell) => (float)($cell['l'] ?? 0) + ($opp ? 1 : -1) * (float)($cell['b'] ?? 0);

$rowKeys = array_map('strval', array_keys($grid));
$colKeys = [];
foreach ($grid as $cols) foreach ($cols as $ck => $_) $colKeys[(string)$ck] = true;
$colKeys = array_map('strval', array_keys($colKeys));
$order = function (array $keys) {
    usort($keys, function ($a, $b) {
        if ($a === '(blank)') return 1;
        if ($b === '(blank)') return -1;
        return strnatcasecmp($a, $b);
    });
    return $keys;
};
$rowKeys = $order($rowKeys);
$colKeys = $order($colKeys);

// totals for every row and column, and the corner
$rowTot = $colTot = [];
$grand  = ['l' => 0, 'b' => 0, 'ln' => 0, 'bn' => 0];
foreach ($grid as $rk => $cols) {
    foreach ($cols as $ck => $cell) {
        foreach (['l', 'b', 'ln', 'bn'] as $k) {
            $rowTot[$rk][$k] = ($rowTot[$rk][$k] ?? 0) + ($cell[$k] ?? 0);
            $colTot[$ck][$k] = ($colTot[$ck][$k] ?? 0) + ($cell[$k] ?? 0);
            $grand[$k] += $cell[$k] ?? 0;
        }
    }
}

// Hide what agrees. A row goes when every cell in it agrees; so does a column.
// Judged cell by cell, not on the total, because +50 and -50 in two months are
// two problems, not none.
$flat = fn($cell) => abs($diffOf($cell)) < 0.005;
$allRows = count($rowKeys);
$allCols = count($colKeys);
if ($hide) {
    $rowKeys = array_values(array_filter($rowKeys, function ($rk) use ($grid, $colKeys, $flat) {
        foreach ($colKeys as $ck) if (isset($grid[$rk][$ck]) && !$flat($grid[$rk][$ck])) return true;
        return false;
    }));
    $colKeys = array_values(array_filter($colKeys, function ($ck) use ($grid, $rowKeys, $flat) {
        foreach ($rowKeys as $rk) if (isset($grid[$rk][$ck]) && !$flat($grid[$rk][$ck])) return true;
        return false;
    }));
}
$tooBig = count($rowKeys) > PIVOT_MAX_ROWS || count($colKeys) > PIVOT_MAX_COLS
       || count($rowKeys) * max(1, count($colKeys)) > PIVOT_MAX_CELLS;

// every drill-down starts from these, plus the filter if there is one
$base = ['show' => $show];
if ($f !== '' && $fv !== '') $base = pivot_merge($base, pivot_link_parts($f, $fv, $dims, $only));
$cellLink = function ($rk, $ck) use ($base, $r, $c, $dims, $only) {
    $q = pivot_merge($base, pivot_link_parts($r, $rk, $dims, $only));
    if ($ck !== null && $c !== '') $q = pivot_merge($q, pivot_link_parts($c, $ck, $dims, $only));
    return 'transactions.php?' . http_build_query($q);
};

$settings = array_filter(['v' => $view === 'diff' ? '' : $view,
                          'r' => $r, 'c' => $c, 'f' => $f, 'fv' => $fv, 'rf' => $rf, 'show' => $show,
                          'opp' => $opp ? 1 : '', 'all' => $hide ? '' : 1],
                         fn($v) => $v !== '' && $v !== null);

// WHY IS IT EMPTY? "No data" is not an answer. So when nothing comes back, the
// same question is asked again with the filters peeled off one at a time, and
// whichever one is responsible is named.
//
// The telling case is a shared file: items matched in ANOTHER reconciliation are
// not matched in this one, so asking for matched here is right to show nothing -
// but only if it says so.
function pivot_why_empty($side, array $dims, $r, $c, $f, $fv, $show, $rf)
{
    $count = function ($extra = []) use ($side) {
        $where = [file_where($side, 't')];
        foreach ($extra as $w) $where[] = $w;
        $sql = "SELECT COUNT(*) n, COALESCE(SUM(t.value), 0) total FROM rec_txns t
                WHERE " . implode(' AND ', $where) . not_split('t');
        return db()->query($sql)->fetch();
    };

    $all = $count();
    if (!(int)$all['n']) {
        return 'There is nothing loaded on that side at all.';
    }

    $state = ['open' => open_where('t') . ' AND ' . not_aside_where('t'),
              'matched' => matched_where('t'),
              'aside' => aside_where('t'),
              'both' => '1 = 1'][$show] ?? '1 = 1';
    $ofState = $count([$state]);
    $words = ['open' => 'unmatched', 'matched' => 'matched', 'aside' => 'set aside',
              'both' => 'loaded'][$show] ?? 'loaded';

    if (!(int)$ofState['n']) {
        $out = 'Of the ' . number_format((int)$all['n']) . ' items on that side, none are '
             . $words . ' in this reconciliation.';
        // the shared-file trap, worth naming because it looks like a fault
        if ($show === 'matched') {
            $elsewhere = $count([matched_anywhere('t')]);
            if ((int)$elsewhere['n']) {
                $out .= ' ' . number_format((int)$elsewhere['n']) . ' of them are matched in a '
                      . 'different reconciliation - this file is used by more than one, and matching '
                      . 'belongs to the reconciliation it was done in.';
            }
        }
        return $out;
    }

    // something is in that state, so a filter is hiding it
    $bits = [];
    if ($f !== '' && $fv !== '') $bits[] = 'the "' . $dims[$f]['label'] . ' equal to ' . $fv . '" filter';
    if (trim((string)$rf) !== '') $bits[] = 'the "only ' . mb_strtolower($dims[$r]['label'])
                                          . ' like ' . trim((string)$rf) . '" box';
    $have = number_format((int)$ofState['n']) . ' ' . $words . ' items worth '
          . money((float)$ofState['total']) . ' on that side';
    return $bits
        ? 'There are ' . $have . ', but ' . implode(' and ', $bits) . ' leaves none of them.'
        : 'There are ' . $have . ', which should be showing - so this is a fault, not a filter. '
          . 'Worth telling whoever looks after the tool.';
}

// --- the grid as a file ----------------------------------------------------------
if (isset($_GET['csv']) && !$noFiles) {
    $rec  = current_rec();
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', ($rec['name'] ?? 'reconciliation') . ' pivot'
            . ($oneSide ? ' ' . side_label($oneSide) : ' differences')), '-');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    $cols = $c !== '' ? $colKeys : ['all'];
    fputcsv($out, array_merge([$dims[$r]['label']],
        array_map(fn($ck) => $c !== '' ? pivot_label($c, $ck)
            : ($oneSide ? side_label($oneSide) : 'Difference'), $cols),
        $c !== '' ? ['Total'] : []));
    foreach ($rowKeys as $rk) {
        $line = [pivot_label($r, $rk)];
        foreach ($cols as $ck) $line[] = number_format($diffOf($grid[$rk][$ck] ?? []), 2, '.', '');
        if ($c !== '') $line[] = number_format($diffOf($rowTot[$rk] ?? []), 2, '.', '');
        fputcsv($out, $line);
    }
    $line = ['Total'];
    foreach ($cols as $ck) $line[] = number_format($diffOf($colTot[$ck] ?? []), 2, '.', '');
    if ($c !== '') $line[] = number_format($diffOf($grand), 2, '.', '');
    fputcsv($out, $line);
    fclose($out);
    exit;
}

render_header('Pivot');

// one cell of the grid
$cellHtml = function ($cell, $href, $strong = false) use ($diffOf, $oneSide, $only) {
    if (!$cell || (empty($cell['ln']) && empty($cell['bn']))) return '<td class="num pv-empty"></td>';
    $d = $diffOf($cell);
    $ok = abs($d) < 0.005;

    // Reading one side, a figure is just a figure: no colour, because there is
    // nothing for it to disagree with. The count still comes on hover, and the
    // cell still opens that slice on that side.
    if ($oneSide) {
        $n = (int)($cell[$only . 'n'] ?? 0);
        if (!$n) return '<td class="num pv-empty"></td>';
        $tip = $n . ' item' . ($n === 1 ? '' : 's') . ' in ' . side_label($oneSide);
        return '<td class="num' . ($strong ? ' pv-tot' : '') . ($d < 0 ? ' neg' : '')
             . '" title="' . h($tip) . '"><a href="' . h($href) . '">' . money($d) . '</a></td>';
    }

    $tip = side_label('ledger') . ' ' . money($cell['l'] ?? 0) . ' (' . (int)($cell['ln'] ?? 0) . ')  |  '
         . side_label('bank') . ' ' . money($cell['b'] ?? 0) . ' (' . (int)($cell['bn'] ?? 0) . ')';
    // Port and starboard: red when only the left file has anything in this
    // slice, green when only the right does, yellow when both do but disagree.
    // Only cells with a difference are coloured, so the problems stand out.
    $hasL = !empty($cell['ln']);
    $hasB = !empty($cell['bn']);
    $kind = $ok ? 'pv-ok' : ($hasL && $hasB ? 'pv-both' : ($hasL ? 'pv-left' : 'pv-right'));
    return '<td class="num ' . $kind . ($strong ? ' pv-tot' : '') . '" title="' . h($tip) . '">'
         . '<a href="' . h($href) . '">' . ($ok ? '&ndash;' : money($d)) . '</a></td>';
};
?>
<h1><?= $oneSide ? h(side_label($oneSide)) . ' pivot' : 'Pivot of differences' ?></h1>

<?php
  // the three views, as tabs: the difference, then each side on its own
  $tabs = ['diff'  => 'Differences',
           'left'  => side_label('ledger'),
           'right' => side_label('bank')];
  // the dimensions differ per view, so a setting that does not exist there is
  // dropped rather than carried over as something meaningless
  $carry = array_filter(['show' => $show, 'opp' => $opp ? 1 : '']);
?>
<div class="panel" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="btn <?= $k === $view ? '' : 'ghost' ?>"
       href="?<?= h(http_build_query($carry + ($k === 'diff' ? [] : ['v' => $k]))) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
  <span class="muted small"><?= $oneSide
    ? 'One side on its own, by any field of that file'
    : 'Each side against the other, by the fields they share' ?></span>
</div>

<?php if ($oneSide): ?>
  <p class="muted">What <?= h(side_label($oneSide)) ?> holds, sliced however you like &mdash; by any
    field of that file, not only the ones both files share. Each cell is that side's total for the
    slice. <b>Click any cell</b> to open the transactions screen filtered to it on that side.
    The box on the right narrows what goes down the side: <code>5035</code> for anything containing it,
    <code>5035,5040</code> for either, <code>5000..5999</code> for a range, <code>!CASH</code> for
    everything except.</p>
<?php else: ?>
  <p class="muted">Find which slice a difference sits in. Choose what goes down the side and across the
    top; each cell is <?= h(side_label('ledger')) ?> less <?= h(side_label('bank')) ?> for that slice.
    <b>Click any cell</b> to open the transactions screen filtered to exactly that slice on both sides.
    The box on the right narrows what goes down the side: <code>5035</code> for anything containing it,
    <code>5035,5040</code> for either, <code>5000..5999</code> for a range, <code>!CASH</code> for
    everything except.</p>
<?php endif; ?>

<form method="get" class="panel" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
  <?php if ($view !== 'diff'): ?><input type="hidden" name="v" value="<?= h($view) ?>"><?php endif; ?>
  <div><label>Down the side</label>
    <select name="r" onchange="this.form.submit()">
      <?php foreach ($dims as $k => $d): ?>
        <option value="<?= h($k) ?>"<?= $k === $r ? ' selected' : '' ?>><?= h($d['label']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label>Across the top</label>
    <select name="c" onchange="this.form.submit()">
      <option value="">Nothing &mdash; one column</option>
      <?php foreach ($dims as $k => $d): if ($k === $r) continue; ?>
        <option value="<?= h($k) ?>"<?= $k === $c ? ' selected' : '' ?>><?= h($d['label']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label>Filter on</label>
    <select name="f" onchange="this.form.fv.value='';this.form.submit()">
      <option value="">No filter</option>
      <?php foreach ($dims as $k => $d): if ($k === $r || $k === $c) continue; ?>
        <option value="<?= h($k) ?>"<?= $k === $f ? ' selected' : '' ?>><?= h($d['label']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label>Equal to</label>
    <select name="fv" onchange="this.form.submit()" <?= $f === '' ? 'disabled' : '' ?>>
      <option value="">Anything</option>
      <?php foreach ($filterValues as $v): ?>
        <option value="<?= h($v) ?>"<?= $v === $fv ? ' selected' : '' ?>><?= h(pivot_label($f, $v)) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label>Only <?= h(mb_strtolower($dims[$r]['label'])) ?> like</label>
    <input type="text" name="rf" value="<?= h($rf) ?>" style="width:11rem"
           placeholder="all of them"
           title="5035 &middot; 5035,5040 &middot; 5000..5999 &middot; !CASH"></div>
  <div><label>Show</label>
    <select name="show" onchange="this.form.submit()">
      <option value="open"<?= $show === 'open' ? ' selected' : '' ?>>Unmatched</option>
      <option value="matched"<?= $show === 'matched' ? ' selected' : '' ?>>Matched</option>
      <option value="both"<?= $show === 'both' ? ' selected' : '' ?>>Everything</option>
      <?php if (function_exists('aside_ready') && aside_ready()): ?>
        <option value="aside"<?= $show === 'aside' ? ' selected' : '' ?>>Set aside</option>
      <?php endif; ?>
    </select></div>
  <?php if (!$oneSide): ?>
    <div style="display:flex;flex-direction:column;gap:.2rem;padding-bottom:.2rem">
      <label style="margin:0;color:var(--ink);white-space:nowrap"><input type="checkbox" name="all" value="1"
        style="width:auto" <?= $hide ? '' : 'checked' ?> onchange="this.form.submit()"> Show rows and columns that agree</label>
      <label style="margin:0;color:var(--ink);white-space:nowrap"><input type="checkbox" name="opp" value="1"
        style="width:auto" <?= $opp ? 'checked' : '' ?> onchange="this.form.submit()">
        <?= h(side_label('bank')) ?> is the opposite sign</label>
    </div>
  <?php endif; ?>
  <a class="btn ghost" href="?<?= h(http_build_query($view === 'diff' ? [] : ['v' => $view])) ?>">Reset</a>
  <?php if (!$noFiles && $rowKeys && !$tooBig): ?>
    <a class="btn ghost" style="margin-left:auto" href="?<?= h(http_build_query($settings + ['csv' => 1])) ?>">Download</a>
  <?php endif; ?>
</form>

<?php
$spareCount = count(array_filter(array_keys($dims), fn($k) => str_starts_with($k, 'f:')));
if (!$noFiles && !$spareCount): ?>
  <p class="small muted">Only dates and money in/out are offered. <?= $oneSide
    ? 'Name this file\'s spare fields on the <a href="files.php">Files</a> page to slice by them.'
    : 'A spare field can be used once both files have one with the same name &mdash; name them on the '
      . '<a href="files.php">Files</a> page.' ?></p>
<?php endif; ?>

<?php if ($noFiles): ?>
  <div class="panel"><p class="muted"><?= $oneSide
    ? 'This reconciliation has no file on that side yet.'
    : 'This reconciliation needs a file on each side first.' ?></p></div>
<?php elseif ($tooBig): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0">That makes <?= number_format(count($rowKeys)) ?> rows by <?= number_format(count($colKeys)) ?>
      columns &mdash; too many to draw. Narrow the rows with the
      &ldquo;Only <?= h(mb_strtolower($dims[$r]['label'])) ?> like&rdquo; box, add a filter, or choose
      something with fewer values across the top (month rather than date, say).
      The limits are <?= number_format(PIVOT_MAX_ROWS) ?> rows, <?= PIVOT_MAX_COLS ?> columns and
      <?= number_format(PIVOT_MAX_CELLS) ?> cells between them.</p>
  </div>
<?php elseif (!$rowKeys): ?>
  <div class="panel">
    <?php if ($allRows && $hide): ?>
      <p class="muted" style="margin:0">&#10003; Everything agrees in every slice.
        Tick &ldquo;Show rows and columns that agree&rdquo; to see them.</p>
    <?php else: ?>
      <p style="margin:0 0 .3rem"><b>Nothing to show for these settings.</b></p>
      <p class="muted" style="margin:0"><?= h(pivot_why_empty($oneSide ?? 'ledger', $dims, $r, $c, $f, $fv, $show, $rf)) ?></p>
      <?php if (!$oneSide): ?>
        <p class="muted small" style="margin:.3rem 0 0">That is the <?= h(side_label('ledger')) ?> side;
          the <?= h(side_label('bank')) ?> side is asked the same way.</p>
      <?php endif; ?>
      <?php if (($f !== '' && $fv !== '') || trim((string)$rf) !== ''): ?>
        <p style="margin:.5rem 0 0"><a class="btn ghost"
          href="?<?= h(http_build_query(array_filter(['v' => $view === 'diff' ? '' : $view,
                'r' => $r, 'c' => $c, 'show' => $show]))) ?>">Clear the filters and look again</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="panel">
    <p class="small muted" style="margin-top:0">
      <?= number_format(count($rowKeys)) ?> of <?= number_format($allRows) ?> <?= h(mb_strtolower($dims[$r]['label'])) ?> values
      <?php if ($c !== ''): ?>&middot; <?= number_format(count($colKeys)) ?> of <?= number_format($allCols) ?>
        <?= h(mb_strtolower($dims[$c]['label'])) ?> values<?php endif; ?>
      <?= $hide ? '&middot; the ones that fully agree are hidden' : '' ?>.
      Hover a cell for each side's total and count.</p>
    <?php
    // the legend follows the Show setting, so "items" means what is on show
    $items = ['open' => 'items still to match', 'matched' => 'matched items', 'both' => 'items',
              'aside' => 'items set aside'][$show] ?? 'items';
    ?>
    <?php if ($oneSide): ?>
      <p class="small muted" style="margin-top:-.2rem">Each figure is what <?= h(side_label($oneSide)) ?>
        holds in that slice, negative in red. A blank cell has no <?= $items ?> in it. Hover for the
        number of items.</p>
    <?php else: ?>
    <p class="small pv-legend pv-list">
      <span><span class="pv-key pv-left"></span><b>Only in <?= h(side_label('ledger')) ?></b>:
        nothing on the other side in this slice</span>
      <span><span class="pv-key pv-right"></span><b>Only in <?= h(side_label('bank')) ?></b>:
        nothing on the other side in this slice</span>
      <span><span class="pv-key pv-both"></span><b>In both, but they differ</b></span>
      <span><span class="pv-key pv-dash"></span><b>Nets to zero</b>: <?= $items ?> here,
        but they cancel out, so nothing is missing</span>
      <span><span class="pv-key"></span><b>Blank</b>: no <?= $items ?> in this slice</span></p>
    <?php endif; ?>
    <div class="scroll" style="max-height:70vh">
      <table class="pivot">
        <thead><tr>
          <th><?= h($dims[$r]['label']) ?><?= $c !== '' ? ' \\ ' . h($dims[$c]['label']) : '' ?></th>
          <?php if ($c !== ''): foreach ($colKeys as $ck): ?>
            <th class="num"><?= h(pivot_label($c, $ck)) ?></th>
          <?php endforeach; ?>
            <th class="num">Total</th>
          <?php else: ?>
            <th class="num"><?= $oneSide ? h(side_label($oneSide)) : 'Difference' ?></th>
          <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($rowKeys as $rk): ?>
          <tr>
            <th class="pv-rowhead"><?= h(pivot_label($r, $rk)) ?></th>
            <?php if ($c !== ''): ?>
              <?php foreach ($colKeys as $ck) echo $cellHtml($grid[$rk][$ck] ?? null, $cellLink($rk, $ck)); ?>
              <?= $cellHtml($rowTot[$rk] ?? null, $cellLink($rk, null), true) ?>
            <?php else: ?>
              <?= $cellHtml($grid[$rk]['all'] ?? null, $cellLink($rk, null)) ?>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($c !== ''): ?>
        <tfoot><tr>
          <th class="pv-rowhead">Total</th>
          <?php foreach ($colKeys as $ck) {
              $q = pivot_merge($base, pivot_link_parts($c, $ck, $dims));
              echo $cellHtml($colTot[$ck] ?? null, 'transactions.php?' . http_build_query($q), true);
          } ?>
          <?= $cellHtml($grand, 'transactions.php?' . http_build_query($base), true) ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <p class="small muted" style="margin-bottom:0"><?php if ($oneSide): ?>
      The corner is everything <?= h(side_label($oneSide)) ?> holds for these settings.
    <?php else: ?>
      The corner is the whole difference for these settings.
      With nothing hidden and no filter it is the same figure the Overview shows. Totals include rows
      that are hidden for agreeing, so they always add up to what is really there.
    <?php endif; ?></p>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
