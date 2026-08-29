<?php
// The header records: one per reconciliation you have on the go.
require_once __DIR__ . '/../includes/layout.php';

$pdo   = db();
$error = null;

if (!recs_ready()) {
    render_header('Reconciliations');
    ?>
    <h1>Reconciliations</h1>
    <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
      <p style="margin:0"><b>One database change is needed first.</b>
        In phpMyAdmin, run <code>sql/migration_003_reconciliations.sql</code> against
        <code>entigy_recon</code>. Everything already loaded is put into a first reconciliation
        called &ldquo;Main reconciliation&rdquo;, which you can rename here afterwards.</p>
      <p class="muted small" style="margin:.5rem 0 0">Until then the tool works exactly as it did
        before, as a single reconciliation.</p>
    </div>
    <?php
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $left  = trim($_POST['left_label'] ?? '')  ?: 'Ledger';
            $right = trim($_POST['right_label'] ?? '') ?: 'Bank';
            $order = (int)($_POST['sort_order'] ?? 100);
            $notes = trim($_POST['notes'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;
            if ($name === '') throw new RuntimeException('Give the reconciliation a name.');

            if ($id) {
                $pdo->prepare("UPDATE rec_recs SET name=?, left_label=?, right_label=?,
                               sort_order=?, notes=?, active=? WHERE id=?")
                    ->execute([$name, $left, $right, $order, $notes, $active, $id]);
                flash('Saved.');
            } else {
                $pdo->prepare("INSERT INTO rec_recs (name, left_label, right_label, sort_order, notes, active)
                               VALUES (?,?,?,?,?,?)")
                    ->execute([$name, $left, $right, $order, $notes, $active]);
                $new = (int)$pdo->lastInsertId();
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['rec_id'] = $new;      // switch straight to the new one
                flash('Created ' . $name . ', and switched to it.');
            }
            header('Location: recs.php');
            exit;
        }

        if ($action === 'delete') {
            $id = (int)$_POST['id'];
            $counts = [];
            foreach (['rec_ledger' => 'ledger', 'rec_bank' => 'bank'] as $t => $label) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM {$t} WHERE rec_id = ?");
                $st->execute([$id]);
                $counts[$label] = (int)$st->fetchColumn();
            }
            if ($counts['ledger'] || $counts['bank']) {
                throw new RuntimeException('That reconciliation still holds '
                    . $counts['ledger'] . ' ledger and ' . $counts['bank'] . ' bank transactions. '
                    . 'Remove those first, or just switch it off instead of deleting it.');
            }
            $pdo->prepare("DELETE FROM rec_recs WHERE id = ?")->execute([$id]);
            flash('Reconciliation deleted.');
            header('Location: recs.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM rec_recs WHERE id = ?");
    $st->execute([$editId]);
    $edit = $st->fetch();
}
$blank = ['id' => 0, 'name' => '', 'left_label' => 'Ledger', 'right_label' => 'Bank',
          'sort_order' => 100, 'notes' => '', 'active' => 1];
$f = $edit ?: $blank;

// a line of numbers for each one
$rows = $pdo->query(
    "SELECT r.*,
       (SELECT COUNT(*) FROM rec_ledger t WHERE t.rec_id = r.id AND t.matched_at IS NULL) l_open,
       (SELECT COUNT(*) FROM rec_bank   t WHERE t.rec_id = r.id AND t.matched_at IS NULL) b_open,
       (SELECT COALESCE(SUM(t.value),0) FROM rec_ledger t WHERE t.rec_id = r.id AND t.matched_at IS NULL) l_val,
       (SELECT COALESCE(SUM(t.value),0) FROM rec_bank   t WHERE t.rec_id = r.id AND t.matched_at IS NULL) b_val,
       (SELECT COUNT(*) FROM rec_runs n WHERE n.rec_id = r.id AND n.status = 'finalised') runs_n
     FROM rec_recs r ORDER BY r.sort_order, r.name")->fetchAll();

$here = rec_id();
render_header('Reconciliations');
?>
<h1>Reconciliations</h1>
<p class="muted">One for each thing you are reconciling &mdash; a bank account, a supplier statement,
an intercompany balance. Pick which one you are working on from the box at the top right; everything
else on the site then shows only that one.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<div class="panel">
<table>
  <thead><tr><th></th><th>Name</th><th>Sides are called</th>
    <th class="num">Open left</th><th class="num">Open right</th>
    <th class="num">Difference</th><th class="num">Runs</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $diff = (float)$r['l_val'] - (float)$r['b_val']; ?>
    <tr<?= $r['active'] ? '' : ' style="opacity:.5"' ?>>
      <td><?= $r['id'] == $here ? '<span class="tag manual">working on</span>' : '' ?></td>
      <td><b><?= h($r['name']) ?></b>
        <?php if ($r['notes']): ?><br><span class="muted small"><?= h($r['notes']) ?></span><?php endif; ?></td>
      <td class="small"><?= h($r['left_label']) ?> / <?= h($r['right_label']) ?></td>
      <td class="num"><?= (int)$r['l_open'] ?><br><span class="muted small"><?= money($r['l_val']) ?></span></td>
      <td class="num"><?= (int)$r['b_open'] ?><br><span class="muted small"><?= money($r['b_val']) ?></span></td>
      <td class="num <?= abs($diff) < 0.005 ? 'pos' : 'neg' ?>"><?= money($diff) ?></td>
      <td class="num"><?= (int)$r['runs_n'] ?></td>
      <td style="display:flex;gap:.4rem">
        <?php if ($r['id'] != $here): ?>
          <a class="btn ghost small" href="?switch_rec=<?= (int)$r['id'] ?>">Work on this</a>
        <?php endif; ?>
        <a class="btn ghost small" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
        <form method="post" onsubmit="return confirm('Delete <?= h($r['name']) ?>?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn ghost small" type="submit"
            style="color:var(--bad);border-color:var(--bad)">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2><?= $edit ? 'Edit ' . h($edit['name']) : 'Add a reconciliation' ?></h2>
<form method="post" class="panel">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <div class="row">
    <div style="flex:3"><label>Name</label>
      <input type="text" name="name" value="<?= h($f['name']) ?>"
             placeholder="e.g. NatWest account 1" required></div>
    <div><label>Order</label>
      <input type="number" name="sort_order" value="<?= (int)$f['sort_order'] ?>"></div>
    <div><label>&nbsp;</label>
      <label style="margin:0"><input type="checkbox" name="active" value="1" style="width:auto"
        <?= $f['active'] ? 'checked' : '' ?>> In use</label></div>
  </div>
  <div class="row">
    <div><label>The left side is called</label>
      <input type="text" name="left_label" value="<?= h($f['left_label']) ?>" placeholder="Ledger"></div>
    <div><label>The right side is called</label>
      <input type="text" name="right_label" value="<?= h($f['right_label']) ?>" placeholder="Bank"></div>
  </div>
  <p class="small muted">Those two are only what the screens say. For a bank account, Ledger and Bank.
    For a supplier account, you might use Purchase Ledger and Supplier Statement. The matching works
    the same either way.</p>
  <label>Notes</label>
  <textarea name="notes" placeholder="Account number, which system the ledger comes from, anything worth remembering"><?= h($f['notes']) ?></textarea>
  <div class="actions">
    <button class="btn" type="submit"><?= $edit ? 'Save' : 'Create and switch to it' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="recs.php">Cancel</a><?php endif; ?>
  </div>
</form>

<div class="panel">
  <h3 style="margin-top:0">A note on rules</h3>
  <p class="muted small" style="margin:0">A rule normally applies to <b>every</b> reconciliation &mdash;
    "same day, same amount" is just as true for one bank account as another. On the rule form you can
    tie a rule to a single reconciliation when it only makes sense there, such as one that keys on a
    particular supplier's narrative.</p>
</div>
<?php render_footer(); ?>
