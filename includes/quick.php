<?php
// -----------------------------------------------------------------------------
// The quick route: two files in, one reconciliation out, from a single screen.
//
// Nothing here is a new kind of thing. It creates two ordinary files, imports
// into each with the ordinary importer, and creates an ordinary reconciliation
// pairing them - the same records the Files, Import and Reconciliations screens
// make one at a time. The only addition is the one_off flag (migration 013), so
// the lot can be recognised and removed in one step afterwards.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/importer.php';

// Rows sent to the browser for each file. Enough to show the top of a report
// with its title lines, the heading, and the first few transactions.
const QUICK_PREVIEW_ROWS = 40;

function quick_storage()
{
    $dir = __DIR__ . '/../storage/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// Has migration 013 been run? Until it has, one-offs cannot be marked.
function oneoff_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT one_off FROM rec_recs LIMIT 1");
            db()->query("SELECT one_off FROM rec_files LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// An upload token is only ever a name we made: 16 hex characters and a known
// extension. Anything else could be a path, so it is refused.
function quick_token_path($token)
{
    $token = (string)$token;
    if (!preg_match('/^q[0-9a-f]{16}\.(csv|txt|tsv|xlsx|xlsm)$/', $token)) return null;
    $path = quick_storage() . '/' . $token;
    return is_file($path) ? $path : null;
}

// Uploads for a quick reconciliation that was never finished would otherwise
// sit in storage for ever. Anything of ours older than a day goes.
function quick_tidy_uploads()
{
    foreach (glob(quick_storage() . '/q*') ?: [] as $f) {
        if (preg_match('/[\\\\\/]q[0-9a-f]{16}\.\w+$/', $f) && filemtime($f) < time() - 86400) @unlink($f);
    }
}

// Save one uploaded file under a token of our own making.
function quick_store_upload(array $upload)
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please choose both files.');
    }
    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'tsv', 'xlsx', 'xlsm'], true)) {
        throw new RuntimeException($upload['name'] . ' is not a CSV or Excel file.');
    }
    $token = 'q' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($upload['tmp_name'], quick_storage() . '/' . $token)) {
        throw new RuntimeException('Could not save ' . $upload['name'] . '.');
    }
    return $token;
}

// Where the heading is, where the data starts, and which column is which -
// the same guesses the Import screen makes (see build_preview in import.php).
// Row numbers are 1-based as the person sees them; heading 0 means none.
function guess_layout(array $rows, $headerRow = null, $dataStart = null)
{
    if ($dataStart === null) $dataStart = find_data_start($rows) + 1;
    if ($headerRow === null) {
        $above = $dataStart - 2;
        $headerRow = ($above >= 0 && isset($rows[$above]) && looks_like_header($rows[$above])) ? $above + 1 : 0;
    }
    $dataStart = max(1, min((int)$dataStart, max(1, count($rows))));
    $headerRow = max(0, min((int)$headerRow, count($rows)));

    $header  = $headerRow > 0 ? ($rows[$headerRow - 1] ?? []) : [];
    $dataRow = $rows[$dataStart - 1] ?? [];

    $map = $header ? guess_columns($header) : ['date' => null, 'description' => null, 'value' => null];
    $fromData = guess_columns_from_row($dataRow);
    foreach ($map as $k => $v) {
        if ($v === null || !$header) $map[$k] = $fromData[$k];
    }
    foreach ($map as $k => $v) {
        if ($map[$k] === null) $map[$k] = ['date' => 0, 'description' => 1, 'value' => 2][$k];
    }
    $map = check_columns($map, $dataRow, $fromData);
    return ['header_row' => $headerRow, 'data_start' => $dataStart, 'map' => $map];
}

// Everything the screen needs about one file: the top rows, the sheets if it
// is a workbook, and the guesses.
function quick_describe($token, $name, $sheet = null, $headerRow = null, $dataStart = null)
{
    $path = quick_token_path($token);
    if (!$path) throw new RuntimeException('That upload has expired. Please start again.');
    $rows = read_table($path, $name, $sheet);
    if (!$rows) throw new RuntimeException($name . ' appears to be empty.');
    $xl = xlsx_last_read();

    $width = 0;
    foreach (array_slice($rows, 0, 60) as $r) $width = max($width, count($r));
    $layout = guess_layout($rows, $headerRow, $dataStart);

    $top = [];
    foreach (array_slice($rows, 0, QUICK_PREVIEW_ROWS) as $r) {
        $cells = [];
        for ($i = 0; $i < $width; $i++) $cells[] = mb_substr(trim((string)($r[$i] ?? '')), 0, 60);
        $top[] = $cells;
    }
    return [
        'token' => $token, 'name' => $name, 'rows' => $top, 'row_count' => count($rows),
        'width' => max(1, $width),
        'sheets' => array_map(fn($s) => ['name' => $s['name'], 'rows' => (int)$s['rows']], $xl['sheets'] ?? []),
        'sheet' => $xl['chosen'] ?? '',
        'header_row' => $layout['header_row'], 'data_start' => $layout['data_start'], 'map' => $layout['map'],
    ];
}

