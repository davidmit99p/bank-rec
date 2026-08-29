<?php
// The shelf: things worth doing, not being done now. Kept in SHELF.md at the
// top of the repository and shown here so it is in front of you rather than
// buried in a chat window.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/markdown.php';

$path = dirname(__DIR__) . '/SHELF.md';
$md   = is_file($path) ? file_get_contents($path) : false;
$when = is_file($path) ? date('j F Y', filemtime($path)) : null;

render_header('Shelf');
?>
<h1>Shelf</h1>
<p class="muted">Ideas and jobs that are parked rather than forgotten. This is
  <code>SHELF.md</code> in the repository, so it travels with the code and changes to it are part of
  the history.<?= $when ? ' Last changed ' . h($when) . '.' : '' ?></p>

<?php if ($md === false): ?>
  <div class="panel"><p class="muted">No <code>SHELF.md</code> found. It should sit at the top of the
    repository, alongside <code>README.md</code>.</p></div>
<?php else: ?>
  <div class="panel notes">
    <?= markdown_to_html($md) ?>
  </div>
<?php endif; ?>

<div class="panel">
  <p class="small muted" style="margin:0">To add something, edit <code>SHELF.md</code> and push &mdash;
    it appears here on the next deployment. Ask if you would rather edit it in the browser; that would
    mean keeping it in the database instead, so a deployment could not overwrite it.</p>
</div>
<?php render_footer(); ?>
