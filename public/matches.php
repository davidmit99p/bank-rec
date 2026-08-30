<?php
// Everything that has been matched and committed, searchable, both sides
// together - and the place to undo a match.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';

$pdo   = db();
$error = null;

// --- undoing ------------------------------------------------------------------

if (($_POST['action'] ?? '') === 'unmatch_group') {
    $gid = (int)$_POST['group_id'];
    $n = unmatch_whole_group($gid);
    flash("Match undone. {$n} transactions are open again.");
    header('Location: matches.php?' . http_build_query($_POST['back'] ?? []));
    exit;
}

if (($_POST['action'] ?? '') === 'unmatch_selected') {
    [$ok, $msg] = unmatch_selection($_POST['ledger'] ?? [], $_POST['bank'] ?? []);
    if ($ok) {
        flash($msg);
        header('Location: matches.php?' . http_build_query($_POST['back'] ?? []));
        exit;
    }
    $error = $msg;
}

// --- searching ----------------------------------------------------------------

$q     = trim($_GET['q'] ?? '');
$from  = trim($_GET['from'] ?? '');
$to    = trim($_GET['to'] ?? '');
$minV  = trim($_GET['min'] ?? '');
$rule  = trim($_GET['rule'] ?? '');
$run   = trim($_GET['run'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 40;

$where = ["r.status = 'finalised'", rec_where('r')];
$args  = [];

// the description search looks at both sides, since the ledger narrative and
// the bank narrative rarely say the same thing
if ($q !== '') {
    $where[] = "EXISTS (SELECT 1 FROM rec_match_lines l
                        JOIN rec_txns t ON t.id = l.txn_id
                        WHERE l.group_id = g.id AND t.description LIKE ?)";
    $args[] = '%' . $q . '%';
}
foreach ([['>=', $from], ['<=', $to]] as [$op, $val]) {
    if ($val === '') continue;
    $where[] = "EXISTS (SELECT 1 FROM rec_match_lines l
                        JOIN rec_txns t ON t.id = l.txn_id
                        WHERE l.group_id = g.id AND t.txn_date {$op} ?)";
    $args[] = $val;
}
if ($minV !== '' && is_numeric($minV)) {
    $where[] = "ABS(g.ledger_total) >= ?";
    $args[] = (float)$minV;
}
if ($rule !== '') { $where[] = "g.rule_ref = ?"; $args[] = $rule; }
if ($run  !== '') { $where[] = "r.run_ref  = ?"; $args[] = $run; }

