<?php
// -----------------------------------------------------------------------------
// The matching engine.
//
// GOLDEN RULE: a match is only ever created when the two sides total the same
// amount. Nothing in this file creates an unbalanced match.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';

// The choices offered on the rule form ---------------------------------------
function desc_ops() {
    return [
        'any'          => 'anything',
        'contains'     => 'contains',
        'not_contains' => 'does not contain',
        'equals'       => 'is exactly',
        'starts'       => 'starts with',
        'ends'         => 'ends with',
        'regex'        => 'matches pattern (regex)',
    ];
}
function value_ops() {
    return [
        'any'        => 'anything',
        'equals'     => 'is exactly',
        'abs_equals' => 'is exactly (ignore + / -)',
        'between'    => 'is between',
        'gt'         => 'is more than',
        'lt'         => 'is less than',
        'negative'   => 'is a payment out (negative)',
        'positive'   => 'is a receipt in (positive)',
    ];
}
function date_ops() {
    return [
        'any'     => 'any date',
        'on'      => 'is on',
        'from'    => 'is on or after',
        'to'      => 'is on or before',
        'between' => 'is between',
    ];
}
function grouping_modes() {
    return [
        'one'        => 'One ledger line to one bank line',
        'many_left'  => 'Several ledger lines add up to one bank line',
        'many_right' => 'One ledger line splits into several bank lines',
    ];
}

// --- criteria testing --------------------------------------------------------

function test_desc($desc, $op, $val)
{
    if ($op === 'any' || $val === null || $val === '') return true;
    $d = mb_strtoupper($desc);
    $v = mb_strtoupper(trim($val));
    switch ($op) {
        case 'contains':     return mb_strpos($d, $v) !== false;
        case 'not_contains': return mb_strpos($d, $v) === false;
        case 'equals':       return $d === $v;
        case 'starts':       return mb_strpos($d, $v) === 0;
        case 'ends':         return $v === '' || mb_substr($d, -mb_strlen($v)) === $v;
        case 'regex':        return @preg_match('/' . str_replace('/', '\/', $val) . '/i', $desc) === 1;
    }
    return true;
}

function test_value($value, $op, $a, $b)
{
    $v = (float)$value;
    switch ($op) {
        case 'any':        return true;
        case 'equals':     return $a !== null && abs($v - (float)$a) < 0.005;
        case 'abs_equals': return $a !== null && abs(abs($v) - abs((float)$a)) < 0.005;
        case 'between':    return $a !== null && $b !== null
                               && $v >= min((float)$a, (float)$b) && $v <= max((float)$a, (float)$b);
        case 'gt':         return $a !== null && $v > (float)$a;
        case 'lt':         return $a !== null && $v < (float)$a;
        case 'negative':   return $v < 0;
        case 'positive':   return $v > 0;
    }
    return true;
}

function test_date($date, $op, $a, $b)
{
    if ($op === 'any') return true;
    $d = substr($date, 0, 10);
    switch ($op) {
        case 'on':      return $a && $d === $a;
        case 'from':    return $a && $d >= $a;
        case 'to':      return $a && $d <= $a;
        case 'between': return $a && $b && $d >= min($a, $b) && $d <= max($a, $b);
    }
    return true;
}

// Does this transaction meet one side of a rule? $p is 'l_' (ledger) or 'b_' (bank).
function row_matches_side(array $row, array $rule, $p)
{
    return test_desc($row['description'], $rule[$p . 'desc_op'],  $rule[$p . 'desc_val'])
        && test_value($row['value'],      $rule[$p . 'value_op'], $rule[$p . 'value_val'], $rule[$p . 'value_val2'])
        && test_date($row['txn_date'],    $rule[$p . 'date_op'],  $rule[$p . 'date_val'],  $rule[$p . 'date_val2']);
}

// --- description similarity (used when a rule ticks "descriptions must agree") -

