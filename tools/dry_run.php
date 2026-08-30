<?php
/*
 * Dry run - no database needed.
 *
 * Reads a ledger file and a bank file straight from disk, applies a set of
 * rules, and prints what would have matched. Use it to try rule ideas out
 * before committing anything.
 *
 *   php tools/dry_run.php "ledger.xlsx" "bank.xlsx"
 */
require_once __DIR__ . '/../includes/importer.php';
require_once __DIR__ . '/../includes/matcher.php';

$ledgerFile = $argv[1] ?? null;
$bankFile   = $argv[2] ?? null;
if (!$ledgerFile || !$bankFile) {
    fwrite(STDERR, "Usage: php tools/dry_run.php <ledger file> <bank file>\n");
    exit(1);
}

function load($path)
{
    $rows  = read_table($path, $path);
    $start = find_data_start($rows);
    $head  = ($start > 0 && looks_like_header($rows[$start - 1] ?? [])) ? $rows[$start - 1] : null;
    $map   = $head ? guess_columns($head) : ['date' => null, 'description' => null, 'value' => null];
    $fromData = guess_columns_from_row($rows[$start] ?? []);
    foreach ($map as $k => $v) if ($v === null) $map[$k] = $fromData[$k];
    $map = check_columns($map, $rows[$start] ?? [], $fromData);
    [$txns, $skipped] = build_transactions($rows, $map, $start);
    $out = [];
    foreach ($txns as $i => $t) {
        $out[] = ['id' => $i + 1, 'txn_date' => $t[0], 'description' => $t[1], 'value' => $t[2]];
    }
    printf("%-46s %5d rows read, %d skipped\n", basename($path), count($out), $skipped);
    return $out;
}

// The rules to try. Same shape as a row of rec_rules.
function rule(array $over)
{
    $base = ['id' => 0, 'name' => '', 'date_tol' => 3, 'sign_mode' => 'same',
             'grouping' => 'one', 'max_group' => 4, 'link_desc' => 0];
    foreach (['l_', 'b_'] as $p) {
        $base += [$p.'desc_op' => 'any', $p.'desc_val' => null,
                  $p.'value_op' => 'any', $p.'value_val' => null, $p.'value_val2' => null,
                  $p.'date_op' => 'any', $p.'date_val' => null, $p.'date_val2' => null];
    }
    return array_merge($base, $over);
}

$RULES = [
    rule(['id' => 1, 'name' => 'Same day, same amount, same wording',
          'date_tol' => 0, 'link_desc' => 1]),
    rule(['id' => 2, 'name' => 'Within 3 days, same amount, same wording',
          'date_tol' => 3, 'link_desc' => 1]),
    rule(['id' => 3, 'name' => 'Within 7 days, same amount, same wording',
          'date_tol' => 7, 'link_desc' => 1]),
    rule(['id' => 4, 'name' => 'Within 3 days, same amount, wording ignored',
          'date_tol' => 3]),
    // grouping rules go last - try the simple pairings first
    rule(['id' => 5, 'name' => 'Several ledger lines add up to one bank line',
          'date_tol' => 5, 'grouping' => 'many_left', 'max_group' => 4]),
    rule(['id' => 6, 'name' => 'Ledger: same day, equal and opposite (contra)',
          'date_tol' => 0, 'grouping' => 'contra_left']),
];

echo str_repeat('=', 78), "\n";
$ledger = load($ledgerFile);
$bank   = load($bankFile);

$lTotal = array_sum(array_column($ledger, 'value'));
$bTotal = array_sum(array_column($bank, 'value'));
printf("\nLedger total %14s   Bank total %14s   Difference %s\n",
    money($lTotal), money($bTotal), money($lTotal - $bTotal));
echo str_repeat('=', 78), "\n\n";

$usedL = [];
$usedB = [];
$groups = [];
$groupNo = 0;

