<?php
// The rule library. Grows over time.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';

if (($_POST['action'] ?? '') === 'toggle') {
    db()->prepare("UPDATE rec_rules SET active = 1 - active WHERE id = ?")->execute([(int)$_POST['id']]);
    header('Location: rules.php'); exit;
}
if (($_POST['action'] ?? '') === 'delete') {
    db()->prepare("DELETE FROM rec_rules WHERE id = ?")->execute([(int)$_POST['id']]);
    flash('Rule deleted. Matches already finalised under it keep their rule number.');
    header('Location: rules.php'); exit;
}
// Move a rule up or down the running order. Swaps it with its neighbour and
// renumbers everything 10, 20, 30... so the gaps stay tidy.
if (($_POST['action'] ?? '') === 'move') {
    $id  = (int)$_POST['id'];
    $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
    $ids = db()->query("SELECT id FROM rec_rules ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);
    $pos = array_search($id, $ids);
    $new = $pos === false ? -1 : $pos + $dir;
    if ($pos !== false && $new >= 0 && $new < count($ids)) {
        [$ids[$pos], $ids[$new]] = [$ids[$new], $ids[$pos]];
        $st = db()->prepare("UPDATE rec_rules SET sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $rid) $st->execute([($i + 1) * 10, $rid]);
    }
    header('Location: rules.php'); exit;
}

$rules = db()->query("SELECT * FROM rec_rules ORDER BY sort_order, id")->fetchAll();

// A short readable summary of one side of a rule.
function side_summary(array $r, $p)
{
    $bits = [];
    if ($r[$p.'desc_op'] !== 'any' && $r[$p.'desc_val'] !== null && $r[$p.'desc_val'] !== '') {
        $bits[] = 'description ' . desc_ops()[$r[$p.'desc_op']] . ' "' . $r[$p.'desc_val'] . '"';
    }
    if ($r[$p.'value_op'] !== 'any') {
        $v = 'value ' . value_ops()[$r[$p.'value_op']];
        if (in_array($r[$p.'value_op'], ['equals','abs_equals','gt','lt'], true)) $v .= ' ' . money($r[$p.'value_val']);
        if ($r[$p.'value_op'] === 'between') $v .= ' ' . money($r[$p.'value_val']) . ' and ' . money($r[$p.'value_val2']);
        $bits[] = $v;
    }
    if ($r[$p.'date_op'] !== 'any') {
        $d = 'date ' . date_ops()[$r[$p.'date_op']] . ' ' . $r[$p.'date_val'];
        if ($r[$p.'date_op'] === 'between') $d .= ' and ' . $r[$p.'date_val2'];
        $bits[] = $d;
    }
    return $bits ? implode(', ', $bits) : 'any transaction';
}

render_header('Rules');
?>
<h1>2. Rules</h1>
<p class="muted">Each rule describes what to look for in the ledger (left) and what it should be paired
with in the bank (right). Rules are tried top to bottom, and once a transaction is claimed by one rule
the later ones leave it alone &mdash; so put your tightest rules at the top and use the arrows to
reorder.</p>
<p><a class="btn" href="rule-edit.php">Add a rule</a></p>

<?php if (!$rules): ?>
  <div class="panel"><p class="muted">No rules yet. Add your first one, or start with the
  suggested starter set in <code>sql/starter_rules.sql</code>.</p></div>
<?php endif; ?>

<?php $last = count($rules) - 1; foreach ($rules as $i => $r): ?>
<div class="panel<?= $r['active'] ? '' : ' group-card off' ?>">
  <div class="group-card" style="border:0;padding:0;margin:0">
    <header>
      <span class="muted small" title="the order this rule is tried in"><?= $i + 1 ?>.</span>
      <span class="tag">Rule <?= (int)$r['id'] ?></span>
      <b><?= h($r['name']) ?></b>
      <span class="muted small"><?= h(grouping_modes()[$r['grouping']] ?? $r['grouping']) ?>
        &middot; dates within <?= (int)$r['date_tol'] ?> day<?= $r['date_tol'] == 1 ? '' : 's' ?>
        <?= $r['sign_mode'] === 'opposite' ? '&middot; signs reversed' : '' ?>
        <?= $r['link_desc'] ? '&middot; descriptions must agree' : '' ?></span>
      <span style="margin-left:auto;display:flex;gap:.4rem">
        <form method="post"><input type="hidden" name="action" value="move">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="dir" value="up">
          <button class="btn ghost small" type="submit" title="Run this rule earlier"
            <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button></form>
        <form method="post"><input type="hidden" name="action" value="move">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="dir" value="down">
          <button class="btn ghost small" type="submit" title="Run this rule later"
            <?= $i === $last ? 'disabled' : '' ?>>&darr;</button></form>
        <a class="btn ghost small" href="rule-edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
        <form method="post"><input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn ghost small" type="submit"><?= $r['active'] ? 'Turn off' : 'Turn on' ?></button></form>
        <form method="post" onsubmit="return confirm('Delete this rule?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn ghost small" type="submit" style="color:var(--bad);border-color:var(--bad)">Delete</button></form>
      </span>
    </header>
    <div class="sides">
      <div><span class="muted small">Ledger side</span><br><?= h(side_summary($r, 'l_')) ?></div>
      <div><span class="muted small">Bank side</span><br><?= h(side_summary($r, 'b_')) ?></div>
    </div>
    <?php if ($r['notes']): ?><p class="small muted" style="margin:.4rem 0 0"><?= h($r['notes']) ?></p><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php render_footer(); ?>
