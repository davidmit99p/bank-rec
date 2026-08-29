<?php
// The shelf: things worth doing, not being done now. Editable here, because
// the point of it is to write something down the moment you think of it.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/notes.php';

$seed  = dirname(__DIR__) . '/SHELF.md';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && notes_ready()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        [$ok, $msg] = save_note('shelf', (string)($_POST['body'] ?? ''));
        if ($ok) { flash($msg); header('Location: shelf.php'); exit; }
        $error = $msg;
    }
    if ($action === 'restore') {
        [$ok, $msg] = restore_note_version('shelf', (int)$_POST['version_id']);
        flash($msg);
        if ($ok) { header('Location: shelf.php'); exit; }
        $error = $msg;
    }
}

$note     = notes_ready() ? get_note('shelf', 'Shelf notes', $seed) : null;
$body     = $note ? $note['body'] : (is_file($seed) ? file_get_contents($seed) : '');
$editing  = isset($_GET['edit']);
$versions = $note ? note_versions($note['id']) : [];

render_header('Shelf');
?>
<h1>Shelf</h1>
<p class="muted">Ideas and jobs that are parked rather than forgotten. Jot things down as they occur to
  you &mdash; that is what it is for.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if (!notes_ready()): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>One database change is needed before this can be edited here.</b>
      In phpMyAdmin, run <code>sql/migration_004_notes.sql</code> against <code>entigy_recon</code>.
      Until then the notes below are read straight from <code>SHELF.md</code> in the repository.</p>
  </div>
<?php endif; ?>

<?php if ($editing && notes_ready()): ?>

  <form method="post" class="panel">
    <input type="hidden" name="action" value="save">
    <label>Shelf notes</label>
    <textarea name="body" style="min-height:32rem;font-family:ui-monospace,Consolas,monospace;font-size:.9rem"><?= h($body) ?></textarea>
    <p class="small muted">Plain text. A line starting with <code>##</code> is a heading,
      <code>###</code> a smaller one; a line starting with <code>-</code> is a bullet;
      <code>**text**</code> comes out bold and <code>`text`</code> as code. None of that is
      required &mdash; ordinary paragraphs are fine.</p>
    <div class="actions">
      <button class="btn" type="submit">Save</button>
      <a class="btn ghost" href="shelf.php">Cancel</a>
    </div>
  </form>

<?php else: ?>

  <div class="panel" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <?php if (notes_ready()): ?>
      <a class="btn" href="shelf.php?edit=1">Edit</a>
      <span class="muted small">Last changed <?= h($note['updated_at'] ?? '') ?></span>
    <?php else: ?>
      <span class="muted small">Read-only until the database change above is done.</span>
    <?php endif; ?>
    <?php if ($versions): ?>
      <span class="muted small" style="margin-left:auto"><?= count($versions) ?>
        earlier version<?= count($versions) == 1 ? '' : 's' ?> kept</span>
    <?php endif; ?>
  </div>

  <div class="panel notes">
    <?php if (trim($body) === ''): ?>
      <p class="muted">Nothing on the shelf. Press Edit and write something.</p>
    <?php else: ?>
      <?= markdown_to_html($body) ?>
    <?php endif; ?>
  </div>

  <?php if ($versions): ?>
    <h2>Earlier versions</h2>
    <p class="muted">Every save keeps what it replaced, so an edit can be undone. The last 30 are kept.</p>
    <div class="panel">
      <table>
        <thead><tr><th>Saved</th><th class="num">Length</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
          <tr>
            <td class="small"><?= h($v['saved_at']) ?></td>
            <td class="num"><?= number_format((int)$v['len']) ?> characters</td>
            <td>
              <form method="post" onsubmit="return confirm('Put back the version from <?= h($v['saved_at']) ?>? What is there now is kept as a version, so this can be undone.')">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                <button class="btn ghost small" type="submit">Put this back</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>
<?php render_footer(); ?>
