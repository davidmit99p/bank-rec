<?php
// -----------------------------------------------------------------------------
// The matching engine.
//
// GOLDEN RULE: a match is only ever created when the two sides total the same
// amount. Nothing in this file creates an unbalanced match.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';

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
        'one'           => 'One ledger line to one bank line',
        'many_left'     => 'Several ledger lines add up to one bank line',
        'many_right'    => 'One ledger line splits into several bank lines',
        'contra_left'   => 'Two ledger lines that cancel each other out (contra)',
        'contra_right'  => 'Two bank lines that cancel each other out (contra)',
    ];
}

// Which side a contra rule works on, or null if it is not a contra rule.
function contra_side($grouping)
{
    if ($grouping === 'contra_left')  return 'ledger';
    if ($grouping === 'contra_right') return 'bank';
    return null;
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
    $table = $side === 'ledger' ? 'rec_ledger' : 'rec_bank';
    return db()->query("SELECT id, txn_date, description, value FROM {$table}
                        WHERE matched_at IS NULL" . rec_and() . " ORDER BY txn_date, id")->fetchAll();
}

// Transactions already spoken for by suggestions in this draft run.
function ids_used_in_run($runId, $side)
{
    $st = db()->prepare("SELECT l.txn_id FROM rec_match_lines l
                         JOIN rec_match_groups g ON g.id = l.group_id
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

// Find pairs on ONE side that are equal and opposite - a posting error and its
// reversal. There is nothing on the other side to match them against, but they
// still net to nothing, so the golden rule is honoured rather than bent.
//
// Deliberately pairs only. "Equal and opposite" means two entries; hunting for
// larger sets that happen to reach zero is how you end up matching things that
// have nothing to do with each other.
function find_contra_pairs(array $pool, array &$used, $tol, $linkDesc)
{
    // index by value so we can jump straight to the opposite amount
    $byValue = [];
    foreach ($pool as $r) {
        $byValue[number_format((float)$r['value'], 2, '.', '')][] = $r;
    }

    $pairs = [];
    foreach ($pool as $a) {
        if (isset($used[$a['id']])) continue;
        $va = (float)$a['value'];
        if (abs($va) < 0.005) continue;                 // a nil entry cancels nothing
        $key = number_format(-$va, 2, '.', '');
        if (empty($byValue[$key])) continue;

        $best = null;
        $bestGap = PHP_INT_MAX;
        foreach ($byValue[$key] as $b) {
            if ($b['id'] === $a['id'] || isset($used[$b['id']])) continue;
            $gap = days_apart($a['txn_date'], $b['txn_date']);
            if ($gap > $tol) continue;
            if ($linkDesc && !descs_agree($a['description'], $b['description'])) continue;
            if ($gap < $bestGap) { $best = $b; $bestGap = $gap; }
        }
        if ($best) {
            $used[$a['id']] = 1;
            $used[$best['id']] = 1;
            $pairs[] = [$a, $best];
        }
    }
    return $pairs;
}

// -----------------------------------------------------------------------------
// Run every active rule against the open items and write the suggestions.
// -----------------------------------------------------------------------------
function run_rules($runId)
{
    $pdo   = db();
    // rules with no reconciliation of their own apply to every one
    $rules = $pdo->query("SELECT * FROM rec_rules WHERE active = 1" . rule_and()
                         . " ORDER BY sort_order, id")->fetchAll();

    $ledger = load_open('ledger');
    $bank   = load_open('bank');
    $usedL  = ids_used_in_run($runId, 'ledger');   // respects manual matches already ticked in
    $usedB  = ids_used_in_run($runId, 'bank');

    $groupNo = (int)$pdo->query("SELECT COALESCE(MAX(group_no),0) FROM rec_match_groups
                                 WHERE run_id = " . (int)$runId)->fetchColumn();

    $insG = $pdo->prepare("INSERT INTO rec_match_groups
        (run_id, group_no, rule_ref, rule_name, ledger_total, bank_total, sign_mode, accepted)
        VALUES (?,?,?,?,?,?,?,1)");
    $insL = $pdo->prepare("INSERT INTO rec_match_lines (group_id, side, txn_id, value) VALUES (?,?,?,?)");

    $perRule = [];
    foreach ($rules as $rule) {
        $made = 0;
        $L = array_values(array_filter($ledger,
                fn($r) => !isset($usedL[$r['id']]) && row_matches_side($r, $rule, 'l_')));
        $B = array_values(array_filter($bank,
                fn($r) => !isset($usedB[$r['id']]) && row_matches_side($r, $rule, 'b_')));
        // a contra rule only needs its own side to have anything in it
        $contra = contra_side($rule['grouping']);
        $haveWork = $contra === 'ledger' ? (bool)$L
                  : ($contra === 'bank' ? (bool)$B : ($L && $B));
        if (!$haveWork) { $perRule[] = ['rule' => $rule, 'made' => 0]; continue; }

        $sign = $rule['sign_mode'] === 'opposite' ? -1 : 1;

        if (contra_side($rule['grouping'])) {
            // equal and opposite entries on one side only
            $side  = contra_side($rule['grouping']);
            $isL   = $side === 'ledger';
            $pairs = $isL
                ? find_contra_pairs($L, $usedL, (int)$rule['date_tol'], (int)$rule['link_desc'])
                : find_contra_pairs($B, $usedB, (int)$rule['date_tol'], (int)$rule['link_desc']);
            foreach ($pairs as $pair) {
                $groupNo++;
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $rule['name'],
                                0, 0, 'same']);
                $gid = $pdo->lastInsertId();
                foreach ($pair as $r) $insL->execute([$gid, $side, $r['id'], $r['value']]);
                $made++;
            }
        } elseif ($rule['grouping'] === 'many_left') {
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

    $run = $pdo->prepare("SELECT * FROM rec_runs WHERE id = ?");
    $run->execute([$runId]);
    $run = $run->fetch();
    if (!$run)                          return [false, 'That run no longer exists.'];
    if ($run['status'] === 'finalised') return [false, 'That run has already been finalised.'];

    $st = $pdo->prepare("SELECT * FROM rec_match_groups WHERE run_id = ? AND accepted = 1 ORDER BY group_no");
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
        $lines = $pdo->prepare("SELECT side, txn_id FROM rec_match_lines WHERE group_id = ?");
        $upL = $pdo->prepare("UPDATE rec_ledger SET run_id=?, rule_ref=?, group_no=?, matched_at=NOW()
                              WHERE id=? AND matched_at IS NULL");
        $upB = $pdo->prepare("UPDATE rec_bank   SET run_id=?, rule_ref=?, group_no=?, matched_at=NOW()
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
        $pdo->prepare("DELETE FROM rec_match_groups WHERE run_id = ? AND accepted = 0")->execute([$runId]);
        $pdo->prepare("UPDATE rec_runs SET status='finalised', finalised_at=NOW() WHERE id=?")->execute([$runId]);
        $pdo->commit();
        return [true, count($groups) . " matches committed, covering {$count} transactions."];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Nothing was committed: ' . $e->getMessage()];
    }
}


// -----------------------------------------------------------------------------
// Undoing matches.
// -----------------------------------------------------------------------------

// Undo one whole committed match. Returns how many transactions were freed.
// Deleting the group takes its lines with it (foreign key cascade).
function unmatch_whole_group($groupId)
{
    $pdo = db();
    $st = $pdo->prepare("SELECT side, txn_id FROM rec_match_lines WHERE group_id = ?");
    $st->execute([$groupId]);
    $lines = $st->fetchAll();

    $upL = $pdo->prepare("UPDATE rec_ledger SET run_id=NULL, rule_ref=NULL, group_no=NULL, matched_at=NULL WHERE id=?");
    $upB = $pdo->prepare("UPDATE rec_bank   SET run_id=NULL, rule_ref=NULL, group_no=NULL, matched_at=NULL WHERE id=?");
    foreach ($lines as $l) {
        ($l['side'] === 'ledger' ? $upL : $upB)->execute([$l['txn_id']]);
    }
    $pdo->prepare("DELETE FROM rec_match_groups WHERE id = ?")->execute([$groupId]);
    return count($lines);
}

// Unmatch a hand-picked set of transactions, which may span several matches.
//
// THE GOLDEN RULE, IN REVERSE. A match balances, so if you take out a selection
// that itself balances, what is left still balances. But that has to hold
// WITHIN EACH MATCH, not just across the selection as a whole - taking 10 out
// of one match and 10 out of another leaves both of them broken, even though
// the two cancel out on paper.
//
// Selecting one side only is the same test: the other side counts as zero, so
// the selection has to come to zero - which is how you pull a contra apart.
function unmatch_selection(array $ledgerIds, array $bankIds)
{
    $pdo = db();
    $sel = [];

    foreach ([['ledger', $ledgerIds], ['bank', $bankIds]] as [$side, $ids]) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) continue;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id AS line_id, group_id, side, txn_id, value
                             FROM rec_match_lines WHERE side = ? AND txn_id IN ($in)");
        $st->execute(array_merge([$side], $ids));
        foreach ($st->fetchAll() as $r) $sel[$r['group_id']][$r['side']][] = $r;
    }

    if (!$sel) return [false, 'None of those are matched, so there is nothing to unmatch.'];

    // check every affected match before touching anything
    $nums = $pdo->query("SELECT id, group_no FROM rec_match_groups
                         WHERE id IN (" . implode(',', array_map('intval', array_keys($sel))) . ")")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($sel as $gid => $sides) {
        $l = array_sum(array_map(fn($r) => (float)$r['value'], $sides['ledger'] ?? []));
        $b = array_sum(array_map(fn($r) => (float)$r['value'], $sides['bank'] ?? []));
        if (abs($l - $b) >= 0.005) {
            $no = $nums[$gid] ?? $gid;
            return [false, "What you have picked out of match {$no} does not balance - "
                . money($l) . ' on the ledger against ' . money($b) . ' on the bank. '
                . 'Either even it up, or take the whole match out. Nothing has been changed.'];
        }
    }

    $pdo->beginTransaction();
    try {
        $freed = 0;
        $split = 0;
        $gone  = 0;
        $upL = $pdo->prepare("UPDATE rec_ledger SET run_id=NULL, rule_ref=NULL, group_no=NULL, matched_at=NULL WHERE id=?");
        $upB = $pdo->prepare("UPDATE rec_bank   SET run_id=NULL, rule_ref=NULL, group_no=NULL, matched_at=NULL WHERE id=?");

        foreach ($sel as $gid => $sides) {
            foreach (['ledger', 'bank'] as $side) {
                foreach ($sides[$side] ?? [] as $line) {
                    $pdo->prepare("DELETE FROM rec_match_lines WHERE id = ?")->execute([$line['line_id']]);
                    ($side === 'ledger' ? $upL : $upB)->execute([$line['txn_id']]);
                    $freed++;
                }
            }
            // what is left of the match?
            $st = $pdo->prepare("SELECT side, SUM(value) total, COUNT(*) n FROM rec_match_lines
                                 WHERE group_id = ? GROUP BY side");
            $st->execute([$gid]);
            $left = ['ledger' => 0.0, 'bank' => 0.0];
            $count = 0;
            foreach ($st->fetchAll() as $r) { $left[$r['side']] = (float)$r['total']; $count += (int)$r['n']; }

            if ($count === 0) {
                $pdo->prepare("DELETE FROM rec_match_groups WHERE id = ?")->execute([$gid]);
                $gone++;
            } else {
                $pdo->prepare("UPDATE rec_match_groups SET ledger_total = ?, bank_total = ? WHERE id = ?")
                    ->execute([$left['ledger'], $left['bank'], $gid]);
                $split++;
            }
        }
        $pdo->commit();

        $msg = "{$freed} transactions unmatched and open again.";
        if ($gone)  $msg .= " {$gone} match" . ($gone == 1 ? '' : 'es') . ' removed entirely.';
        if ($split) $msg .= " {$split} match" . ($split == 1 ? '' : 'es') . ' kept the rest, still balancing.';
        return [true, $msg];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Nothing was unmatched: ' . $e->getMessage()];
    }
}
