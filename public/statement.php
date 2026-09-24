<?php
// -----------------------------------------------------------------------------
// The reconciliation statement: as at a date, this balance against that one,
// the open items that explain the gap, and whatever is left over.
//
// Two ways of saying the same thing, because accountants read both:
//   - side by side, each balance corrected by the other side's open items;
//   - and as a bridge, walking from one side's balance to the other's.
//
// Snap keeps a copy of it for ever.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/statements.php';

$ready = statements_ready();

// --- what is being looked at --------------------------------------------------

// A balance typed as 1,234.56 or (1,234.56) or blank.
function read_balance($raw)
{
    $s = trim((string)$raw);
    if ($s === '') return null;
    $neg = (strpos($s, '(') !== false);
    $s = str_replace([',', ' ', '(', ')', "\xC2\xA3", '$'], '', $s);
    if (!is_numeric($s)) return null;
    return $neg ? -abs((float)$s) : (float)$s;
}

// The latest date on either side, which is nearly always the date wanted.
function latest_txn_date()
{
    $st = db()->query("SELECT MAX(t.txn_date) FROM rec_txns t
                       WHERE (" . file_where('ledger', 't') . " OR " . file_where('bank', 't') . ")"
                       . not_split('t'));
    return $st->fetchColumn() ?: date('Y-m-d');
}

$src   = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$asAt  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($src['as_at'] ?? '')) ? $src['as_at'] : latest_txn_date();
$lRaw  = (string)($src['l_bal'] ?? '');
$bRaw  = (string)($src['b_bal'] ?? '');

// --- taking a snap, or removing one -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    if (($_POST['action'] ?? '') === 'snap') {
        $f = statement_figures($asAt, read_balance($lRaw), read_balance($bRaw));
        [$ok, $msg, $id] = save_snap($f, $_POST['note'] ?? '');
        flash($msg);
        header('Location: statement.php?' . http_build_query(
            $ok ? ['snap' => $id] : ['as_at' => $asAt, 'l_bal' => $lRaw, 'b_bal' => $bRaw]));
        exit;
    }
    if (($_POST['action'] ?? '') === 'delete_snap') {
        [$ok, $msg] = delete_snap((int)($_POST['id'] ?? 0));
        flash($msg);
        header('Location: statement.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        [$ok, $msg] = approve_snap($id, $_POST['approved_note'] ?? '');
        flash($msg);
        header('Location: statement.php?snap=' . $id);
        exit;
    }
    if (($_POST['action'] ?? '') === 'withdraw') {
        $id = (int)($_POST['id'] ?? 0);
        [$ok, $msg] = withdraw_approval($id);
        flash($msg);
        header('Location: statement.php?snap=' . $id);
        exit;
    }
}

// --- a snap, or the live position ---------------------------------------------
$snap = $ready && isset($_GET['snap']) ? get_snap((int)$_GET['snap']) : null;

if ($snap) {
    $f = [
        'as_at'     => $snap['as_at'],
        'l_label'   => $snap['left_label']  ?: side_label('ledger'),
        'b_label'   => $snap['right_label'] ?: side_label('bank'),
        'l_bal'     => (float)$snap['left_balance'],  'b_bal' => (float)$snap['right_balance'],
        'l_derived' => (int)$snap['left_derived'],    'b_derived' => (int)$snap['right_derived'],
        'l_blocks'  => snap_blocks($snap['id'], 'ledger'),
        'b_blocks'  => snap_blocks($snap['id'], 'bank'),
        'l_open'    => (float)$snap['left_open'],     'b_open' => (float)$snap['right_open'],
        'l_adj'     => (float)$snap['left_adj'],      'b_adj'  => (float)$snap['right_adj'],
        'unexplained' => (float)$snap['unexplained'],
    ];
} else {
    $f = statement_figures($asAt, read_balance($lRaw), read_balance($bRaw));
}

