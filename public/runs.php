<?php
// The history: every matching run, and what it matched.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';

$pdo = db();

// Unpick a finalised run - the items come back as open.
if (($_POST['action'] ?? '') === 'unmatch') {
    $runId = (int)$_POST['run'];
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE rec_ledger SET run_id=NULL, rule_ref=NULL, group_no=NULL, matched_at=NULL WHERE run_id=?")->execute([$runId]);
    $pdo->prepare("UPDATE rec_bank   SET run_id=NULL, rule_ref=NULL, group_no=NULL, matched_at=NULL WHERE run_id=?")->execute([$runId]);
    $pdo->prepare("DELETE FROM rec_match_groups WHERE run_id=?")->execute([$runId]);
    $pdo->prepare("UPDATE rec_runs SET status='discarded' WHERE id=?")->execute([$runId]);
    $pdo->commit();
    flash('Run unpicked. Those transactions are open again.');
    header('Location: runs.php');
    exit;
}

$runs = $pdo->query(
    "SELECT r.*,
            (SELECT COUNT(*) FROM rec_match_groups g WHERE g.run_id = r.id) groups_n,
            (SELECT COUNT(*) FROM rec_ledger  t WHERE t.run_id = r.id) ledger_n,
            (SELECT COUNT(*) FROM rec_bank    t WHERE t.run_id = r.id) bank_n,
            (SELECT COALESCE(SUM(t.value),0) FROM rec_ledger t WHERE t.run_id = r.id) ledger_v
     FROM rec_runs r ORDER BY r.id DESC")->fetchAll();

render_header('Runs');
?>
<h1>Matching runs</h1>
<p class="muted">Every run keeps its own reference. Each matched transaction carries that reference and the
rule number that matched it, so you can always see why something was matched.</p>

<div class="panel">
<table>
  <thead><tr><th>Run</th><th>Status</th><th>Created</th><th>Finalised</th>
    <th class="num">Matches</th><th class="num">Ledger</th><th class="num">Bank</th>
    <th class="num">Value</th><th>Note</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): ?>
    <tr>
      <td><a href="review.php?run=<?= (int)$r['id'] ?>"><?= h($r['run_ref']) ?></a></td>
      <td><span class="tag"><?= h($r['status']) ?></span></td>
      <td class="small"><?= h($r['created_at']) ?></td>
      <td class="small"><?= h($r['finalised_at'] ?: '') ?></td>
      <td class="num"><?= (int)$r['groups_n'] ?></td>
      <td class="num"><?= (int)$r['ledger_n'] ?></td>
      <td class="num"><?= (int)$r['bank_n'] ?></td>
      <td class="num <?= $r['ledger_v'] < 0 ? 'neg' : '' ?>"><?= money($r['ledger_v']) ?></td>
      <td class="small muted"><?= h($r['note']) ?></td>
      <td><?php if ($r['status'] === 'finalised'): ?>
        <form method="post" onsubmit="return confirm('Unpick this run? Those transactions become open again.')">
          <input type="hidden" name="action" value="unmatch">
          <input type="hidden" name="run" value="<?= (int)$r['id'] ?>">
          <button class="btn ghost small" type="submit" style="color:var(--bad);border-color:var(--bad)">Unpick</button>
        </form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="10" class="muted">No runs yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<h2>Matched transactions</h2>
<?php
$matchedL = $pdo->query("SELECT t.*, r.run_ref FROM rec_ledger t LEFT JOIN rec_runs r ON r.id = t.run_id
                         WHERE t.matched_at IS NOT NULL ORDER BY t.matched_at DESC, t.id DESC LIMIT 300")->fetchAll();
$matchedB = $pdo->query("SELECT t.*, r.run_ref FROM rec_bank t LEFT JOIN rec_runs r ON r.id = t.run_id
                         WHERE t.matched_at IS NOT NULL ORDER BY t.matched_at DESC, t.id DESC LIMIT 300")->fetchAll();
?>
<div class="sides">
<?php foreach ([['Ledger', $matchedL], ['Bank', $matchedB]] as [$label, $rows]): ?>
  <div>
    <div class="side-head"><h2><?= $label ?></h2><span class="muted small">most recent 300</span></div>
    <div class="scroll">
      <table>
        <thead><tr><th>Date</th><th>Description</th><th class="num">Value</th><th>Rule</th><th>Run</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
          <tr><td class="small"><?= h($t['txn_date']) ?></td>
              <td class="desc" title="<?= h($t['description']) ?>"><?= h($t['description']) ?></td>
              <td class="num <?= $t['value'] < 0 ? 'neg' : '' ?>"><?= money($t['value']) ?></td>
              <td><span class="tag <?= $t['rule_ref'] === 'manual' ? 'manual' : '' ?>"><?= h($t['rule_ref']) ?></span></td>
              <td class="small"><?= h($t['run_ref']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Nothing matched yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php render_footer(); ?>
