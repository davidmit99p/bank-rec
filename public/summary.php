<?php
// Totals by month, both sides together, with the difference - so you can see
// which period the gap is coming from rather than hunting through a long list.
require_once __DIR__ . '/../includes/layout.php';

$pdo = db();

$show = in_array($_GET['show'] ?? '', ['open', 'matched', 'both'], true) ? $_GET['show'] : 'open';

$virgin  = !isset($_GET['in']) && !isset($_GET['out']);
$wantIn  = $virgin ? true : isset($_GET['in']);
$wantOut = $virgin ? true : isset($_GET['out']);
if (!$wantIn && !$wantOut) { $wantIn = $wantOut = true; }
$sign = $wantIn && $wantOut ? 'both' : ($wantIn ? 'in' : 'out');

// One side's totals, a row per month.
function monthly($table, $show, $sign)
{
    $where = [rec_where('t')];
    if ($show === 'open')    $where[] = 't.matched_at IS NULL';
    if ($show === 'matched') $where[] = 't.matched_at IS NOT NULL';
    if ($sign === 'in')      $where[] = 't.value > 0';
    if ($sign === 'out')     $where[] = 't.value < 0';
    $sql = "SELECT DATE_FORMAT(t.txn_date, '%Y-%m') ym,
                   COUNT(*) n,
                   COALESCE(SUM(t.value), 0) total
            FROM {$table} t
            WHERE " . implode(' AND ', $where) . not_split('t') . "
            GROUP BY ym ORDER BY ym";
    $out = [];
    foreach (db()->query($sql)->fetchAll() as $r) $out[$r['ym']] = $r;
    return $out;
}

$L = monthly('rec_ledger', $show, $sign);
$B = monthly('rec_bank',   $show, $sign);

$months = array_keys($L + $B);
sort($months);

$rows = [];
$runL = $runB = 0.0;
foreach ($months as $m) {
    $lt = (float)($L[$m]['total'] ?? 0);
    $bt = (float)($B[$m]['total'] ?? 0);
    $runL += $lt;
    $runB += $bt;
    $rows[] = [
        'ym'      => $m,
        'l_n'     => (int)($L[$m]['n'] ?? 0),
        'l_total' => $lt,
        'b_n'     => (int)($B[$m]['n'] ?? 0),
        'b_total' => $bt,
        'diff'    => $lt - $bt,
        'running' => $runL - $runB,
    ];
}
$totL = array_sum(array_column($rows, 'l_total'));
$totB = array_sum(array_column($rows, 'b_total'));

// --- the same thing as a file, for a working paper ---------------------------
if (isset($_GET['csv'])) {
    $rec = current_rec();
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-',
        ($rec['name'] ?? 'reconciliation') . ' monthly ' . $show), '-');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    fputcsv($out, ['Month', side_label('ledger') . ' count', side_label('ledger') . ' total',
                   side_label('bank') . ' count', side_label('bank') . ' total',
                   'Difference', 'Running difference']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['ym'], $r['l_n'], number_format($r['l_total'], 2, '.', ''),
                       $r['b_n'], number_format($r['b_total'], 2, '.', ''),
                       number_format($r['diff'], 2, '.', ''),
                       number_format($r['running'], 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Total', array_sum(array_column($rows, 'l_n')), number_format($totL, 2, '.', ''),
                   array_sum(array_column($rows, 'b_n')), number_format($totB, 2, '.', ''),
                   number_format($totL - $totB, 2, '.', '')]);
    fclose($out);
    exit;
}

// a month's first and last day, for the drill-down links
function month_range($ym)
{
    $first = $ym . '-01';
    return [$first, date('Y-m-t', strtotime($first))];
}

$qs = array_filter(['show' => $show, 'in' => $wantIn ? '1' : '', 'out' => $wantOut ? '1' : '']);

render_header('Summary');
?>
<h1>Monthly summary</h1>
<p class="muted">Each side totalled by month, with the difference between them. Click a month to see
  those transactions. The running column carries the difference forward, which is usually where an
  odd period shows itself.</p>