function desc_words($s)
{
    $s = mb_strtoupper($s);
    $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) as $w) {
        if (mb_strlen($w) >= 4 && !preg_match('/^\d+$/', $w)) $out[] = $w;
    }
    return $out;
}

// True when the two descriptions share a meaningful word, e.g.
// "VISPA LTD" and "VISPA LTD  QUIK INTERNET  VIA MOBILE - PYMT".
function descs_agree($a, $b)
{
    $wa = desc_words($a);
    $wb = desc_words($b);
    if (!$wa || !$wb) return false;
    return (bool)array_intersect($wa, $wb);
}

function days_apart($d1, $d2)
{
    return (int)round(abs(strtotime(substr($d1, 0, 10)) - strtotime(substr($d2, 0, 10))) / 86400);
}

// --- loading the open items --------------------------------------------------

// Everything not yet finalised as matched.
function load_open($side)
{
    $table = $side === 'ledger' ? 'ledger' : 'bank';
    return db()->query("SELECT id, txn_date, description, value FROM {$table}
                        WHERE matched_at IS NULL ORDER BY txn_date, id")->fetchAll();
}

// Transactions already spoken for by suggestions in this draft run.
function ids_used_in_run($runId, $side)
{
    $st = db()->prepare("SELECT l.txn_id FROM match_lines l
                         JOIN match_groups g ON g.id = l.group_id
                         WHERE g.run_id = ? AND l.side = ?");
    $st->execute([$runId, $side]);
    return array_flip(array_column($st->fetchAll(), 'txn_id'));
}

// --- the pairing itself ------------------------------------------------------

// Find the one bank row that settles this ledger row, or null.
function find_single(array $lrow, array $bankRows, array $usedB, array $rule)
{
    $target = $rule['sign_mode'] === 'opposite' ? -(float)$lrow['value'] : (float)$lrow['value'];
    $tol    = (int)$rule['date_tol'];
    $best   = null;
    $bestGap = PHP_INT_MAX;
    foreach ($bankRows as $b) {
        if (isset($usedB[$b['id']])) continue;
        if (abs((float)$b['value'] - $target) > 0.004) continue;
        $gap = days_apart($lrow['txn_date'], $b['txn_date']);
        if ($gap > $tol) continue;
        if ($rule['link_desc'] && !descs_agree($lrow['description'], $b['description'])) continue;
        if ($gap < $bestGap) { $best = $b; $bestGap = $gap; }
    }
    return $best;
}

// Does any part of this set cancel itself out? A group containing, say,
// +2,000 and -2,000 balances on paper but is not a real match - those two
// lines have nothing to do with the bank item, they just net to nothing.
function has_self_cancelling_part(array $rows)
{
    $n = count($rows);
    if ($n < 2) return false;
    $full = (1 << $n) - 1;
    for ($mask = 1; $mask < $full; $mask++) {          // proper subsets only
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            if ($mask & (1 << $i)) $sum += (float)$rows[$i]['value'];
        }
        if (abs($sum) < 0.005) return true;
    }
    return false;
}

// Find a small set of rows from $pool that adds up to $target, near $anchorDate.
// Returns the rows, or null. Smallest set wins; ties broken by tightest dates.
function find_combination(array $pool, array $used, $target, $anchorDate, $tol,
                          $maxSize, $anchorDesc, $linkDesc)
{
    $cands = [];
    foreach ($pool as $r) {
        if (isset($used[$r['id']])) continue;
        if (days_apart($r['txn_date'], $anchorDate) > $tol) continue;
        if ($linkDesc && !descs_agree($anchorDesc, $r['description'])) continue;
        $cands[] = $r;
    }
    if (count($cands) < 2) return null;
    if (count($cands) > 14) $cands = array_slice($cands, 0, 14); // keep the search quick
    $n = count($cands);

    $best = null;
    $bestScore = null;
    for ($mask = 1; $mask < (1 << $n); $mask++) {
        $size = 0;
        $sum  = 0.0;
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            if ($mask & (1 << $i)) {
                $size++;
                if ($size > $maxSize) { $rows = null; break; }
                $sum += (float)$cands[$i]['value'];
                $rows[] = $cands[$i];
            }
        }
        if ($rows === null || $size < 2) continue;
        if (abs($sum - $target) > 0.004) continue;
        if (has_self_cancelling_part($rows)) continue;
        $spread = 0;
        foreach ($rows as $r) $spread += days_apart($r['txn_date'], $anchorDate);
        $score = [$size, $spread];
        if ($bestScore === null || $score < $bestScore) { $bestScore = $score; $best = $rows; }
    }
    return $best;
}

