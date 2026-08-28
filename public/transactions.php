<?php
// Steps 4, 5, 7 and 8: the open items, the Process button, and manual matching.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';

$pdo = db();

// Find the run we are building, or start one.
function current_draft($create = false)
{
    $pdo = db();
    $r = $pdo->query("SELECT * FROM runs WHERE status='draft' ORDER BY id DESC LIMIT 1")->fetch();
    if ($r || !$create) return $r ?: null;
    $ref = make_run_ref();
    $pdo->prepare("INSERT INTO runs (run_ref) VALUES (?)")->execute([$ref]);
    $id = $pdo->lastInsertId();
    return $pdo->query("SELECT * FROM runs WHERE id = " . (int)$id)->fetch();
}

$error = null;

// --- Step 5: apply the rules -------------------------------------------------
if (($_POST['action'] ?? '') === 'process') {
    $run = current_draft(true);
    $result = run_rules($run['id']);
    $made = array_sum(array_column($result, 'made'));
    flash($made
        ? "{$made} matches suggested by the rules. Review them and finalise."
        : 'The rules did not find anything new to match.');
    header('Location: review.php?run=' . (int)$run['id']);
    exit;
}

// --- Step 8: manual match ----------------------------------------------------
if (($_POST['action'] ?? '') === 'manual') {
    $lIds = array_map('intval', $_POST['ledger'] ?? []);
    $bIds = array_map('intval', $_POST['bank'] ?? []);
    try {
        if (!$lIds || !$bIds) throw new RuntimeException('Tick at least one item on each side.');

        $fetch = function ($table, $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT id, value FROM {$table} WHERE id IN ($in) AND matched_at IS NULL");
            $st->execute($ids);
            return $st->fetchAll();
        };
        $lRows = $fetch('ledger', $lIds);
        $bRows = $fetch('bank',   $bIds);
        if (count($lRows) !== count($lIds) || count($bRows) !== count($bIds)) {
            throw new RuntimeException('Some of those items have already been matched. Refresh and try again.');
        }

        $lTot = array_sum(array_map(fn($r) => (float)$r['value'], $lRows));
        $bTot = array_sum(array_map(fn($r) => (float)$r['value'], $bRows));
        // GOLDEN RULE
        if (abs($lTot - $bTot) >= 0.005) {
            throw new RuntimeException('Those do not balance: ledger ' . money($lTot)
                . ' against bank ' . money($bTot) . ', a difference of ' . money($lTot - $bTot) . '.');
        }

        $run = current_draft(true);
        // don't let the same item be claimed twice within this run
        $usedL = ids_used_in_run($run['id'], 'ledger');
        $usedB = ids_used_in_run($run['id'], 'bank');
        foreach ($lRows as $r) if (isset($usedL[$r['id']])) throw new RuntimeException('A ledger item is already in this run.');
        foreach ($bRows as $r) if (isset($usedB[$r['id']])) throw new RuntimeException('A bank item is already in this run.');

        $groupNo = 1 + (int)$pdo->query("SELECT COALESCE(MAX(group_no),0) FROM match_groups
                                         WHERE run_id = " . (int)$run['id'])->fetchColumn();
        $pdo->prepare("INSERT INTO match_groups
            (run_id, group_no, rule_ref, rule_name, ledger_total, bank_total, sign_mode, accepted)
            VALUES (?,?,'manual','Manual match',?,?,'same',1)")
            ->execute([$run['id'], $groupNo, $lTot, $bTot]);
        $gid = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO match_lines (group_id, side, txn_id, value) VALUES (?,?,?,?)");
        foreach ($lRows as $r) $ins->execute([$gid, 'ledger', $r['id'], $r['value']]);
        foreach ($bRows as $r) $ins->execute([$gid, 'bank',   $r['id'], $r['value']]);

        flash('Manual match added to ' . $run['run_ref'] . ' for ' . money($lTot)
              . '. It will be committed when you finalise.');
        header('Location: transactions.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// --- filters -----------------------------------------------------------------
$q    = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

function open_items($table, $q, $from, $to, $skipIds)
{
    $where = ['matched_at IS NULL'];
    $args  = [];
    if ($q !== '')    { $where[] = 'description LIKE ?'; $args[] = '%' . $q . '%'; }
    if ($from !== '') { $where[] = 'txn_date >= ?';      $args[] = $from; }
    if ($to !== '')   { $where[] = 'txn_date <= ?';      $args[] = $to; }
    if ($skipIds)     { $where[] = 'id NOT IN (' . implode(',', array_map('intval', $skipIds)) . ')'; }
    $st = db()->prepare("SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY txn_date, id");
    $st->execute($args);
    return $st->fetchAll();
}

// Items already sitting in the draft run are not available to tick again.
$draft = current_draft();
$claimL = $draft ? array_keys(ids_used_in_run($draft['id'], 'ledger')) : [];
$claimB = $draft ? array_keys(ids_used_in_run($draft['id'], 'bank'))   : [];

$ledger = open_items('ledger', $q, $from, $to, $claimL);
$bank   = open_items('bank',   $q, $from, $to, $claimB);
$lTot   = array_sum(array_map(fn($r) => (float)$r['value'], $ledger));
$bTot   = array_sum(array_map(fn($r) => (float)$r['value'], $bank));
$rules  = (int)$pdo->query("SELECT COUNT(*) FROM rules WHERE active = 1")->fetchColumn();

render_header('Transactions');
?>
<h1>3. Transactions</h1>
<p class="muted">Everything still to be matched. Press <b>Process</b> to run the rules, or tick items on
both sides and match them yourself.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if ($draft): ?>
<div class="panel" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
  <b>Run <?= h($draft['run_ref']) ?> is open</b>
  <span class="muted small"><?= (int)$pdo->query("SELECT COUNT(*) FROM match_groups WHERE run_id=" . (int)$draft['id'])->fetchColumn() ?>
    suggested matches waiting</span>
  <a class="btn" style="margin-left:auto" href="review.php?run=<?= (int)$draft['id'] ?>">Review &amp; finalise</a>
</div>
<?php endif; ?>

<form method="get" class="panel" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
  <div style="flex:2;min-width:200px"><label>Search description</label>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="e.g. VISPA"></div>
  <div><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
  <button class="btn ghost" type="submit">Filter</button>
  <a class="btn ghost" href="transactions.php">Clear</a>
</form>

<form method="post" id="txnForm">
  <div class="panel" style="position:sticky;top:0;z-index:5;display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <button class="btn" type="submit" name="action" value="process"
      <?= $rules ? '' : 'disabled title="Add a rule first"' ?>>Process rules</button>
    <span class="muted small"><?= $rules ?> active rule<?= $rules == 1 ? '' : 's' ?></span>
    <span style="margin-left:auto;display:flex;gap:.75rem;align-items:center">
      <span class="balance" id="sumL">Ledger ticked 0.00</span>
      <span class="balance" id="sumB">Bank ticked 0.00</span>
      <span class="balance" id="diff">Difference 0.00</span>
      <button class="btn" type="submit" name="action" value="manual" id="manualBtn" disabled>
        Match ticked items</button>
    </span>
  </div>

  <div class="sides">
    <div>
      <div class="side-head"><h2>Ledger &mdash; table 1</h2>
        <span class="muted small"><?= count($ledger) ?> open,
          <span class="num <?= $lTot < 0 ? 'neg' : '' ?>"><?= money($lTot) ?></span></span></div>
      <div class="scroll">
        <table>
          <thead><tr><th style="width:1.5rem"></th><th>Date</th><th>Description</th><th class="num">Value</th></tr></thead>
          <tbody>
          <?php foreach ($ledger as $t): ?>
            <tr><td><input type="checkbox" name="ledger[]" value="<?= (int)$t['id'] ?>"
                     class="tick" data-side="L" data-value="<?= h($t['value']) ?>"></td>
                <td class="small"><?= h($t['txn_date']) ?></td>
                <td class="desc" title="<?= h($t['description']) ?>"><?= h($t['description']) ?></td>
                <td class="num <?= $t['value'] < 0 ? 'neg' : '' ?>"><?= money($t['value']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$ledger): ?><tr><td colspan="4" class="muted">Nothing open.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div>
      <div class="side-head"><h2>Bank &mdash; table 2</h2>
        <span class="muted small"><?= count($bank) ?> open,
          <span class="num <?= $bTot < 0 ? 'neg' : '' ?>"><?= money($bTot) ?></span></span></div>
      <div class="scroll">
        <table>
          <thead><tr><th style="width:1.5rem"></th><th>Date</th><th>Description</th><th class="num">Value</th></tr></thead>
          <tbody>
          <?php foreach ($bank as $t): ?>
            <tr><td><input type="checkbox" name="bank[]" value="<?= (int)$t['id'] ?>"
                     class="tick" data-side="B" data-value="<?= h($t['value']) ?>"></td>
                <td class="small"><?= h($t['txn_date']) ?></td>
                <td class="desc" title="<?= h($t['description']) ?>"><?= h($t['description']) ?></td>
                <td class="num <?= $t['value'] < 0 ? 'neg' : '' ?>"><?= money($t['value']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$bank): ?><tr><td colspan="4" class="muted">Nothing open.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</form>

<script>
// Live golden-rule check: the ticked totals on both sides must agree.
(function () {
  var form = document.getElementById('txnForm');
  var elL = document.getElementById('sumL'), elB = document.getElementById('sumB'),
      elD = document.getElementById('diff'), btn = document.getElementById('manualBtn');

  function fmt(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function update() {
    var l = 0, b = 0, nL = 0, nB = 0;
    form.querySelectorAll('.tick:checked').forEach(function (c) {
      var v = parseFloat(c.dataset.value) || 0;
      if (c.dataset.side === 'L') { l += v; nL++; } else { b += v; nB++; }
      c.closest('tr').classList.add('ticked');
    });
    form.querySelectorAll('.tick:not(:checked)').forEach(function (c) {
      c.closest('tr').classList.remove('ticked');
    });
    var d = Math.round((l - b) * 100) / 100;
    elL.textContent = 'Ledger ticked ' + fmt(l) + ' (' + nL + ')';
    elB.textContent = 'Bank ticked ' + fmt(b) + ' (' + nB + ')';
    elD.textContent = 'Difference ' + fmt(d);
    var ok = nL > 0 && nB > 0 && Math.abs(d) < 0.005;
    elD.className = 'balance ' + (nL + nB === 0 ? '' : (ok ? 'ok' : 'off'));
    btn.disabled = !ok;
  }
  form.addEventListener('change', function (e) { if (e.target.classList.contains('tick')) update(); });
  update();
})();
</script>
<?php render_footer(); ?>
