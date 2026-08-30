<?php
// Files: the things you load. A file stands on its own - it does not need to
// know what it will be reconciled against, and it can be used by more than one
// reconciliation.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/importer.php';

$pdo   = db();
$error = null;

if (!files_ready()) {
    render_header('Files');
    ?>
    <h1>Files</h1>
    <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
      <p style="margin:0"><b>One database change is needed first.</b>
        In phpMyAdmin, run <code>sql/migration_007_files.sql</code> against <code>entigy_recon</code>.</p>
    </div>
    <?php
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $act   = isset($_POST['active']) ? 1 : 0;
            if ($name === '') throw new RuntimeException('Give the file a name.');
            $ex = [];
            for ($i = 1; $i <= 3; $i++) $ex[] = trim((string)($_POST['extra' . $i] ?? '')) ?: null;

            if ($id) {
                $pdo->prepare("UPDATE rec_files SET name=?, notes=?, active=?, extra1=?, extra2=?, extra3=?
                               WHERE id=?")
                    ->execute(array_merge([$name, $notes, $act], $ex, [$id]));
                flash('Saved.');
            } else {
                $pdo->prepare("INSERT INTO rec_files (name, notes, active, extra1, extra2, extra3)
                               VALUES (?,?,?,?,?,?)")
                    ->execute(array_merge([$name, $notes, $act], $ex));
                flash('Created ' . $name . '. You can import into it now.');
            }
            header('Location: files.php');
            exit;
        }

        if (($_POST['action'] ?? '') === 'delete') {
            $id = (int)$_POST['id'];
            $st = $pdo->prepare("SELECT COUNT(*) FROM rec_txns WHERE file_id = ?");
            $st->execute([$id]);
            if ((int)$st->fetchColumn()) {
                throw new RuntimeException('That file still holds transactions. Remove its imports '
                    . 'first, or switch it off instead of deleting it.');
            }
            if (file_used_by($id)) {
                throw new RuntimeException('That file is used by a reconciliation. Take it off that '
                    . 'first.');
            }
            $pdo->prepare("DELETE FROM rec_files WHERE id = ?")->execute([$id]);
            flash('File deleted.');
            header('Location: files.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit   = $editId ? get_file($editId) : null;
$blank  = ['id' => 0, 'name' => '', 'notes' => '', 'active' => 1,
           'extra1' => '', 'extra2' => '', 'extra3' => ''];
$f = $edit ?: $blank;

$files = all_files();

render_header('Files');
?>
<h1>Files</h1>
<p class="muted">Everything you have loaded. A file stands on its own &mdash; give it a name, say what
  its spare columns are called, and import into it. What it gets reconciled against is decided
  separately, on the <a href="recs.php">Reconciliations</a> screen, and one file can be used by more
  than one of them.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<div class="panel">
<table>
  <thead><tr><th>Name</th><th>Spare fields</th><th class="num">Rows</th><th class="num">Open</th>
    <th class="num">Open value</th><th>Used by</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($files as $file):
      $s  = file_stats($file['id']);
      $ex = array_values(array_filter([$file['extra1'], $file['extra2'], $file['extra3']]));
      $by = file_used_by($file['id']);
  ?>
    <tr<?= $file['active'] ? '' : ' style="opacity:.5"' ?>>
      <td><b><?= h($file['name']) ?></b>
        <?php if ($file['notes']): ?><br><span class="muted small"><?= h($file['notes']) ?></span><?php endif; ?></td>
      <td class="small"><?= $ex ? h(implode(', ', $ex)) : '<span class="muted">none</span>' ?></td>
      <td class="num"><?= number_format((int)$s['n']) ?></td>
      <td class="num"><?= number_format((int)$s['open_n']) ?></td>
      <td class="num <?= $s['open_total'] < 0 ? 'neg' : '' ?>"><?= money($s['open_total']) ?></td>
      <td class="small"><?php
          if (!$by) echo '<span class="muted">nothing yet</span>';
          else echo h(implode(', ', array_column($by, 'name'))); ?></td>
      <td style="display:flex;gap:.4rem">
        <a class="btn ghost small" href="import.php?file=<?= (int)$file['id'] ?>">Import</a>
        <a class="btn ghost small" href="?edit=<?= (int)$file['id'] ?>">Edit</a>
        <form method="post" onsubmit="return confirm('Delete <?= h($file['name']) ?>?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$file['id'] ?>">
          <button class="btn ghost small" type="submit"
            style="color:var(--bad);border-color:var(--bad)">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$files): ?>
    <tr><td colspan="7" class="muted">No files yet. Add one below, then import into it.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<h2><?= $edit ? 'Edit ' . h($edit['name']) : 'Add a file' ?></h2>
<form method="post" class="panel">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <div class="row">
    <div style="flex:3"><label>Name</label>
      <input type="text" name="name" value="<?= h($f['name']) ?>"
             placeholder="e.g. BCB account x847, or Sun nominal ledger" required></div>
    <div><label>&nbsp;</label>
      <label style="margin:0"><input type="checkbox" name="active" value="1" style="width:auto"
        <?= $f['active'] ? 'checked' : '' ?>> In use</label></div>
  </div>

  <label>Spare fields</label>
  <p class="small muted" style="margin-top:0">Three extra columns this file carries beyond the date,
    description and value &mdash; a reference, a type, a cost centre. Name them here and they can be
    mapped on import, shown beside each transaction and included in downloads. Leave one empty and it
    is not used. You can add one later and re-import to pick it up.</p>
  <div class="row">
    <?php for ($i = 1; $i <= 3; $i++): ?>
      <input type="text" name="extra<?= $i ?>" value="<?= h($f['extra' . $i] ?? '') ?>"
             placeholder="Spare field <?= $i ?>">
    <?php endfor; ?>
  </div>

  <label>Notes</label>
  <textarea name="notes" placeholder="Where this comes from, which account it is, anything worth remembering"><?= h($f['notes']) ?></textarea>

  <div class="actions">
    <button class="btn" type="submit"><?= $edit ? 'Save' : 'Create' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="files.php">Cancel</a><?php endif; ?>
  </div>
</form>
<?php render_footer(); ?>
