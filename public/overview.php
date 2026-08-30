<?php
// Every reconciliation at once, with its difference - so you can see which link
// is broken without opening each one in turn.
//
// Where reconciliations join up - the file on one's right side is the file on
// another's left - they are drawn as a sequence. That is the whole of what a
// "chain" means here: a way of reading a set of pairs, not a thing in its own
// right.
require_once __DIR__ . '/../includes/layout.php';

$pdo = db();

if (!recs_ready() || !files_ready()) {
    render_header('Overview');
    ?>
    <h1>Overview</h1>
    <div class="panel"><p class="muted">Nothing to show until reconciliations and files are set up.</p></div>
    <?php
    render_footer();
    exit;
}

// One row per reconciliation, with each side counted in ITS OWN reconciliation.
$rows = $pdo->query(
    "SELECT r.*,
       lf.name AS left_file_name, rf.name AS right_file_name,
       (SELECT COUNT(*) FROM rec_txns t WHERE t.file_id = r.left_file_id AND t.split_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM rec_matched m WHERE m.txn_id = t.id AND m.rec_id = r.id)) l_open,
       (SELECT COUNT(*) FROM rec_txns t WHERE t.file_id = r.right_file_id AND t.split_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM rec_matched m WHERE m.txn_id = t.id AND m.rec_id = r.id)) b_open,
       (SELECT COALESCE(SUM(t.value),0) FROM rec_txns t WHERE t.file_id = r.left_file_id AND t.split_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM rec_matched m WHERE m.txn_id = t.id AND m.rec_id = r.id)) l_val,
       (SELECT COALESCE(SUM(t.value),0) FROM rec_txns t WHERE t.file_id = r.right_file_id AND t.split_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM rec_matched m WHERE m.txn_id = t.id AND m.rec_id = r.id)) b_val,
       (SELECT COUNT(*) FROM rec_runs n WHERE n.rec_id = r.id AND n.status = 'finalised') runs_n,
       (SELECT MAX(n.finalised_at) FROM rec_runs n WHERE n.rec_id = r.id AND n.status = 'finalised') last_run,
       (SELECT COUNT(*) FROM rec_runs n WHERE n.rec_id = r.id AND n.status = 'draft') drafts
     FROM rec_recs r
     LEFT JOIN rec_files lf ON lf.id = r.left_file_id
     LEFT JOIN rec_files rf ON rf.id = r.right_file_id
     WHERE r.active = 1
     ORDER BY r.sort_order, r.name")->fetchAll();

// --- work out which reconciliations join up ---------------------------------
//
// One follows another when the file on its left is the file on the other's
// right. Nothing is stored; this is read off the pairings each time.
$byLeftFile = [];
$rightFiles = [];
foreach ($rows as $r) {
    if ($r['left_file_id'])  $byLeftFile[(int)$r['left_file_id']][] = $r;
    if ($r['right_file_id']) $rightFiles[(int)$r['right_file_id']] = true;
}

$chains  = [];
$placed  = [];
foreach ($rows as $r) {
    // a sequence starts where nothing feeds into it
    if ($r['left_file_id'] && isset($rightFiles[(int)$r['left_file_id']])) continue;
    $chain = [];
    $cur   = $r;
    $guard = 0;
    while ($cur && $guard++ < 50) {
        if (isset($placed[$cur['id']])) break;      // never walk the same one twice
        $placed[$cur['id']] = true;
        $chain[] = $cur;
        $next = null;
        foreach ($byLeftFile[(int)$cur['right_file_id']] ?? [] as $cand) {
            if (!isset($placed[$cand['id']])) { $next = $cand; break; }
        }
        $cur = $next;
    }
    if ($chain) $chains[] = $chain;
}
// anything not reached - a loop, or a pairing that feeds itself
foreach ($rows as $r) if (!isset($placed[$r['id']])) $chains[] = [$r];

render_header('Overview');
?>
<h1>Overview</h1>
<p class="muted">Every reconciliation and where it stands. Where one leads into the next &mdash; the file
  on one's right being the file on the next one's left &mdash; they are shown as a sequence, so you can
  see which link is out rather than opening each in turn.</p>

<?php if (!$rows): ?>
  <div class="panel"><p class="muted">No reconciliations in use.
    <a href="recs.php">Set one up</a>.</p></div>
<?php endif; ?>

<?php foreach ($chains as $chain): ?>
<div class="panel">
  <?php if (count($chain) > 1): ?>
    <h2 style="margin-top:0"><?= h($chain[0]['left_file_name'] ?? '?') ?>
      <?php foreach ($chain as $link): ?>
        &rarr; <?= h($link['right_file_name'] ?? '?') ?>
      <?php endforeach; ?></h2>
    <p class="small muted" style="margin-top:-.3rem"><?= count($chain) ?> links in this sequence.
      A break early on makes the ones after it hard to read, so work left to right.</p>
  <?php endif; ?>

  <table>
    <thead><tr>
      <th>Reconciliation</th><th>Compares</th>
      <th class="num">Open left</th><th class="num">Open right</th>
      <th class="num">Difference</th><th>Last finalised</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($chain as $r):
        $diff  = (float)$r['l_val'] - (float)$r['b_val'];
        $clean = abs($diff) < 0.005;
        $noFiles = !$r['left_file_id'] || !$r['right_file_id'];
    ?>
      <tr<?= $clean && !$noFiles ? '' : ' style="background:#fdf6e6"' ?>>
        <td><b><?= h($r['name']) ?></b>
          <?= (int)$r['id'] === rec_id() ? ' <span class="tag manual">working on</span>' : '' ?>
          <?php if ($r['drafts']): ?><br><span class="tag">a run is open</span><?php endif; ?></td>
        <td class="small"><?php
            if ($noFiles) echo '<span class="muted">no files chosen yet</span>';
            else echo h($r['left_file_name']) . ' &rarr; ' . h($r['right_file_name']); ?></td>
        <td class="num"><?= number_format((int)$r['l_open']) ?><br>
          <span class="muted small"><?= money($r['l_val']) ?></span></td>
        <td class="num"><?= number_format((int)$r['b_open']) ?><br>
          <span class="muted small"><?= money($r['b_val']) ?></span></td>
        <td class="num <?= $clean ? 'pos' : 'neg' ?>" style="font-weight:600">
          <?= $noFiles ? '&mdash;' : money($diff) ?>
          <?php if ($clean && !$noFiles): ?><br><span class="small">reconciled</span><?php endif; ?></td>
        <td class="small"><?= $r['last_run'] ? h(substr($r['last_run'], 0, 10)) : '<span class="muted">never</span>' ?>
          <?php if ($r['runs_n']): ?><br><span class="muted small"><?= (int)$r['runs_n'] ?> runs</span><?php endif; ?></td>
        <td><a class="btn ghost small" href="transactions.php?switch_rec=<?= (int)$r['id'] ?>">Work on this</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<?php if ($rows): ?>
<p class="small muted">A difference is the open total on one side less the open total on the other, so a
  reconciliation that is fully matched shows nothing. Where one bank line covers many bookings the
  difference is still the right figure, but working out which items make it up belongs on the
  transactions screen rather than here.</p>
<?php endif; ?>
<?php render_footer(); ?>
