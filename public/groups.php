<?php
// The group notes: several items that belong to the same issue, with one note
// covering them. This is the list of what is outstanding and why.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/issues.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        if (($_POST['action'] ?? '') === 'note') {
            [$ok, $msg] = update_issue_note($id, $_POST['note'] ?? '');
            if (!$ok) throw new RuntimeException($msg);
            log_event('changed a group note', (get_issue($id)['ref'] ?? '') . '');
            flash($msg);
        } elseif (($_POST['action'] ?? '') === 'delete') {
            [$ok, $msg] = delete_issue($id);
            if (!$ok) throw new RuntimeException($msg);
            log_event('removed a group note', $msg);
            flash($msg);
        }
        header('Location: groups.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$issues = issues_ready() ? list_issues() : [];

render_header('Group notes');
?>
<h1>Group notes</h1>
<p class="muted">One note covering several items that are all part of the same issue, on either side or
  both. Tick the items on the <a href="transactions.php">Transactions</a> screen and press
  <b>Group note</b> to make one. Grouping is not matching: these items usually do not balance, which is
  generally why there is a note.</p>

<?php if (!issues_ready()): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>One small database change is still to run.</b> An administrator can apply it on
      the <a href="database.php">Database</a> page.</p>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p>
<?php endif; ?>

<?php if (issues_ready() && !$issues): ?>
  <div class="panel"><p class="muted" style="margin:0">No group notes yet.</p></div>
<?php endif; ?>

<?php foreach ($issues as $g):
    $diff    = (float)$g['l_total'] - (float)$g['b_total'];
    $settled = (int)$g['still_open'] === 0;
?>
  <div class="panel">
    <div class="side-head">
      <h2 style="margin:0"><?= h($g['ref']) ?>
        <span class="tag" style="vertical-align:middle"><?= $settled ? 'settled' : 'open' ?></span></h2>
      <span class="muted small"><?= (int)$g['n'] ?> item<?= (int)$g['n'] === 1 ? '' : 's' ?>
        &middot; <?= h(substr((string)$g['created_at'], 0, 16)) ?>
        <?php if ($who = user_name($g['created_by'] ?? null)): ?>&middot; <?= h($who) ?><?php endif; ?></span>
    </div>

    <div class="sides" style="margin:.4rem 0">
      <div class="small"><b><?= h(side_label('ledger')) ?></b>:
        <?= (int)$g['l_n'] ?> item<?= (int)$g['l_n'] === 1 ? '' : 's' ?>,
        <span class="num <?= $g['l_total'] < 0 ? 'neg' : '' ?>"><?= money($g['l_total']) ?></span></div>
      <div class="small"><b><?= h(side_label('bank')) ?></b>:
        <?= (int)$g['b_n'] ?> item<?= (int)$g['b_n'] === 1 ? '' : 's' ?>,
        <span class="num <?= $g['b_total'] < 0 ? 'neg' : '' ?>"><?= money($g['b_total']) ?></span></div>
    </div>
    <p style="margin:.2rem 0">
      <span class="balance <?= abs($diff) < 0.005 ? 'ok' : 'off' ?>">Difference <?= money(abs($diff) < 0.005 ? 0 : $diff) ?></span>
      <?php if (!$settled): ?>
        <span class="muted small"><?= (int)$g['still_open'] ?> of them still to be matched</span>
      <?php else: ?>
        <span class="muted small">everything in it has been matched</span>
      <?php endif; ?>
    </p>

    <form method="post">
      <input type="hidden" name="action" value="note">
      <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
      <label>The note</label>
      <textarea name="note" style="min-height:5rem"><?= h((string)$g['note']) ?></textarea>
      <div class="actions">
        <button class="btn" type="submit">Save the note</button>
        <a class="btn ghost" href="transactions.php?grp=<?= (int)$g['id'] ?>&amp;show=both">See its items</a>
      </div>
    </form>
    <form method="post" onsubmit="return confirm('Remove <?= h($g['ref']) ?>? Its items stay, but they will no longer be grouped.')"
          style="margin-top:.4rem">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
      <button class="btn ghost small" type="submit" style="color:var(--bad);border-color:var(--bad)">Remove the group</button>
    </form>
  </div>
<?php endforeach; ?>
<?php render_footer(); ?>
