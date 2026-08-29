<?php
// Steps 4, 5, 7 and 8: the open items, the Process button, and manual matching.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';
require_once __DIR__ . '/../includes/txnlist.php';

$pdo = db();

// Find the run we are building, or start one.
function current_draft($create = false)
{
    $pdo = db();
    $r = $pdo->query("SELECT * FROM rec_runs WHERE status='draft'" . rec_and()
                     . " ORDER BY id DESC LIMIT 1")->fetch();
    if ($r || !$create) return $r ?: null;
    $ref = make_run_ref();
    if (recs_ready()) {
        $pdo->prepare("INSERT INTO rec_runs (run_ref, rec_id) VALUES (?,?)")->execute([$ref, rec_id()]);
    } else {
        $pdo->prepare("INSERT INTO rec_runs (run_ref) VALUES (?)")->execute([$ref]);
    }
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
            $st = db()->prepare("SELECT id, value FROM {$table} WHERE id IN ($in) AND matched_at IS NULL"
                                . rec_and());
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

// --- splitting one transaction into parts ------------------------------------
if (($_POST['action'] ?? '') === 'split') {
    $side = ($_POST['side'] ?? '') === 'bank' ? 'bank' : 'ledger';
    $parts = [];
    foreach ((array)($_POST['part_value'] ?? []) as $i => $v) {
        $parts[] = ['value' => $v, 'description' => $_POST['part_desc'][$i] ?? ''];
    }
    [$ok, $msg] = split_transaction($side, (int)($_POST['txn_id'] ?? 0), $parts);
    if ($ok) {
        flash($msg);
        header('Location: transactions.php?' . http_build_query($_POST['back'] ?? []));
        exit;
    }
    $error = $msg;
}

if (($_POST['action'] ?? '') === 'unsplit') {
    $side = ($_POST['side'] ?? '') === 'bank' ? 'bank' : 'ledger';
    [$ok, $msg] = unsplit_transaction($side, (int)($_POST['parent_id'] ?? 0));
    if ($ok) {
        flash($msg);
        header('Location: transactions.php?' . http_build_query($_POST['back'] ?? []));
        exit;
    }
    $error = $msg;
}

// --- unmatching, when matched items are on show ------------------------------
if (($_POST['action'] ?? '') === 'unmatch') {
    [$ok, $msg] = unmatch_selection($_POST['ledger'] ?? [], $_POST['bank'] ?? []);
    if ($ok) {
        flash($msg);
        header('Location: transactions.php?' . http_build_query($_POST['back'] ?? []));
        exit;
    }
    $error = $msg;
}

// --- filters -----------------------------------------------------------------
// A search box per side: the ledger narrative and the bank narrative rarely
// look anything like each other.
$lq   = trim($_GET['lq'] ?? '');
$bq   = trim($_GET['bq'] ?? '');
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$show = in_array($_GET['show'] ?? '', ['open', 'matched', 'both'], true) ? $_GET['show'] : 'open';

// Money in / money out. A first visit has no parameters at all, so both are on;
// after that they say what they say. Turning both off is treated as both on.
$virgin = !isset($_GET['in']) && !isset($_GET['out']);
$wantIn  = $virgin ? true : isset($_GET['in']);
$wantOut = $virgin ? true : isset($_GET['out']);
if (!$wantIn && !$wantOut) { $wantIn = $wantOut = true; }
$sign = $wantIn && $wantOut ? 'both' : ($wantIn ? 'in' : 'out');

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

// $show is 'open', 'matched' or 'both'. Matched rows come back with the run
// reference and the id of the match they belong to, so they can be unmatched
// from here without going anywhere else.
// Items already sitting in the draft run are not available to tick again.
$draft = current_draft();
$claimL = $draft ? array_keys(ids_used_in_run($draft['id'], 'ledger')) : [];
$claimB = $draft ? array_keys(ids_used_in_run($draft['id'], 'bank'))   : [];

$lsort = isset(sort_columns()[$_GET['ls'] ?? '']) ? $_GET['ls'] : 'date';
$ldir  = ($_GET['ld'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$bsort = isset(sort_columns()[$_GET['bs'] ?? '']) ? $_GET['bs'] : 'date';
$bdir  = ($_GET['bd'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$ledger = list_items('ledger', $lq, $from, $to, $claimL, $show, $lsort, $ldir, $sign);
$bank   = list_items('bank',   $bq, $from, $to, $claimB, $show, $bsort, $bdir, $sign);
$lTot   = array_sum(array_map(fn($r) => (float)$r['value'], $ledger));
$bTot   = array_sum(array_map(fn($r) => (float)$r['value'], $bank));
$lOpen  = array_sum(array_map(fn($r) => $r['matched_at'] === null ? 1 : 0, $ledger));
$bOpen  = array_sum(array_map(fn($r) => $r['matched_at'] === null ? 1 : 0, $bank));
$back   = array_filter(['lq' => $lq, 'bq' => $bq, 'from' => $from, 'to' => $to, 'show' => $show,
                        'in' => $wantIn ? '1' : '', 'out' => $wantOut ? '1' : '']);
$rules  = (int)$pdo->query("SELECT COUNT(*) FROM rec_rules WHERE active = 1" . rule_and())->fetchColumn();

render_header('Transactions');
?>
<h1>3. Transactions</h1>
<p class="muted">Press <b>Process</b> to run the rules, or tick items on both sides and match them
yourself. To clear a posting error and its reversal, tick them on <b>one side alone</b> &mdash; they
match as a contra, so long as they cancel each other out. Use <b>Show</b> to bring already-matched
items into view when something needs undoing.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if ($draft): ?>
<div class="panel" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
  <b>Run <?= h($draft['run_ref']) ?> is open</b>
  <span class="muted small"><?= (int)$pdo->query("SELECT COUNT(*) FROM rec_match_groups WHERE run_id=" . (int)$draft['id'])->fetchColumn() ?>
    suggested matches waiting</span>
  <a class="btn" style="margin-left:auto" href="review.php?run=<?= (int)$draft['id'] ?>">Review &amp; finalise</a>
</div>
<?php endif; ?>

<?php
// Two small search forms, one per side. They live out here so the inputs shown
// inside each panel can point at them with form="..." without nesting a form
// inside the form that carries the tick boxes.
foreach ([['searchL', ['bq' => $bq]], ['searchB', ['lq' => $lq]]] as [$id, $others]): ?>
  <form method="get" id="<?= $id ?>" style="display:none">
    <?php foreach ($others + ['from' => $from, 'to' => $to, 'show' => $show] as $k => $v): ?>
      <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
    <?php endforeach; ?>
    <?php if ($wantIn): ?><input type="hidden" name="in" value="1"><?php endif; ?>
    <?php if ($wantOut): ?><input type="hidden" name="out" value="1"><?php endif; ?>
  </form>
<?php endforeach; ?>

<form method="get" class="panel" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
  <input type="hidden" name="lq" value="<?= h($lq) ?>">
  <input type="hidden" name="bq" value="<?= h($bq) ?>">
  <div><label>Dated from</label><input type="date" name="from" value="<?= h($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
  <div><label>Show</label>
    <select name="show" onchange="this.form.submit()">
      <option value="open"<?= $show === 'open' ? ' selected' : '' ?>>Still to be matched</option>
      <option value="matched"<?= $show === 'matched' ? ' selected' : '' ?>>Already matched</option>
      <option value="both"<?= $show === 'both' ? ' selected' : '' ?>>Both</option>
    </select></div>
  <div><label>Direction</label>
    <span style="display:flex;gap:.9rem;align-items:center;padding-top:.35rem;white-space:nowrap">
      <label style="margin:0;color:var(--ink)">
        <input type="checkbox" name="in" value="1" style="width:auto"
          <?= $wantIn ? 'checked' : '' ?> onchange="this.form.submit()"> Money in</label>
      <label style="margin:0;color:var(--ink)">
        <input type="checkbox" name="out" value="1" style="width:auto"
          <?= $wantOut ? 'checked' : '' ?> onchange="this.form.submit()"> Money out</label>
    </span></div>
  <button class="btn ghost" type="submit">Filter</button>
  <a class="btn ghost" href="transactions.php">Clear all</a>
  <?php if ($show !== 'open'): ?>
    <span class="muted small" style="flex:1;min-width:16rem">Matched items are greyed, and carry their
      rule and run. Tick them and press Unmatch to bring them back.</span>
  <?php endif; ?>
</form>

<form method="post" id="txnForm">
  <?php foreach ($back as $k => $v): ?>
    <input type="hidden" name="back[<?= h($k) ?>]" value="<?= h($v) ?>">
  <?php endforeach; ?>
  <div class="panel" style="position:sticky;top:0;z-index:5;display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <button class="btn" type="submit" name="action" value="process"
      <?= $rules ? '' : 'disabled title="Add a rule first"' ?>>Process rules</button>
    <span class="muted small"><?= $rules ?> active rule<?= $rules == 1 ? '' : 's' ?></span>
    <span style="margin-left:auto;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
      <span class="balance" id="sumL"><?= h(side_label('ledger')) ?> ticked 0.00</span>
      <span class="balance" id="sumB"><?= h(side_label('bank')) ?> ticked 0.00</span>
      <span class="balance" id="diff">Difference 0.00</span>
      <button class="btn" type="submit" name="action" value="manual" id="manualBtn" disabled>
        Match ticked items</button>
      <button class="btn" type="submit" name="action" value="unmatch" id="unmatchBtn" disabled
        style="display:none">Unmatch ticked lines</button>
    </span>
  </div>

  <div class="sides">
<?php
  $panels = [
    ['ledger', h(side_label('ledger')), $ledger, $lOpen, $lTot, 'L', 'l', $lsort, $ldir, 'searchL', 'lq', $lq],
    ['bank',   h(side_label('bank')),   $bank,   $bOpen, $bTot, 'B', 'b', $bsort, $bdir, 'searchB', 'bq', $bq],
  ];
  foreach ($panels as [$side, $title, $rows, $openN, $tot, $tag, $pfx, $sortKey, $dir, $formId, $field, $val]):
    $tickFirst = ($side === 'bank');   // ledger ticks sit on the inside edge
?>
    <div>
      <div class="side-head"><h2><?= $title ?></h2>
        <span class="muted small"><?= count($rows) ?> shown<?= $sign === 'in' ? ', money in only'
            : ($sign === 'out' ? ', money out only' : '') ?><?= $show === 'both' ? ', ' . $openN . ' open' : '' ?>,
          <span class="num <?= $tot < 0 ? 'neg' : '' ?>"><?= money($tot) ?></span></span></div>

      <div style="display:flex;gap:.4rem;margin-bottom:.4rem">
        <input type="text" name="<?= $field ?>" value="<?= h($val) ?>" form="<?= $formId ?>"
               placeholder="Search this side only...">
        <button class="btn ghost" type="submit" form="<?= $formId ?>">Search</button>
        <?php
          // the download takes the same filters as the screen, so what comes out
          // is what you are looking at
          $dl = ['side' => $side, 'q' => $val, 'from' => $from, 'to' => $to,
                 'show' => $show, 'sort' => $sortKey, 'dir' => $dir];
          if ($wantIn)  $dl['in']  = 1;
          if ($wantOut) $dl['out'] = 1;
        ?>
        <a class="btn ghost" href="export.php?<?= h(http_build_query(array_filter($dl, fn($v) => $v !== ''))) ?>"
           title="Download this list as a CSV, exactly as filtered">Download</a>
      </div>

      <div class="scroll">
        <table>
          <thead><tr>
            <?php if ($tickFirst) echo '<th style="width:3.2rem"></th>'; ?>
            <?= sort_head('Date', $pfx, 'date', $sortKey, $dir) ?>
            <?= sort_head('Description', $pfx, 'description', $sortKey, $dir) ?>
            <?= sort_head('Value', $pfx, 'value', $sortKey, $dir, 'num') ?>
            <?php if (!$tickFirst) echo '<th style="width:3.2rem"></th>'; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $t):
              $isMatched = $t['matched_at'] !== null;
              $box = '<input type="checkbox" name="' . $side . '[]" value="' . (int)$t['id'] . '"'
                   . ' class="tick" data-side="' . $tag . '" data-value="' . h($t['value']) . '"'
                   . ' data-matched="' . ($isMatched ? 1 : 0) . '"'
                   . ' data-group="' . (int)($t['group_id'] ?? 0) . '">';
              $splitBtn = '';
              if (splits_ready() && !$isMatched) {
                  $splitBtn = '<button type="button" class="splitbtn" title="Split this into parts"'
                    . ' data-side="' . $side . '"'
                    . ' data-id="' . (int)$t['id'] . '"'
                    . ' data-value="' . h($t['value']) . '"'
                    . ' data-date="' . h($t['txn_date']) . '"'
                    . ' data-parent="' . (int)($t['parent_id'] ?? 0) . '"'
                    . ' data-desc="' . h($t['description']) . '">&#9986;</button>';
              }
          ?>
            <tr<?= $isMatched ? ' style="opacity:.6"' : '' ?>>
              <?php if ($tickFirst) echo '<td class="tickcell">' . $box . $splitBtn . '</td>'; ?>
              <td class="small"><?= h($t['txn_date']) ?></td>
              <td class="desc" title="<?= h($t['description']) ?>"><?= h($t['description']) ?>
                <?php if (!empty($t['parent_id'])): ?>
                  <span class="tag" title="split out of <?= h(money($t['parent_value'] ?? 0)) ?> on <?= h($t['txn_date']) ?>">split</span>
                <?php endif; ?>
                <?php if ($isMatched): ?>
                  <span class="tag <?= is_numeric($t['rule_ref']) ? '' : 'manual' ?>"><?php
                    echo is_numeric($t['rule_ref']) ? 'rule ' . h($t['rule_ref']) : h($t['rule_ref']); ?></span>
                  <span class="tag"><?= h($t['run_ref']) ?></span>
                <?php endif; ?></td>
              <td class="num <?= $t['value'] < 0 ? 'neg' : '' ?>"><?= money($t['value']) ?></td>
              <?php if (!$tickFirst) echo '<td class="tickcell">' . $splitBtn . $box . '</td>'; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing to show.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
<?php endforeach; ?>
  </div>
</form>

<script>
// The golden rule, live. Matching needs the two sides to agree, or one side to
// cancel itself out. Unmatching needs what you take out of EACH match to
// balance, or the rest of that match is left broken.
(function () {
  var form = document.getElementById('txnForm');
  var elL = document.getElementById('sumL'), elB = document.getElementById('sumB'),
      elD = document.getElementById('diff'),
      matchBtn = document.getElementById('manualBtn'),
      unBtn = document.getElementById('unmatchBtn');

  var LEFT_LABEL  = <?= json_encode(side_label('ledger'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var RIGHT_LABEL = <?= json_encode(side_label('bank'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function fmt(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function update() {
    var l = 0, b = 0, nL = 0, nB = 0, nOpen = 0, nMatched = 0;
    var groups = {};

    form.querySelectorAll('.tick').forEach(function (c) {
      var row = c.closest('tr');
      if (!c.checked) { row.classList.remove('ticked'); return; }
      row.classList.add('ticked');
      var v = parseFloat(c.dataset.value) || 0;
      if (c.dataset.side === 'L') { l += v; nL++; } else { b += v; nB++; }

      if (c.dataset.matched === '1') {
        nMatched++;
        var g = c.dataset.group || '0';
        if (!groups[g]) groups[g] = 0;
        groups[g] += (c.dataset.side === 'L' ? v : -v);
      } else {
        nOpen++;
      }
    });

    elL.textContent = LEFT_LABEL + ' ticked ' + fmt(l) + ' (' + nL + ')';
    elB.textContent = RIGHT_LABEL + ' ticked ' + fmt(b) + ' (' + nB + ')';

    var mixed = nOpen > 0 && nMatched > 0;
    var unmatchMode = nMatched > 0 && nOpen === 0;

    matchBtn.style.display = unmatchMode ? 'none' : '';
    unBtn.style.display    = unmatchMode ? '' : 'none';

    if (mixed) {
      elD.textContent = 'Tick either open items or matched ones, not both';
      elD.className = 'balance off';
      matchBtn.disabled = true;
      unBtn.disabled = true;
      return;
    }

    if (unmatchMode) {
      var allBalance = true, n = 0;
      for (var g in groups) {
        n++;
        if (Math.abs(Math.round(groups[g] * 100) / 100) >= 0.005) allBalance = false;
      }
      elD.textContent = allBalance
        ? nMatched + ' lines from ' + n + ' match' + (n === 1 ? '' : 'es') + ', all still balancing'
        : 'What you have picked out of a match does not balance';
      elD.className = 'balance ' + (allBalance ? 'ok' : 'off');
      unBtn.disabled = !allBalance;
      return;
    }

    var d = Math.round((l - b) * 100) / 100;
    var bothSides = nL > 0 && nB > 0;
    var oneSided  = (nL > 0) !== (nB > 0);
    var ok;

    if (bothSides) {
      elD.textContent = 'Difference ' + fmt(d);
      ok = Math.abs(d) < 0.005;
      matchBtn.textContent = 'Match ticked items';
    } else if (oneSided) {
      var sideTotal = nL > 0 ? l : b, sideCount = nL > 0 ? nL : nB;
      elD.textContent = (nL > 0 ? LEFT_LABEL : RIGHT_LABEL) + ' ticked comes to ' + fmt(sideTotal);
      ok = sideCount >= 2 && Math.abs(sideTotal) < 0.005;
      matchBtn.textContent = 'Match as contra';
    } else {
      elD.textContent = 'Difference 0.00';
      ok = false;
      matchBtn.textContent = 'Match ticked items';
    }
    elD.className = 'balance ' + (nL + nB === 0 ? '' : (ok ? 'ok' : 'off'));
    matchBtn.disabled = !ok;
  }

  form.addEventListener('change', function (e) { if (e.target.classList.contains('tick')) update(); });
  update();
})();
</script>
<?php if (splits_ready()): ?>
<dialog id="splitDlg" class="split-dlg">
  <form method="post" id="splitForm">
    <input type="hidden" name="action" value="split">
    <input type="hidden" name="side"   id="spSide">
    <input type="hidden" name="txn_id" id="spId">
    <?php foreach ($back as $k => $v): ?>
      <input type="hidden" name="back[<?= h($k) ?>]" value="<?= h($v) ?>">
    <?php endforeach; ?>

    <h2 style="margin-top:0">Split a transaction</h2>
    <p class="muted small" id="spSummary"></p>

    <table id="spParts">
      <thead><tr><th>Description</th><th class="num" style="width:9rem">Value</th><th style="width:2rem"></th></tr></thead>
      <tbody></tbody>
    </table>

    <div class="actions" style="margin-top:.5rem">
      <button class="btn ghost" type="button" id="spAdd">Add another part</button>
      <span class="balance" id="spLeft" style="margin-left:auto"></span>
    </div>

    <p class="small muted">The parts have to come to exactly the original amount, or the totals on that
      side would move. The original is kept and marked as split, so this can be undone.</p>

    <div class="actions">
      <button class="btn" type="submit" id="spSave" disabled>Save the split</button>
      <button class="btn ghost" type="button" id="spCancel">Cancel</button>
    </div>
  </form>

  <form method="post" id="unsplitForm" style="border-top:1px solid var(--line);margin-top:1rem;padding-top:.75rem;display:none">
    <input type="hidden" name="action" value="unsplit">
    <input type="hidden" name="side"      id="usSide">
    <input type="hidden" name="parent_id" id="usParent">
    <?php foreach ($back as $k => $v): ?>
      <input type="hidden" name="back[<?= h($k) ?>]" value="<?= h($v) ?>">
    <?php endforeach; ?>
    <p class="small muted" style="margin:0 0 .5rem">This one came from a split. You can put the whole
      split back together, so long as none of its parts are matched.</p>
    <button class="btn ghost" type="submit" style="color:var(--bad);border-color:var(--bad)">
      Undo the split this came from</button>
  </form>
</dialog>

<script>
(function () {
  var dlg = document.getElementById('splitDlg');
  if (!dlg || !dlg.showModal) return;          // very old browser: buttons just do nothing

  var body = dlg.querySelector('#spParts tbody');
  var left = document.getElementById('spLeft');
  var save = document.getElementById('spSave');
  var total = 0;

  function fmt(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function addRow(desc, value) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" name="part_desc[]" value=""></td>' +
      '<td><input type="text" name="part_value[]" class="pval num" value="" placeholder="0.00"></td>' +
      '<td><button type="button" class="btn ghost small takerest" title="Use whatever is left">&#8594;</button></td>';
    body.appendChild(tr);
    tr.querySelector('input[name="part_desc[]"]').value = desc || '';
    if (value !== undefined) tr.querySelector('.pval').value = value;
    return tr;
  }

  function allocated() {
    var sum = 0;
    body.querySelectorAll('.pval').forEach(function (i) {
      var v = parseFloat((i.value || '').replace(/,/g, ''));
      if (!isNaN(v)) sum += v;
    });
    return Math.round(sum * 100) / 100;
  }

  function update() {
    var rest = Math.round((total - allocated()) * 100) / 100;
    var filled = 0;
    body.querySelectorAll('.pval').forEach(function (i) {
      var v = parseFloat((i.value || '').replace(/,/g, ''));
      if (!isNaN(v) && Math.abs(v) >= 0.005) filled++;
    });
    left.textContent = rest === 0 ? 'Fully allocated' : 'Still to allocate ' + fmt(rest);
    left.className = 'balance ' + (rest === 0 && filled >= 2 ? 'ok' : 'off');
    save.disabled = !(rest === 0 && filled >= 2);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.splitbtn');
    if (btn) {
      total = parseFloat(btn.dataset.value) || 0;
      document.getElementById('spSide').value = btn.dataset.side;
      document.getElementById('spId').value = btn.dataset.id;
      document.getElementById('spSummary').textContent =
        btn.dataset.date + '  ' + btn.dataset.desc + '  ' + fmt(total);

      body.innerHTML = '';
      addRow(btn.dataset.desc, '');
      addRow(btn.dataset.desc, '');

      var us = document.getElementById('unsplitForm');
      if (btn.dataset.parent && btn.dataset.parent !== '0') {
        document.getElementById('usSide').value = btn.dataset.side;
        document.getElementById('usParent').value = btn.dataset.parent;
        us.style.display = '';
      } else {
        us.style.display = 'none';
      }
      update();
      dlg.showModal();
      return;
    }
    if (e.target.closest('#spCancel')) { dlg.close(); return; }
    if (e.target.closest('#spAdd'))    { addRow('', ''); update(); return; }
    var take = e.target.closest('.takerest');
    if (take) {
      var input = take.closest('tr').querySelector('.pval');
      var others = 0;
      body.querySelectorAll('.pval').forEach(function (i) {
        if (i === input) return;
        var v = parseFloat((i.value || '').replace(/,/g, ''));
        if (!isNaN(v)) others += v;
      });
      input.value = (Math.round((total - others) * 100) / 100).toFixed(2);
      update();
    }
  });

  dlg.addEventListener('input', function (e) {
    if (e.target.classList.contains('pval')) update();
  });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
