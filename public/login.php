<?php
// Signing in. Also the way the first administrator is created, so nobody has to
// write a row by hand.
//
// This page draws itself rather than using layout.php: the layout carries the
// navigation and the reconciliation picker, and neither means anything until
// somebody is signed in.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/central.php';
require_once __DIR__ . '/../includes/auth.php';

// on a central installation the tables are made on first sight of the page
if (central_on() && !central_ready()) central_install();

$back  = (string)($_GET['back'] ?? $_POST['back'] ?? '');
// only ever return to a page of this app
if ($back !== '' && !preg_match('#^/[A-Za-z0-9._/-]*$#', $back)) $back = '';

$ready = users_ready();
$first = $ready && !any_users();
$error = null;

if ($ready && current_user() && !$first) {
    header('Location: ' . ($back ?: 'index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($first) {
            // the first account is an administrator, and its password is its own
            if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                throw new RuntimeException('The two passwords are not the same.');
            }
            [$ok, $msg] = create_user($_POST['username'] ?? '', $_POST['name'] ?? '',
                                      $_POST['password'] ?? '', central_on() ? 'owner' : 'admin', 0);
            if (!$ok) throw new RuntimeException($msg);
            [$ok, $msg] = attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '');
            if (!$ok) throw new RuntimeException($msg);
            flash(central_on()
                ? 'Signed in as the owner. Add your first client here.'
                : 'Signed in as the administrator. Add anyone else from the Users page.');
            header('Location: ' . (central_on() ? 'clients.php' : 'users.php'));
            exit;
        }
        [$ok, $msg] = attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if (!$ok) throw new RuntimeException($msg);
        header('Location: ' . ($back ?: 'index.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$appName = config('app')['name'] ?? 'Bank Reconciliation';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($first ? 'First administrator' : 'Sign in') ?> &mdash; <?= h($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<header class="site-header"><span class="brand">&#9884; <?= h($appName) ?></span></header>
<div class="container" style="max-width:26rem">
  <h1><?= $first ? ($ready && central_on() ? 'Create the owner' : 'Create the administrator') : 'Sign in' ?></h1>

  <?php if (!$ready): ?>
    <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
      <p style="margin:0"><b>One small database change is still to run.</b> In phpMyAdmin, run
        <code>sql/migration_017_users.sql</code> against <code>entigy_recon</code>. Until then there are no
        named users and the tool works as it did before, behind the site password.</p>
      <p style="margin:.5rem 0 0"><a class="btn" href="index.php">Carry on without signing in</a></p>
    </div>
  <?php else: ?>

    <?php if ($error): ?>
      <p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p>
    <?php endif; ?>

    <?php if ($first): ?>
      <p class="muted">Nobody has an account yet. This first one can add everyone else.</p>
    <?php endif; ?>

    <form method="post" class="panel">
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <label>Username</label>
      <input type="text" name="username" autocomplete="username" autofocus required
             value="<?= h($_POST['username'] ?? '') ?>">
      <?php if ($first): ?>
        <label>Your name <span class="muted small">(as it should appear against your work)</span></label>
        <input type="text" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
      <?php endif; ?>
      <label>Password</label>
      <input type="password" name="password" autocomplete="<?= $first ? 'new-password' : 'current-password' ?>" required>
      <?php if ($first): ?>
        <label>Password again</label>
        <input type="password" name="password2" autocomplete="new-password" required>
        <p class="small muted" style="margin:.4rem 0 0">At least 8 characters.</p>
      <?php endif; ?>
      <div class="actions">
        <button class="btn" type="submit"><?= $first ? 'Create and sign in' : 'Sign in' ?></button>
      </div>
    </form>

    <?php if (!$first): ?>
      <p class="small muted">Forgotten your password? An administrator can set a new one for you on the
        Users page.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<footer class="site-footer"><?= h($appName) ?> &middot; Entigy Group</footer>
</body>
</html>
