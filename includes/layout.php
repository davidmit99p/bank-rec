<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';

// Switching reconciliation redirects, so it must happen before any output.
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
        'import.php'       => '1. Import',
        'rules.php'        => '2. Rules',
        'transactions.php' => '3. Transactions',
        'matches.php'      => 'Matches',
        'runs.php'         => 'Runs',
        'recs.php'         => 'Reconciliations',
    ];
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
<link rel="stylesheet" href="assets/style.css">
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
          <?= h($r['name']) ?><?= $r['active'] ? '' : ' (off)' ?></option>
      <?php endforeach; ?>
    </select>
  </form>
<?php endif; ?>
</header>
<main class="container">
<?php foreach ((array)flash() as $m): ?>
  <p class="flash"><?= h($m) ?></p>
<?php endforeach; ?>
<?php
}

function render_footer()
{
    ?>
</main>
<footer class="site-footer">
  <p>Bank Reconciliation &middot; Entigy Group</p>
</footer>
</body>
</html>
<?php
}
