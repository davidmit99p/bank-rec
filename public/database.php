<?php
// The state of the database behind this installation - or behind each client -
// and the place to apply a change that is waiting.
//
// Administrators only, and the owner where there are several clients.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/migrate.php';

if (central_on()) { if (!is_owner()) { flash('That page is for the owner.'); header('Location: index.php'); exit; } }
else require_admin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $want   = (int)($_POST['target'] ?? -1);
    try {
        $targets = migrate_targets();
        $hit = null;
        foreach ($targets as $t) if ((int)$t['id'] === $want) $hit = $t;
        if ($action === 'all') {
            $done = 0;
            $said = [];
            foreach ($targets as $t) {
                [$ran, $note] = bring_up_to_date($t['cfg']);
                $done += count($ran);
                if ($note) $said[] = $t['label'] . ': ' . $note;
            }
            account_log('applied changes to every database', $done . ' in all');
            flash($done . ' change' . ($done === 1 ? '' : 's') . ' applied across '
                  . count($targets) . ' database' . (count($targets) === 1 ? '' : 's') . '.'
                  . ($said ? ' ' . implode(' ', $said) : ''));
        } elseif ($hit && $action === 'apply') {
            [$ran, $note] = bring_up_to_date($hit['cfg']);
            account_log('applied changes', $hit['label'] . ': ' . count($ran));
            flash($hit['label'] . ': ' . count($ran) . ' change' . (count($ran) === 1 ? '' : 's')
                  . ' applied.' . ($note ? ' ' . $note : ''));
        } elseif ($hit && $action === 'adopt') {
            $n = adopt_as_up_to_date($hit['cfg']);
            account_log('marked a database as up to date', $hit['label'] . ': ' . $n);
            flash($hit['label'] . ': ' . $n . ' change' . ($n === 1 ? '' : 's') . ' marked as already applied.');
        } else {
            throw new RuntimeException('That database is not one of this installation\'s.');
        }
        header('Location: database.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$targets = migrate_targets();
$waiting = 0;
$rows    = [];
foreach ($targets as $t) {
    $s = migrate_status($t['cfg']);
    $waiting += count($s['waiting']);
    $rows[] = $t + ['status' => $s];
}

render_header('Database');
?>
<h1>Database</h1>
<p class="muted">Every change to the shape of the database is a numbered file. This page says which have
  been applied to <?= central_on() ? "each client's database" : 'the database' ?>, and applies the rest.
  Nothing here touches the reconciliations themselves.</p>

<?php if ($error): ?>
  <p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($waiting): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b><?= number_format($waiting) ?> change<?= $waiting === 1 ? ' is' : 's are' ?>
      waiting.</b> They come with the code, so this normally follows a deployment.</p>
    <?php if (count($rows) > 1): ?>
      <form method="post" style="margin:.5rem 0 0"
            onsubmit="return confirm('Apply everything waiting, to every database?')">
        <input type="hidden" name="action" value="all">
        <button class="btn" type="submit">Apply everything, everywhere</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach ($rows as $r): $s = $r['status']; ?>
  <div class="panel">
    <div class="side-head">
      <h2><?= h($r['label']) ?></h2>
      <span class="muted small"><?= h($r['cfg']['name']) ?></span>
    </div>

    <?php if ($s['error']): ?>
      <p class="neg" style="margin:.3rem 0">Could not be read: <?= h($s['error']) ?></p>
      <p class="small muted" style="margin:0">Check its details in the config file.</p>
    <?php else: ?>
      <p style="margin:.3rem 0">
        <b><?= number_format($s['applied']) ?></b> applied<?php
          if ($s['waiting']): ?>, <b class="neg"><?= count($s['waiting']) ?> waiting</b><?php
          else: ?> &middot; <span style="color:var(--good)">up to date</span><?php endif; ?>
      </p>

      <?php if ($s['waiting']): ?>
        <ul class="small" style="margin:.2rem 0 .6rem">
          <?php foreach ($s['waiting'] as $f): ?><li><code><?= h($f) ?></code></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <?php if ($s['waiting']): ?>
          <form method="post" onsubmit="return confirm('Apply <?= count($s['waiting']) ?> change(s) to <?= h($r['cfg']['name']) ?>?')">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="target" value="<?= (int)$r['id'] ?>">
            <button class="btn" type="submit">Apply what is waiting</button>
          </form>
          <form method="post" onsubmit="return confirm('Mark them as already applied, without running them?')">
            <input type="hidden" name="action" value="adopt">
            <input type="hidden" name="target" value="<?= (int)$r['id'] ?>">
            <button class="btn ghost" type="submit"
              title="For a database that already has these changes, applied by hand">Already up to date</button>
          </form>
        <?php endif; ?>
        <details style="margin-left:auto">
          <summary class="small muted" style="cursor:pointer">What has been applied</summary>
          <div class="scroll" style="max-height:14rem;margin-top:.4rem">
            <table>
              <thead><tr><th>File</th><th>When</th></tr></thead>
              <tbody>
              <?php foreach (migrate_history($r['cfg']) as $h): ?>
                <tr><td class="small"><code><?= h($h['file']) ?></code></td>
                  <td class="small"><?= h(substr((string)$h['applied_at'], 0, 16)) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="panel">
  <h3 style="margin-top:0">How this works</h3>
  <ul class="small" style="margin:.3rem 0 0;padding-left:1.2rem">
    <li>Each change is a file in <code>sql/</code>, applied in number order.</li>
    <li>What has been applied is recorded in <code>rec_migrations</code>, inside the database itself.</li>
    <li>A change that is already there is treated as done rather than as a failure, so a database
      brought up by hand can be picked up from wherever it got to.</li>
    <li>If a change fails for a real reason, it stops there and says which file and why. Nothing
      after it is attempted.</li>
    <li><b>Already up to date</b> records the waiting files without running them. Use it only for a
      database you know already has those changes.</li>
  </ul>
</div>
<?php render_footer(); ?>