// --- the same thing as a file -------------------------------------------------
if (isset($_GET['csv'])) {
    $rec  = current_rec();
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-',
        ($rec['name'] ?? 'reconciliation') . ' statement ' . $f['as_at']), '-');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $out = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    $put = function ($row) use ($out) { fputcsv($out, $row); };
    $num = fn($v) => number_format((float)$v, 2, '.', '');

    $put([($rec['name'] ?? 'Reconciliation') . ' - reconciliation statement']);
    $put(['As at', date('j M Y', strtotime($f['as_at']))]);
    if ($snap) $put(['Snapped', date('j M Y H:i', strtotime($snap['created_at'])),
                     'by', user_name($snap['created_by'])]);
    if ($snap && $snap['approved_at']) {
        $put(['Approved', date('j M Y H:i', strtotime($snap['approved_at'])),
              'by', user_name($snap['approved_by']),
              self_approved($snap) ? 'the same person who took it' : '']);
        if (!empty($snap['approved_note'])) $put(['Approval note', $snap['approved_note']]);
    } elseif ($snap) {
        $put(['Approved', 'not yet']);
    }
    $put([]);
    foreach ([['l', 'b'], ['b', 'l']] as [$me, $other]) {
        $put(['Balance per ' . $f[$me . '_label'], $num($f[$me . '_bal']),
              $f[$me . '_derived'] ? 'added up from the items' : 'entered']);
        $put(['Add: ' . $f[$other . '_label'] . ' items not yet in the ' . $f[$me . '_label']]);
        foreach ($f[$other . '_blocks'] as $bl) {
            foreach ($bl['items'] as $it) {
                $put([date('d/m/Y', strtotime($it['txn_date'])), $it['description'],
                      $num($it['value']), $bl['ref'] ?: '', $bl['ref'] ? $bl['note'] : '']);
            }
        }
        $put(['Total reconciling items', $num($f[$other . '_open'])]);
        $put(['Adjusted ' . $f[$me . '_label'], $num($f[$me . '_adj'])]);
        $put([]);
    }
    $put(['Unexplained difference', $num($f['unexplained'])]);
    if ($snap && $snap['note']) { $put([]); $put(['Note', $snap['note']]); }
    fclose($out);
    exit;
}

// For the hint under each balance box: what the items themselves come to.
$derL = $snap ? null : derived_balance('ledger', $asAt);
$derB = $snap ? null : derived_balance('bank',   $asAt);

$snaps = $ready ? list_snaps() : [];

render_header('Statement');
?>
<h1>Reconciliation statement</h1>

<?php if (!$ready): ?>
  <div class="panel"><p class="muted">The database has not been updated for statements yet.
    <?= is_admin() ? '<a href="database.php">Apply what is waiting</a>.' : 'Ask an administrator to apply the waiting change.' ?></p></div>
  <?php render_footer(); exit;
endif; ?>

<?php if ($snap): ?>
  <div class="panel" style="background:#f6f7f9">
    <b>Snap of <?= h(date('j M Y', strtotime($snap['as_at']))) ?></b>
    <span class="muted">&middot; taken <?= h(date('j M Y \a\t H:i', strtotime($snap['created_at']))) ?>
      by <?= h(user_name($snap['created_by']) ?: 'someone') ?></span>
    <?php if ($snap['approved_at']): ?>
      <span class="pos">&middot; approved <?= h(date('j M Y', strtotime($snap['approved_at']))) ?>
        by <?= h(user_name($snap['approved_by']) ?: 'someone') ?></span>
      <?php if (self_approved($snap)): ?>
        <span class="muted small">(the same person who took it)</span>
      <?php endif; ?>
    <?php endif; ?>
    <a class="btn ghost small" style="float:right" href="statement.php">Back to the live statement</a>
    <p class="small muted" style="margin:.4rem 0 0">This is a copy taken at the time. Nothing that has
      happened since has changed it, and nothing ever will.</p>
    <?php if ($snap['note']): ?><p style="margin:.5rem 0 0"><?= nl2br(h($snap['note'])) ?></p><?php endif; ?>
  </div>
<?php else: ?>
<form method="get" class="panel" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap">
  <div><label>As at</label>
    <input type="date" name="as_at" value="<?= h($asAt) ?>"></div>
  <div><label>Balance per <?= h($f['l_label']) ?></label>
    <input type="text" name="l_bal" id="lbal" value="<?= h($lRaw) ?>" placeholder="leave blank to add it up"
           style="text-align:right">
    <span class="small muted">items come to
      <a href="#" onclick="document.getElementById('lbal').value='<?= number_format($derL, 2, '.', '') ?>';return false"><?= money($derL) ?></a></span></div>
  <div><label>Balance per <?= h($f['b_label']) ?></label>
    <input type="text" name="b_bal" id="bbal" value="<?= h($bRaw) ?>" placeholder="leave blank to add it up"
           style="text-align:right">
    <span class="small muted">items come to
      <a href="#" onclick="document.getElementById('bbal').value='<?= number_format($derB, 2, '.', '') ?>';return false"><?= money($derB) ?></a></span></div>
  <button class="btn" type="submit">Show</button>
  <a class="btn ghost" href="statement.php">Reset</a>
