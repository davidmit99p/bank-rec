<?php
// Step 1: load the ledger file into one table and the bank file into the other.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/importer.php';

$storage = __DIR__ . '/../storage/uploads';
if (!is_dir($storage)) @mkdir($storage, 0775, true);

$error   = null;
$preview = null;   // set when we are showing the confirm-columns step

try {
    // --- step A: a file has just been uploaded -------------------------------
    if (($_POST['stage'] ?? '') === 'upload') {
        $side = ($_POST['side'] ?? '') === 'bank' ? 'bank' : 'ledger';
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please choose a file to upload.');
        }
        $name  = $_FILES['file']['name'];
        $token = bin2hex(random_bytes(8)) . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!move_uploaded_file($_FILES['file']['tmp_name'], "$storage/$token")) {
            throw new RuntimeException('Could not save the uploaded file.');
        }
        $rows = read_table("$storage/$token", $name);
        if (!$rows) throw new RuntimeException('That file appears to be empty.');

        $header    = $rows[0];
        $skipFirst = looks_like_header($header);
        $preview = [
            'side' => $side, 'token' => $token, 'name' => $name,
            'rows' => array_slice($rows, 0, 8), 'count' => count($rows),
            'map' => guess_columns($header), 'skip' => $skipFirst,
            'width' => max(array_map('count', array_slice($rows, 0, 20))),
        ];
    }

    // --- step B: columns confirmed, do the import ----------------------------
    if (($_POST['stage'] ?? '') === 'confirm') {
        $side  = ($_POST['side'] ?? '') === 'bank' ? 'bank' : 'ledger';
        $token = basename($_POST['token'] ?? '');
        $name  = $_POST['name'] ?? $token;
        $path  = "$storage/$token";
        if (!is_file($path)) throw new RuntimeException('That upload has expired. Please choose the file again.');

        $rows = read_table($path, $name);
        $map  = [
            'date'        => (int)$_POST['col_date'],
            'description' => (int)$_POST['col_description'],
            'value'       => (int)$_POST['col_value'],
        ];
        [$txns, $skipped] = build_transactions($rows, $map, !empty($_POST['skip']));
        if (!$txns) throw new RuntimeException('No usable transactions were found. Check the column choices.');

        $n = insert_transactions($side, $txns, $name);
        @unlink($path);
        $label = $side === 'ledger' ? 'ledger' : 'bank';
        flash("Imported {$n} {$label} transactions from " . $name
              . ($skipped ? " ({$skipped} rows skipped - no usable date or value)." : '.'));
        header('Location: transactions.php');
        exit;
    }

    // --- clearing a table ----------------------------------------------------
    if (($_POST['stage'] ?? '') === 'clear') {
        $side  = ($_POST['side'] ?? '') === 'bank' ? 'bank' : 'ledger';
        $table = $side === 'ledger' ? 'rec_ledger' : 'rec_bank';
        $open  = (int)db()->query("SELECT COUNT(*) FROM {$table} WHERE matched_at IS NULL")->fetchColumn();
        db()->exec("DELETE FROM {$table} WHERE matched_at IS NULL");
        flash("Removed {$open} unmatched {$side} transactions. Matched history was left alone.");
        header('Location: import.php');
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$counts = [
    'ledger' => db()->query("SELECT COUNT(*) c, SUM(matched_at IS NULL) o FROM rec_ledger")->fetch(),
    'bank'   => db()->query("SELECT COUNT(*) c, SUM(matched_at IS NULL) o FROM rec_bank")->fetch(),
];

render_header('Import');
?>
<h1>1. Import transactions</h1>
<p class="muted">Ledger on the left, bank on the right. CSV or Excel (.xlsx). Importing adds to what is
already there, so you can load a month at a time.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if ($preview): ?>
  <div class="panel">
    <h2 style="margin-top:0">Check the columns &mdash; <?= h($preview['name']) ?></h2>
    <p class="muted"><?= (int)$preview['count'] ?> rows found. Tell me which column is which.</p>
    <div class="scroll" style="max-height:auto">
      <table>
        <thead><tr><th></th><?php for ($i = 0; $i < $preview['width']; $i++): ?>
          <th>Column <?= chr(65 + $i) ?></th><?php endfor; ?></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $ri => $r): ?>
          <tr><td class="muted small"><?= $ri + 1 ?></td>
          <?php for ($i = 0; $i < $preview['width']; $i++): ?>
            <td class="small"><?= h(mb_substr((string)($r[$i] ?? ''), 0, 60)) ?></td>
          <?php endfor; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post">
      <input type="hidden" name="stage" value="confirm">
      <input type="hidden" name="side"  value="<?= h($preview['side']) ?>">
      <input type="hidden" name="token" value="<?= h($preview['token']) ?>">
      <input type="hidden" name="name"  value="<?= h($preview['name']) ?>">
      <div class="row">
        <?php foreach (['date' => 'Date column', 'description' => 'Description column', 'value' => 'Value column'] as $k => $label): ?>
        <div>
          <label><?= $label ?></label>
          <select name="col_<?= $k ?>">
            <?php for ($i = 0; $i < $preview['width']; $i++): ?>
              <option value="<?= $i ?>"<?= $preview['map'][$k] === $i ? ' selected' : '' ?>>
                Column <?= chr(65 + $i) ?><?= isset($preview['rows'][0][$i]) && $preview['skip']
                  ? ' - ' . h(mb_substr((string)$preview['rows'][0][$i], 0, 25)) : '' ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <label style="margin-top:.8rem">
        <input type="checkbox" name="skip" value="1"<?= $preview['skip'] ? ' checked' : '' ?>
               style="width:auto"> First row is a heading, not a transaction
      </label>
      <div class="actions">
        <button class="btn" type="submit">Import into the <?= h($preview['side']) ?> table</button>
        <a class="btn ghost" href="import.php">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="sides">
<?php foreach (['ledger' => 'Ledger (table 1)', 'bank' => 'Bank (table 2)'] as $side => $title): ?>
  <div class="panel">
    <div class="side-head"><h2><?= $title ?></h2>
      <span class="muted small"><?= (int)$counts[$side]['c'] ?> rows,
        <?= (int)$counts[$side]['o'] ?> open</span></div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="stage" value="upload">
      <input type="hidden" name="side"  value="<?= $side ?>">
      <label>Choose a file</label>
      <input type="file" name="file" accept=".csv,.txt,.tsv,.xlsx,.xlsm" required>
      <div class="actions"><button class="btn" type="submit">Upload and preview</button></div>
    </form>
    <form method="post" onsubmit="return confirm('Remove all unmatched <?= $side ?> transactions?')"
          style="margin-top:1rem;border-top:1px solid var(--line);padding-top:.75rem">
      <input type="hidden" name="stage" value="clear">
      <input type="hidden" name="side"  value="<?= $side ?>">
      <button class="btn ghost danger" type="submit" style="background:transparent;color:var(--bad)">
        Clear unmatched <?= $side ?> items</button>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php render_footer(); ?>
