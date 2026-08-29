<?php
// Step 2 & 3: the two-sided rule form.
// Left form = table 1 (ledger). Right form = table 2 (bank).
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matcher.php';

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$rule = null;

if ($id) {
    $st = db()->prepare("SELECT * FROM rec_rules WHERE id = ?");
    $st->execute([$id]);
    $rule = $st->fetch();
    if (!$rule) { flash('That rule no longer exists.'); header('Location: rules.php'); exit; }
}

// a blank rule for the "add" case
$blank = [
    'name' => '', 'active' => 1, 'sort_order' => 100, 'notes' => '',
    'date_tol' => 3, 'sign_mode' => 'same', 'grouping' => 'one', 'max_group' => 4, 'link_desc' => 0,
    'rec_id' => null,
];
foreach (['l_', 'b_'] as $p) {
    $blank += [
        $p.'desc_op' => 'any',  $p.'desc_val' => '',
        $p.'value_op' => 'any', $p.'value_val' => '', $p.'value_val2' => '',
        $p.'date_op' => 'any',  $p.'date_val' => '',  $p.'date_val2' => '',
    ];
}
$r = $rule ?: $blank;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cols = ['name','sort_order','notes','date_tol','sign_mode','grouping','max_group'];
    if (recs_ready()) $cols[] = 'rec_id';
    foreach (['l_','b_'] as $p) {
        foreach (['desc_op','desc_val','value_op','value_val','value_val2','date_op','date_val','date_val2'] as $c) {
            $cols[] = $p . $c;
        }
    }
    $vals = [];
    foreach ($cols as $c) {
        $v = trim((string)($_POST[$c] ?? ''));
        // empty numbers and dates must go in as NULL, not ''
        if ($v === '' && (str_ends_with($c, 'value_val') || str_ends_with($c, 'value_val2')
                       || str_ends_with($c, 'date_val') || str_ends_with($c, 'date_val2'))) $v = null;
        $vals[$c] = $v;
    }
    $vals['name']       = $vals['name'] !== '' ? $vals['name'] : 'Untitled rule';
    $vals['sort_order'] = (int)($vals['sort_order'] ?: 100);
    $vals['date_tol']   = max(0, (int)$vals['date_tol']);
    $vals['max_group']  = min(8, max(2, (int)($vals['max_group'] ?: 4)));
    $vals['active']     = isset($_POST['active']) ? 1 : 0;
    if (recs_ready()) $vals['rec_id'] = $vals['rec_id'] === '' ? null : (int)$vals['rec_id'];
    $vals['link_desc']  = isset($_POST['link_desc']) ? 1 : 0;

    $names = array_keys($vals);
    if ($id) {
        $set = implode(', ', array_map(fn($c) => "$c = :$c", $names));
        $st = db()->prepare("UPDATE rec_rules SET $set WHERE id = :id");
        $st->execute($vals + ['id' => $id]);
        flash("Rule {$id} saved.");
    } else {
        $st = db()->prepare("INSERT INTO rec_rules (" . implode(',', $names) . ")
                             VALUES (:" . implode(', :', $names) . ")");
        $st->execute($vals);
        flash('Rule ' . db()->lastInsertId() . ' created.');
    }
    header('Location: rules.php');
    exit;
}

