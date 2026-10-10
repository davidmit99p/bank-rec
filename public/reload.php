<?php
// What a fresh load of a rolling file would change, before any of it is applied.
//
// The key is tunable here, because which fields the source system leaves alone
// is something you only learn by looking at a real load: change it, compare
// again, read the counts. Nothing is written until you tick and press.
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
        $act = $_POST['action'] ?? '';

        // which fields may be written, from the tick boxes by the Apply button
        $allow = isset($_POST['allow'])
            ? array_values(array_intersect(array_keys(rolling_writable_fields($fileId)),
                                           (array)$_POST['allow']))
            : null;

        if ($act === 'apply' || $act === 'free') {
            // the ids come as one comma-separated field, because a long load
            // would otherwise go past PHP's limit on how many fields it reads
            $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
            [$n, $msg] = $act === 'free'
                ? unmatch_and_apply($fileId, $ids, $allow)
                : apply_stage($fileId, $ids, $allow);
            flash($msg);
            header('Location: reload.php?file=' . $fileId);
            exit;
        }
        if ($act === 'recompare') {
            [$ok, $res] = recompare($fileId, (array)($_POST['keyf'] ?? []),
                                    (array)($_POST['ignoref'] ?? []),
                                    !empty($_POST['remember']));
            if (!$ok) throw new RuntimeException($res);
            flash('Compared again: ' . number_format($res['same']) . ' unchanged, '
                . number_format($res['new']) . ' new, ' . number_format($res['amended'])
                . ' amended, ' . number_format($res['blocked']) . ' held back.'
                . (!empty($_POST['remember']) ? ' Saved as this file\'s usual setting.' : ''));
            header('Location: reload.php?file=' . $fileId);
            exit;
        }
        if ($act === 'pair' || $act === 'accept') {
            $stageId = (int)($_POST['stage_id'] ?? 0);
            $txnId   = $act === 'accept' ? (int)($_POST['suggest'] ?? 0) : (int)($_POST['txn_id'] ?? 0);
            if (!$txnId) throw new RuntimeException('Choose the record it belongs to.');
            [$ok, $msg] = pair_stage($fileId, $stageId, $txnId);
            if (!$ok) throw new RuntimeException($msg);
            flash($msg);
            header('Location: reload.php?file=' . $fileId);
            exit;
        }
        if ($act === 'unpair') {
            [$ok, $msg] = unpair_stage($fileId, (int)($_POST['stage_id'] ?? 0));
            if (!$ok) throw new RuntimeException($msg);
            flash($msg);
            header('Location: reload.php?file=' . $fileId);
            exit;
        }
        if ($act === 'discard') {
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
$choices = $file ? rolling_key_choices($fileId) : [];
$keys    = $file ? active_key_fields($fileId) : [];
$ignored = $file ? active_ignore_fields($fileId) : [];
$meta    = $file ? stage_load_meta($fileId) : null;
$cands   = ($file && rolling_tune_ready()) ? pair_candidates($fileId) : [];

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
    $done    = (int)($summary['done'] ?? 0);
?>
  <div class="panel">
    <h2 style="margin-top:0"><?= h($file['name']) ?></h2>
    <p class="muted small" style="margin-top:-.4rem">Loaded from
      <b><?= h((string)($meta['source_file'] ?? $rows[0]['source_file'] ?? 'the file you chose')) ?></b>.</p>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <span class="balance"><?= number_format($same) ?> unchanged</span>
      <span class="balance <?= $new ? 'ok' : '' ?>"><?= number_format($new) ?> new</span>
      <span class="balance <?= $amend ? 'ok' : '' ?>"><?= number_format($amend) ?> amended</span>
      <span class="balance <?= $blocked ? 'off' : '' ?>"><?= number_format($blocked) ?> held back</span>
      <?php if ($done): ?><span class="balance ok"><?= number_format($done) ?> already applied</span><?php endif; ?>
    </div>
  </div>

  <?php if (rolling_tune_ready()): ?>
  <div class="panel">
    <form method="post">
      <input type="hidden" name="action" value="recompare">
      <input type="hidden" name="file_id" value="<?= $fileId ?>">
      <h3 style="margin-top:0">How a record is recognised</h3>
      <p class="small muted" style="margin-top:-.4rem">Try it, read the counts, change it, try again
        &mdash; nothing is written by comparing. A loose key (date and amount, say) lets descriptions
        and references change without looking like new records; a tight key spots more real changes but
        turns a re-numbered record into a duplicate. If the counts look wrong, the key is wrong.</p>

      <label>Fields that identify a record</label>
      <div class="row">
        <?php foreach ($choices as $col => $label): ?>
          <label style="margin:0;color:var(--ink);white-space:nowrap">
            <input type="checkbox" name="keyf[]" value="<?= h($col) ?>" style="width:auto"
              <?= in_array($col, $keys, true) ? 'checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach; ?>
      </div>

      <label style="margin-top:.7rem">Fields to ignore when comparing</label>
      <p class="small muted" style="margin-top:0">A field named here is left out of the comparison
        altogether, so a change in it never raises an amendment and never asks you anything. Use it for
        fields that wobble without meaning anything.</p>
      <div class="row">
        <?php foreach ($choices as $col => $label): ?>
          <label style="margin:0;color:var(--ink);white-space:nowrap">
            <input type="checkbox" name="ignoref[]" value="<?= h($col) ?>" style="width:auto"
              <?= in_array($col, $ignored, true) ? 'checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:.8rem">
        <button class="btn" type="submit">Compare again</button>
        <label style="margin:0;color:var(--ink)">
          <input type="checkbox" name="remember" value="1" style="width:auto">
          Remember this as the file's usual setting</label>
      </div>
      <p class="small muted" style="margin-bottom:0">A field cannot both identify a record and be
        ignored; if you tick it in both, identifying wins.</p>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($blocked): ?>
    <div class="panel" style="background:#fbeeee;border-color:#eccfcf">
      <p class="small" style="color:var(--bad);margin:0 0 .4rem">The ones held back have a changed
        amount on a line that is already matched. Applying one means undoing its match first &mdash; a
        whole match at a time, so nothing is left matched and out of balance. Each row says what that
        would cost before you press it.</p>
      <form method="post" id="freeAllForm" style="margin:0">
        <input type="hidden" name="action" value="free">
        <input type="hidden" name="file_id" value="<?= $fileId ?>">
        <input type="hidden" name="ids" value="<?= h(implode(',', array_map(
            fn($r) => $r['id'], array_filter($rows, fn($r) => $r['verdict'] === 'blocked')))) ?>">
        <button class="btn" type="submit" style="background:var(--bad);border-color:var(--bad)"
          onclick="return confirm('Undo the matches holding all <?= $blocked ?> of these records, then apply them?')">
          Unmatch and apply all <?= $blocked ?> held back</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="panel"><p class="muted" style="margin:0">&#10003; Nothing left to decide in that load.</p>
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

    <div class="panel" style="position:sticky;top:0;z-index:5">
      <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
        <button class="btn" type="submit">Apply ticked</button>
        <span class="balance" id="stageCount"></span>
        <span class="muted small">Anything left unticked stays here until you come back to it.</span>
        <span style="margin-left:auto">
          <button class="btn ghost" type="submit" form="discardForm"
            onclick="return confirm('Throw this whole load away? Nothing will be changed.')"
            style="color:var(--bad);border-color:var(--bad)">Throw the load away</button></span>
      </div>
      <div style="margin-top:.6rem">
        <span class="small muted">Fields you will let change:</span>
        <span class="row" style="display:inline-flex;gap:.6rem;margin-left:.4rem">
          <?php foreach (rolling_writable_fields($fileId) as $col => $label): ?>
            <label style="margin:0;color:var(--ink);white-space:nowrap" class="small">
              <input type="checkbox" name="allow[]" value="<?= h($col) ?>" style="width:auto" checked>
              <?= h($label) ?></label>
          <?php endforeach; ?>
        </span>
        <p class="small muted" style="margin:.3rem 0 0">A field you untick is not written, so the
          record keeps its old value and shows as amended again on the next load &mdash; a standing
          reminder that something is unresolved. To stop a field being noticed at all, ignore it when
          comparing instead. New records arrive whole, so this applies to amendments.</p>
      </div>
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
              $changes = $s['txn_id'] ? stage_changes($s, $ignored) : [];
              $ch  = array_flip($changes);
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
              <td class="center"><?php if ($can): ?>
                <input type="checkbox" class="stagetick" value="<?= (int)$s['id'] ?>" checked>
              <?php else: $words = unmatch_cost_words(matches_holding((int)$s['txn_id'])); ?>
                <button class="btn ghost small" type="submit" form="freeOne<?= (int)$s['id'] ?>"
                  title="<?= h($words) ?>"
                  onclick="return confirm(<?= h(json_encode($words . "\n\nGo ahead?")) ?>)"
                  style="color:var(--bad);border-color:var(--bad);white-space:nowrap">Unmatch<br>and apply</button>
              <?php endif; ?></td>
              <td class="small">
                <span class="tag <?= $s['verdict'] === 'new' ? 'manual' : '' ?>"><?= h($s['verdict'] === 'blocked' ? 'held back' : $s['verdict']) ?></span>
                <?php if ($changes): ?><br><span class="muted small"><?php
                  echo h(implode(', ', array_map(fn($c) => $choices[$c] ?? $c, $changes))) . ' changed'; ?></span><?php endif; ?>
                <?php if ($s['note']): ?><br><span class="small" style="color:var(--warn)"><?= h($s['note']) ?></span><?php endif; ?>
                <?php if (rolling_tune_ready()): ?>
                  <?php if ($s['verdict'] === 'new' && !empty($s['suggest_txn_id'])): ?>
                    <br><button class="btn ghost small" type="submit" form="acceptOne<?= (int)$s['id'] ?>"
                      style="margin-top:.2rem">Treat as that amendment</button>
                  <?php endif; ?>
                  <?php if ($s['verdict'] === 'new' && $cands): ?>
                    <br><button class="btn ghost small" type="button" style="margin-top:.2rem"
                      onclick="pairWith(<?= (int)$s['id'] ?>, this)">Pair with an existing record&hellip;</button>
                  <?php endif; ?>
                  <?php if (!empty($s['paired_by_hand'])): ?>
                    <br><button class="btn ghost small" type="submit" form="unpairOne<?= (int)$s['id'] ?>"
                      style="margin-top:.2rem">Undo pairing</button>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <?= $cell($s['txn_date'], $s['old_date'], isset($ch['txn_date'])) ?>
              <?= $cell($s['description'], $s['old_desc'], isset($ch['description'])) ?>
              <?= $cell(money($s['value']), money($s['old_value'] ?? 0), isset($ch['value']), true) ?>
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

  <?php foreach ($rows as $s): ?>
    <?php if ($s['verdict'] === 'blocked'): ?>
      <form method="post" id="freeOne<?= (int)$s['id'] ?>">
        <input type="hidden" name="action" value="free">
        <input type="hidden" name="file_id" value="<?= $fileId ?>">
        <input type="hidden" name="ids" value="<?= (int)$s['id'] ?>">
      </form>
    <?php endif; ?>
    <?php if (rolling_tune_ready() && !empty($s['suggest_txn_id'])): ?>
      <form method="post" id="acceptOne<?= (int)$s['id'] ?>">
        <input type="hidden" name="action" value="accept">
        <input type="hidden" name="file_id" value="<?= $fileId ?>">
        <input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
        <input type="hidden" name="suggest" value="<?= (int)$s['suggest_txn_id'] ?>">
      </form>
    <?php endif; ?>
    <?php if (rolling_tune_ready() && !empty($s['paired_by_hand'])): ?>
      <form method="post" id="unpairOne<?= (int)$s['id'] ?>">
        <input type="hidden" name="action" value="unpair">
        <input type="hidden" name="file_id" value="<?= $fileId ?>">
        <input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
      </form>
    <?php endif; ?>
  <?php endforeach; ?>

  <form method="post" id="discardForm">
    <input type="hidden" name="action" value="discard">
    <input type="hidden" name="file_id" value="<?= $fileId ?>">
  </form>

  <?php if (rolling_tune_ready() && $cands): ?>
    <div class="panel" id="pairBox" style="display:none;position:fixed;right:1rem;bottom:1rem;
         max-width:30rem;z-index:20;box-shadow:0 .4rem 1.2rem rgba(0,0,0,.2)">
      <form method="post">
        <input type="hidden" name="action" value="pair">
        <input type="hidden" name="file_id" value="<?= $fileId ?>">
        <input type="hidden" name="stage_id" id="pairStage">
        <h3 style="margin-top:0">Which record is this really?</h3>
        <p class="small muted" style="margin-top:-.4rem">Pick the record it is an amendment of. Use this
          when the source system has re-numbered a record, so its key no longer matches.</p>
        <select name="txn_id" style="width:100%">
          <?php foreach ($cands as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h(rolling_record_words($c, $fileId)
              . ' - ' . mb_substr((string)$c['description'], 0, 40)) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:.5rem;margin-top:.6rem">
          <button class="btn" type="submit">Pair them</button>
          <button class="btn ghost" type="button" onclick="document.getElementById('pairBox').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

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
  function pairWith(stageId, btn) {
    var box = document.getElementById('pairBox');
    if (!box) return;
    document.getElementById('pairStage').value = stageId;
    box.style.display = '';
    box.scrollIntoView({block: 'nearest'});
  }
  </script>
  <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