</form>
<p class="small muted" style="margin-top:-.5rem">Type the balances from the bank statement and the
  ledger. Left blank, a balance is added up from the items loaded &mdash; and then the unexplained
  line below has to come to zero, so it stops being a check.</p>
<?php endif; ?>

<?php
// One side: its balance, the OTHER side's open items, and the adjusted figure.
function side_block($f, $me, $other)
{
    $label = $f[$me . '_label'];
    $oth   = $f[$other . '_label'];
    ob_start(); ?>
  <div class="panel">
    <div class="side-head"><h2 style="margin:0">Per <?= h($label) ?></h2>
      <span class="muted small"><?= $f[$me . '_derived'] ? 'added up from the items' : 'as entered' ?></span></div>
    <table class="statement">
      <tbody>
        <tr class="bal"><td>Balance per <?= h($label) ?> </td>
            <td class="num <?= $f[$me . '_bal'] < 0 ? 'neg' : '' ?>"><?= money($f[$me . '_bal']) ?></td></tr>
        <tr class="head"><td colspan="2"><?= h($oth) ?> items not yet in the <?= h($label) ?></td></tr>
      <?php if (!$f[$other . '_blocks']): ?>
        <tr><td class="muted" colspan="2">None &mdash; everything on that side is matched.</td></tr>
      <?php endif; ?>
      <?php foreach ($f[$other . '_blocks'] as $bl): ?>
        <?php if ($bl['ref']): ?>
          <tr class="grp"><td><b><?= h($bl['ref']) ?></b> <?= h($bl['note']) ?>
              <span class="muted small">(<?= count($bl['items']) ?> item<?= count($bl['items']) === 1 ? '' : 's' ?>)</span></td>
              <td class="num <?= $bl['total'] < 0 ? 'neg' : '' ?>"><?= money($bl['total']) ?></td></tr>
          <?php foreach ($bl['items'] as $it): ?>
            <tr class="sub"><td><span class="muted"><?= h(date('d/m/Y', strtotime($it['txn_date']))) ?></span>
                <?= h($it['description']) ?></td>
                <td class="num muted"><?= money($it['value']) ?></td></tr>
          <?php endforeach; ?>
        <?php else: $it = $bl['items'][0]; ?>
          <tr><td><span class="muted"><?= h(date('d/m/Y', strtotime($it['txn_date']))) ?></span>
              <?= h($it['description']) ?></td>
              <td class="num <?= $it['value'] < 0 ? 'neg' : '' ?>"><?= money($it['value']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
        <tr class="sum"><td><?= h($oth) ?> items, in total</td>
            <td class="num"><?= money($f[$other . '_open']) ?></td></tr>
        <tr class="bal total"><td>Adjusted <?= h($label) ?></td>
            <td class="num"><?= money($f[$me . '_adj']) ?></td></tr>
      </tbody>
    </table>
  </div>
<?php return ob_get_clean();
}
$flat = abs($f['unexplained']) < 0.005;
?>

<div class="two-up">
  <?= side_block($f, 'l', 'b') ?>
  <?= side_block($f, 'b', 'l') ?>
</div>

<div class="panel <?= $flat ? '' : 'warn' ?>" style="text-align:center">
  <div class="small muted">Adjusted <?= h($f['l_label']) ?> less adjusted <?= h($f['b_label']) ?></div>
  <div style="font-size:1.6rem;font-weight:700" class="<?= $flat ? 'pos' : 'neg' ?>">
    <?= money($flat ? 0 : $f['unexplained']) ?></div>
  <div><b>Unexplained difference</b></div>
  <p class="small muted" style="margin:.4rem 0 0">
    <?= $flat
        ? 'The two sides agree once the reconciling items are taken into account. Nothing is unaccounted for.'
        : 'Something is wrong that has not been identified. Either a balance is not what was entered, or an item is missing from one of the files.' ?></p>
</div>

<h2>From <?= h($f['l_label']) ?> to <?= h($f['b_label']) ?></h2>
<p class="muted">The same statement walked through in one line of thought, which is how it usually
  gets written up.</p>
<div class="panel">
  <table class="statement">
    <tbody>
      <tr class="bal"><td>Balance per <?= h($f['l_label']) ?></td>
          <td class="num"><?= money($f['l_bal']) ?></td></tr>
      <tr><td>Less: <?= h($f['l_label']) ?> items not on the <?= h($f['b_label']) ?>
          <span class="muted small">(<?= count($f['l_blocks']) ?> lines)</span></td>
          <td class="num"><?= money(-$f['l_open']) ?></td></tr>
      <tr><td>Add: <?= h($f['b_label']) ?> items not in the <?= h($f['l_label']) ?>
          <span class="muted small">(<?= count($f['b_blocks']) ?> lines)</span></td>
          <td class="num"><?= money($f['b_open']) ?></td></tr>
      <tr class="sum"><td>Balance the <?= h($f['b_label']) ?> should show</td>
          <td class="num"><?= money($f['l_bal'] - $f['l_open'] + $f['b_open']) ?></td></tr>
      <tr class="bal"><td>Balance per <?= h($f['b_label']) ?></td>
          <td class="num"><?= money($f['b_bal']) ?></td></tr>
      <tr class="bal total"><td>Unexplained difference</td>
          <td class="num <?= $flat ? 'pos' : 'neg' ?>"><?= money($flat ? 0 : $f['unexplained']) ?></td></tr>
    </tbody>
  </table>
</div>

<div class="panel" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
  <a class="btn ghost" href="?<?= h(http_build_query($snap
        ? ['snap' => $snap['id'], 'csv' => 1]
        : ['as_at' => $asAt, 'l_bal' => $lRaw, 'b_bal' => $bRaw, 'csv' => 1])) ?>">Download</a>
  <?php if (!$snap): ?>
    <a class="btn ghost" href="transactions.php?<?= h(http_build_query(['to' => $asAt, 'show' => 'open'])) ?>">See the
      open items</a>
  <?php endif; ?>
  <?php if ($snap && is_admin() && !$snap['approved_at']): ?>
    <form method="post" style="margin-left:auto" onsubmit="return confirm('Remove this snap for good?')">
      <input type="hidden" name="action" value="delete_snap">
      <input type="hidden" name="id" value="<?= (int)$snap['id'] ?>">
      <button class="btn ghost" type="submit">Remove this snap</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($snap): ?>
