<?php
require_once __DIR__ . '/db.php';

function render_header($title = '')
{
    $appName = config('app')['name'] ?? 'Bank Reconciliation';
    $full = $title ? "$title \xE2\x80\x94 $appName" : $appName;
    $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $nav = [
        'index.php'        => 'Home',
        'import.php'       => '1. Import',
        'rules.php'        => '2. Rules',
        'transactions.php' => '3. Transactions',
        'runs.php'         => '4. Runs',
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
