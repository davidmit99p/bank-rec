<?php
// The clients this installation serves, and which one you are working on.
//
// Everyone sees the ones they may reach, to choose or switch between. Only the
// owner registers a new client or sets up its database.
//
// This page draws its own header when no client has been chosen yet, because
// the ordinary layout shows a reconciliation picker that would have nothing to
// pick from.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/central.php';
require_once __DIR__ . '/../includes/auth.php';

if (!central_on()) {
    require_once __DIR__ . '/../includes/layout.php';
    render_header('Clients');
    echo '<h1>Clients</h1><div class="panel"><p class="muted" style="margin:0">This installation serves one '
       . 'database, so there is nothing to choose between. Several clients are set up by adding a '
       . '<code>central</code> section to the config file.</p></div>';
    render_footer();
    exit;
}

if (!central_ready()) central_install();          // first visit: make the central tables
require_login();

$me      = current_user();
$mine    = clients_for_user($me);
$error   = null;
$chosen  = current_client_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'switch') {
            $id = (int)($_POST['client_id'] ?? 0);
            if (!user_may_reach($me, $id)) throw new RuntimeException('That client is not one of yours.');
            set_current_client($id);
            central_log('switched client', get_client($id)['name'] ?? ('#' . $id));
            header('Location: index.php');
            exit;
        }
        if (!is_owner()) throw new RuntimeException('Only the owner can set up clients.');

        if ($action === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $key  = trim((string)($_POST['cfg_key'] ?? ''));
            if ($name === '') throw new RuntimeException('Give the client a name.');
            if (!preg_match('/^[a-z0-9_-]{2,60}$/i', $key)) {
                throw new RuntimeException('The config key is 2 to 60 characters: letters, numbers, dash or underscore.');
            }
            if (!client_config($key)) {
                throw new RuntimeException('There is no "' . $key . '" under "clients" in the config file. '
                    . 'Add its database details there first - that is the only place a database password lives.');
            }
            central_db()->prepare("INSERT INTO cen_clients (name, cfg_key, notes) VALUES (?,?,?)")
                ->execute([$name, $key, trim((string)($_POST['notes'] ?? '')) ?: null]);
            central_log('added a client', $name . ' (' . $key . ')');
            flash($name . ' added. Now set up its database, unless it is already in use.');
        } elseif ($action === 'install' || $action === 'adopt') {
            $client = get_client((int)($_POST['client_id'] ?? 0));
            if (!$client) throw new RuntimeException('That client no longer exists.');
            $cfg = client_config($client['cfg_key']);
            if (!$cfg) throw new RuntimeException('The config file has no details for ' . $client['cfg_key'] . '.');
            if ($action === 'adopt') {
                $n = adopt_as_up_to_date($cfg);
                central_log('adopted a database', $client['name']);
                flash($client['name'] . ': marked ' . $n . ' change' . ($n === 1 ? '' : 's') . ' as already applied.');
            } else {
                [$ran, $note] = bring_up_to_date($cfg);
                central_log('set up a database', $client['name'] . ': ' . count($ran) . ' files');
                flash($client['name'] . ': ' . count($ran) . ' change' . (count($ran) === 1 ? '' : 's')
                      . ' applied.' . ($note ? ' ' . $note : ''));
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['client_id'] ?? 0);
            central_db()->prepare("UPDATE cen_clients SET active = 1 - active WHERE id = ?")->execute([$id]);
            central_log('turned a client on or off', get_client($id)['name'] ?? ('#' . $id));
        }
        header('Location: clients.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$all = is_owner() ? all_clients(false) : $mine;

// With a client chosen the ordinary layout works; without one it would have
// nothing to draw its picker from.
$standalone = !$chosen;
if (!$standalone) {
    require_once __DIR__ . '/../includes/layout.php';
    render_header('Clients');
} else {
    $appName = config('app')['name'] ?? 'Bank Reconciliation';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose a client &mdash; <?= h($appName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<header class="site-header"><span class="brand">&#9878; <?= h($appName) ?></span>
  <span class="whoami"><?= h($me['name'] ?? '') ?><a class="btn ghost small" href="logout.php">Sign out</a></span>
</header>
<main class="container">
<?php foreach ((array)flash() as $m): ?><p class="flash"><?= h($m) ?></p><?php endforeach; ?>
<?php } ?>

<h1><?= $standalone ? 'Choose a client' : 'Clients' ?></h1>

<?php if ($error): ?>
  <p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p>
<?php endif; ?>

<?php if (!$mine && !is_owner()): ?>
  <div class="panel"><p class="muted" style="margin:0">You have not been given a client to work on yet.
    Ask an administrator.</p></div>
<?php endif; ?>

<div class="panel">
  <div class="scroll">
    <table>
      <thead><tr><th>Client</th><th>Database</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($all as $c):
          $cfg  = client_config($c['cfg_key']);
          $here = (int)$c['id'] === (int)$chosen; ?>
        <tr<?= $c['active'] ? '' : ' style="opacity:.5"' ?>>
          <td><b><?= h($c['name']) ?></b><?= $here ? ' <span class="tag">working on this</span>' : '' ?>
            <?php if ($c['notes']): ?><br><span class="small muted"><?= h($c['notes']) ?></span><?php endif; ?></td>
          <td class="small"><?= h($c['cfg_key']) ?>
            <?php if (!$cfg): ?>
              <br><span class="neg">nothing in the config file for this key</span>
            <?php else: ?>
              <br><span class="muted"><?= h($cfg['name']) ?></span>
            <?php endif; ?></td>
          <td>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap">
              <?php if ($cfg && $c['active'] && user_may_reach($me, $c['id']) && !$here): ?>
                <form method="post"><input type="hidden" name="action" value="switch">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn small" type="submit">Work on this</button></form>
              <?php endif; ?>
              <?php if (is_owner() && $cfg): ?>
                <form method="post" onsubmit="return confirm('Create or update the tables in <?= h($cfg['name']) ?>?')">
                  <input type="hidden" name="action" value="install">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn ghost small" type="submit"
                    title="Run whatever has not been run in that database yet">Set up its database</button></form>
                <form method="post" onsubmit="return confirm('Mark <?= h($cfg['name']) ?> as already up to date?')">
                  <input type="hidden" name="action" value="adopt">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn ghost small" type="submit"
                    title="For a database that has been in use: record every change as already applied">Already up to date</button></form>
                <form method="post"><input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn ghost small" type="submit"><?= $c['active'] ? 'Turn off' : 'Turn on' ?></button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$all): ?>
        <tr><td colspan="3" class="muted">No clients yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (is_owner()): ?>
  <h2>Add a client</h2>
  <form method="post" class="panel">
    <input type="hidden" name="action" value="add">
    <div class="row">
      <div><label>Name</label><input type="text" name="name" placeholder="e.g. Bermudair" required></div>
      <div><label>Config key</label><input type="text" name="cfg_key" placeholder="e.g. bermudair" required></div>
      <div style="flex:2"><label>Notes</label><input type="text" name="notes" placeholder="optional"></div>
    </div>
    <div class="actions"><button class="btn" type="submit">Add</button></div>
  </form>

  <div class="panel">
    <h3 style="margin-top:0">How a client is set up</h3>
    <ol class="small" style="margin:.3rem 0 0;padding-left:1.2rem">
      <li>In Plesk, create the database and a user for it, as you do now.</li>
      <li>In the config file above the web root, add those details under <code>clients</code>,
        with a short key:<br>
        <code>'clients' =&gt; ['bermudair' =&gt; ['host' =&gt; 'localhost', 'name' =&gt; '...', 'user' =&gt; '...', 'pass' =&gt; '...', 'charset' =&gt; 'utf8mb4']]</code></li>
      <li>Add the client here, using that key.</li>
      <li>Press <b>Set up its database</b> to create the tables, or <b>Already up to date</b> if it has
        been in use since before this page existed.</li>
      <li>On the <a href="users.php">Users</a> page, say who may work on it.</li>
    </ol>
    <p class="small muted" style="margin:.5rem 0 0">Database passwords stay in the config file. Nothing
      here can reach a client you are not signed in to, because the connection does not go there.</p>
  </div>
<?php endif; ?>

<?php if ($standalone): ?>
</main>
<footer class="site-footer"><?= h(config('app')['name'] ?? 'Bank Reconciliation') ?> &middot; Entigy Group</footer>
</body>
</html>
<?php else: render_footer(); endif; ?>
