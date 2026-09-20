<?php
// Who may sign in, and what they have been doing. Administrators only.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/central.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'add') {
            [$ok, $msg] = create_user($_POST['username'] ?? '', $_POST['name'] ?? '',
                                      $_POST['password'] ?? '', $_POST['role'] ?? 'user', 1);
            if (!$ok) throw new RuntimeException($msg);
            flash($msg . ' They will be asked to choose their own password when they first sign in.');
        } elseif ($action === 'reset') {
            [$ok, $msg] = set_password($id, $_POST['password'] ?? '', 1);
            if (!$ok) throw new RuntimeException($msg);
            account_log('reset a password', user_name($id));
            flash('New password set for ' . user_name($id) . '. They must change it when they sign in.');
        } elseif ($action === 'toggle') {
            [$ok, $msg] = set_user_active($id, (int)($_POST['to'] ?? 0));
            if (!$ok) throw new RuntimeException($msg);
            flash($msg);
        } elseif ($action === 'access') {
            // which clients this person may work on. The owner reaches them all
            // without any rows, so there is nothing to tick.
            if (!central_ready()) throw new RuntimeException('This installation serves one database.');
            $want = array_map('intval', (array)($_POST['clients'] ?? []));
            central_db()->prepare("DELETE FROM cen_access WHERE user_id = ?")->execute([$id]);
            $ins = central_db()->prepare("INSERT INTO cen_access (user_id, client_id) VALUES (?,?)");
            foreach ($want as $cid) if (get_client($cid)) $ins->execute([$id, $cid]);
            central_log('changed who works on what', user_name($id) . ': ' . count($want) . ' client(s)');
            flash('Saved.');
        } elseif ($action === 'role') {
            [$ok, $msg] = set_user_role($id, $_POST['to'] ?? 'user');
            if (!$ok) throw new RuntimeException($msg);
            flash($msg);
        }
        header('Location: users.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$users  = all_users();
$events = db()->query("SELECT * FROM rec_events ORDER BY id DESC LIMIT 200")->fetchAll();

// on a central installation, who may work on which client
$clients = central_ready() ? all_clients(false) : [];
$access  = [];
if ($clients) {
    foreach (central_db()->query("SELECT * FROM cen_access")->fetchAll() as $a) {
        $access[(int)$a['user_id']][(int)$a['client_id']] = true;
    }
}

render_header('Users');
?>
<h1>Users</h1>
<p class="muted">Everyone signs in as themselves, so their work carries their name. The site password in
front of the whole site is separate and unchanged.</p>

<?php if ($error): ?>
  <p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p>
<?php endif; ?>

<div class="panel">
  <div class="scroll">
    <table>
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last signed in</th>
        <?php if ($clients): ?><th>Works on</th><?php endif; ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr<?= $u['active'] ? '' : ' style="opacity:.5"' ?>>
          <td><?= h($u['name']) ?><?= $u['id'] == current_user_id() ? ' <span class="tag">you</span>' : '' ?>
            <?= empty($u['must_change']) ? '' : ' <span class="tag">must change password</span>' ?></td>
          <td><?= h($u['username']) ?></td>
          <td><?= $u['role'] === 'owner' ? 'Owner' : ($u['role'] === 'admin' ? 'Administrator' : 'User') ?></td>
          <td class="small"><?= h($u['last_login_at'] ? substr($u['last_login_at'], 0, 16) : 'never') ?></td>
          <?php if ($clients): ?>
            <td class="small">
              <?php if ($u['role'] === 'owner'): ?>
                <span class="muted">every client</span>
              <?php else: ?>
                <form method="post" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                  <input type="hidden" name="action" value="access">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <?php foreach ($clients as $c): ?>
                    <label style="margin:0;color:var(--ink);white-space:nowrap">
                      <input type="checkbox" name="clients[]" value="<?= (int)$c['id'] ?>" style="width:auto"
                        <?= isset($access[(int)$u['id']][(int)$c['id']]) ? 'checked' : '' ?>> <?= h($c['name']) ?></label>
                  <?php endforeach; ?>
                  <button class="btn ghost small" type="submit">Save</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;align-items:center">
              <form method="post" style="display:flex;gap:.3rem;align-items:center">
                <input type="hidden" name="action" value="role">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="to" value="<?= $u['role'] === 'user' ? 'admin' : 'user' ?>">
                <button class="btn ghost small" type="submit"<?= $u['role'] === 'owner' ? ' disabled title="The owner stays the owner"' : '' ?>>
                  Make <?= $u['role'] === 'user' ? 'an administrator' : 'a user' ?></button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="to" value="<?= $u['active'] ? 0 : 1 ?>">
                <button class="btn ghost small" type="submit"><?= $u['active'] ? 'Turn off' : 'Turn on' ?></button>
              </form>
              <form method="post" style="display:flex;gap:.3rem;align-items:center">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="password" name="password" placeholder="new password" style="width:11rem"
                       autocomplete="new-password">
                <button class="btn ghost small" type="submit">Set password</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted" style="margin:.5rem 0 0">Somebody who has left is turned off rather than deleted,
    so the record of what they did still reads properly.</p>
</div>

<h2>Add someone</h2>
<form method="post" class="panel">
  <input type="hidden" name="action" value="add">
  <div class="row">
    <div><label>Name</label><input type="text" name="name" placeholder="e.g. Jane Smith" required></div>
    <div><label>Username</label><input type="text" name="username" placeholder="e.g. jane" required></div>
    <div><label>Role</label>
      <select name="role"><option value="user">User</option><option value="admin">Administrator</option></select></div>
    <div><label>Password to start with</label>
      <input type="password" name="password" autocomplete="new-password" required></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Add</button></div>
  <p class="small muted" style="margin:.2rem 0 0">Tell them the password yourself. They must choose their
    own the first time they sign in. An administrator can add people and set passwords; a user can do
    everything else.</p>
</form>

<?php if (central_ready()): ?>
  <h2>Signing in, and anything above a single client</h2>
  <div class="panel">
    <div class="scroll" style="max-height:20rem">
      <table>
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach (central_db()->query("SELECT * FROM cen_events ORDER BY id DESC LIMIT 100")->fetchAll() as $e): ?>
          <tr><td class="small"><?= h(substr((string)$e['at'], 0, 16)) ?></td>
            <td class="small"><?= $e['who'] ? h($e['who']) : '&mdash;' ?></td>
            <td class="small"><?= h($e['kind']) ?></td>
            <td class="small"><?= h((string)$e['detail']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<h2>What has been done<?= central_ready() ? ' in ' . h(current_client()['name'] ?? 'this client') : '' ?></h2>
<div class="panel">
  <?php if (!$events): ?>
    <p class="muted" style="margin:0">Nothing recorded yet.</p>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td class="small"><?= h(substr((string)$e['at'], 0, 16)) ?></td>
            <td class="small"><?= $e['who'] ? h($e['who']) : '&mdash;' ?></td>
            <td class="small"><?= h($e['kind']) ?></td>
            <td class="small"><?= h((string)$e['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small muted" style="margin:.5rem 0 0">The last 200. Names are kept as they were at the time.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
