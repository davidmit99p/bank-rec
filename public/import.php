<?php
// Step 1: load the ledger file into one table and the bank file into the other.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/importer.php';

$storage = __DIR__ . '/../storage/uploads';
if (!is_dir($storage)) @mkdir($storage, 0775, true);

$error   = null;
$preview = null;   // set when we are showing the confirm-columns step

// Build everything the preview screen needs. $headerRow and $dataStart are
// 1-based row numbers as the user sees them; 0 means "there is no heading row".
function build_preview($rows, $side, $token, $name, $headerRow = null, $dataStart = null)
{
    $width = 0;
    foreach (array_slice($rows, 0, 50) as $r) $width = max($width, count($r));

    // sensible defaults the first time through
    if ($dataStart === null) $dataStart = find_data_start($rows) + 1;
    if ($headerRow === null) {
        $above = $dataStart - 2;                      // the row just above the data
        $headerRow = ($above >= 0 && isset($rows[$above]) && looks_like_header($rows[$above]))
            ? $above + 1 : 0;
    }

    $dataStart = max(1, min((int)$dataStart, max(1, count($rows))));
    $headerRow = max(0, min((int)$headerRow, count($rows)));

    $header  = $headerRow > 0 ? ($rows[$headerRow - 1] ?? []) : [];
    $dataRow = $rows[$dataStart - 1] ?? [];

    // guess from the headings if there are any, then fill any gaps by looking
    // at what a real transaction row actually contains
    $map = $header ? guess_columns($header) : ['date' => null, 'description' => null, 'value' => null];
    $fromData = guess_columns_from_row($dataRow);
    foreach ($map as $k => $v) {
        if ($v === null || !$header) $map[$k] = $fromData[$k];
    }
    foreach ($map as $k => $v) {
        if ($map[$k] === null) $map[$k] = ['date' => 0, 'description' => 1, 'value' => 2][$k];
    }

    // how many rows would actually import with these settings
    [$would, $skipped] = build_transactions($rows, $map, $dataStart - 1);

    return [
        'side' => $side, 'token' => $token, 'name' => $name,
        'rows' => array_slice($rows, 0, 15), 'count' => count($rows),
        'width' => max(1, $width), 'map' => $map,
        'header_row' => $headerRow, 'data_start' => $dataStart,
        'usable' => count($would), 'skipped' => $skipped,
        'header' => $header, 'sample' => $dataRow,
    ];
}

try {
    $stage = $_POST['stage'] ?? '';

    // --- a file has just been uploaded ---------------------------------------
    if ($stage === 'upload') {
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
        $preview = build_preview($rows, $side, $token, $name);
    }

    // --- the row numbers were changed, look again ----------------------------
    if ($stage === 'repreview') {
        $side  = ($_POST['side'] ?? '') === 'bank' ? 'bank' : 'ledger';
        $token = basename($_POST['token'] ?? '');
        $name  = $_POST['name'] ?? $token;
        if (!is_file("$storage/$token")) throw new RuntimeException('That upload has expired. Please choose the file again.');
        $rows = read_table("$storage/$token", $name);
        $preview = build_preview($rows, $side, $token, $name,
                                 $_POST['header_row'] ?? null, $_POST['data_start'] ?? null);
    }

    // --- columns confirmed, do the import ------------------------------------
    if ($stage === 'confirm') {
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
        $start = max(1, (int)($_POST['data_start'] ?? 1)) - 1;
        [$txns, $skipped] = build_transactions($rows, $map, $start);
        if (!$txns) throw new RuntimeException('No usable transactions were found. Check the column choices and the row the transactions start on.');

        $n = insert_transactions($side, $txns, $name);
        @unlink($path);
        flash("Imported {$n} {$side} transactions from " . $name
              . ($skipped ? " ({$skipped} rows skipped - no usable date or value)." : '.'));
        header('Location: transactions.php');
        exit;
    }

    // --- clearing a table ----------------------------------------------------
    if ($stage === 'clear') {
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
<p class="muted">Ledger on the left, bank on the right. CSV or Excel. Importing adds to what is already
there, so you can load a month at a time.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if ($preview): ?>
  <div class="panel">
    <h2 style="margin-top:0">Check the file &mdash; <?= h($preview['name']) ?></h2>
    <p class="muted"><?= (int)$preview['count'] ?> rows in the file.
      With these settings <b><?= (int)$preview['usable'] ?></b> would import<?php
        if ($preview['skipped']): ?>, and <?= (int)$preview['skipped'] ?>
        would be skipped for having no usable date or value<?php endif; ?>.</p>

    <form method="post">
      <input type="hidden" name="side"  value="<?= h($preview['side']) ?>">
      <input type="hidden" name="token" value="<?= h($preview['token']) ?>">
      <input type="hidden" name="name"  value="<?= h($preview['name']) ?>">

      <div class="row" style="align-items:end">
        <div><label>Heading row <span class="muted small">(0 if none)</span></label>
          <input type="number" name="header_row" min="0" value="<?= (int)$preview['header_row'] ?>"></div>
        <div><label>Transactions start on row</label>
          <input type="number" name="data_start" min="1" value="<?= (int)$preview['data_start'] ?>"></div>
        <div><label>&nbsp;</label>
          <button class="btn ghost" type="submit" name="stage" value="repreview">Update preview</button></div>
        <div style="flex:2"></div>
      </div>

      <div class="scroll" style="margin-top:.75rem">
        <table>
          <thead><tr><th>Row</th><?php for ($i = 0; $i < $preview['width']; $i++): ?>
            <th><?= chr(65 + $i) ?></th><?php endfor; ?></tr></thead>
          <tbody>
          <?php foreach ($preview['rows'] as $ri => $r):
              $n = $ri + 1;
              $isHead = ($n === (int)$preview['header_row']);
              $isData = ($n >= (int)$preview['data_start']);
              $style = $isHead ? 'background:#eef3fb;font-weight:600' : ($isData ? '' : 'opacity:.45'); ?>
            <tr style="<?= $style ?>">
              <td class="muted small" style="white-space:nowrap"><?= $n ?><?php
                if ($isHead) echo ' &larr; heading';
                elseif ($n === (int)$preview['data_start']) echo ' &larr; first'; ?></td>
              <?php for ($i = 0; $i < $preview['width']; $i++): ?>
                <td class="small"><?= h(mb_substr((string)($r[$i] ?? ''), 0, 40)) ?></td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="small muted">Greyed rows are above where the transactions start and will be ignored.
        If that is wrong, change the two boxes above and press Update preview.</p>

      <div class="row">
        <?php foreach (['date' => 'Date column', 'description' => 'Description column', 'value' => 'Value column'] as $k => $label): ?>
        <div>
          <label><?= $label ?></label>
          <select name="col_<?= $k ?>">
            <?php for ($i = 0; $i < $preview['width']; $i++):
                $head = trim((string)($preview['header'][$i] ?? ''));
                $samp = trim((string)($preview['sample'][$i] ?? ''));
                $bits = array_filter([
                    $head !== '' ? mb_substr($head, 0, 20) : '',
                    $samp !== '' ? 'e.g. ' . mb_substr($samp, 0, 20) : '',
                ]); ?>
              <option value="<?= $i ?>"<?= (int)$preview['map'][$k] === $i ? ' selected' : '' ?>>
                <?= chr(65 + $i) ?><?= $bits ? ' - ' . h(implode(' - ', $bits)) : '' ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="actions">
        <button class="btn" type="submit" name="stage" value="confirm">
          Import <?= (int)$preview['usable'] ?> rows into the <?= h($preview['side']) ?> table</button>
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