// Draw one side of the form. $p is 'l_' or 'b_'.
function side_form(array $r, $p)
{
    $sel = function ($name, $options, $current) {
        echo '<select name="' . h($name) . '" data-op="' . h($name) . '">';
        foreach ($options as $k => $label) {
            echo '<option value="' . h($k) . '"' . ($current === $k ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select>';
    };
    ?>
    <label>Description</label>
    <div class="row">
      <?php $sel($p.'desc_op', desc_ops(), $r[$p.'desc_op']); ?>
      <input type="text" name="<?= $p ?>desc_val" value="<?= h($r[$p.'desc_val']) ?>"
             placeholder="e.g. VISPA" style="flex:2">
    </div>

    <label>Value</label>
    <div class="row">
      <?php $sel($p.'value_op', value_ops(), $r[$p.'value_op']); ?>
      <input type="text" name="<?= $p ?>value_val"  value="<?= h($r[$p.'value_val']) ?>"  placeholder="0.00">
      <input type="text" name="<?= $p ?>value_val2" value="<?= h($r[$p.'value_val2']) ?>" placeholder="and 0.00">
    </div>

    <label>Date</label>
    <div class="row">
      <?php $sel($p.'date_op', date_ops(), $r[$p.'date_op']); ?>
      <input type="date" name="<?= $p ?>date_val"  value="<?= h($r[$p.'date_val']) ?>">
      <input type="date" name="<?= $p ?>date_val2" value="<?= h($r[$p.'date_val2']) ?>">
    </div>
    <?php
}

render_header($id ? "Rule $id" : 'New rule');
?>
<h1><?= $id ? 'Rule ' . $id : 'New rule' ?></h1>
<p class="muted">Fill in the left form to say which <b>ledger</b> lines this rule applies to, and the right
form to say which <b>bank</b> lines they should be paired with. Leave a box on &ldquo;anything&rdquo; to ignore it.</p>

<form method="post">
  <div class="panel">
    <div class="row">
      <div style="flex:3"><label>Rule name</label>
        <input type="text" name="name" value="<?= h($r['name']) ?>" placeholder="e.g. Vispa monthly hosting" required></div>
      <div><label>Order</label>
        <input type="number" name="sort_order" value="<?= (int)$r['sort_order'] ?>"></div>
      <div><label>&nbsp;</label>
        <label style="margin:0"><input type="checkbox" name="active" value="1" style="width:auto"
          <?= $r['active'] ? 'checked' : '' ?>> Rule is active</label></div>
    </div>
    <?php if (recs_ready()): ?>
      <label>Applies to</label>
      <select name="rec_id">
        <option value="">Every reconciliation</option>
        <?php foreach (all_recs() as $rr): ?>
          <option value="<?= (int)$rr['id'] ?>"<?= (string)($r['rec_id'] ?? '') === (string)$rr['id'] ? ' selected' : '' ?>>
            Only <?= h($rr['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="small muted">Most rules belong to every reconciliation &mdash; &ldquo;same day, same
        amount&rdquo; is just as true for one bank account as another. Tie a rule to one only when it
        keys on something particular to that account.</p>
    <?php endif; ?>
  </div>

  <div class="sides">
    <div class="panel">
      <div class="side-head"><h2><?= h(side_label('ledger')) ?> &mdash; table 1</h2><span class="muted small">left</span></div>
      <?php side_form($r, 'l_'); ?>
    </div>
    <div class="panel">
      <div class="side-head"><h2><?= h(side_label('bank')) ?> &mdash; table 2</h2><span class="muted small">right</span></div>
      <?php side_form($r, 'b_'); ?>
    </div>
  </div>

  <div class="panel">
    <h2 style="margin-top:0">How the two sides are paired</h2>
    <p class="small muted">A match is only ever made when both sides total exactly the same amount.
       These settings decide which candidates are allowed to be paired in the first place.</p>
    <div class="row">
      <div><label>Shape of the match</label>
        <select name="grouping">
          <?php foreach (grouping_modes() as $k => $label): ?>
            <option value="<?= $k ?>"<?= $r['grouping'] === $k ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Most lines in a group</label>
        <input type="number" name="max_group" min="2" max="8" value="<?= (int)$r['max_group'] ?>"></div>
      <div><label>Dates may differ by (days)</label>
        <input type="number" name="date_tol" min="0" max="120" value="<?= (int)$r['date_tol'] ?>"></div>
      <div><label>Signs</label>
        <select name="sign_mode">
          <option value="same"<?= $r['sign_mode'] === 'same' ? ' selected' : '' ?>>Both sides same sign</option>
          <option value="opposite"<?= $r['sign_mode'] === 'opposite' ? ' selected' : '' ?>>Bank is the opposite sign</option>
        </select></div>
    </div>
    <label style="margin-top:.8rem"><input type="checkbox" name="link_desc" value="1" style="width:auto"
      <?= $r['link_desc'] ? 'checked' : '' ?>> Only pair items whose descriptions share a word
      <span class="muted small">&mdash; useful for a general catch-all rule, e.g. ledger &ldquo;VISPA LTD&rdquo;
      with bank &ldquo;VISPA LTD QUIK INTERNET VIA MOBILE&rdquo;</span></label>

    <label>Notes</label>
    <textarea name="notes" placeholder="Why this rule exists, anything to watch for"><?= h($r['notes']) ?></textarea>
  </div>

  <div class="actions">
    <button class="btn" type="submit"><?= $id ? 'Save rule' : 'Create rule' ?></button>
    <a class="btn ghost" href="rules.php">Cancel</a>
  </div>
</form>
<?php render_footer(); ?>
