<?php
// -----------------------------------------------------------------------------
// Reading transaction files: CSV, TXT and Excel .xlsx.
// No third-party libraries - an .xlsx is just a zip full of XML, so we open it
// ourselves.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';

// Read any supported file into a plain array of rows (each row an array of cells).
function read_table($path, $originalName)
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'xlsm') return read_xlsx($path);
    if (in_array($ext, ['csv', 'txt', 'tsv'], true)) return read_delimited($path);
    throw new RuntimeException("Sorry, I can't read a .{$ext} file. Please use CSV or Excel (.xlsx).");
}

function read_delimited($path)
{
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Could not read that file.');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);           // strip Excel's BOM
    $firstLine = strtok($raw, "\n");
    $delim = (substr_count($firstLine, "\t") > substr_count($firstLine, ','))
        ? "\t" : (substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',');

    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        if (row_is_blank($r)) continue;
        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
}

// Excel happily exports thousands of empty trailing rows when a sheet has had
// formatting applied below the data. They are not transactions.
function row_is_blank($r)
{
    if (!is_array($r)) return true;
    foreach ($r as $c) {
        if (trim((string)$c) !== '') return false;
    }
    return true;
}

function read_xlsx($path)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('This server cannot open Excel files. Please save the file as CSV and upload that.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('That does not look like a valid Excel file.');

    // shared strings: most text in an xlsx lives in one big lookup table
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($xml);
        if ($sx) {
            foreach ($sx->si as $si) {
                // <si> may be one <t>, or several <r><t> runs
                $shared[] = $si->t !== null && count($si->t) ? (string)$si->t
                          : implode('', array_map(fn($r) => (string)$r->t, iterator_to_array($si->r ?? [])));
            }
        }
    }

    // find the first worksheet
    $sheetXml = false;
    foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/Sheet1.xml'] as $try) {
        if (($sheetXml = $zip->getFromName($try)) !== false) break;
    }
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, 'xl/worksheets/') === 0 && substr($n, -4) === '.xml') {
                $sheetXml = $zip->getFromName($n);
                break;
            }
        }
    }
    // which number formats mean "this is a date"
    $dateStyles = xlsx_date_styles($zip);
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('That Excel file has no readable sheet.');

    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) throw new RuntimeException('That Excel file could not be read.');

    $rows = [];
    foreach ($sx->sheetData->row as $r) {
        $cells = [];
        foreach ($r->c as $c) {
            $ref  = (string)$c['r'];
            $col  = xlsx_col_index(preg_replace('/\d+/', '', $ref));
            $type = (string)$c['t'];
            $style = $c['s'] !== null ? (int)$c['s'] : -1;

            if ($type === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = (string)$c->v;
                if ($v !== '' && is_numeric($v) && isset($dateStyles[$style])) {
                    $v = excel_serial_to_date((float)$v);
                }
            }
            $cells[$col] = $v;
        }
        if (!$cells) continue;
        $width = max(array_keys($cells)) + 1;
        $line = [];
        for ($i = 0; $i < $width; $i++) $line[] = $cells[$i] ?? '';
        $rows[] = $line;
    }
    return $rows;
}

// Work out which cell styles are date formats, so 43467 shows as 2019-01-02.
function xlsx_date_styles(ZipArchive $zip)
{
    $out = [];
    $xml = $zip->getFromName('xl/styles.xml');
    if ($xml === false) return $out;
    $sx = @simplexml_load_string($xml);
    if (!$sx) return $out;

    // built-in formats that are dates
    $builtInDates = array_merge(range(14, 22), range(45, 47), [27, 30, 36, 50, 57]);
    $customDates = [];
    if (isset($sx->numFmts)) {
        foreach ($sx->numFmts->numFmt as $f) {
            $code = (string)$f['formatCode'];
            if (preg_match('/[dmyhs]/i', preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $code))) {
                $customDates[(int)$f['numFmtId']] = true;
            }
        }
    }
    if (isset($sx->cellXfs)) {
        $i = 0;
        foreach ($sx->cellXfs->xf as $xf) {
            $id = (int)$xf['numFmtId'];
            if (in_array($id, $builtInDates, true) || isset($customDates[$id])) $out[$i] = true;
            $i++;
        }
    }
    return $out;
}

// "A" -> 0, "B" -> 1, "AA" -> 26
function xlsx_col_index($letters)
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function excel_serial_to_date($serial)
{
    // Excel counts days from 1899-12-30 (its leap-year quirk included)
    $ts = ($serial - 25569) * 86400;
    return gmdate('Y-m-d', (int)round($ts));
}

// --- turning cells into transactions -----------------------------------------

// Guess which columns hold the date, description and value.
function guess_columns(array $header)
{
    $map = ['date' => null, 'description' => null, 'value' => null];
    foreach ($header as $i => $name) {
        $n = strtolower(trim((string)$name));
        if ($map['date'] === null && preg_match('/date|posted/', $n))                     $map['date'] = $i;
        if ($map['description'] === null && preg_match('/desc|detail|narrat|refer|payee|particular/', $n)) $map['description'] = $i;
        if ($map['value'] === null && preg_match('/value|amount|debit|credit|net|total/', $n))  $map['value'] = $i;
    }
    // fall back to the usual order
    if ($map['date'] === null)        $map['date'] = 0;
    if ($map['description'] === null) $map['description'] = 1;
    if ($map['value'] === null)       $map['value'] = min(2, max(0, count($header) - 1));
    return $map;
}