<form method="get" class="panel" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
  <div><label>Show</label>
    <select name="show" onchange="this.form.submit()">
      <option value="open"<?= $show === 'open' ? ' selected' : '' ?>>Still to be matched</option>
      <option value="matched"<?= $show === 'matched' ? ' selected' : '' ?>>Already matched</option>
      <option value="both"<?= $show === 'both' ? ' selected' : '' ?>>Everything</option>
    </select></div>
  <div><label>Direction</label>
    <span style="display:flex;gap:.9rem;align-items:center;padding-top:.35rem;white-space:nowrap">
      <label style="margin:0;color:var(--ink)"><input type="checkbox" name="in" value="1" style="width:auto"
        <?= $wantIn ? 'checked' : '' ?> onchange="this.form.submit()"> Money in</label>
      <label style="margin:0;color:var(--ink)"><input type="checkbox" name="out" value="1" style="width:auto"
        <?= $wantOut ? 'checked' : '' ?> onchange="this.form.submit()"> Money out</label>
    </span></div>
  <button class="btn ghost" type="submit">Filter</button>
  <a class="btn ghost" href="summary.php">Clear</a>
  <a class="btn ghost" style="margin-left:auto"
     href="?<?= h(http_build_query($qs + ['csv' => 1])) ?>">Download</a>
</form>

<?php if (!$rows): ?>
  <div class="panel"><p class="muted">Nothing to summarise. Import some transactions first.</p></div>
<?php else: ?>
<div class="panel">
<table>
  <thead><tr>
    <th>Month</th>
    <th class="num"><?= h(side_label('ledger')) ?></th>
    <th class="num">Count</th>
    <th class="num"><?= h(side_label('bank')) ?></th>
    <th class="num">Count</th>
    <th class="num">Difference</th>
    <th class="num">Running</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      [$from, $to] = month_range($r['ym']);
      $link = 'transactions.php?' . http_build_query($qs + ['from' => $from, 'to' => $to]);
      $flat = abs($r['diff']) < 0.005;
  ?>
    <tr<?= $flat ? '' : ' style="background:#fdf6e6"' ?>>
      <td><a href="<?= h($link) ?>"><?= h(date('F Y', strtotime($r['ym'] . '-01'))) ?></a></td>
      <td class="num <?= $r['l_total'] < 0 ? 'neg' : '' ?>"><?= money($r['l_total']) ?></td>
      <td class="num muted"><?= $r['l_n'] ?></td>
      <td class="num <?= $r['b_total'] < 0 ? 'neg' : '' ?>"><?= money($r['b_total']) ?></td>
      <td class="num muted"><?= $r['b_n'] ?></td>
      <td class="num <?= $flat ? 'pos' : 'neg' ?>"><?= money($r['diff']) ?></td>
      <td class="num <?= abs($r['running']) < 0.005 ? 'pos' : '' ?>"><?= money($r['running']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="border-top:2px solid var(--accent);font-weight:600">
      <td>Total</td>
      <td class="num <?= $totL < 0 ? 'neg' : '' ?>"><?= money($totL) ?></td>
      <td class="num muted"><?= array_sum(array_column($rows, 'l_n')) ?></td>
      <td class="num <?= $totB < 0 ? 'neg' : '' ?>"><?= money($totB) ?></td>
      <td class="num muted"><?= array_sum(array_column($rows, 'b_n')) ?></td>
      <td class="num <?= abs($totL - $totB) < 0.005 ? 'pos' : 'neg' ?>"><?= money($totL - $totB) ?></td>
      <td></td>
    </tr>
  </tfoot>
</table>
</div>
<p class="small muted">Months where the two sides do not agree are shaded. With
  &ldquo;still to be matched&rdquo; showing, a month that comes to nothing on both sides is fully
  reconciled.</p>
<?php endif; ?>
<?php render_footer(); ?>
