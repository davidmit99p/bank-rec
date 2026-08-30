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
function build_preview($rows, $fileId, $token, $name, $headerRow = null, $dataStart = null)
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
    // a heading can mislead - check the choice against a real transaction row
    $map = check_columns($map, $dataRow, $fromData);

    // the spare fields this file has been given a name for
    $extras = file_extra_labels($fileId);
    foreach ($extras as $key => $label) {
        $map[$key] = null;
        foreach ($header as $i => $name) {
            if (strcasecmp(trim((string)$name), $label) === 0) { $map[$key] = $i; break; }
        }
    }

    // how many rows would actually import with these settings
    [$would, $skipped] = build_transactions($rows, $map, $dataStart - 1);

    // is any of this already in the file? same date and same value
    $dupes = $would ? find_existing_duplicates($fileId, $would) : [];

    return [
        'dupes' => $dupes,
        'file_id' => $fileId, 'token' => $token, 'name' => $name,
        'rows' => array_slice($rows, 0, 15), 'count' => count($rows),
        'width' => max(1, $width), 'map' => $map,
        'header_row' => $headerRow, 'data_start' => $dataStart,
        'usable' => count($would), 'skipped' => $skipped,
        'header' => $header, 'sample' => $dataRow, 'extras' => $extras,
    ];
}

