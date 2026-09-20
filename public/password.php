<?php
// Changing your own password. Also where you land when an administrator has set
// one for you and it has to be replaced before anything else.
require_once __DIR__ . '/../includes/layout.php';

$u = current_user();
if (!$u) { header('Location: login.php'); exit; }

$error = null;
$must  = !empty($u['must_change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $now  = (string)($_POST['current'] ?? '');
        $new  = (string)($_POST['password'] ?? '');
        $new2 = (string)($_POST['password2'] ?? '');
        if (!password_verify($now, $u['password_hash'])) throw new RuntimeException('That is not your current password.');
        if ($new !== $new2)      throw new RuntimeException('The two new passwords are not the same.');
        if ($new === $now)       throw new RuntimeException('The new password is the same as the old one.');
        [$ok, $msg] = set_password($u['id'], $new, 0);
        if (!$ok) throw new RuntimeException($msg);
        account_log('changed own password');
        flash('Password changed.');
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('Password');
?>
<h1>Your password</h1>

<?php if ($must): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0">Your password was set for you. Choose your own before carrying on.</p>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p>
<?php endif; ?>

<form method="post" class="panel" style="max-width:26rem">
  <label>Current password</label>
  <input type="password" name="current" autocomplete="current-password" required autofocus>
  <label>New password</label>
  <input type="password" name="password" autocomplete="new-password" required>
  <label>New password again</label>
  <input type="password" name="password2" autocomplete="new-password" required>
  <p class="small muted" style="margin:.4rem 0 0">At least 8 characters.</p>
  <div class="actions">
    <button class="btn" type="submit">Change it</button>
    <?php if (!$must): ?><a class="btn ghost" href="index.php">Cancel</a><?php endif; ?>
  </div>
</form>
<?php render_footer(); ?>