// -----------------------------------------------------------------------------
// Run every active rule against the open items and write the suggestions.
// -----------------------------------------------------------------------------
function run_rules($runId)
{
    $pdo   = db();
    $rules = $pdo->query("SELECT * FROM rules WHERE active = 1 ORDER BY sort_order, id")->fetchAll();

    $ledger = load_open('ledger');
    $bank   = load_open('bank');
    $usedL  = ids_used_in_run($runId, 'ledger');   // respects manual matches already ticked in
    $usedB  = ids_used_in_run($runId, 'bank');

    $groupNo = (int)$pdo->query("SELECT COALESCE(MAX(group_no),0) FROM match_groups
                                 WHERE run_id = " . (int)$runId)->fetchColumn();

    $insG = $pdo->prepare("INSERT INTO match_groups
        (run_id, group_no, rule_ref, rule_name, ledger_total, bank_total, sign_mode, accepted)
        VALUES (?,?,?,?,?,?,?,1)");
    $insL = $pdo->prepare("INSERT INTO match_lines (group_id, side, txn_id, value) VALUES (?,?,?,?)");

    $perRule = [];
    foreach ($rules as $rule) {
        $made = 0;
        $L = array_values(array_filter($ledger,
                fn($r) => !isset($usedL[$r['id']]) && row_matches_side($r, $rule, 'l_')));
        $B = array_values(array_filter($bank,
                fn($r) => !isset($usedB[$r['id']]) && row_matches_side($r, $rule, 'b_')));
        if (!$L || !$B) { $perRule[] = ['rule' => $rule, 'made' => 0]; continue; }

        $sign = $rule['sign_mode'] === 'opposite' ? -1 : 1;

        if ($rule['grouping'] === 'many_left') {
            // several ledger lines add up to one bank line
            foreach ($B as $b) {
                if (isset($usedB[$b['id']])) continue;
                $target = $sign * (float)$b['value'];
                $set = find_combination($L, $usedL, $target, $b['txn_date'], (int)$rule['date_tol'],
                                        (int)$rule['max_group'], $b['description'], (int)$rule['link_desc']);
                if (!$set) continue;
                $groupNo++;
                $lTot = array_sum(array_map(fn($r) => (float)$r['value'], $set));
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $rule['name'],
                                $lTot, (float)$b['value'], $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                foreach ($set as $r) { $insL->execute([$gid, 'ledger', $r['id'], $r['value']]); $usedL[$r['id']] = 1; }
                $insL->execute([$gid, 'bank', $b['id'], $b['value']]);
                $usedB[$b['id']] = 1;
                $made++;
            }
        } elseif ($rule['grouping'] === 'many_right') {
            // one ledger line splits into several bank lines
            foreach ($L as $l) {
                if (isset($usedL[$l['id']])) continue;
                $target = $sign * (float)$l['value'];
                $set = find_combination($B, $usedB, $target, $l['txn_date'], (int)$rule['date_tol'],
                                        (int)$rule['max_group'], $l['description'], (int)$rule['link_desc']);
                if (!$set) continue;
                $groupNo++;
                $bTot = array_sum(array_map(fn($r) => (float)$r['value'], $set));
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $rule['name'],
                                (float)$l['value'], $bTot, $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                $insL->execute([$gid, 'ledger', $l['id'], $l['value']]);
                $usedL[$l['id']] = 1;
                foreach ($set as $r) { $insL->execute([$gid, 'bank', $r['id'], $r['value']]); $usedB[$r['id']] = 1; }
                $made++;
            }
        } else {
            // one to one - the common case
            foreach ($L as $l) {
                if (isset($usedL[$l['id']])) continue;
                $b = find_single($l, $B, $usedB, $rule);
                if (!$b) continue;
                $groupNo++;
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $rule['name'],
                                (float)$l['value'], (float)$b['value'], $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                $insL->execute([$gid, 'ledger', $l['id'], $l['value']]);
                $insL->execute([$gid, 'bank',   $b['id'], $b['value']]);
                $usedL[$l['id']] = 1;
                $usedB[$b['id']] = 1;
                $made++;
            }
        }
        $perRule[] = ['rule' => $rule, 'made' => $made];
    }
    return $perRule;
}

// Does a group balance? (The golden rule, checked again before anything is committed.)
function group_balances($ledgerTotal, $bankTotal, $signMode)
{
    $target = $signMode === 'opposite' ? -(float)$bankTotal : (float)$bankTotal;
    return abs((float)$ledgerTotal - $target) < 0.005;
}

// -----------------------------------------------------------------------------
// Commit the ticked groups of a run to the transactions themselves.
// -----------------------------------------------------------------------------
function finalise_run($runId)
{
    $pdo = db();

    $run = $pdo->prepare("SELECT * FROM runs WHERE id = ?");
    $run->execute([$runId]);
    $run = $run->fetch();
    if (!$run)                          return [false, 'That run no longer exists.'];
    if ($run['status'] === 'finalised') return [false, 'That run has already been finalised.'];

    $st = $pdo->prepare("SELECT * FROM match_groups WHERE run_id = ? AND accepted = 1 ORDER BY group_no");
    $st->execute([$runId]);
    $groups = $st->fetchAll();
    if (!$groups) return [false, 'There is nothing ticked to finalise.'];

    // Golden rule: refuse the whole thing if any ticked group is out of balance.
    foreach ($groups as $g) {
        if (!group_balances($g['ledger_total'], $g['bank_total'], $g['sign_mode'])) {
            return [false, "Match {$g['group_no']} does not balance ("
                . money($g['ledger_total']) . ' against ' . money($g['bank_total'])
                . '). Nothing has been committed.'];
        }
    }

    $pdo->beginTransaction();
    try {
        $lines = $pdo->prepare("SELECT side, txn_id FROM match_lines WHERE group_id = ?");
        $upL = $pdo->prepare("UPDATE ledger SET run_id=?, rule_ref=?, group_no=?, matched_at=NOW()
                              WHERE id=? AND matched_at IS NULL");
        $upB = $pdo->prepare("UPDATE bank   SET run_id=?, rule_ref=?, group_no=?, matched_at=NOW()
                              WHERE id=? AND matched_at IS NULL");
        $count = 0;
        foreach ($groups as $g) {
            $lines->execute([$g['id']]);
            foreach ($lines->fetchAll() as $ln) {
                $stmt = $ln['side'] === 'ledger' ? $upL : $upB;
                $stmt->execute([$runId, $g['rule_ref'], $g['group_no'], $ln['txn_id']]);
                $count += $stmt->rowCount();
            }
        }
        // Anything unticked is thrown away, so those items come back as open.
        $pdo->prepare("DELETE FROM match_groups WHERE run_id = ? AND accepted = 0")->execute([$runId]);
        $pdo->prepare("UPDATE runs SET status='finalised', finalised_at=NOW() WHERE id=?")->execute([$runId]);
        $pdo->commit();
        return [true, count($groups) . " matches committed, covering {$count} transactions."];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Nothing was committed: ' . $e->getMessage()];
    }
}