try {
    $stage = $_POST['stage'] ?? '';

    // --- a file has just been uploaded ---------------------------------------
    if ($stage === 'upload') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if (!$fileId || !get_file($fileId)) throw new RuntimeException('Choose which file this is going into.');
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
        $preview = build_preview($rows, $fileId, $token, $name);
    }

    // --- the row numbers were changed, look again ----------------------------
    if ($stage === 'repreview') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $token  = basename($_POST['token'] ?? '');
        $name  = ($_POST['name'] ?? '') ?: $token;
        if (!is_file("$storage/$token")) throw new RuntimeException('That upload has expired. Please choose the file again.');
        $rows = read_table("$storage/$token", $name);
        $preview = build_preview($rows, $fileId, $token, $name,
                                 $_POST['header_row'] ?? null, $_POST['data_start'] ?? null);
    }

    // --- columns confirmed, do the import ------------------------------------
    if ($stage === 'confirm') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $file   = get_file($fileId);
        if (!$file) throw new RuntimeException('Choose which file this is going into.');
        $token  = basename($_POST['token'] ?? '');
        $name  = ($_POST['name'] ?? '') ?: $token;
        $path  = "$storage/$token";
        if (!is_file($path)) throw new RuntimeException('That upload has expired. Please choose the file again.');

        $rows = read_table($path, $name);
        $map  = [
            'date'        => (int)$_POST['col_date'],
            'description' => (int)$_POST['col_description'],
            'value'       => (int)$_POST['col_value'],
        ];
        foreach (['extra1', 'extra2', 'extra3'] as $x) {
            $picked = $_POST['col_' . $x] ?? '';
            $map[$x] = ($picked === '' ? null : (int)$picked);
        }
        $start = max(1, (int)($_POST['data_start'] ?? 1)) - 1;
        [$txns, $skipped] = build_transactions($rows, $map, $start);
        if (!$txns) throw new RuntimeException('No usable transactions were found. Check the column choices and the row the transactions start on.');

        $n = insert_transactions($fileId, $txns, $name);
        @unlink($path);
        flash("Imported {$n} transactions from " . $name . ' into ' . $file['name']
              . ($skipped ? " ({$skipped} rows skipped - no usable date or value)." : '.'));
        header('Location: transactions.php');
        exit;
    }

    // --- removing a whole file that was loaded earlier -----------------------
    if ($stage === 'remove_import') {
        [$ok, $msg] = delete_import((int)($_POST['import_id'] ?? 0));
        flash($msg);
        if (!$ok) $error = $msg;
        else { header('Location: import.php'); exit; }
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$files    = all_files();
$pickFile = (int)($_GET['file'] ?? 0);

render_header('Import');
?>
<h1>1. Import transactions</h1>
<p class="muted">Load a file, whatever it is. A file stands on its own &mdash; you do not have to decide
  what it is reconciled against first, and the same file can be used by more than one reconciliation.
  CSV or Excel. Importing adds to what is already there, so you can load a month at a time. Before
  anything goes in you get a look at the file, and a warning if the transactions appear to be in
  already.</p>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if (!$files): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>There are no files yet.</b> A file is the thing you load into &mdash; a bank
      statement, a ledger extract. <a href="files.php">Create one first</a>, then come back.</p>
  </div>
<?php endif; ?>

<?php if ($preview): $pf = get_file($preview['file_id']); ?>
  <div class="panel">
    <h2 style="margin-top:0">Check the file &mdash; <?= h($preview['name']) ?></h2>
    <p class="muted">Going into <b><?= h($pf['name'] ?? 'a file') ?></b>.
      <?= (int)$preview['count'] ?> rows in the file.
      With these settings <b><?= (int)$preview['usable'] ?></b> would import<?php
        if ($preview['skipped']): ?>, and <?= (int)$preview['skipped'] ?>
        would be skipped for having no usable date or value<?php endif; ?>.</p>

    <?php if ($preview['dupes']): ?>
      <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
        <h3 style="margin-top:0">&#9888; <?= count($preview['dupes']) ?>
          of these look like they are already in this file</h3>
        <p class="muted small">Same date and same value. That usually means the file, or part of it,
          has been imported before. Have a look and decide &mdash; importing anyway will give you two
          copies of each. If it turns out you have loaded something twice, you can remove a whole
          import lower down this page.</p>
        <div class="scroll" style="max-height:22rem">
          <table>
            <thead><tr>
              <th colspan="3" style="border-bottom:2px solid var(--accent)">Already in <?= h($pf['name'] ?? '') ?></th>
              <th colspan="3" style="border-bottom:2px solid var(--accent)">In the file you are importing</th>
            </tr>
            <tr><th>Date</th><th>Description</th><th class="num">Value</th>
                <th>Date</th><th>Description</th><th class="num">Value</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($preview['dupes'], 0, 60) as $d):
                $first = true;
                foreach ($d['existing'] as $e): ?>
              <tr>
                <td class="small"><?= h($e['txn_date']) ?></td>
                <td class="desc" title="<?= h($e['description']) ?>"><?= h($e['description']) ?>
                  <?php if ($e['matched_at']): ?><span class="tag">matched</span><?php endif; ?></td>
                <td class="num <?= $e['value'] < 0 ? 'neg' : '' ?>"><?= money($e['value']) ?></td>
                <?php if ($first): ?>
                  <td class="small"><?= h($d['incoming'][0]) ?></td>
                  <td class="desc" title="<?= h($d['incoming'][1]) ?>"><?= h($d['incoming'][1]) ?></td>
                  <td class="num <?= $d['incoming'][2] < 0 ? 'neg' : '' ?>"><?= money($d['incoming'][2]) ?></td>
                <?php else: ?>
                  <td colspan="3" class="muted small">&ldquo; also matches the row above</td>
                <?php endif; $first = false; ?>
              </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (count($preview['dupes']) > 60): ?>
          <p class="small muted">Showing the first 60 of <?= count($preview['dupes']) ?>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="file_id" value="<?= (int)$preview['file_id'] ?>">
      <input type="hidden" name="token"   value="<?= h($preview['token']) ?>">
      <input type="hidden" name="name"    value="<?= h($preview['name']) ?>">

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

      <?php if (!$preview['extras']): ?>
        <p class="small muted" style="margin-top:.75rem">Only the date, description and value are being
          brought in. To carry more from this file &mdash; a reference, a type, a cost centre &mdash;
          give <?= h($pf['name'] ?? 'the file') ?> some
          <a href="files.php?edit=<?= (int)$preview['file_id'] ?>">spare field names</a> first, then
          import again.</p>
      <?php else: ?>
      <div class="row">
        <?php foreach ($preview['extras'] as $key => $label): ?>
        <div>
          <label><?= h($label) ?> <span class="muted small">(spare field)</span></label>
          <select name="col_<?= $key ?>">
            <option value="">not in this file</option>
            <?php for ($i = 0; $i < $preview['width']; $i++):
                $head = trim((string)($preview['header'][$i] ?? ''));
                $samp = trim((string)($preview['sample'][$i] ?? ''));
                $bits = array_filter([
                    $head !== '' ? mb_substr($head, 0, 20) : '',
                    $samp !== '' ? 'e.g. ' . mb_substr($samp, 0, 20) : '',
                ]); ?>
              <option value="<?= $i ?>"<?= (string)($preview['map'][$key] ?? '') === (string)$i ? ' selected' : '' ?>>
                <?= chr(65 + $i) ?><?= $bits ? ' - ' . h(implode(' - ', $bits)) : '' ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="actions">
        <?php
          $warn = $preview['dupes']
            ? count($preview['dupes']) . ' of these look like they are already loaded. Import anyway?'
            : '';
        ?>
        <button class="btn" type="submit" name="stage" value="confirm"
          <?= $warn ? 'onclick="return confirm(' . h(json_encode($warn)) . ')"' : '' ?>>
          Import <?= (int)$preview['usable'] ?> rows into <?= h($pf['name'] ?? 'the file') ?></button>
        <a class="btn ghost" href="import.php">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if ($files): ?>
