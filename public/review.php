<?php
// Step 6: review the suggestions, untick anything you disagree with, then finalise.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';

$pdo   = db();
$runId = (int)($_GET['run'] ?? $_POST['run'] ?? 0);
$st = $pdo->prepare("SELECT * FROM rec_runs WHERE id = ?");
$st->execute([$runId]);
$run = $st->fetch();
if (!$run) { flash('That run could not be found.'); header('Location: runs.php'); exit; }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $run['status'] === 'draft') {
    $action = $_POST['action'] ?? '';

    if ($action === 'discard') {
        $pdo->prepare("DELETE FROM rec_match_groups WHERE run_id = ?")->execute([$runId]);
        $pdo->prepare("UPDATE rec_runs SET status='discarded' WHERE id = ?")->execute([$runId]);
        flash('Run ' . $run['run_ref'] . ' discarded. Nothing was committed.');
        header('Location: transactions.php');
        exit;
    }

    // Save the ticks, whichever button was pressed.
    //
    // We send the UNTICKED ones as a single field rather than one value per
    // ticked match. PHP's max_input_vars defaults to 1000, so a big run used to
    // lose everything past the 999th silently - it looked like the whole screen
    // had been finalised when it had not.
    if (($_POST['mode'] ?? '') === 'rejected') {
        $rejected = array_filter(array_map('intval', explode(',', (string)($_POST['rejected'] ?? ''))));
        $pdo->prepare("UPDATE rec_match_groups SET accepted = 1 WHERE run_id = ?")->execute([$runId]);
        if ($rejected) {
            $in = implode(',', array_fill(0, count($rejected), '?'));
            $pdo->prepare("UPDATE rec_match_groups SET accepted = 0 WHERE run_id = ? AND id IN ($in)")
                ->execute(array_merge([$runId], $rejected));
        }
    } else {
        // Fallback for a browser with JavaScript switched off.
        $groupCount = (int)$pdo->query("SELECT COUNT(*) FROM rec_match_groups WHERE run_id = " . (int)$runId)->fetchColumn();
        $limit = (int)ini_get('max_input_vars') ?: 1000;
        if ($groupCount > $limit - 10) {
            $error = "This run has {$groupCount} matches, which is more than this server can accept from a "
                   . "form with JavaScript switched off (the limit is {$limit}). Nothing has been changed. "
                   . "Switch JavaScript on, or finalise in smaller runs.";
        } else {
            $keep = array_map('intval', $_POST['accept'] ?? []);
            $pdo->prepare("UPDATE rec_match_groups SET accepted = 0 WHERE run_id = ?")->execute([$runId]);
            if ($keep) {
                $in = implode(',', array_fill(0, count($keep), '?'));
                $pdo->prepare("UPDATE rec_match_groups SET accepted = 1 WHERE run_id = ? AND id IN ($in)")
                    ->execute(array_merge([$runId], $keep));
            }
        }
    }
    $pdo->prepare("UPDATE rec_runs SET note = ? WHERE id = ?")->execute([trim($_POST['note'] ?? ''), $runId]);

    if ($error !== null) {
        // the fallback above refused to save - fall through and redisplay
    } elseif ($action === 'finalise') {
        [$ok, $msg] = finalise_run($runId);
        if ($ok) { flash($msg . ' Run ' . $run['run_ref'] . ' is finalised.'); header('Location: transactions.php'); exit; }
        $error = $msg;
    } else {
        // "sort" is just a save that keeps your ticks and comes back in a
        // different order, so changing the order never loses your work.
        $back = 'review.php?run=' . $runId;
        if (!empty($_POST['gsort'])) $back .= '&gsort=' . urlencode($_POST['gsort']);
        if ($action !== 'sort') flash('Ticks saved. Nothing has been committed yet.');
        header('Location: ' . $back);
        exit;
    }
}