// Spare fields worth suggesting: columns whose headings match on both sides
// once case, spacing and punctuation are ignored ("Booking Ref" = "booking-ref"),
// leaving out the columns already used for the date, amount and description.
function quick_suggest_pairs(array $left, array $right, $max)
{
    $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
    $head = fn($f) => $f['header_row'] > 0 ? ($f['rows'][$f['header_row'] - 1] ?? []) : [];
    $usedL = array_values($left['map']);
    $usedR = array_values($right['map']);
    $byName = [];
    foreach ($head($right) as $j => $h) {
        if (in_array($j, $usedR, true) || $norm($h) === '') continue;
        $byName[$norm($h)] ??= $j;
    }
    $out = [];
    foreach ($head($left) as $i => $h) {
        if (count($out) >= $max) break;
        $k = $norm($h);
        if ($k === '' || in_array($i, $usedL, true) || !isset($byName[$k])) continue;
        $out[] = ['label' => mb_substr(trim($h), 0, 60), 'left' => $i, 'right' => $byName[$k]];
        unset($byName[$k]);
    }
    return $out;
}

/* Turn one side of the plan into the importer's map. $side holds the column
 * for each field in the grid ('' or null where this file does not have it);
 * spare fields become extra1, extra2 ... in the order they appear on screen,
 * which is the order the Transactions screen shows them. */
function quick_side_map(array $plan, $side)
{
    $col = fn($v) => ($v === '' || $v === null) ? null : (int)$v;
    $map = [
        'date'        => $col($plan['date'][$side] ?? null),
        'value'       => $col($plan['value'][$side] ?? null),
        'description' => $col($plan['description'][$side] ?? null) ?? -1,   // -1: no such column, so blank
    ];
    foreach (array_values($plan['spares'] ?? []) as $n => $s) {
        $map['extra' . ($n + 1)] = $col($s[$side] ?? null);
    }
    return $map;
}

// Read one file with the chosen settings. Returns [transactions, skipped].
function quick_build($token, $name, $sheet, $dataStart, array $map, $reverse)
{
    $path = quick_token_path($token);
    if (!$path) throw new RuntimeException('That upload has expired. Please start again.');
    if ($map['date'] === null || $map['value'] === null) return [[], 0];
    $rows = read_table($path, $name, $sheet !== '' ? $sheet : null);
    [$txns, $skipped] = build_transactions($rows, $map, max(1, (int)$dataStart) - 1);
    if ($reverse) {
        foreach ($txns as &$t) $t[2] = -$t[2];
        unset($t);
    }
    return [$txns, $skipped];
}

// The screen's live check: what would come in with these settings.
function quick_check($token, $name, $sheet, $dataStart, array $map, $reverse)
{
    [$txns, $skipped] = quick_build($token, $name, $sheet, $dataStart, $map, $reverse);
    $dates = array_column($txns, 0);
    return [
        'usable'  => count($txns),
        'skipped' => $skipped,
        'total'   => round(array_sum(array_column($txns, 2)), 2),
        'from'    => $dates ? min($dates) : null,
        'to'      => $dates ? max($dates) : null,
        'first'   => array_slice($txns, 0, 5),
    ];
}

/* Check a plan from the screen before anything is written. Returns a list of
 * plain-English problems; empty means it can go ahead. */
function quick_plan_problems(array $plan)
{
    $p = [];
    if (trim((string)($plan['name'] ?? '')) === '') $p[] = 'Give the reconciliation a name.';
    foreach (['left' => 'the first file', 'right' => 'the second file'] as $side => $which) {
        if (($plan['date'][$side] ?? '') === '')  $p[] = "Say which column of $which holds the date.";
        if (($plan['value'][$side] ?? '') === '') $p[] = "Say which column of $which holds the amount.";
    }
    $spares = array_values($plan['spares'] ?? []);
    if (count($spares) > spare_count()) $p[] = 'There is room for ' . spare_count() . ' extra fields at most.';
    $seen = [];
    foreach ($spares as $s) {
        $label = trim((string)($s['label'] ?? ''));
        if ($label === '') { $p[] = 'Every extra field needs a name.'; continue; }
        if (isset($seen[strtolower($label)])) $p[] = "Two extra fields are both called \"$label\".";
        $seen[strtolower($label)] = true;
        if (($s['left'] ?? '') === '' && ($s['right'] ?? '') === '') $p[] = "\"$label\" is not in either file.";
    }
    return $p;
}

