<?php
// What a fresh load of a rolling file would change, before any of it is applied.
//
// Everything safe is ticked. Anything whose amount has changed on a line that
// is already matched is held back, because applying it would leave a committed
// match that no longer balances.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/rolling.php';

$pdo   = db();
$error = null;

if (!rolling_ready()) {
    render_header('Reload');
    ?>
    <h1>Reload a rolling file</h1>
    <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
      <p style="margin:0"><b>One database change is needed first.</b>
        Open the <a href="database.php">Database</a> page and apply what is waiting.</p>
    </div>
    <?php
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if (!get_file($fileId)) throw new RuntimeException('That file no longer exists.');

        if (($_POST['action'] ?? '') === 'apply') {
            // the ids come as one comma-separated field, because a long load
            // would otherwise go past PHP's limit on how many fields it reads
            $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
            [$n, $msg] = apply_stage($fileId, $ids);
            flash($msg);
            header('Location: reload.php?file=' . $fileId);
            exit;
        }
        if (($_POST['action'] ?? '') === 'discard') {
            clear_stage($fileId);
            if (function_exists('log_event')) log_event('discarded a reload', get_file($fileId)['name']);
            flash('That load has been thrown away. Nothing was changed.');
            header('Location: reload.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$waiting = stage_files();
$fileId  = (int)($_GET['file'] ?? ($waiting[0]['file_id'] ?? 0));
$file    = $fileId ? get_file($fileId) : null;
$summary = $file ? stage_summary($fileId) : [];
$rows    = $file ? stage_rows($fileId) : [];
$labels  = $file ? file_extra_labels($fileId) : [];

render_header('Reload');
?>
<h1>Reload a rolling file</h1>
<p class="muted">A rolling file is one you load again and again. A fresh load is compared with what is
  already there, record by record, and nothing is applied until you say so. Records that have not
  changed are counted and otherwise ignored. Loading the same file again replaces whatever is waiting
  here, so you always decide against the newest load.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if (!$waiting): ?>
  <div class="panel"><p class="muted" style="margin:0">No load is waiting to be looked at.
    Mark a file as rolling on the <a href="files.php">Files</a> page, say which fields identify a
    record, then load it on the <a href="import.php">Import</a> page as usual.</p></div>
  <?php render_footer(); exit; ?>
<?php endif; ?>

<?php if (count($waiting) > 1): ?>
  <div class="panel" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
    <span class="muted small">Loads waiting:</span>
    <?php foreach ($waiting as $w): ?>
      <a class="btn <?= (int)$w['file_id'] === $fileId ? '' : 'ghost' ?> small"
         href="?file=<?= (int)$w['file_id'] ?>"><?= h($w['name']) ?>
        (<?= number_format((int)$w['n'] - (int)$w['same_n']) ?>)</a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($file):
    $same    = (int)($summary['same'] ?? 0);
    $new     = (int)($summary['new'] ?? 0);
    $amend   = (int)($summary['amended'] ?? 0);
    $blocked = (int)($summary['blocked'] ?? 0);
?>
  <div class="panel">
    <h2 style="margin-top:0"><?= h($file['name']) ?></h2>
    <p class="muted small" style="margin-top:-.4rem">Loaded from
      <b><?= h((string)($rows[0]['source_file'] ?? $waiting[0]['source_file'] ?? 'the file you chose')) ?></b>.
      A record is recognised by <b><?php
        $kc = rolling_key_choices($fileId);
        echo h(implode(' + ', array_map(fn($c) => $kc[$c] ?? $c, file_key_fields($fileId)))); ?></b>.</p>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <span class="balance"><?= number_format($same) ?> unchanged</span>
      <span class="balance <?= $new ? 'ok' : '' ?>"><?= number_format($new) ?> new</span>
      <span class="balance <?= $amend ? 'ok' : '' ?>"><?= number_format($amend) ?> amended</span>
      <span class="balance <?= $blocked ? 'off' : '' ?>"><?= number_format($blocked) ?> held back</span>
    </div>
    <?php if ($blocked): ?>
      <p class="small" style="color:var(--bad);margin-bottom:0">The ones held back have a changed amount
        on a line that is already matched. Applying them would leave a match that no longer balances, so
        they cannot be ticked. Unmatch those lines first &mdash; from the
        <a href="matches.php">Matches</a> screen, a whole match at a time &mdash; then load the file
        again.</p>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="panel"><p class="muted" style="margin:0">&#10003; Nothing in that load differs from what
      you already have.</p>
      <form method="post" style="margin-top:.5rem">
        <input type="hidden" name="action" value="discard">
        <input type="hidden" name="file_id" value="<?= $fileId ?>">
        <button class="btn ghost" type="submit">Clear it away</button>
      </form>
    </div>
  <?php else: ?>
  <form method="post" id="stageForm">
    <input type="hidden" name="action" value="apply">
    <input type="hidden" name="file_id" value="<?= $fileId ?>">
    <input type="hidden" name="ids" id="stageIds">

    <div class="panel" style="position:sticky;top:0;z-index:5;display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
      <button class="btn" type="submit">Apply ticked</button>
      <span class="balance" id="stageCount"></span>
      <span class="muted small">Anything left unticked stays here until you come back to it.</span>
      <span style="margin-left:auto">
        <button class="btn ghost" type="submit" form="discardForm"
          onclick="return confirm('Throw this whole load away? Nothing will be changed.')"
          style="color:var(--bad);border-color:var(--bad)">Throw the load away</button></span>
    </div>

    <div class="panel">
      <div class="scroll" style="max-height:70vh">
        <table>
          <thead><tr>
            <th style="width:2.5rem"><input type="checkbox" id="stageAll" checked
                title="Tick or untick everything that can be applied"></th>
            <th>What it is</th><th>Date</th><th>Description</th><th class="num">Amount</th>
            <?php foreach ($labels as $label): ?><th><?= h($label) ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $s):
              $changes = $s['txn_id'] ? stage_changes($s) : [];
              $ch = array_flip($changes);
              $can = $s['verdict'] !== 'blocked';
              $cell = function ($new, $old, $isChanged, $num = false) {
                  $c = $num ? 'num' : 'small';
                  $out = '<td class="' . $c . ($isChanged ? ' pv-both' : '') . '">' . h((string)$new);
                  if ($isChanged) {
                      $out .= '<br><span class="muted small" style="text-decoration:line-through">'
                            . h((string)$old) . '</span>';
                  }
                  return $out . '</td>';
              };
          ?>
            <tr<?= $can ? '' : ' style="opacity:.75"' ?>>
              <td class="center"><input type="checkbox" class="stagetick" value="<?= (int)$s['id'] ?>"
                <?= $can ? 'checked' : 'disabled title="Already matched - unmatch it first"' ?>></td>
              <td class="small">
                <span class="tag <?= $s['verdict'] === 'new' ? 'manual' : '' ?>"><?= h($s['verdict'] === 'blocked' ? 'held back' : $s['verdict']) ?></span>
                <?php if ($changes): ?><br><span class="muted small"><?php
                  $kc = rolling_key_choices($fileId);
                  echo h(implode(', ', array_map(fn($c) => $kc[$c] ?? $c, $changes))) . ' changed'; ?></span><?php endif; ?>
                <?php if ($s['note']): ?><br><span class="small" style="color:var(--warn)"><?= h($s['note']) ?></span><?php endif; ?>
              </td>
              <?= $cell($s['txn_date'], $s['old_date'], isset($ch['date'])) ?>
              <?= $cell($s['description'], $s['old_desc'], isset($ch['description'])) ?>
              <?= $cell(money($s['value']), money($s['old_value'] ?? 0), isset($ch['amount']), true) ?>
              <?php foreach (array_keys($labels) as $k): ?>
                <?= $cell($s[$k] ?? '', $s['old_' . $k] ?? '', isset($ch[$k])) ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="small muted" style="margin-bottom:0">A changed value is shown with what it was
        underneath it. New records go in as their own batch, so they can be removed like any import.
        An amended date or description keeps what the file first said, and the line is marked with a
        <b>*</b> on the transactions screen.</p>
    </div>
  </form>

  <form method="post" id="discardForm">
    <input type="hidden" name="action" value="discard">
    <input type="hidden" name="file_id" value="<?= $fileId ?>">
  </form>

  <script>
  (function () {
    var form = document.getElementById('stageForm');
    var all  = document.getElementById('stageAll');
    var out  = document.getElementById('stageCount');
    function ticks() { return Array.prototype.slice.call(form.querySelectorAll('.stagetick:not([disabled])')); }
    function update() {
      var on = ticks().filter(function (c) { return c.checked; });
      out.textContent = on.length + ' of ' + ticks().length + ' ticked';
      out.className = 'balance' + (on.length ? ' ok' : '');
    }
    all.addEventListener('change', function () {
      ticks().forEach(function (c) { c.checked = all.checked; });
      update();
    });
    form.addEventListener('change', function (e) {
      if (e.target.classList.contains('stagetick')) update();
    });
    // one compact field rather than one per row, so a long load still arrives whole
    form.addEventListener('submit', function () {
      document.getElementById('stageIds').value =
        ticks().filter(function (c) { return c.checked; }).map(function (c) { return c.value; }).join(',');
    });
    update();
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
