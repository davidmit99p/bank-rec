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
    'rec_id' => null, 'key_left' => 'extra1', 'key_right' => 'extra1', 'ignore_date' => 0,
    'self_contra' => 0,
];
for ($i = 1; $i <= AGREE_MAX; $i++) $blank += ['agree_left' . $i => '', 'agree_right' . $i => ''];
foreach (['l_', 'b_'] as $p) {
    $blank += [
        $p.'desc_op' => 'any',  $p.'desc_val' => '',
        $p.'value_op' => 'any', $p.'value_val' => '', $p.'value_val2' => '',
        $p.'date_op' => 'any',  $p.'date_val' => '',  $p.'date_val2' => '',
    ];
    for ($i = 1; $i <= FIELD_COND_MAX; $i++) {
        $blank += [$p.'f'.$i.'_key' => '', $p.'f'.$i.'_op' => 'any',
                   $p.'f'.$i.'_val' => '', $p.'f'.$i.'_val2' => ''];
    }
}
$r = $rule ?: $blank;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cols = ['name','sort_order','notes','date_tol','sign_mode','grouping','max_group'];
    if (recs_ready()) $cols[] = 'rec_id';
    if (key_rules_ready()) { $cols[] = 'key_left'; $cols[] = 'key_right'; }
    foreach (['l_','b_'] as $p) {
        foreach (['desc_op','desc_val','value_op','value_val','value_val2','date_op','date_val','date_val2'] as $c) {
            $cols[] = $p . $c;
        }
        if (field_conds_ready()) {
            $bits = field_range_ready() ? ['_key', '_op', '_val', '_val2'] : ['_key', '_op', '_val'];
            for ($i = 1; $i <= FIELD_COND_MAX; $i++) {
                foreach ($bits as $c) $cols[] = $p . 'f' . $i . $c;
            }
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
    if (self_contra_ready()) $vals['self_contra'] = isset($_POST['self_contra']) ? 1 : 0;
    if (agree_ready()) {
        $allowed = key_fields();
        for ($i = 1; $i <= AGREE_MAX; $i++) {
            $l = (string)($_POST['agree_left' . $i] ?? '');
            $b = (string)($_POST['agree_right' . $i] ?? '');
            // a pair only counts when both halves are chosen
            $ok = isset($allowed[$l]) && isset($allowed[$b]);
            $vals['agree_left' . $i]  = $ok ? $l : null;
            $vals['agree_right' . $i] = $ok ? $b : null;
        }
        $vals['ignore_date'] = isset($_POST['ignore_date']) ? 1 : 0;
    }
    if (field_conds_ready()) {
        // a condition only counts with a real field and a test we know; anything
        // else goes back in as nothing, so it cannot quietly narrow a rule
        $fieldKeys = array_flip(spare_keys());
        $ops       = field_ops();
        foreach (['l_','b_'] as $p) {
            for ($i = 1; $i <= FIELD_COND_MAX; $i++) {
                $base = $p . 'f' . $i;
                $key  = (string)$vals[$base . '_key'];
                $op   = (string)$vals[$base . '_op'];
                $ok   = isset($fieldKeys[$key]) && isset($ops[$op]) && $op !== 'any';
                $vals[$base . '_key'] = $ok ? $key : null;
                $vals[$base . '_op']  = $ok ? $op  : null;
                $vals[$base . '_val'] = $ok ? $vals[$base . '_val'] : null;
                if (field_range_ready()) $vals[$base . '_val2'] = $ok ? $vals[$base . '_val2'] : null;
            }
        }
    }

    // "Test" tries the rule as it stands on the form, saving nothing, and comes
    // back to the same form with what it would find.
    if (($_POST['action'] ?? '') === 'test') {
        $r = array_merge($r, $vals);
        $r['active'] = $vals['active'];
        $test = rule_test($r);
    } else {

    // the column names are quoted because one of them, "grouping", is a word
    // some versions of MySQL keep for themselves
    $names = array_keys($vals);
    if ($id) {
        $set = implode(', ', array_map(fn($c) => "`$c` = :$c", $names));
        $st = db()->prepare("UPDATE rec_rules SET $set WHERE id = :id");
        $st->execute($vals + ['id' => $id]);
        flash("Rule {$id} saved.");
    } else {
        $st = db()->prepare("INSERT INTO rec_rules (`" . implode('`,`', $names) . "`)
                             VALUES (:" . implode(', :', $names) . ")");
        $st->execute($vals);
        flash('Rule ' . db()->lastInsertId() . ' created.');
    }
    header('Location: rules.php');
    exit;
    }
}

$test = $test ?? null;

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
    // Conditions on this file's own fields - only code 4010, only period
    // 2026/03. The fields are this side's, named on its file.
    if (!field_conds_ready()) return;
    $side  = $p === 'l_' ? 'ledger' : 'bank';
    $named = extra_labels($side);
    ?>
    <label>This file's own fields</label>
    <?php if (!$named): ?>
      <p class="small muted" style="margin:0">This side's file has no named fields yet &mdash;
        name them on the <a href="files.php">Files</a> page and they can be used here.</p>
    <?php else: ?>
      <?php for ($i = 1; $i <= FIELD_COND_MAX; $i++): $b = $p . 'f' . $i; ?>
        <div class="row" style="margin-bottom:.3rem">
          <select name="<?= $b ?>_key">
            <option value="">&mdash; not used &mdash;</option>
            <?php foreach ($named as $k => $label): ?>
              <option value="<?= h($k) ?>"<?= ($r[$b.'_key'] ?? '') === $k ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php $sel($b . '_op', field_ops(), $r[$b.'_op'] ?: 'any'); ?>
          <input type="text" name="<?= $b ?>_val" value="<?= h((string)($r[$b.'_val'] ?? '')) ?>"
                 placeholder="e.g. 4010" style="flex:2">
          <?php if (field_range_ready()): ?>
            <input type="text" name="<?= $b ?>_val2" value="<?= h((string)($r[$b.'_val2'] ?? '')) ?>"
                   placeholder="and (only for is between)">
          <?php endif; ?>
        </div>
      <?php endfor; ?>
      <p class="small muted" style="margin:.1rem 0 0">Capitals and spaces at either end are ignored.
        Leave the field on &ldquo;not used&rdquo; to ignore it. <b>Is one of</b> takes a list separated by
        commas, such as 2026/01,2026/02,2026/03.<?php if (field_range_ready()): ?> <b>Is between</b> uses
        both boxes and goes by the order the values would sort in, so 2026/01 to 2026/06 takes in
        2026/03.<?php endif; ?></p>
    <?php endif; ?>
    <?php
}

render_header($id ? "Rule $id" : 'New rule');
?>
<h1><?= $id ? 'Rule ' . $id : 'New rule' ?></h1>
<p class="muted">Fill in the left form to say which <b>ledger</b> lines this rule applies to, and the right
form to say which <b>bank</b> lines they should be paired with. Leave a box on &ldquo;anything&rdquo; to ignore it.</p>

<?php if (!field_conds_ready()): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>One small database change is still to run.</b> In phpMyAdmin, run
      <code>sql/migration_014_field_conditions.sql</code> against <code>entigy_recon</code>. Until then a rule
      can be held to a description, a value and a date, but not to a particular code or period.</p>
  </div>
<?php elseif (!self_contra_ready()): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>One small database change is still to run.</b> In phpMyAdmin, run
      <code>sql/migration_016_self_contra.sql</code> against <code>entigy_recon</code>. Until then a rule
      cannot be asked to clear a period that cancels itself out on one side.</p>
  </div>
<?php elseif (!field_range_ready()): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>One small database change is still to run.</b> In phpMyAdmin, run
      <code>sql/migration_015_field_condition_range.sql</code> against <code>entigy_recon</code>. Until then a
      field condition can say <b>is one of</b> but not <b>is between</b>, which needs a second box.</p>
  </div>
<?php endif; ?>

<?php if ($test): ?>
  <?php
    $none  = !$test['fit_l'] || !$test['fit_b'];
    $shade = $none ? ['#fbeeee', '#eccfcf'] : ['#eef6ee', '#cfe3cf'];
  ?>
  <div class="panel" style="background:<?= $shade[0] ?>;border-color:<?= $shade[1] ?>">
    <h2 style="margin-top:0">What this rule finds</h2>
    <p style="margin:.2rem 0">Of the items still to be matched in
      <b><?= h(current_rec()['name'] ?? 'this reconciliation') ?></b>, the conditions fit:</p>
    <?php if ($test['held']): ?>
      <p class="small muted" style="margin:.2rem 0"><?= number_format($test['held']) ?> lines are left out of
        this because they are already in a run you have not finalised. Process would skip them too.</p>
    <?php endif; ?>
    <ul style="margin:.2rem 0 .6rem">
      <li><b><?= h(side_label('ledger')) ?>:</b> <?= number_format($test['fit_l']) ?> of
        <?= number_format($test['open_l']) ?> open lines, totalling <?= money($test['total_l']) ?></li>
      <li><b><?= h(side_label('bank')) ?>:</b> <?= number_format($test['fit_b']) ?> of
        <?= number_format($test['open_b']) ?> open lines, totalling <?= money($test['total_b']) ?></li>
    </ul>
    <?php if ($none): ?>
      <?php if ($test['held'] && !$test['open_l'] && !$test['open_b']): ?>
        <p style="margin:.2rem 0"><b>Nothing would be matched</b>, because everything is already in a run you
          have not finalised. Finish that run, or discard it, and try again.</p>
      <?php else: ?>
      <?php
        $empty = !$test['fit_l'] && !$test['fit_b']
               ? 'Neither side has anything left once its conditions have been applied.'
               : 'The ' . side_label(!$test['fit_l'] ? 'ledger' : 'bank')
                 . ' side has nothing left once its conditions have been applied.';
      ?>
      <p style="margin:.2rem 0"><b>Nothing would be matched.</b> <?= h($empty) ?> Look at the conditions there &mdash; a code written differently from the
        file, or a range of periods that misses the ones in the file, will empty a side. The
        <a href="transactions.php">Transactions</a> screen shows the values as they really are.</p>
      <?php endif; ?>
    <?php elseif ($test['groups'] === null): ?>
      <p style="margin:.2rem 0">This shape pairs lines up one by one, so what it matches depends on the
        dates and amounts as well. Press <b>Process rules</b> on the Transactions screen to see the
        suggestions; nothing is committed until you finalise them.</p>
    <?php elseif (!$test['groups'] && ($test['keys_l'] ?? null) !== null): ?>
      <?php
        $kl = file_extra_labels(side_file_id('ledger'))[$r['key_left']] ?? 'the key';
        $kb = file_extra_labels(side_file_id('bank'))[$r['key_right']] ?? 'the key';
      ?>
      <p style="margin:.2rem 0"><b>Nothing would be matched.</b> No value appears on both sides, so there is
        nothing to group. The <?= h(side_label('ledger')) ?> side has <b><?= number_format($test['keys_l']) ?></b>
        different values of <b><?= h($kl) ?></b><?= $test['blank_l'] ? ' (' . number_format($test['blank_l']) . ' lines have nothing in it)' : '' ?>;
        the <?= h(side_label('bank')) ?> side has <b><?= number_format($test['keys_b']) ?></b> of
        <b><?= h($kb) ?></b><?= $test['blank_b'] ? ' (' . number_format($test['blank_b']) . ' lines have nothing in it)' : '' ?>.
        Check that each side's key names the field that really holds it on that file, and that the two write
        it the same way.</p>
    <?php else: ?>
      <p style="margin:.2rem 0"><b><?= number_format($test['groups']) ?></b>
        <?= $test['groups'] === 1 ? 'appears' : 'appear' ?> on both sides.
        <b><?= number_format($test['balance']) ?></b> <?= $test['balance'] === 1 ? 'comes' : 'come' ?>
        to the same on each side and would be matched; <b><?= number_format($test['off']) ?></b>
        <?= $test['off'] === 1 ? 'does' : 'do' ?> not, and would be left for you to look at.</p>
      <?php if ($test['examples']): ?>
        <?php
          // what the columns of the example table actually hold, said plainly:
          // the key is a field on each file, and the agreeing values are another
          $lenNow  = period_len($r['grouping']);
          $keyHead = $lenNow === 10 ? 'Day' : ($lenNow === 7 ? 'Month' : 'Key: '
                     . (file_extra_labels(side_file_id('ledger'))[$r['key_left']] ?? 'the key field'));
          $agreeHead = '';
          if ($pairs = agree_pairs($r)) {
              $ln = file_extra_labels(side_file_id('ledger'));
              $agreeHead = implode(' / ', array_map(fn($pr) => $ln[$pr[0]] ?? $pr[0], $pairs));
          }
        ?>
        <table style="width:auto;background:var(--panel);border-radius:6px">
          <thead><tr>
            <?php if ($agreeHead): ?><th><?= h($agreeHead) ?></th><?php endif; ?>
            <th><?= h($keyHead) ?></th>
            <th class="num"><?= h(side_label('ledger')) ?></th>
            <th class="num"><?= h(side_label('bank')) ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($test['examples'] as $e): ?>
            <tr>
              <?php if ($agreeHead): ?><td><?= h($e['agree']) ?></td><?php endif; ?>
              <td><?= h($e['key']) ?></td>
              <td class="num"><?= money($e['l']) ?></td><td class="num"><?= money($e['b']) ?></td>
              <td class="small <?= $e['ok'] ? '' : 'neg' ?>"><?= $e['ok'] ? 'matches' : 'does not balance' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="small muted" style="margin:.3rem 0 0">The first few, as an example. The
          <?= h($agreeHead ? 'first two columns are' : 'first column is') ?> what the lines were grouped by.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($test['contras']): ?>
      <?php
        $byS = ['ledger' => 0, 'bank' => 0];
        $lines = 0;
        foreach ($test['contras'] as [$sd, $k, $n]) { $byS[$sd]++; $lines += $n; }
        $said = [];
        foreach ($byS as $sd => $n) {
            if ($n) $said[] = $n . ' group' . ($n === 1 ? '' : 's') . ' on the ' . side_label($sd) . ' side';
        }
      ?>
      <p style="margin:.2rem 0">It would also clear <b><?= h(implode(' and ', $said)) ?></b>
        that cancel themselves out, <?= number_format($lines) ?> lines in all:
        <?= h(implode(', ', array_map(fn($c) => $c[1], array_slice($test['contras'], 0, 5)))) ?><?= count($test['contras']) > 5 ? ' and others' : '' ?>.</p>
    <?php endif; ?>
    <?php if ($test['too_big']): ?>
      <p style="margin:.2rem 0"><b><?= number_format(count($test['too_big'])) ?></b>
        <?= count($test['too_big']) === 1 ? 'group is' : 'groups are' ?> too big to suggest, at more than
        <?= number_format(PERIOD_GROUP_CAP) ?> <?= PERIOD_GROUP_CAP === 1 ? 'line' : 'lines' ?> on one side,
        and would be left alone:
        <?= h(implode(', ', array_slice($test['too_big'], 0, 5))) ?><?= count($test['too_big']) > 5 ? ' and others' : '' ?>.
        Narrow the rule &mdash; by account, say &mdash; so they come out smaller.</p>
    <?php endif; ?>
    <p class="small muted" style="margin:.5rem 0 0">Nothing has been saved or matched. Press
      <b><?= $id ? 'Save rule' : 'Create rule' ?></b> to keep the rule as it is on this page.</p>
  </div>
<?php endif; ?>

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
    <p class="small muted">A match is only ever <b>committed</b> when both sides total exactly the same
       amount. These settings decide which candidates are allowed to be paired in the first place.
       One shape &mdash; everything in the same month &mdash; deliberately suggests groups that may
       not balance, for files summarised differently from each other; you trim those by eye on the
       review screen and nothing is committed until they come to nothing.</p>
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
    <p class="small muted" style="margin:.4rem 0 0"><b>Most lines in a group</b> is only used when several lines
      add up to one, where it caps how many are tried together. The same day, same month and same key shapes
      take everything that fits (up to <?= number_format(PERIOD_GROUP_CAP) ?> lines a side) and ignore it.</p>
    <?php if (key_rules_ready()): ?>
      <h3 style="margin-top:1.2rem">If the shape is &ldquo;everything sharing the same key&rdquo;</h3>
      <p class="small muted">Which field holds the key &mdash; a booking reference, say. Two settings,
        because the same reference can be a different spare field on each side: each file names its
        own. Rows with nothing in that field are left out, and a key found on only one side is not
        suggested. Ignored by every other shape.</p>
      <div class="row">
        <?php foreach ([['key_left', 'ledger'], ['key_right', 'bank']] as [$field, $sd]):
            $named = extra_labels($sd); ?>
          <div>
            <label>The key on the <?= h(side_label($sd)) ?> side is</label>
            <select name="<?= $field ?>">
              <?php foreach (key_fields() as $k => $generic): ?>
                <option value="<?= $k ?>"<?= ($r[$field] ?? '') === $k ? ' selected' : '' ?>>
                  <?= h($named[$k] ?? $generic) ?><?= isset($named[$k]) ? '' : ' (not named)' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (agree_ready()): ?>
      <h3 style="margin-top:1.2rem">Fields that must agree</h3>
      <p class="small muted">As well as the amount, insist that these agree on both sides &mdash; an
        accounting period, a reference, a journal type. Pick the field on each side; they can be different
        spare fields, because each file names its own. Capitals and spaces at either end are ignored.
        A line with nothing in one of these fields is left alone by this rule. Works with every shape.
        <?php if (!recs_ready() || ($r['rec_id'] ?? null) === null): ?>
          The names shown come from the reconciliation you are working on.<?php endif; ?></p>
      <?php $namedL = extra_labels('ledger'); $namedB = extra_labels('bank'); ?>
      <table style="width:auto">
        <thead><tr><th></th><th><?= h(side_label('ledger')) ?></th><th></th><th><?= h(side_label('bank')) ?></th></tr></thead>
        <tbody>
        <?php for ($i = 1; $i <= AGREE_MAX; $i++): ?>
          <tr><td class="small muted"><?= $i ?>.</td>
          <?php foreach ([['agree_left', $namedL], ['agree_right', $namedB]] as $n => [$field, $named]): ?>
            <?php if ($n === 1): ?><td>=</td><?php endif; ?>
            <td><select name="<?= $field . $i ?>">
              <option value="">&mdash; not used &mdash;</option>
              <?php foreach (key_fields() as $k => $generic): ?>
                <option value="<?= $k ?>"<?= ($r[$field . $i] ?? '') === $k ? ' selected' : '' ?>>
                  <?= h($named[$k] ?? $generic) ?><?= ($k === 'description' || isset($named[$k])) ? '' : ' (not named)' ?></option>
              <?php endforeach; ?>
            </select></td>
          <?php endforeach; ?>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
      <label style="margin-top:.8rem"><input type="checkbox" name="ignore_date" value="1" style="width:auto"
        <?= !empty($r['ignore_date']) ? 'checked' : '' ?>> Ignore dates altogether
        <span class="muted small">&mdash; the &ldquo;dates may differ by&rdquo; setting is not used; where
        there is a choice, the nearest date is still preferred. Handy when the period and reference say
        all that matters.</span></label>
    <?php endif; ?>

    <?php if (self_contra_ready()): ?>
      <label style="margin-top:.8rem"><input type="checkbox" name="self_contra" value="1" style="width:auto"
        <?= !empty($r['self_contra']) ? 'checked' : '' ?>> Also clear a key, day or month that cancels itself
        out on one side
        <span class="muted small">&mdash; a posting and its reversal sitting in one file with nothing on the
        other side to match them against. Tried after the two sides have been paired, so nothing that could
        have been matched across is taken. Used only by the same key, same day and same month shapes.</span></label>
    <?php endif; ?>

    <label style="margin-top:.8rem"><input type="checkbox" name="link_desc" value="1" style="width:auto"
      <?= $r['link_desc'] ? 'checked' : '' ?>> Only pair items whose descriptions share a word
      <span class="muted small">&mdash; useful for a general catch-all rule, e.g. ledger &ldquo;VISPA LTD&rdquo;
      with bank &ldquo;VISPA LTD QUIK INTERNET VIA MOBILE&rdquo;</span></label>

    <label>Notes</label>
    <textarea name="notes" placeholder="Why this rule exists, anything to watch for"><?= h($r['notes']) ?></textarea>
  </div>

  <div class="actions">
    <button class="btn" type="submit" name="action" value="save"><?= $id ? 'Save rule' : 'Create rule' ?></button>
    <button class="btn ghost" type="submit" name="action" value="test"
      title="Try it against the open items without saving or matching anything">Test this rule</button>
    <a class="btn ghost" href="rules.php">Cancel</a>
  </div>
</form>
<?php render_footer(); ?>
