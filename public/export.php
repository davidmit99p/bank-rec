<?php
// Download what is on the transactions screen as a CSV, so it can be worked on
// in Excel - preparing journals for the items that will never match, usually.
//
// It takes exactly the same filters as the screen, so narrow the list down
// first and the download follows.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/context.php';
require_once __DIR__ . '/../includes/splits.php';
require_once __DIR__ . '/../includes/txnlist.php';
require_once __DIR__ . '/../includes/matcher.php';
require_once __DIR__ . '/../includes/extras.php';

$side = ($_GET['side'] ?? '') === 'bank' ? 'bank' : 'ledger';
$q    = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$show = in_array($_GET['show'] ?? '', ['open', 'matched', 'both'], true) ? $_GET['show'] : 'open';
$sort = isset(sort_columns()[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'date';
$dir  = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$virgin  = !isset($_GET['in']) && !isset($_GET['out']);
$wantIn  = $virgin ? true : isset($_GET['in']);
$wantOut = $virgin ? true : isset($_GET['out']);
if (!$wantIn && !$wantOut) { $wantIn = $wantOut = true; }
$sign = $wantIn && $wantOut ? 'both' : ($wantIn ? 'in' : 'out');

// No limit: the download is the whole filtered list, not the page that happened
// to be on screen. Items sitting in an unfinalised run are hidden here just as
// they are on the screen - that is handled inside item_filters().
$rows = list_items($side, $q, $from, $to, $show, $sort, $dir, $sign);

// a filename that says what it is, without spaces or punctuation to trip Excel
$rec  = current_rec();
$bits = array_filter([
    $rec ? preg_replace('/[^A-Za-z0-9]+/', '-', $rec['name']) : null,
    preg_replace('/[^A-Za-z0-9]+/', '-', side_label($side)),
    $show === 'open' ? 'unmatched' : ($show === 'matched' ? 'matched' : 'all'),
    date('Y-m-d'),
]);
$filename = trim(implode('-', $bits), '-') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
echo "\xEF\xBB\xBF";   // so Excel opens it as UTF-8 rather than guessing

$head = ['Date', 'Description', 'Value', 'Status', 'Rule', 'Run',
         'Split from', 'Source file', 'Reference'];
foreach (extra_labels($side) as $label) $head[] = $label;
if (extras_ready()) $head[] = 'Notes';
fputcsv($out, $head);

$total = 0.0;
foreach ($rows as $r) {
    $total += (float)$r['value'];
    $line = [
        $r['txn_date'],
        $r['description'],
        number_format((float)$r['value'], 2, '.', ''),   // plain, so Excel reads it as a number
        $r['matched_at'] === null ? 'Unmatched' : 'Matched',
        $r['rule_ref'] ?? '',
        $r['run_ref'] ?? '',
        empty($r['parent_id']) ? '' : number_format((float)($r['parent_value'] ?? 0), 2, '.', ''),
        $r['source_file'] ?? '',
        $r['id'],
    ];
    foreach (array_keys(extra_labels($side)) as $key) $line[] = $r[$key] ?? '';
    if (extras_ready()) $line[] = $r['notes'] ?? '';
    fputcsv($out, $line);
}

// a total on the end, so the figure can be checked against the screen
fputcsv($out, []);
fputcsv($out, ['', count($rows) . ' transactions', number_format($total, 2, '.', '')]);
fclose($out);
