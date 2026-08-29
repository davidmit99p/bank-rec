<?php
// Steps 4, 5, 7 and 8: the open items, the Process button, and manual matching.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';

$pdo = db();

// Find the run we are building, or start one.
function current_draft($create = false)
{
    $pdo = db();
    $r = $pdo->query("SELECT * FROM rec_runs WHERE status='draft' ORDER BY id DESC LIMIT 1")->fetch();
    if ($r || !$create) return $r ?: null;
    $ref = make_run_ref();
    $pdo->prepare("INSERT INTO rec_runs (run_ref) VALUES (?)")->execute([$ref]);
    $id = $pdo->lastInsertId();
    return $pdo->query("SELECT * FROM rec_runs WHERE id = " . (int)$id)->fetch();
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
        if (!$lIds && !$bIds) throw new RuntimeException('Tick some items first.');
        // Ticking only one side is allowed when those entries cancel each other
        // out - a posting error and its reversal, say. There is nothing on the
        // bank to match them against, but they still net to nothing.
        $oneSided = (!$lIds || !$bIds);

        $fetch = function ($table, $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT id, value FROM {$table} WHERE id IN ($in) AND matched_at IS NULL");
            $st->execute($ids);
            return $st->fetchAll();
        };
        $lRows = $fetch('rec_ledger', $lIds);
        $bRows = $fetch('rec_bank',   $bIds);
        if (count($lRows) !== count($lIds) || count($bRows) !== count($bIds)) {
            throw new RuntimeException('Some of those items have already been matched. Refresh and try again.');
        }

        $lTot = array_sum(array_map(fn($r) => (float)$r['value'], $lRows));
        $bTot = array_sum(array_map(fn($r) => (float)$r['value'], $bRows));

        // GOLDEN RULE, in both its forms
        if ($oneSided) {
            $side  = $lIds ? 'ledger' : 'bank';
            $total = $lIds ? $lTot : $bTot;
            $n     = count($lRows) + count($bRows);
            if ($n < 2) {
                throw new RuntimeException('Ticking one side on its own is for entries that cancel each '
                    . 'other out, so tick at least two of them.');
            }
            if (abs($total) >= 0.005) {
                throw new RuntimeException('Those ' . $side . ' entries do not cancel each other out - they '
                    . 'come to ' . money($total) . ' rather than zero. To match against the other side, tick '
                    . 'something there too.');
            }
            $ruleRef  = 'contra';
            $ruleName = 'Contra - entries that cancel out';
        } else {
            if (abs($lTot - $bTot) >= 0.005) {
                throw new RuntimeException('Those do not balance: ledger ' . money($lTot)
                    . ' against bank ' . money($bTot) . ', a difference of ' . money($lTot - $bTot) . '.');
            }
            $ruleRef  = 'manual';
            $ruleName = 'Manual match';
        }

        $run = current_draft(true);
        // don't let the same item be claimed twice within this run
        $usedL = ids_used_in_run($run['id'], 'ledger');
        $usedB = ids_used_in_run($run['id'], 'bank');
        foreach ($lRows as $r) if (isset($usedL[$r['id']])) throw new RuntimeException('A ledger item is already in this run.');
        foreach ($bRows as $r) if (isset($usedB[$r['id']])) throw new RuntimeException('A bank item is already in this run.');

        $groupNo = 1 + (int)$pdo->query("SELECT COALESCE(MAX(group_no),0) FROM rec_match_groups
                                         WHERE run_id = " . (int)$run['id'])->fetchColumn();
        $pdo->prepare("INSERT INTO rec_match_groups
            (run_id, group_no, rule_ref, rule_name, ledger_total, bank_total, sign_mode, accepted)
            VALUES (?,?,?,?,?,?,'same',1)")
            ->execute([$run['id'], $groupNo, $ruleRef, $ruleName, $lTot, $bTot]);
        $gid = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO rec_match_lines (group_id, side, txn_id, value) VALUES (?,?,?,?)");
        foreach ($lRows as $r) $ins->execute([$gid, 'ledger', $r['id'], $r['value']]);
        foreach ($bRows as $r) $ins->execute([$gid, 'bank',   $r['id'], $r['value']]);

        flash($oneSided
            ? 'Contra added to ' . $run['run_ref'] . ' - ' . (count($lRows) + count($bRows))
              . ' entries that cancel each other out. It will be committed when you finalise.'
            : 'Manual match added to ' . $run['run_ref'] . ' for ' . money($lTot)
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

// Column names allowed in an ORDER BY, so nothing from the address bar
// reaches the query.
function sort_columns()
{
    return ['date' => 'txn_date', 'description' => 'description', 'value' => 'value'];
}

// One clickable column heading. $side is 'l' for ledger or 'b' for bank, so the
// two lists sort independently.
function sort_head($label, $side, $key, $curKey, $curDir, $class = '')
{
    $params = $_GET;
    $params[$side . 's'] = $key;
    $params[$side . 'd'] = ($curKey === $key && $curDir === 'asc') ? 'desc' : 'asc';
    $arrow = $curKey === $key ? ($curDir === 'asc' ? ' &uarr;' : ' &darr;') : '';
    return '<th' . ($class ? ' class="' . $class . '"' : '') . '>'
         . '<a href="?' . h(http_build_query($params)) . '" style="text-decoration:none;color:inherit">'
         . h($label) . $arrow . '</a></th>';
}

function open_items($table, $q, $from, $to, $skipIds, $sortKey = 'date', $dir = 'asc')
{
    $where = ['matched_at IS NULL'];
    $args  = [];
    if ($q !== '')    { $where[] = 'description LIKE ?'; $args[] = '%' . $q . '%'; }
    if ($from !== '') { $where[] = 'txn_date >= ?';      $args[] = $from; }
    if ($to !== '')   { $where[] = 'txn_date <= ?';      $args[] = $to; }
    if ($skipIds)     { $where[] = 'id NOT IN (' . implode(',', array_map('intval', $skipIds)) . ')'; }
    $col = sort_columns()[$sortKey] ?? 'txn_date';
    $d   = $dir === 'desc' ? 'DESC' : 'ASC';
    $st = db()->prepare("SELECT * FROM {$table} WHERE " . implode(' AND ', $where)
                        . " ORDER BY {$col} {$d}, id");
    $st->execute($args);
    return $st->fetchAll();
}

// Items already sitting in the draft run are not available to tick again.
$draft = current_draft();
$claimL = $draft ? array_keys(ids_used_in_run($draft['id'], 'ledger')) : [];
$claimB = $draft ? array_keys(ids_used_in_run($draft['id'], 'bank'))   : [];

$lsort = isset(sort_columns()[$_GET['ls'] ?? '']) ? $_GET['ls'] : 'date';
$ldir  = ($_GET['ld'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$bsort = isset(sort_columns()[$_GET['bs'] ?? '']) ? $_GET['bs'] : 'date';
$bdir  = ($_GET['bd'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$ledger = open_items('rec_ledger', $q, $from, $to, $claimL, $lsort, $ldir);
$bank   = open_items('rec_bank',   $q, $from, $to, $claimB, $bsort, $bdir);
$lTot   = array_sum(array_map(fn($r) => (float)$r['value'], $ledger));
$bTot   = array_sum(array_map(fn($r) => (float)$r['value'], $bank));
$rules  = (int)$pdo->query("SELECT COUNT(*) FROM rec_rules WHERE active = 1")->fetchColumn();

render_header('Transactions');
?>
<h1>3. Transactions</h1>
<p class="muted">Everything still to be matched. Press <b>Process</b> to run the rules, or tick items on
both sides and match them yourself. To clear a posting error and its reversal, tick them on
<b>one side alone</b> &mdash; they match as a contra, so long as they cancel each other out.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if ($draft): ?>
<div class="panel" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
  <b>Run <?= h($draft['run_ref']) ?> is open</b>
  <span class="muted small"><?= (int)$pdo->query("SELECT COUNT(*) FROM rec_match_groups WHERE run_id=" . (int)$draft['id'])->fetchColumn() ?>
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
          <thead><tr>
            <?= sort_head('Date', 'l', 'date', $lsort, $ldir) ?>
            <?= sort_head('Description', 'l', 'description', $lsort, $ldir) ?>
            <?= sort_head('Value', 'l', 'value', $lsort, $ldir, 'num') ?>
            <th style="width:1.5rem"></th></tr></thead>
          <tbody>
          <?php foreach ($ledger as $t): ?>
            <tr><td class="small"><?= h($t['txn_date']) ?></td>
                <td class="desc" title="<?= h($t['description']) ?>"><?= h($t['description']) ?></td>
                <td class="num <?= $t['value'] < 0 ? 'neg' : '' ?>"><?= money($t['value']) ?></td>
                <td><input type="checkbox" name="ledger[]" value="<?= (int)$t['id'] ?>"
                     class="tick" data-side="L" data-value="<?= h($t['value']) ?>"></td></tr>
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
          <thead><tr><th style="width:1.5rem"></th>
            <?= sort_head('Date', 'b', 'date', $bsort, $bdir) ?>
            <?= sort_head('Description', 'b', 'description', $bsort, $bdir) ?>
            <?= sort_head('Value', 'b', 'value', $bsort, $bdir, 'num') ?>
            </tr></thead>
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

    var bothSides = nL > 0 && nB > 0;
    var oneSided  = (nL > 0) !== (nB > 0);
    var sideTotal = nL > 0 ? l : b;
    var sideCount = nL > 0 ? nL : nB;
    var ok;

    if (bothSides) {
      // the two sides must agree
      elD.textContent = 'Difference ' + fmt(d);
      ok = Math.abs(d) < 0.005;
      btn.textContent = 'Match ticked items';
    } else if (oneSided) {
      // one side on its own must cancel itself out - a posting error and its
      // reversal, with nothing on the other side to match against
      elD.textContent = (nL > 0 ? 'Ledger' : 'Bank') + ' ticked comes to ' + fmt(sideTotal);
      ok = sideCount >= 2 && Math.abs(sideTotal) < 0.005;
      btn.textContent = 'Match as contra';
    } else {
      elD.textContent = 'Difference 0.00';
      ok = false;
      btn.textContent = 'Match ticked items';
    }

    elD.className = 'balance ' + (nL + nB === 0 ? '' : (ok ? 'ok' : 'off'));
    btn.disabled = !ok;
  }
  form.addEventListener('change', function (e) { if (e.target.classList.contains('tick')) update(); });
  update();
})();
</script>
<?php render_footer(); ?>