<h2>Approval</h2>
<?php if ($snap['approved_at']): ?>
  <div class="panel" style="background:#eef6ee;border-color:#cfe3cf">
    <p style="margin:0"><b>Approved</b> by <?= h(user_name($snap['approved_by']) ?: 'someone') ?>
      on <?= h(date('j M Y \a\t H:i', strtotime($snap['approved_at']))) ?>.
      <?php if (self_approved($snap)): ?>
        <span class="muted">This was approved by the same person who took it, which is recorded
          here rather than glossed over.</span>
      <?php endif; ?></p>
    <?php if (!empty($snap['approved_note'])): ?>
      <p style="margin:.5rem 0 0"><?= nl2br(h($snap['approved_note'])) ?></p>
    <?php endif; ?>
    <p class="small muted" style="margin:.5rem 0 0">An approved snap cannot be removed.</p>
    <?php if (is_admin()): ?>
      <form method="post" style="margin-top:.5rem"
            onsubmit="return confirm('Withdraw this approval? It goes in the log.')">
        <input type="hidden" name="action" value="withdraw">
        <input type="hidden" name="id" value="<?= (int)$snap['id'] ?>">
        <button class="btn ghost small" type="submit">Withdraw the approval</button>
      </form>
    <?php endif; ?>
  </div>
