<?php
// Follow one transaction through the sequence: what it was matched to, in which
// reconciliation, and what that in turn was matched to - until it stops being
// findable.
//
// It stops for one of three honest reasons, and says which:
//   - it is still open in the next reconciliation,
//   - its file takes part in nothing further,
//   - or the grain has collapsed, where one line answers many and the thread
//     is no longer about this transaction in particular.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/txnlist.php';

$pdo = db();

if (!files_ready() || !matchstate_ready()) {
    render_header('Trace');
    echo '<h1>Trace</h1><div class="panel"><p class="muted">Nothing to trace until files and '
       . 'matching are set up.</p></div>';
    render_footer();
    exit;
}

function trace_txn($id)
{
    $st = db()->prepare("SELECT t.*, f.name AS file_name
                         FROM rec_txns t LEFT JOIN rec_files f ON f.id = t.file_id
                         WHERE t.id = ?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

// Which reconciliations this transaction's file takes part in.
function recs_for_file($fileId)
{
    $st = db()->prepare("SELECT * FROM rec_recs
                         WHERE left_file_id = ? OR right_file_id = ? ORDER BY sort_order, name");
    $st->execute([(int)$fileId, (int)$fileId]);
    return $st->fetchAll();
}

// What this transaction was matched to in one reconciliation, if anything.
function counterparts($txnId, $recId)
{
    $st = db()->prepare("SELECT m.group_id, m.rule_ref, m.matched_at, g.group_no, g.rule_name, r.run_ref
                         FROM rec_matched m
                         JOIN rec_match_groups g ON g.id = m.group_id
                         JOIN rec_runs r ON r.id = m.run_id
                         WHERE m.txn_id = ? AND m.rec_id = ?");
    $st->execute([(int)$txnId, (int)$recId]);
    $m = $st->fetch();
    if (!$m) return null;

    // everything else in that match, both sides
    $st = db()->prepare("SELECT l.side, t.*, f.name AS file_name
                         FROM rec_match_lines l
                         JOIN rec_txns t ON t.id = l.txn_id
                         LEFT JOIN rec_files f ON f.id = t.file_id
                         WHERE l.group_id = ? ORDER BY t.txn_date, t.id");
    $st->execute([$m['group_id']]);
    $all = $st->fetchAll();

    $mine = $others = [];
    foreach ($all as $row) {
        if ((int)$row['id'] === (int)$txnId) { $mine[] = $row; continue; }
        // the other side of the match is what we follow
        $others[] = $row;
    }
    $m['same_side'] = $mine;
    $m['others']    = $others;
    return $m;
}

$startId = (int)($_GET['txn'] ?? 0);
$start   = $startId ? trace_txn($startId) : null;

// --- walk it ------------------------------------------------------------------
$steps   = [];
$visited = [];

// $cameVia is the reconciliation we arrived through. Without it the walk turns
// round and reports the link it just came down, which reads as a loop even
// though it is not one.
function walk($txn, $depth, &$steps, &$visited, $cameVia = null)
{
    if ($depth > 8 || !$txn || isset($visited[$txn['id']])) return;
    $visited[$txn['id']] = true;

    foreach (recs_for_file($txn['file_id']) as $rec) {
        if ($cameVia !== null && (int)$rec['id'] === (int)$cameVia) continue;

        $cp = counterparts($txn['id'], $rec['id']);
        $steps[] = ['depth' => $depth, 'txn' => $txn, 'rec' => $rec, 'match' => $cp];
        if (!$cp) continue;                                  // open here - the thread stops
        foreach ($cp['others'] as $other) {
            if (isset($visited[$other['id']])) continue;
            walk($other, $depth + 1, $steps, $visited, $rec['id']);
        }
    }
}

if ($start) walk($start, 0, $steps, $visited);

// --- finding somewhere to start ----------------------------------------------
$q    = trim($_GET['q'] ?? '');
$amt  = trim($_GET['amt'] ?? '');
$hits = [];
if (!$start && ($q !== '' || $amt !== '')) {
    $where = ['1=1'];
    $args  = [];
    if ($q !== '')   { $where[] = 't.description LIKE ?'; $args[] = '%' . $q . '%'; }
    if ($amt !== '' && is_numeric(str_replace(',', '', $amt))) {
        $where[] = 'ABS(t.value) = ?'; $args[] = abs((float)str_replace(',', '', $amt));
    }
    $st = $pdo->prepare("SELECT t.*, f.name AS file_name FROM rec_txns t
                         LEFT JOIN rec_files f ON f.id = t.file_id
                         WHERE " . implode(' AND ', $where) . " AND t.split_at IS NULL
                         ORDER BY t.txn_date DESC, t.id DESC LIMIT 50");
    $st->execute($args);
    $hits = $st->fetchAll();
}

render_header('Trace');
?>
<h1>Trace a transaction</h1>
<p class="muted">Follow one line through the sequence &mdash; what it was matched to, where, and what
  that was matched to in turn &mdash; until the thread runs out.</p>

<form method="get" class="panel" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
  <div style="flex:2;min-width:200px"><label>Description contains</label>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="e.g. SKYPORT"></div>
  <div><label>Amount (either sign)</label>
    <input type="text" name="amt" value="<?= h($amt) ?>" placeholder="149150.34"></div>
  <button class="btn" type="submit">Find</button>
  <?php if ($start): ?><a class="btn ghost" href="trace.php">Start again</a><?php endif; ?>
</form>

<?php if ($hits): ?>
  <div class="panel">
    <h2 style="margin-top:0">Pick one to follow</h2>
    <table>
      <thead><tr><th>Date</th><th>File</th><th>Description</th><th class="num">Value</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($hits as $hit): ?>
        <tr><td class="small"><?= h($hit['txn_date']) ?></td>
            <td class="small"><?= h($hit['file_name']) ?></td>
            <td class="desc" title="<?= h($hit['description']) ?>"><?= h($hit['description']) ?></td>
            <td class="num <?= $hit['value'] < 0 ? 'neg' : '' ?>"><?= money($hit['value']) ?></td>
            <td><a class="btn ghost small" href="?txn=<?= (int)$hit['id'] ?>">Follow this</a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php elseif (!$start && ($q !== '' || $amt !== '')): ?>
  <div class="panel"><p class="muted">Nothing found.</p></div>
<?php endif; ?>

<?php if ($start): ?>
  <div class="panel">
    <h2 style="margin-top:0">Starting from</h2>
    <p style="margin:0"><b><?= h($start['file_name']) ?></b> &middot; <?= h($start['txn_date']) ?>
      &middot; <?= h($start['description']) ?>
      &middot; <span class="num <?= $start['value'] < 0 ? 'neg' : '' ?>"><?= money($start['value']) ?></span></p>
  </div>

  <?php if (!$steps): ?>
    <div class="panel"><p class="muted">That transaction's file does not take part in any
      reconciliation yet, so there is nothing to follow.</p></div>
  <?php endif; ?>

  <?php foreach ($steps as $step):
      $t   = $step['txn'];
      $rec = $step['rec'];
      $cp  = $step['match'];
      $pad = $step['depth'] * 2;
  ?>
    <div class="panel" style="margin-left:<?= $pad ?>rem;
         border-left:3px solid <?= $cp ? 'var(--good)' : 'var(--warn)' ?>">
      <p class="small muted" style="margin:0 0 .3rem">
        <?= h($t['file_name']) ?> &middot; <?= h($t['txn_date']) ?> &middot;
        <?= h(mb_substr($t['description'], 0, 60)) ?> &middot; <?= money($t['value']) ?>
      </p>

      <?php if (!$cp): ?>
        <p style="margin:0"><b>Still open in <?= h($rec['name']) ?>.</b>
          <span class="muted small">The thread stops here &mdash; nothing has been matched to it yet.</span></p>
        <p class="small" style="margin:.3rem 0 0">
          <a href="transactions.php?switch_rec=<?= (int)$rec['id'] ?>">Work on that reconciliation</a></p>
      <?php else:
          $mine   = count($cp['same_side']) + 1;
          $theirs = count($cp['others']);
          $collapsed = $theirs > 1 || $mine > 1;
      ?>
        <p style="margin:0"><b>Matched in <?= h($rec['name']) ?></b>
          <span class="tag <?= is_numeric($cp['rule_ref']) ? '' : 'manual' ?>"><?php
            echo is_numeric($cp['rule_ref']) ? 'rule ' . h($cp['rule_ref']) : h($cp['rule_ref']); ?></span>
          <span class="muted small"><?= h($cp['run_ref']) ?> &middot;
            <?= h(substr((string)$cp['matched_at'], 0, 10)) ?></span></p>

        <?php if ($collapsed): ?>
          <p class="small" style="margin:.3rem 0 0;color:var(--warn)">
            This match covers <?= $mine ?> on one side and <?= $theirs ?> on the other, so from here
            the thread is about the group rather than this one transaction.</p>
        <?php endif; ?>

        <table style="margin-top:.4rem">
          <?php foreach (array_slice($cp['others'], 0, 25) as $o): ?>
            <tr><td class="small" style="width:6rem"><?= h($o['txn_date']) ?></td>
                <td class="small" style="width:12rem"><?= h($o['file_name']) ?></td>
                <td class="desc" title="<?= h($o['description']) ?>"><?= h($o['description']) ?></td>
                <td class="num <?= $o['value'] < 0 ? 'neg' : '' ?>"><?= money($o['value']) ?></td>
                <td><a class="btn ghost small" href="?txn=<?= (int)$o['id'] ?>">Follow</a></td></tr>
          <?php endforeach; ?>
        </table>
        <?php if ($theirs > 25): ?>
          <p class="small muted">Showing 25 of <?= $theirs ?>.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
