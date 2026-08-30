<?php
require_once __DIR__ . '/../includes/layout.php';

$pdo = db();
function side_stats($side)
{
    return db()->query("SELECT COUNT(*) total,
                               SUM(CASE WHEN " . open_where('t') . " THEN 1 ELSE 0 END) open,
                               COALESCE(SUM(CASE WHEN " . open_where('t') . " THEN t.value ELSE 0 END),0) open_value
                        FROM rec_txns t WHERE " . file_where($side, 't') . not_split('t'))->fetch();
}
$L = side_stats('ledger');
$B = side_stats('bank');
$rules  = (int)$pdo->query("SELECT COUNT(*) FROM rec_rules WHERE active = 1" . rule_and())->fetchColumn();
$draft  = $pdo->query("SELECT * FROM rec_runs WHERE status='draft'" . rec_and()
                      . " ORDER BY id DESC LIMIT 1")->fetch();
$runs   = (int)$pdo->query("SELECT COUNT(*) FROM rec_runs WHERE status='finalised'"
                           . rec_and())->fetchColumn();

render_header();
?>
<?php
  $missing = [];
  foreach (['ledger', 'bank'] as $sd) if (recs_ready() && side_file_id($sd) === null) $missing[] = side_label($sd);
?>
<h1>Bank Reconciliation</h1>
<?php if ($missing): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>This reconciliation is not set up yet.</b>
      There is no file behind <?= count($missing) === 2 ? 'either side' : 'its ' . h($missing[0]) . ' side' ?>,
      so there is nothing to match. Load files on the <a href="files.php">Files</a> screen, then
      <a href="recs.php?edit=<?= (int)rec_id() ?>">say which two this reconciliation pairs</a>.</p>
  </div>
<?php endif; ?>
<p class="muted">Match your ledger against your bank statement using a growing library of rules.</p>

<div class="stats">
  <div class="stat"><span class="muted small"><?= h(side_label('ledger')) ?> items open</span><b><?= (int)$L['open'] ?></b>
    <span class="num small <?= $L['open_value'] < 0 ? 'neg' : '' ?>"><?= money($L['open_value']) ?></span></div>
  <div class="stat"><span class="muted small"><?= h(side_label('bank')) ?> items open</span><b><?= (int)$B['open'] ?></b>
    <span class="num small <?= $B['open_value'] < 0 ? 'neg' : '' ?>"><?= money($B['open_value']) ?></span></div>
  <div class="stat"><span class="muted small">Difference</span>
    <b class="<?= abs($L['open_value'] - $B['open_value']) < 0.005 ? 'pos' : 'neg' ?>">
      <?= money($L['open_value'] - $B['open_value']) ?></b>
    <span class="muted small">ledger less bank</span></div>
  <div class="stat"><span class="muted small">Active rules</span><b><?= $rules ?></b>
    <a class="small" href="rules.php">manage</a></div>
  <div class="stat"><span class="muted small">Finalised runs</span><b><?= $runs ?></b>
    <a class="small" href="runs.php">history</a></div>
</div>

<?php if ($draft): ?>
<div class="panel">
  <h2 style="margin-top:0">A run is waiting for you</h2>
  <p><b><?= h($draft['run_ref']) ?></b> has suggested matches that have not been finalised yet.</p>
  <a class="btn" href="review.php?run=<?= (int)$draft['id'] ?>">Review it</a>
</div>
<?php endif; ?>

<div class="panel">
  <h2 style="margin-top:0">How it works</h2>
  <ol class="muted">
    <li><b>Name the files.</b> On <a href="files.php">Files</a>, create one for each thing you will
      load &mdash; a bank statement, a ledger extract. Say what its spare columns are called while you
      are there.</li>
    <li><b>Load them.</b> <a href="import.php">Import</a> into a file. You can do this before or after
      the next step; a file does not need to know what it will be compared against.</li>
    <li><b>Pair them.</b> On <a href="recs.php">Reconciliations</a>, create one and say which two files
      it compares, and what to call each side. The same file can be used by more than one, which is
      how a sequence works &mdash; source to interface, interface to ledger, ledger to statement.</li>
    <li><b>Write the rules.</b> On <a href="rules.php">Rules</a>, describe what to look for on each
      side. Tightest first; a rule can apply to every reconciliation or just to one.</li>
    <li><b>Process.</b> On <a href="transactions.php">Transactions</a>, apply the rules, or tick items
      and match them yourself.</li>
    <li><b>Review and finalise.</b> Suggestions are not committed until you say so. Adjust anything
      that does not balance; only what balances is written.</li>
    <li><b>Repeat</b> until what is left will never match, then download it to work up the journals.</li>
  </ol>
  <p class="small muted">A match is only ever committed when both sides total exactly the same
    amount. Matched items drop off the transactions screen, so it always shows what is still open.</p>
</div>
<?php render_footer(); ?>