foreach ($RULES as $rule) {
    $made = 0;
    $L = array_values(array_filter($ledger, fn($r) => !isset($usedL[$r['id']]) && row_matches_side($r, $rule, 'l_')));
    $B = array_values(array_filter($bank,   fn($r) => !isset($usedB[$r['id']]) && row_matches_side($r, $rule, 'b_')));
    $sign = $rule['sign_mode'] === 'opposite' ? -1 : 1;

    if (contra_side($rule['grouping'])) {
        $isL   = contra_side($rule['grouping']) === 'ledger';
        $pairs = $isL
            ? find_contra_pairs($L, $usedL, (int)$rule['date_tol'], (int)$rule['link_desc'])
            : find_contra_pairs($B, $usedB, (int)$rule['date_tol'], (int)$rule['link_desc']);
        foreach ($pairs as $pair) {
            $groupNo++;
            $groups[] = ['rule' => $rule, 'no' => $groupNo,
                         'ledger' => $isL ? $pair : [], 'bank' => $isL ? [] : $pair];
            $made++;
        }
    } elseif ($rule['grouping'] === 'many_left') {
        foreach ($B as $b) {
            if (isset($usedB[$b['id']])) continue;
            $near = rows_near_date(index_by_date($L), $b['txn_date'], (int)$rule['date_tol']);
            $set = find_combination($near, $usedL, $sign * (float)$b['value'], $b['txn_date'],
                    (int)$rule['max_group'], $b['description'], (int)$rule['link_desc']);
            if (!$set) continue;
            $groupNo++;
            foreach ($set as $r) $usedL[$r['id']] = 1;
            $usedB[$b['id']] = 1;
            $groups[] = ['rule' => $rule, 'no' => $groupNo, 'ledger' => $set, 'bank' => [$b]];
            $made++;
        }
    } else {
        $bankIndex = index_rows($B);
        foreach ($L as $l) {
            if (isset($usedL[$l['id']])) continue;
            $b = find_single($l, $bankIndex, $usedB, $rule);
            if (!$b) continue;
            $groupNo++;
            $usedL[$l['id']] = 1;
            $usedB[$b['id']] = 1;
            $groups[] = ['rule' => $rule, 'no' => $groupNo, 'ledger' => [$l], 'bank' => [$b]];
            $made++;
        }
    }
    printf("Rule %d  %-52s %4d matches\n", $rule['id'], $rule['name'], $made);
}

// --- results -----------------------------------------------------------------
$openL = array_values(array_filter($ledger, fn($r) => !isset($usedL[$r['id']])));
$openB = array_values(array_filter($bank,   fn($r) => !isset($usedB[$r['id']])));

echo "\n", str_repeat('-', 78), "\n";
printf("Matched  ledger %d of %d,  bank %d of %d\n",
    count($ledger) - count($openL), count($ledger),
    count($bank) - count($openB), count($bank));
printf("Still open: ledger %d (%s), bank %d (%s)\n",
    count($openL), money(array_sum(array_column($openL, 'value'))),
    count($openB), money(array_sum(array_column($openB, 'value'))));

// The golden rule, checked on every single group.
$bad = 0;
foreach ($groups as $g) {
    $lt = array_sum(array_column($g['ledger'], 'value'));
    $bt = array_sum(array_column($g['bank'], 'value'));
    if (!group_balances($lt, $bt, $g['rule']['sign_mode'])) $bad++;
}
echo "Golden rule check: ", ($bad ? "FAILED - {$bad} groups out of balance" : 'every group balances'), "\n";
echo str_repeat('-', 78), "\n";

// Show a few multi-line matches, which are the interesting ones.
$multi = array_values(array_filter($groups, fn($g) => count($g['ledger']) + count($g['bank']) > 2));
if ($multi) {
    echo "\nExample grouped matches:\n";
    foreach (array_slice($multi, 0, 5) as $g) {
        printf("\n  Match %d (rule %d)\n", $g['no'], $g['rule']['id']);
        foreach ($g['ledger'] as $r) printf("    ledger  %s  %-40s %12s\n", $r['txn_date'], mb_substr($r['description'], 0, 40), money($r['value']));
        foreach ($g['bank']   as $r) printf("    bank    %s  %-40s %12s\n", $r['txn_date'], mb_substr($r['description'], 0, 40), money($r['value']));
    }
}

echo "\nBiggest items still open on each side:\n";
usort($openL, fn($a, $b) => abs($b['value']) <=> abs($a['value']));
usort($openB, fn($a, $b) => abs($b['value']) <=> abs($a['value']));
for ($i = 0; $i < 8; $i++) {
    $l = $openL[$i] ?? null;
    $b = $openB[$i] ?? null;
    printf("  %-10s %-26s %11s   |  %-10s %-26s %11s\n",
        $l['txn_date'] ?? '', mb_substr($l['description'] ?? '', 0, 26), $l ? money($l['value']) : '',
        $b['txn_date'] ?? '', mb_substr($b['description'] ?? '', 0, 26), $b ? money($b['value']) : '');
}
echo "\n";