/* Create the two files, import into each, and create the reconciliation. The
 * importer commits each file on its own, so if anything fails part way the
 * records already made are removed again rather than left half-built.
 * Returns ['rec_id', 'left' => n, 'right' => n, 'skipped' => [l, r]]. */
function quick_create(array $plan, array $sides)
{
    $pdo = db();
    $name = mb_substr(trim($plan['name']), 0, 150);
    $made = ['files' => [], 'rec' => null];
    $oneOff = oneoff_ready();
    $keys = spare_keys();

    try {
        $counts = [];
        foreach (['left', 'right'] as $side) {
            $s = $sides[$side];
            $map = quick_side_map($plan, $side);
            [$txns, $skipped] = quick_build($s['token'], $s['name'], $s['sheet'] ?? '', $s['data_start'],
                                            $map, !empty($s['reverse']));
            if (!$txns) {
                throw new RuntimeException('Nothing usable was found in ' . $s['name']
                    . '. Check the date and amount columns and the row the data starts on.');
            }

            // The file's spare field names: a field this file does not have is
            // left unnamed, so its column does not appear on this side.
            $labels = [];
            foreach ($keys as $n => $k) {
                $spare = array_values($plan['spares'] ?? [])[$n] ?? null;
                $labels[] = ($spare && ($spare[$side] ?? '') !== '') ? mb_substr(trim($spare['label']), 0, 60) : null;
            }
            $label = mb_substr(trim(($plan[$side . '_label'] ?? '') ?: $s['name']), 0, 60);
            $fileName = mb_substr($name . ' - ' . $label, 0, 150);
            $cols = 'name, notes, active, ' . implode(', ', $keys) . ($oneOff ? ', one_off' : '');
            $qs   = '?,?,?' . str_repeat(',?', count($keys)) . ($oneOff ? ',1' : '');
            $pdo->prepare("INSERT INTO rec_files ($cols) VALUES ($qs)")
                ->execute(array_merge([$fileName, 'Made by the quick route from ' . $s['name'], 1], $labels));
            $fileId = (int)$pdo->lastInsertId();
            $made['files'][] = $fileId;

            insert_transactions($fileId, $txns, $s['name']);
            $counts[$side] = ['file_id' => $fileId, 'n' => count($txns), 'skipped' => $skipped, 'label' => $label];
        }

        $cols = 'name, left_label, right_label, sort_order, notes, active, left_file_id, right_file_id'
              . ($oneOff ? ', one_off' : '');
        $pdo->prepare("INSERT INTO rec_recs ($cols) VALUES (?,?,?,?,?,?,?,?" . ($oneOff ? ',1' : '') . ")")
            ->execute([$name, $counts['left']['label'], $counts['right']['label'], 900,
                       'Made by the quick route on ' . date('j M Y H:i') . '.', 1,
                       $counts['left']['file_id'], $counts['right']['file_id']]);
        $made['rec'] = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        quick_undo($made);
        throw $e;
    }

    foreach ($sides as $s) {
        if ($path = quick_token_path($s['token'])) @unlink($path);
    }
    return ['rec_id' => $made['rec'], 'counts' => $counts];
}

// Remove whatever a failed quick_create had already made.
function quick_undo(array $made)
{
    $pdo = db();
    try {
        if ($made['rec']) $pdo->prepare("DELETE FROM rec_recs WHERE id = ?")->execute([$made['rec']]);
        foreach ($made['files'] as $fid) {
            $pdo->prepare("DELETE FROM rec_txns WHERE file_id = ?")->execute([$fid]);
            $pdo->prepare("DELETE FROM rec_imports WHERE file_id = ?")->execute([$fid]);
            $pdo->prepare("DELETE FROM rec_files WHERE id = ?")->execute([$fid]);
        }
    } catch (Throwable $e) {
        // Leave the original error as the one reported.
    }
}

// Is this reconciliation a one-off?
function is_one_off(array $rec)
{
    return oneoff_ready() && !empty($rec['one_off']);
}

/* What removing a one-off would take away - shown before anything is deleted.
 * Returns ['ok' => bool, 'why' => '...', and counts]. */
