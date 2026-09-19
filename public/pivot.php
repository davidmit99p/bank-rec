<?php
// A small pivot of differences, to find WHICH slice a gap is in before looking
// for it line by line.
//
// Pick what goes down the side, what goes across the top and, optionally, one
// field to narrow everything by. Each cell is one side's total less the other's
// for that slice. Click a cell and the transactions screen opens filtered to
// exactly that slice on both sides, ready to look at or match.
require_once __DIR__ . '/../includes/layout.php';

$pdo = db();

const PIVOT_MAX_ROWS = 300;
const PIVOT_MAX_COLS = 40;

// --- what can be pivoted on --------------------------------------------------
//
// Always the date in a few shapes, and money in/out. Spare fields only when BOTH
// sides' files have one of that name - a slice has to mean the same thing on
// each side. Matched by name, not number, so "Account" can be spare field 2 on
// one file and 5 on the other.
function pivot_dims()
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

// How a slice's key reads on screen.
function pivot_label($dim, $key)
{
    if ($dim === 'month' && preg_match('/^\d{4}-\d{2}$/', $key)) return date('M Y', strtotime($key . '-01'));
    if ($dim === 'date'  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) return date('j M Y', strtotime($key));
    return $key;
}

// Transactions-screen settings that pick out one slice of one dimension.
function pivot_link_parts($dim, $key, array $dims)
{
    if ($dim === '' || $key === null) return [];
    if ($dim === 'month') return ['months' => $key];
    if ($dim === 'year')  return ['from' => $key . '-01-01', 'to' => $key . '-12-31'];
    if ($dim === 'date')  return ['from' => $key, 'to' => $key];
    if ($dim === 'direction') return $key === 'Money out' ? ['out' => 1] : ['in' => 1];
    $d = $dims[$dim] ?? null;
    if (!$d || empty($d['lcol'])) return [];
    $v = $key === '(blank)' ? '(blank)' : '=' . $key;
    return ['lf_' . $d['lcol'] => $v, 'bf_' . $d['rcol'] => $v];
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
$dims = pivot_dims();
$r    = isset($dims[$_GET['r'] ?? '']) ? $_GET['r'] : null;
$c    = isset($dims[$_GET['c'] ?? '']) ? $_GET['c'] : '';
$f    = isset($dims[$_GET['f'] ?? '']) ? $_GET['f'] : '';
$fv   = trim((string)($_GET['fv'] ?? ''));
$show = in_array($_GET['show'] ?? '', ['open', 'matched', 'both'], true) ? $_GET['show'] : 'open';
$opp  = !empty($_GET['opp']);
// hiding what agrees is the useful default; "all" asks to see everything
$hide = empty($_GET['all']);

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
function pivot_side($side, array $dims, $r, $c, $f, $fv, $show)
{
    $p = $side === 'ledger' ? 'l' : 'b';
    $rx = $dims[$r][$p];
    $cx = $c !== '' ? $dims[$c][$p] : "'all'";
    $where = [file_where($side, 't')];
    if ($show === 'open')    $where[] = open_where('t');
    if ($show === 'matched') $where[] = matched_where('t');
    $args = [];
    if ($f !== '' && $fv !== '') { $where[] = $dims[$f][$p] . ' = ?'; $args[] = $fv; }
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
$noFiles = side_file_id('ledger') === null || side_file_id('bank') === null;
if (!$noFiles) {
    foreach (['ledger' => 'l', 'bank' => 'b'] as $side => $p) {
        foreach (pivot_side($side, $dims, $r, $c, $f, $fv, $show) as $row) {
            $cell = &$grid[(string)$row['rk']][(string)$row['ck']];
            $cell[$p] = ($cell[$p] ?? 0) + (float)$row['total'];
            $cell[$p . 'n'] = ($cell[$p . 'n'] ?? 0) + (int)$row['n'];
            unset($cell);
        }
    }
}

// the difference in one cell: left less right, or left plus right when the
// right-hand file carries the opposite sign
$diffOf = fn($cell) => (float)($cell['l'] ?? 0) + ($opp ? 1 : -1) * (float)($cell['b'] ?? 0);

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
$tooBig = count($rowKeys) > PIVOT_MAX_ROWS || count($colKeys) > PIVOT_MAX_COLS;

// every drill-down starts from these, plus the filter if there is one
$base = ['show' => $show];
if ($f !== '' && $fv !== '') $base = pivot_merge($base, pivot_link_parts($f, $fv, $dims));
$cellLink = function ($rk, $ck) use ($base, $r, $c, $dims) {
    $q = pivot_merge($base, pivot_link_parts($r, $rk, $dims));
    if ($ck !== null && $c !== '') $q = pivot_merge($q, pivot_link_parts($c, $ck, $dims));
    return 'transactions.php?' . http_build_query($q);
};

$settings = array_filter(['r' => $r, 'c' => $c, 'f' => $f, 'fv' => $fv, 'show' => $show,
                          'opp' => $opp ? 1 : '', 'all' => $hide ? '' : 1],
                         fn($v) => $v !== '' && $v !== null);

// --- the grid as a file ----------------------------------------------------------
if (isset($_GET['csv']) && !$noFiles) {
    $rec  = current_rec();
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', ($rec['name'] ?? 'reconciliation') . ' pivot'), '-');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    $cols = $c !== '' ? $colKeys : ['all'];
    fputcsv($out, array_merge([$dims[$r]['label']],
        array_map(fn($ck) => $c !== '' ? pivot_label($c, $ck) : 'Difference', $cols),
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
$cellHtml = function ($cell, $href, $strong = false) use ($diffOf) {
    if (!$cell || (empty($cell['ln']) && empty($cell['bn']))) return '<td class="num pv-empty"></td>';
    $d = $diffOf($cell);
    $ok = abs($d) < 0.005;
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
<h1>Pivot of differences</h1>
<p class="muted">Find which slice a difference sits in. Choose what goes down the side and across the
  top; each cell is <?= h(side_label('ledger')) ?> less <?= h(side_label('bank')) ?> for that slice.
  <b>Click any cell</b> to open the transactions screen filtered to exactly that slice on both sides.</p>

<form method="get" class="panel" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
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
  <div><label>Show</label>
    <select name="show" onchange="this.form.submit()">
      <option value="open"<?= $show === 'open' ? ' selected' : '' ?>>Still to be matched</option>
      <option value="matched"<?= $show === 'matched' ? ' selected' : '' ?>>Already matched</option>
      <option value="both"<?= $show === 'both' ? ' selected' : '' ?>>Everything</option>
    </select></div>
  <div style="display:flex;flex-direction:column;gap:.2rem;padding-bottom:.2rem">
    <label style="margin:0;color:var(--ink);white-space:nowrap"><input type="checkbox" name="all" value="1"
      style="width:auto" <?= $hide ? '' : 'checked' ?> onchange="this.form.submit()"> Show rows and columns that agree</label>
    <label style="margin:0;color:var(--ink);white-space:nowrap"><input type="checkbox" name="opp" value="1"
      style="width:auto" <?= $opp ? 'checked' : '' ?> onchange="this.form.submit()">
      <?= h(side_label('bank')) ?> is the opposite sign</label>
  </div>
  <a class="btn ghost" href="pivot.php">Reset</a>
  <?php if (!$noFiles && $rowKeys && !$tooBig): ?>
    <a class="btn ghost" style="margin-left:auto" href="?<?= h(http_build_query($settings + ['csv' => 1])) ?>">Download</a>
  <?php endif; ?>
</form>

<?php
$spareCount = count(array_filter(array_keys($dims), fn($k) => str_starts_with($k, 'f:')));
if (!$noFiles && !$spareCount): ?>
  <p class="small muted">Only dates and money in/out are offered. A spare field can be used once
    both files have one with the same name &mdash; name them on the <a href="files.php">Files</a> page.</p>
<?php endif; ?>

<?php if ($noFiles): ?>
  <div class="panel"><p class="muted">This reconciliation needs a file on each side first.</p></div>
<?php elseif ($tooBig): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0">That makes <?= number_format(count($rowKeys)) ?> rows by <?= number_format(count($colKeys)) ?>
      columns &mdash; too many to be useful. Add a filter, or choose something with fewer values
      (month rather than date, say). The limit is <?= PIVOT_MAX_ROWS ?> rows by <?= PIVOT_MAX_COLS ?> columns.</p>
  </div>
<?php elseif (!$rowKeys): ?>
  <div class="panel"><p class="muted" style="margin:0"><?php
    echo $allRows && $hide
      ? '&#10003; Everything agrees in every slice. Tick &ldquo;Show rows and columns that agree&rdquo; to see them.'
      : 'Nothing to show for these settings.'; ?></p></div>
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
    $items = ['open' => 'items still to match', 'matched' => 'matched items', 'both' => 'items'][$show];
    ?>
    <p class="small pv-legend pv-list">
      <span><span class="pv-key pv-left"></span><b>Only in <?= h(side_label('ledger')) ?></b>:
        nothing on the other side in this slice</span>
      <span><span class="pv-key pv-right"></span><b>Only in <?= h(side_label('bank')) ?></b>:
        nothing on the other side in this slice</span>
      <span><span class="pv-key pv-both"></span><b>In both, but they differ</b></span>
      <span><span class="pv-key pv-dash"></span><b>Nets to zero</b>: <?= $items ?> here,
        but they cancel out, so nothing is missing</span>
      <span><span class="pv-key"></span><b>Blank</b>: no <?= $items ?> in this slice</span></p>
    <div class="scroll" style="max-height:70vh">
      <table class="pivot">
        <thead><tr>
          <th><?= h($dims[$r]['label']) ?><?= $c !== '' ? ' \\ ' . h($dims[$c]['label']) : '' ?></th>
          <?php if ($c !== ''): foreach ($colKeys as $ck): ?>
            <th class="num"><?= h(pivot_label($c, $ck)) ?></th>
          <?php endforeach; ?>
            <th class="num">Total</th>
          <?php else: ?>
            <th class="num">Difference</th>
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
    <p class="small muted" style="margin-bottom:0">The corner is the whole difference for these settings.
      With nothing hidden and no filter it is the same figure the Overview shows. Totals include rows
      that are hidden for agreeing, so they always add up to what is really there.</p>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