// How the suggestions are ordered on screen. Whitelisted, so nothing from the
// address bar reaches the query.
$groupSorts = [
    'match'  => ["rule_ref='manual', group_no",            'Match number'],
    'rule'   => ["rule_ref='manual', rule_ref, group_no",  'Rule'],
    'big'    => ['ABS(ledger_total) DESC, group_no',       'Amount, largest first'],
    'small'  => ['ABS(ledger_total) ASC, group_no',        'Amount, smallest first'],
];
$gsort = isset($groupSorts[$_GET['gsort'] ?? '']) ? $_GET['gsort'] : 'match';

// load the suggestions with their lines
$groups = $pdo->prepare("SELECT * FROM rec_match_groups WHERE run_id = ? ORDER BY " . $groupSorts[$gsort][0]);
$groups->execute([$runId]);
$groups = $groups->fetchAll();

$lineSql = $pdo->prepare(
    "SELECT l.side, l.txn_id, l.value,
            COALESCE(le.txn_date, bk.txn_date)       AS txn_date,
            COALESCE(le.description, bk.description) AS description
     FROM rec_match_lines l
     LEFT JOIN rec_ledger le ON l.side = 'ledger' AND le.id = l.txn_id
     LEFT JOIN rec_bank   bk ON l.side = 'bank'   AND bk.id = l.txn_id
     WHERE l.group_id = ? ORDER BY txn_date, l.txn_id");

$byRule = [];
foreach ($groups as $g) $byRule[$g['rule_ref']] = ($byRule[$g['rule_ref']] ?? 0) + 1;
$accepted = array_sum(array_map(fn($g) => (int)$g['accepted'], $groups));

render_header('Review ' . $run['run_ref']);
?>
<h1>4. Review <span class="tag"><?= h($run['run_ref']) ?></span></h1>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if ($run['status'] !== 'draft'): ?>
  <p class="muted">This run was <b><?= h($run['status']) ?></b>
     <?= $run['finalised_at'] ? 'on ' . h($run['finalised_at']) : '' ?>. It is shown here for the record.</p>
<?php endif; ?>

<div class="panel">
  <p style="margin:0"><b><?= count($groups) ?></b> suggested match<?= count($groups) == 1 ? '' : 'es' ?>,
     <b><?= $accepted ?></b> ticked.
     <span class="muted small">
     <?php foreach ($byRule as $ref => $n): ?>
       &middot; <?= is_numeric($ref) ? 'rule ' . h($ref) : h($ref) ?>: <?= $n ?>
     <?php endforeach; ?></span></p>
</div>

<?php if (!$groups): ?>
  <div class="panel"><p class="muted">Nothing suggested in this run.
    <a href="transactions.php">Back to transactions</a>.</p></div>
<?php else: ?>

<form method="post" id="reviewForm">
  <input type="hidden" name="run" value="<?= $runId ?>">
  <input type="hidden" name="mode" id="mode" value="">
  <input type="hidden" name="rejected" id="rejected" value="">

  <?php if ($run['status'] === 'draft'): ?>
  <div class="panel" style="position:sticky;top:0;z-index:5;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <button class="btn" type="submit" name="action" value="finalise"
      onclick="return confirm('Commit the ticked matches? This writes the rule number and run reference to the records.')">
      Finalise ticked matches</button>
    <button class="btn ghost" type="submit" name="action" value="save">Save ticks for later</button>
    <button class="btn ghost" type="button" onclick="setAll(true)">Tick all</button>
    <button class="btn ghost" type="button" onclick="setAll(false)">Untick all</button>
    <label style="margin:0;display:flex;align-items:center;gap:.4rem">Order
      <select name="gsort" onchange="document.getElementById('sortBtn').click()" style="width:auto">
        <?php foreach ($groupSorts as $k => $g): ?>
          <option value="<?= $k ?>"<?= $gsort === $k ? ' selected' : '' ?>><?= h($g[1]) ?></option>
        <?php endforeach; ?>
      </select></label>
    <button class="btn ghost" type="submit" name="action" value="sort" id="sortBtn">Sort</button>
    <span class="balance" id="counter" style="margin-left:auto"></span>
    <button class="btn ghost" type="submit" name="action" value="discard"
      onclick="return confirm('Throw away this whole run? Nothing will be committed.')"
      style="color:var(--bad);border-color:var(--bad)">Discard run</button>
  </div>
  <?php endif; ?>

  <?php foreach ($groups as $g):
      $lineSql->execute([$g['id']]);
      $lines = $lineSql->fetchAll();
      $ok = group_balances($g['ledger_total'], $g['bank_total'], $g['sign_mode']);
  ?>
  <div class="group-card<?= $g['accepted'] ? '' : ' off' ?>" id="g<?= (int)$g['id'] ?>">
    <header>
      <?php if ($run['status'] === 'draft'): ?>
        <input type="checkbox" name="accept[]" value="<?= (int)$g['id'] ?>" class="acc"
               data-id="<?= (int)$g['id'] ?>" <?= $g['accepted'] ? 'checked' : '' ?> style="width:auto">
      <?php endif; ?>
      <span class="tag <?= is_numeric($g['rule_ref']) ? '' : 'manual' ?>">
        <?= is_numeric($g['rule_ref']) ? 'Rule ' . h($g['rule_ref']) : h(ucfirst($g['rule_ref'])) ?></span>
      <b>Match <?= (int)$g['group_no'] ?></b>
      <span class="muted small"><?= h($g['rule_name']) ?></span>
      <span style="margin-left:auto" class="balance <?= $ok ? 'ok' : 'off' ?>">
        <?php if ($g['rule_ref'] === 'contra'): ?>
          cancels out to 0.00
        <?php else: ?>
          <?= money($g['ledger_total']) ?>
          <?= $g['sign_mode'] === 'opposite' ? 'against' : '=' ?>
          <?= money($g['bank_total']) ?>
        <?php endif; ?>
        <?= $ok ? '' : ' - does not balance' ?>
      </span>
    </header>
    <div class="sides">
      <?php foreach (['ledger' => 'Ledger', 'bank' => 'Bank'] as $side => $label):
          $sideLines = array_values(array_filter($lines, fn($l) => $l['side'] === $side)); ?>
      <div>
        <span class="muted small"><?= $label ?></span>
        <?php if (!$sideLines): ?>
          <p class="small muted" style="margin:.2rem 0">Nothing on this side &mdash; the entries opposite
            cancel each other out.</p>
        <?php endif; ?>
        <table>
          <?php foreach ($sideLines as $l): ?>
          <tr><td class="small" style="width:6rem"><?= h($l['txn_date']) ?></td>
              <td class="desc" title="<?= h($l['description']) ?>"><?= h($l['description']) ?></td>
              <td class="num <?= $l['value'] < 0 ? 'neg' : '' ?>"><?= money($l['value']) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($run['status'] === 'draft'): ?>
  <div class="panel">
    <label>Note for this run (optional)</label>
    <input type="text" name="note" value="<?= h($run['note']) ?>" placeholder="e.g. January 2019 reconciliation">
  </div>
  <?php endif; ?>
</form>

<script>
function setAll(on) {
  document.querySelectorAll('.acc').forEach(function (c) { c.checked = on; });
  refresh();
}
function refresh() {
  var boxes = document.querySelectorAll('.acc'), n = 0;
  boxes.forEach(function (c) {
    if (c.checked) n++;
    c.closest('.group-card').classList.toggle('off', !c.checked);
  });
  var el = document.getElementById('counter');
  if (el) el.textContent = n + ' of ' + boxes.length + ' ticked';
}
document.addEventListener('change', function (e) { if (e.target.classList.contains('acc')) refresh(); });
refresh();

// Send the UNTICKED matches as one field, and stop the tick boxes posting at
// all. A run of any size then fits well inside PHP's max_input_vars, which
// defaults to 1000 and used to swallow everything past the 999th in silence.
document.getElementById('reviewForm').addEventListener('submit', function () {
  var rejected = [];
  document.querySelectorAll('.acc').forEach(function (c) {
    if (!c.checked) rejected.push(c.dataset.id);
    c.disabled = true;                       // disabled inputs are not submitted
  });
  document.getElementById('rejected').value = rejected.join(',');
  document.getElementById('mode').value = 'rejected';
});
</script>
<?php endif; ?>
<?php render_footer(); ?>