<?php else: ?>
  <p class="muted">Approving says you have looked at this statement and accept it. It is the second
    pair of eyes that makes a reconciliation worth something to an auditor, so it is kept separately
    from taking the snap &mdash; and once approved, the snap can no longer be removed.</p>
  <form method="post" class="panel" onsubmit="this.querySelector('button').disabled=true">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="id" value="<?= (int)$snap['id'] ?>">
    <label>What you have checked <span class="muted small">(optional)</span></label>
    <textarea name="approved_note" rows="2"
      placeholder="e.g. agreed to the March statement; the 86.60 is the disputed carriage charge"></textarea>
    <?php if (current_user_id() !== null && (int)$snap['created_by'] === (int)current_user_id()): ?>
      <p class="small muted">You took this snap yourself. You can still approve it &mdash; plenty of
        people work alone &mdash; and it will say so.</p>
    <?php endif; ?>
    <?php if (abs((float)$snap['unexplained']) >= 0.005): ?>
      <p class="small" style="color:#a6431f">This statement has an unexplained difference of
        <?= money($snap['unexplained']) ?>. Approving it accepts that difference.</p>
    <?php endif; ?>
    <button class="btn" type="submit">Approve this snap</button>
  </form>
<?php endif; ?>
<?php endif; ?>

<?php if (!$snap): ?>
<h2>Snap it</h2>
<p class="muted">A snap keeps this page exactly as it reads now &mdash; both balances, every
  reconciling item, the difference, and who took it. Later matching never changes it, so it stands
  as the record of what was reconciled and when.</p>
<form method="post" class="panel" onsubmit="this.querySelector('button').disabled=true">
  <input type="hidden" name="action" value="snap">
  <input type="hidden" name="as_at" value="<?= h($asAt) ?>">
  <input type="hidden" name="l_bal" value="<?= h($lRaw) ?>">
  <input type="hidden" name="b_bal" value="<?= h($bRaw) ?>">
  <label>Anything worth saying about this one <span class="muted small">(optional)</span></label>
  <textarea name="note" rows="2" placeholder="e.g. March reconciliation, bank charges still to be posted"></textarea>
  <?php if (!$flat): ?>
    <p class="small" style="color:#a6431f">There is an unexplained difference of <?= money($f['unexplained']) ?>.
      You can still snap it &mdash; a snap records the position, whatever it is.</p>
  <?php endif; ?>
  <button class="btn" type="submit">Snap as at <?= h(date('j M Y', strtotime($asAt))) ?></button>
</form>
<?php endif; ?>

<h2>Snaps taken</h2>
<?php if (!$snaps): ?>
  <div class="panel"><p class="muted">None yet.</p></div>
<?php else: ?>
<div class="panel">
<table>
  <thead><tr><th>As at</th><th class="num">Unexplained</th><th class="num">Items</th>
    <th>Taken</th><th>Approved</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($snaps as $s): $ok = abs((float)$s['unexplained']) < 0.005; ?>
    <tr<?= $snap && $snap['id'] == $s['id'] ? ' style="background:#f6f7f9"' : '' ?>>
      <td><a href="?snap=<?= (int)$s['id'] ?>"><?= h(date('j M Y', strtotime($s['as_at']))) ?></a>
        <?php if ($s['note']): ?><br><span class="small muted"><?= h($s['note']) ?></span><?php endif; ?></td>
      <td class="num <?= $ok ? 'pos' : 'neg' ?>"><?= money($ok ? 0 : $s['unexplained']) ?></td>
      <td class="num muted"><?= (int)$s['n'] ?></td>
      <td class="small"><?= h(date('d/m/Y', strtotime($s['created_at']))) ?>
        <span class="muted"><?= h(user_name($s['created_by']) ?: '') ?></span></td>
      <td class="small"><?php if ($s['approved_at']): ?>
          <?= h(date('d/m/Y', strtotime($s['approved_at']))) ?>
          <span class="muted"><?= h(user_name($s['approved_by']) ?: '') ?></span>
          <?php if (self_approved($s)): ?><br><span class="muted">by whoever took it</span><?php endif; ?>
        <?php else: ?>
          <a href="?snap=<?= (int)$s['id'] ?>">Not yet</a>
        <?php endif; ?></td>
      <td class="num"><a class="btn ghost small" href="?<?= h(http_build_query(['snap' => $s['id'], 'csv' => 1])) ?>">Download</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="small muted">Open a snap to approve it. Anyone signed in can &mdash; a reconciliation
  prepared and reviewed by the same person is still worth signing, and the page says plainly when
  that is what happened.</p>
<?php endif; ?>
<?php render_footer(); ?>