<div class="panel">
  <h2 style="margin-top:0">Load a file</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="stage" value="upload">
    <div class="row" style="align-items:end">
      <div><label>Into which file</label>
        <select name="file_id" required>
          <option value="">choose...</option>
          <?php foreach ($files as $ff): ?>
            <option value="<?= (int)$ff['id'] ?>"<?= $pickFile === (int)$ff['id'] ? ' selected' : '' ?>>
              <?= h($ff['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div style="flex:2"><label>Choose a file from your computer</label>
        <input type="file" name="file" accept=".csv,.txt,.tsv,.xlsx,.xlsm" required></div>
      <div><label>&nbsp;</label>
        <button class="btn" type="submit">Upload and preview</button></div>
    </div>
  </form>
  <p class="small muted" style="margin-bottom:0">Not there? <a href="files.php">Add a file</a> first.</p>
</div>
<?php endif; ?>

<h2>Imports already loaded</h2>
<p class="muted small">Loaded the wrong thing, or the same file twice? Remove the whole batch here.
  Anything from it that has already been matched is unmatched first &mdash; a whole match at a time,
  so nothing is left half matched. Removing and reloading is also how you pick up columns that were
  not being captured at the time, such as spare fields added after the event.</p>

<?php $imports = list_imports(); ?>
<div class="panel">
<table>
  <thead><tr><th>Loaded</th><th>Into</th><th>From</th><th class="num">Rows</th>
    <th class="num">Value</th><th>Covering</th><th class="num">Still there</th>
    <th class="num">Matched</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($imports as $imp):
      $note = $imp['matched']
        ? $imp['matched'] . ' of these are matched and would be unmatched first. ' : '';
  ?>
    <tr>
      <td class="small"><?= h($imp['imported_at']) ?></td>
      <td><?= h($imp['file_name'] ?? '') ?></td>
      <td class="desc" title="<?= h($imp['filename']) ?>"><?= h($imp['filename']) ?></td>
      <td class="num"><?= number_format((int)$imp['row_count']) ?></td>
      <td class="num <?= $imp['total_value'] < 0 ? 'neg' : '' ?>"><?= money($imp['total_value']) ?></td>
      <td class="small"><?= h($imp['date_from']) ?> to <?= h($imp['date_to']) ?></td>
      <td class="num"><?= number_format((int)$imp['still_there']) ?></td>
      <td class="num"><?= number_format((int)$imp['matched']) ?></td>
      <td>
        <form method="post" onsubmit="return confirm(<?= h(json_encode(
            $note . 'Remove all ' . (int)$imp['still_there'] . ' transactions loaded from '
            . $imp['filename'] . '? This cannot be undone.')) ?>)">
          <input type="hidden" name="stage" value="remove_import">
          <input type="hidden" name="import_id" value="<?= (int)$imp['id'] ?>">
          <button class="btn ghost small" type="submit"
            style="color:var(--bad);border-color:var(--bad)">Remove</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$imports): ?>
    <tr><td colspan="9" class="muted">Nothing loaded yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php render_footer(); ?>