// Does this row look like a header rather than data?
function looks_like_header(array $row)
{
    foreach ($row as $c) {
        if (parse_amount($c) !== null) return false;
        if (parse_date($c) !== null)   return false;
    }
    return true;
}

// A transaction row has a date in one cell and an amount in a different one.
function row_looks_like_transaction(array $row)
{
    $dateCols = [];
    $amountCols = [];
    foreach ($row as $c => $v) {
        $isAmount = parse_amount($v) !== null;
        if (parse_date($v) !== null && !$isAmount) $dateCols[] = $c;
        if ($isAmount) $amountCols[] = $c;
    }
    return $dateCols && $amountCols;
}

// Reports often begin with a title, a date range and some blank lines before
// the transactions start. Find the first row that is actually a transaction.
// Returns a 0-based index, or 0 if nothing looks like one.
function find_data_start(array $rows)
{
    foreach ($rows as $i => $r) {
        if (row_is_blank($r)) continue;
        if (row_looks_like_transaction($r)) return $i;
    }
    return 0;
}

// Work out which column is which by looking at a real transaction row, rather
// than at column headings that may not exist.
function guess_columns_from_row(array $row)
{
    $date = null;
    $value = null;
    foreach ($row as $c => $v) {
        if ($date === null && parse_date($v) !== null && parse_amount($v) === null) $date = $c;
    }
    // the value is the last cell that reads as a number - statements often put
    // a running balance after it, but the amount is the one we want, so prefer
    // the first number that is not the date
    foreach ($row as $c => $v) {
        if ($c === $date) continue;
        if (parse_amount($v) !== null) { $value = $c; break; }
    }
    // the description is the longest piece of text that is neither of those
    $desc = null;
    $bestLen = 0;
    foreach ($row as $c => $v) {
        if ($c === $date || $c === $value) continue;
        $len = mb_strlen(trim((string)$v));
        if ($len > $bestLen) { $bestLen = $len; $desc = $c; }
    }
    return ['date' => $date, 'description' => $desc, 'value' => $value];
}

function parse_date($raw)
{
    $s = trim((string)$raw);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
    // a bare Excel serial that slipped through
    if (is_numeric($s)) {
        return ($s > 20000 && $s < 60000 && strpos($s, '.') === false)
            ? excel_serial_to_date((float)$s)
            : null;                       // any other plain number is an amount, not a date
    }
    // UK style first: 02/01/2019 is 2 January
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})#', $s, $m)) {
        $y = (int)$m[3];
        if ($y < 100) $y += ($y < 70 ? 2000 : 1900);
        if (checkdate((int)$m[2], (int)$m[1], $y)) return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

function parse_amount($raw)
{
    $s = trim((string)$raw);
    if ($s === '') return null;

    // A date is not an amount. Without this, stripping the separators out of
    // "02/01/2019" would leave 02012019, which reads as two million pounds.
    if (preg_match('#^\d{1,4}[/.\-]\d{1,2}[/.\-]\d{2,4}#', $s)) return null;

    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = trim($m[1]); }  // (123.45) means -123.45
    if (preg_match('/\s*(CR|DR)$/i', $s, $m)) {                                 // 123.45 DR means -123.45
        if (strtoupper($m[1]) === 'DR') $neg = true;
        $s = trim(preg_replace('/\s*(CR|DR)$/i', '', $s));
    }
    $s = preg_replace('/[^0-9.,\-+]/', '', $s);                                 // drop currency symbols
    $s = str_replace(',', '', $s);
    if ($s === '' || !is_numeric($s)) return null;
    $v = (float)$s;
    return $neg ? -abs($v) : $v;
}

// Turn raw rows into transactions ready for the database.
// $startRow is a 0-based index: everything above it is ignored.
// Returns [rows, skipped] where each row is [date, description, value].
function build_transactions(array $rows, array $map, $startRow = 0)
{
    $startRow = (int)$startRow;
    $out = [];
    $skipped = 0;
    foreach ($rows as $i => $r) {
        if ($i < $startRow) continue;
        $date  = parse_date($r[$map['date']] ?? '');
        $value = parse_amount($r[$map['value']] ?? '');
        $desc  = trim(preg_replace('/\s+/', ' ', (string)($r[$map['description']] ?? '')));
        if ($date === null || $value === null) { $skipped++; continue; }
        $out[] = [$date, mb_substr($desc, 0, 500), $value];
    }
    return [$out, $skipped];
}

function insert_transactions($side, array $rows, $sourceFile)
{
    $table = $side === 'ledger' ? 'rec_ledger' : 'rec_bank';
    $pdo = db();
    $st = $pdo->prepare("INSERT INTO {$table} (txn_date, description, value, source_file)
                         VALUES (?,?,?,?)");
    $pdo->beginTransaction();
    foreach ($rows as $r) $st->execute([$r[0], $r[1], $r[2], $sourceFile]);
    $pdo->commit();
    return count($rows);
}