$sql  = "FROM rec_match_groups g JOIN rec_runs r ON r.id = g.run_id WHERE " . implode(' AND ', $where);
$st = $pdo->prepare("SELECT COUNT(*) $sql");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$st = $pdo->prepare("SELECT g.*, r.run_ref, r.finalised_at $sql
                     ORDER BY r.finalised_at DESC, g.group_no
                     LIMIT {$per} OFFSET " . (($page - 1) * $per));
$st->execute($args);
$groups = $st->fetchAll();

// fetch every line for the groups on this page in one go
$lines = [];
if ($groups) {
    $ids = implode(',', array_map(fn($g) => (int)$g['id'], $groups));
    foreach ($pdo->query(
        "SELECT l.group_id, l.side, l.txn_id, l.value, t.txn_date, t.description
         FROM rec_match_lines l
         JOIN rec_txns t ON t.id = l.txn_id
         WHERE l.group_id IN ($ids)
         ORDER BY txn_date, l.txn_id")->fetchAll() as $l) {
        $lines[$l['group_id']][$l['side']][] = $l;
    }
}

// for the filter dropdowns
$ruleOpts = $pdo->query("SELECT DISTINCT rule_ref FROM rec_match_groups ORDER BY rule_ref")->fetchAll(PDO::FETCH_COLUMN);
$runOpts  = $pdo->query("SELECT run_ref FROM rec_runs WHERE status='finalised'" . rec_and()
                          . " ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
$back = array_filter(['q' => $q, 'from' => $from, 'to' => $to, 'min' => $minV,
                      'rule' => $rule, 'run' => $run, 'page' => $page]);

render_header('Matches');
?>
<h1>Matched transactions</h1>
<p class="muted">Everything committed, both sides together. Tick individual lines to pull them back out,
or undo a whole match.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<form method="get" class="panel" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
  <div style="flex:2;min-width:180px"><label>Description, either side</label>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="e.g. VISPA"></div>
  <div><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
  <div><label>Value at least</label><input type="text" name="min" value="<?= h($minV) ?>" placeholder="0.00"></div>
  <div><label>Rule</label>
    <select name="rule"><option value="">any</option>
      <?php foreach ($ruleOpts as $o): ?>
        <option value="<?= h($o) ?>"<?= $rule === $o ? ' selected' : '' ?>>
          <?= is_numeric($o) ? 'Rule ' . h($o) : h(ucfirst($o)) ?></option>
      <?php endforeach; ?></select></div>
  <div><label>Run</label>
    <select name="run"><option value="">any</option>
      <?php foreach ($runOpts as $o): ?>
        <option value="<?= h($o) ?>"<?= $run === $o ? ' selected' : '' ?>><?= h($o) ?></option>
      <?php endforeach; ?></select></div>
  <button class="btn ghost" type="submit">Search</button>
  <a class="btn ghost" href="matches.php">Clear</a>
</form>

<?php if (!$groups): ?>
  <div class="panel"><p class="muted">No matches found<?= $total === 0 && $q === '' && $from === '' ? ' yet.' : ' for that search.' ?></p></div>
<?php else: ?>

<form method="post" id="matchForm">
  <input type="hidden" name="action" value="unmatch_selected">
  <?php foreach ($back as $k => $v): ?>
    <input type="hidden" name="back[<?= h($k) ?>]" value="<?= h($v) ?>">
  <?php endforeach; ?>

  <div class="panel" style="position:sticky;top:0;z-index:5;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
    <span><b><?= number_format($total) ?></b> matches<?= $pages > 1 ? ' &middot; page ' . $page . ' of ' . $pages : '' ?></span>
    <span class="balance" id="selInfo" style="margin-left:auto">Nothing ticked</span>
    <button class="btn" type="submit" id="unmatchBtn" disabled>Unmatch ticked lines</button>
  </div>

  <?php foreach ($groups as $g):
      $L = $lines[$g['id']]['ledger'] ?? [];
      $B = $lines[$g['id']]['bank'] ?? [];
      $isContra = !$L || !$B;
  ?>
  <div class="group-card" data-group="<?= (int)$g['id'] ?>">
    <header>
      <span class="tag <?= is_numeric($g['rule_ref']) ? '' : 'manual' ?>">
        <?= is_numeric($g['rule_ref']) ? 'Rule ' . h($g['rule_ref']) : h(ucfirst($g['rule_ref'])) ?></span>
      <b>Match <?= (int)$g['group_no'] ?></b>
      <span class="muted small"><?= h($g['rule_name']) ?> &middot; <?= h($g['run_ref']) ?>
        &middot; <?= h(substr((string)$g['finalised_at'], 0, 10)) ?></span>
      <span class="balance ok" style="margin-left:auto">
        <?= $isContra ? 'cancels out to 0.00' : money($g['ledger_total']) ?></span>
      <span class="balance off" data-warn style="display:none">part-selection does not balance</span>
      <button class="btn ghost small" type="submit" form="undo<?= (int)$g['id'] ?>"
        onclick="return confirm('Undo the whole of match <?= (int)$g['group_no'] ?>?')"
        style="color:var(--bad);border-color:var(--bad)">Undo whole match</button>
    </header>
    <div class="sides">
      <?php foreach ([['ledger', side_label('ledger'), $L], ['bank', side_label('bank'), $B]] as [$side, $label, $rows]): ?>
      <div>
        <span class="muted small"><?= $label ?></span>
        <?php if (!$rows): ?>
          <p class="small muted" style="margin:.2rem 0">Nothing this side &mdash; the entries opposite
            cancel each other out.</p>
        <?php endif; ?>
        <table>
          <?php foreach ($rows as $l): ?>
          <tr>
            <?php if ($side === 'bank'): ?>
              <td style="width:1.5rem"><input type="checkbox" name="bank[]" value="<?= (int)$l['txn_id'] ?>"
                class="pick" data-side="bank" data-value="<?= h($l['value']) ?>"></td>
            <?php endif; ?>
            <td class="small" style="width:6rem"><?= h($l['txn_date']) ?></td>
            <td class="desc" title="<?= h($l['description']) ?>"><?= h($l['description']) ?></td>
            <td class="num <?= $l['value'] < 0 ? 'neg' : '' ?>"><?= money($l['value']) ?></td>
            <?php if ($side === 'ledger'): ?>
              <td style="width:1.5rem"><input type="checkbox" name="ledger[]" value="<?= (int)$l['txn_id'] ?>"
                class="pick" data-side="ledger" data-value="<?= h($l['value']) ?>"></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</form>

<?php // one small form per match, for the whole-match undo button
foreach ($groups as $g): ?>
  <form method="post" id="undo<?= (int)$g['id'] ?>" style="display:none">
    <input type="hidden" name="action" value="unmatch_group">
    <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
    <?php foreach ($back as $k => $v): ?>
      <input type="hidden" name="back[<?= h($k) ?>]" value="<?= h($v) ?>">
    <?php endforeach; ?>
  </form>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<div class="panel" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
  <?php for ($p = max(1, $page - 4); $p <= min($pages, $page + 4); $p++):
      $u = array_merge($back, ['page' => $p]); ?>
    <a class="btn ghost small" href="?<?= h(http_build_query($u)) ?>"
       style="<?= $p === $page ? 'background:var(--accent);color:#fff' : '' ?>"><?= $p ?></a>
  <?php endfor; ?>
  <span class="muted small">page <?= $page ?> of <?= $pages ?></span>
</div>
<?php endif; ?>

<script>
// The golden rule in reverse, checked WITHIN each match: what you take out of a
// match must balance, or the rest of that match is left broken.
(function () {
  var form = document.getElementById('matchForm');
  var info = document.getElementById('selInfo');
  var btn  = document.getElementById('unmatchBtn');

  function fmt(n) { return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function update() {
    var anyTicked = false, allBalance = true, ticked = 0;
    form.querySelectorAll('.group-card').forEach(function (card) {
      var l = 0, b = 0, n = 0;
      card.querySelectorAll('.pick').forEach(function (c) {
        if (!c.checked) return;
        n++;
        var v = parseFloat(c.dataset.value) || 0;
        if (c.dataset.side === 'ledger') l += v; else b += v;
      });
      var ok = Math.abs(Math.round((l - b) * 100) / 100) < 0.005;
      var warn = card.querySelector('[data-warn]');
      if (warn) warn.style.display = (n > 0 && !ok) ? '' : 'none';
      if (n > 0) { anyTicked = true; ticked += n; if (!ok) allBalance = false; }
    });
    info.textContent = anyTicked
      ? ticked + ' lines ticked' + (allBalance ? ', every match still balances' : ' - a match would not balance')
      : 'Nothing ticked';
    info.className = 'balance ' + (!anyTicked ? '' : (allBalance ? 'ok' : 'off'));
    btn.disabled = !(anyTicked && allBalance);
  }
  form.addEventListener('change', function (e) {
    if (e.target.classList.contains('pick')) update();
  });
  update();
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