function one_off_footprint($recId)
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM rec_recs WHERE id = ?");
    $st->execute([(int)$recId]);
    $rec = $st->fetch();
    if (!$rec || !is_one_off($rec)) return ['ok' => false, 'why' => 'That is not a one-off reconciliation.'];

    // Only files made for this one-off go with it, and only if nothing else
    // uses them - a one-off may since have been pointed at a permanent file.
    $files = [];
    foreach ([$rec['left_file_id'], $rec['right_file_id']] as $fid) {
        $f = $fid ? get_file($fid) : null;
        if (!$f || empty($f['one_off'])) continue;
        $others = array_filter(file_used_by($fid), fn($r) => (int)$r['id'] !== (int)$recId);
        if ($others) {
            return ['ok' => false, 'why' => $f['name'] . ' is also used by ' . implode(', ', array_column($others, 'name'))
                . '. Take it off there first, or keep this reconciliation.'];
        }
        $files[] = (int)$fid;
    }
    $txns = 0;
    if ($files) {
        $in = implode(',', $files);
        $txns = (int)$pdo->query("SELECT COUNT(*) FROM rec_txns WHERE file_id IN ($in)")->fetchColumn();
    }
    $runs = $pdo->prepare("SELECT COUNT(*) FROM rec_runs WHERE rec_id = ?");
    $runs->execute([(int)$recId]);
    $rules = $pdo->prepare("SELECT COUNT(*) FROM rec_rules WHERE rec_id = ?");
    $rules->execute([(int)$recId]);
    return ['ok' => true, 'rec' => $rec, 'files' => $files, 'txns' => $txns,
            'runs' => (int)$runs->fetchColumn(), 'rules' => (int)$rules->fetchColumn()];
}

/* Remove a one-off and everything that belongs only to it: its matching runs
 * and matches, rules tied to it, its two files and every transaction in them.
 * All in one database transaction - either everything goes or nothing does. */
function delete_one_off($recId)
{
    $fp = one_off_footprint($recId);
    if (!$fp['ok']) return [false, $fp['why']];
    $pdo = db();
    $recId = (int)$recId;

    $pdo->beginTransaction();
    try {
        $runIds = array_map('intval', array_column(
            $pdo->query("SELECT id FROM rec_runs WHERE rec_id = $recId")->fetchAll(), 'id'));
        $pdo->prepare("DELETE FROM rec_matched WHERE rec_id = ?")->execute([$recId]);
        if ($runIds) {
            $runs = implode(',', $runIds);
            $pdo->exec("DELETE FROM rec_match_lines WHERE group_id IN (SELECT id FROM rec_match_groups WHERE run_id IN ($runs))");
            $pdo->exec("DELETE FROM rec_match_groups WHERE run_id IN ($runs)");
            $pdo->exec("DELETE FROM rec_runs WHERE id IN ($runs)");
        }
        $pdo->prepare("DELETE FROM rec_rules WHERE rec_id = ?")->execute([$recId]);
        $pdo->prepare("DELETE FROM rec_recs WHERE id = ?")->execute([$recId]);
        if ($fp['files']) {
            $in = implode(',', $fp['files']);
            // Anything in these files matched under another reconciliation
            // would make the check above fail, so these are safe to remove.
            $pdo->exec("DELETE FROM rec_matched WHERE txn_id IN (SELECT id FROM rec_txns WHERE file_id IN ($in))");
            $pdo->exec("DELETE FROM rec_txns WHERE file_id IN ($in)");
            $pdo->exec("DELETE FROM rec_imports WHERE file_id IN ($in)");
            $pdo->exec("DELETE FROM rec_files WHERE id IN ($in)");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Nothing was removed: ' . $e->getMessage()];
    }

    if (session_status() === PHP_SESSION_NONE) session_start();
    if ((int)($_SESSION['rec_id'] ?? 0) === $recId) unset($_SESSION['rec_id']);
    return [true, 'Removed ' . $fp['rec']['name'] . ', its two files and their ' . number_format($fp['txns'])
        . ' transactions.'];
}

// Keep a one-off: it simply stops being marked as one.
function keep_one_off($recId)
{
    if (!oneoff_ready()) return;
    $pdo = db();
    $st = $pdo->prepare("SELECT left_file_id, right_file_id FROM rec_recs WHERE id = ?");
    $st->execute([(int)$recId]);
    $r = $st->fetch();
    $pdo->prepare("UPDATE rec_recs SET one_off = 0 WHERE id = ?")->execute([(int)$recId]);
    if ($r) {
        $pdo->prepare("UPDATE rec_files SET one_off = 0 WHERE id IN (?, ?)")
            ->execute([(int)$r['left_file_id'], (int)$r['right_file_id']]);
    }
}
