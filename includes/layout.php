<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/splits.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/matchstate.php';
require_once __DIR__ . '/extras.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/version.php';

// Both of these can redirect, so they must happen before any output. Signing in
// comes first: there is no point switching reconciliation for a stranger.
require_login();
handle_rec_switch();

function render_header($title = '')
{
    $appName = config('app')['name'] ?? 'Bank Reconciliation';
    $rec  = current_rec();
    $recs = all_recs();
    $titleBase = $rec ? $rec['name'] : $appName;
    $full = $title ? "$title \xE2\x80\x94 $titleBase" : $titleBase;
    $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $nav = [
        'index.php'        => 'Home',
        'overview.php'     => 'Overview',
        'quick.php'        => 'Quick rec',
        'files.php'        => 'Files',
        'import.php'       => '1. Import',
        'rules.php'        => '2. Rules',
        'transactions.php' => '3. Transactions',
        'summary.php'      => 'Summary',
        'pivot.php'        => 'Pivot',
        'matches.php'      => 'Matches',
        'groups.php'       => 'Group notes',
        'trace.php'        => 'Trace',
        'runs.php'         => 'Runs',
        'recs.php'         => 'Reconciliations',
        'shelf.php'        => 'Shelf',
    ];
    if (central_on()) $nav['clients.php'] = 'Clients';
    if (is_admin()) $nav['users.php'] = 'Users';
    if (is_admin()) $nav['database.php'] = 'Database';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($full) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@500;600;700&display=swap" rel="stylesheet">
<?php // the file's date on the link, so browsers fetch it afresh whenever it changes ?>
<link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/../public/assets/style.css') ?>">
</head>
<body>
<header class="site-header">
  <a class="brand" href="index.php">&#9878; <?= h($appName) ?></a>
  <nav>
<?php foreach ($nav as $file => $label): ?>
    <a href="<?= $file ?>"<?= $here === $file ? ' class="on"' : '' ?>><?= h($label) ?></a>
<?php endforeach; ?>
  </nav>
<?php if ($recs): ?>
  <form method="get" class="rec-picker">
    <?php foreach ($_GET as $k => $v):
        if ($k === 'switch_rec' || !is_scalar($v)) continue; ?>
      <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
    <?php endforeach; ?>
    <label for="recSel">Working on</label>
    <select id="recSel" name="switch_rec" onchange="this.form.submit()">
      <?php foreach ($recs as $r): ?>
        <option value="<?= (int)$r['id'] ?>"<?= $rec && $rec['id'] == $r['id'] ? ' selected' : '' ?>>
          <?= h($r['name']) ?><?= $r['active'] ? '' : ' (off)' ?><?= !empty($r['one_off']) ? ' (one-off)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </form>
<?php endif; ?>
<?php if ($me = current_user()): ?>
  <span class="whoami">
    <?php if (central_on() && ($client = current_client())): ?>
      <a href="clients.php" title="Work on a different client"><b><?= h($client['name']) ?></b></a>
      <span class="muted">&middot;</span>
    <?php endif; ?>
    <a href="password.php" title="Change your password"><?= h($me['name']) ?></a>
    <a class="btn ghost small" href="logout.php">Sign out</a>
  </span>
<?php endif; ?>
</header>
<main class="container">
<?php foreach ((array)flash() as $m): ?>
  <p class="flash"><?= h($m) ?></p>
<?php endforeach; ?>
<?php
// A change to the shape of the database arrives with the code. Say so once,
// to whoever can do something about it, rather than letting a page fail later.
if (is_admin() && ($pending = changes_waiting())): ?>
  <p class="flash" style="background:#fdf6e6;border-color:#e8d9a8;color:var(--ink)">
    <b><?= number_format($pending) ?> database change<?= $pending === 1 ? '' : 's' ?></b>
    came with the latest version and <?= $pending === 1 ? 'is' : 'are' ?> waiting.
    <a href="database.php">Apply <?= $pending === 1 ? 'it' : 'them' ?></a>.</p>
<?php endif; ?>
<?php
}

function render_footer()
{
    ?>
</main>
<footer class="site-footer">
  <p>Bank Reconciliation &middot; Entigy Group
    <span class="muted small" title="When the site last took an update. If this is older than you expect, the latest push has not arrived yet."> &middot; updated <?= h(deployed_at()) ?></span></p>
</footer>
</body>
</html>
<?php
}
