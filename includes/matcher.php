<?php
// -----------------------------------------------------------------------------
// The matching engine.
//
// GOLDEN RULE: a match is only ever created when the two sides total the same
// amount. Nothing in this file creates an unbalanced match.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/matchstate.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/splits.php';

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
        'period_day'    => 'Everything on the same day, both sides',
        'period_month'  => 'Everything in the same month, both sides',
        'key'           => 'Everything sharing the same key, both sides',
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

// --- conditions on a file's own fields ----------------------------------------
//
// As well as the description, the value and the date, a rule can be held to
// particular values of a file's own fields - only code 4010, only period
// 2026/03. Two per side, each naming the field on that side, because the same
// thing can be a different spare field on each file.
const FIELD_COND_MAX = 2;

// The tests offered. The description ones, plus a list, a stretch, and blank -
// a line with nothing in the field is a real case and worth picking out.
// "Is between" only appears once migration_015 has given it a second box.
function field_ops()
{
    $out = desc_ops() + ['one_of' => 'is one of (separated by commas)'];
    if (field_range_ready()) $out += ['between' => 'is between'];
    return $out + ['blank' => 'is blank', 'not_blank' => 'is not blank'];
}

// Has migration_014 been run?
function field_conds_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT l_f1_key FROM rec_rules LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// And migration_015, which added the second box "is between" needs?
function field_range_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT l_f1_val2 FROM rec_rules LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// The conditions one side of a rule has filled in:
// [[field, test, value, second value], ...]. A condition needs a field, and a
// value unless the test is about blankness.
function field_conds(array $rule, $p)
{
    $out = [];
    for ($i = 1; $i <= FIELD_COND_MAX; $i++) {
        $key  = trim((string)($rule[$p . 'f' . $i . '_key'] ?? ''));
        $op   = trim((string)($rule[$p . 'f' . $i . '_op'] ?? ''));
        $val  = (string)($rule[$p . 'f' . $i . '_val'] ?? '');
        $val2 = (string)($rule[$p . 'f' . $i . '_val2'] ?? '');
        if ($key === '' || $op === '' || $op === 'any') continue;
        if (trim($val) === '' && $op !== 'blank' && $op !== 'not_blank') continue;
        if ($op === 'between' && trim($val2) === '') continue;      // half a stretch is no stretch
        $out[] = [$key, $op, $val, $val2];
    }
    return $out;
}

// One condition against one transaction. Everything is compared trimmed and
// without regard to capitals, as the rest of the matching does.
//
// "Is between" compares as text, which is what a period written 2026/01 wants:
// 2026/01 to 2026/06 takes in 2026/03. Either way round works.
function test_field($value, $op, $want, $want2 = '')
{
    $v = trim((string)$value);
    if ($op === 'blank')     return $v === '';
    if ($op === 'not_blank') return $v !== '';
    if ($op === 'one_of') {
        foreach (explode(',', (string)$want) as $one) {
            $one = trim($one);
            if ($one !== '' && mb_strtoupper($v) === mb_strtoupper($one)) return true;
        }
        return false;
    }
    if ($op === 'between') {
        $a = mb_strtoupper(trim((string)$want));
        $b = mb_strtoupper(trim((string)$want2));
        if ($a > $b) [$a, $b] = [$b, $a];
        $u = mb_strtoupper($v);
        return $u >= $a && $u <= $b;
    }
    return test_desc($v, $op, $want);
}

// Does this transaction meet one side of a rule? $p is 'l_' (ledger) or 'b_' (bank).
function row_matches_side(array $row, array $rule, $p)
{
    foreach (field_conds($rule, $p) as [$key, $op, $val, $val2]) {
        if (!array_key_exists($key, $row)) return false;      // the file has no such field
        if (!test_field($row[$key], $op, $val, $val2)) return false;
    }
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

// strtotime is slow and gets called a great many times, but there are only ever
// a few hundred distinct dates in a file, so remember them.
function date_ts($d)
{
    static $memo = [];
    $k = substr((string)$d, 0, 10);
    if (!isset($memo[$k])) $memo[$k] = strtotime($k);
    return $memo[$k];
}

function days_apart($d1, $d2)
{
    return (int)round(abs(date_ts($d1) - date_ts($d2)) / 86400);
}

// -----------------------------------------------------------------------------
// Indexes.
//
// The engine used to compare every item on one side against every item on the
// other. That is fine for a few hundred rows and hopeless for twelve thousand -
// the work grows with the square of the count.
//
// Nearly every rule requires the two amounts to be equal, so the amount is the
// natural way in. Index one side by amount and then by date, and a rule can go
// straight to the handful of candidates that could possibly match instead of
// walking the whole list. The second level matters because a file can hold
// thousands of transactions for the same amount - a monthly subscription, say -
// and indexing by amount alone would leave us scanning all of them.
// -----------------------------------------------------------------------------

function value_key($v)
{
    return number_format((float)$v, 2, '.', '');
}

// [amount][date] => rows
function index_rows(array $rows)
{
    $ix = [];
    foreach ($rows as $r) {
        $ix[value_key($r['value'])][substr((string)$r['txn_date'], 0, 10)][] = $r;
    }
    return $ix;
}

// [date] => rows, for the grouped rules where the amount is not known up front
function index_by_date(array $rows)
{
    $ix = [];
    foreach ($rows as $r) $ix[substr((string)$r['txn_date'], 0, 10)][] = $r;
    return $ix;
}

// Rows within $tol days of $anchor, in date order.
function rows_near_date(array $dateIndex, $anchor, $tol)
{
    $out  = [];
    $base = date_ts($anchor);
    for ($d = -$tol; $d <= $tol; $d++) {
        $day = date('Y-m-d', $base + $d * 86400);
        if (empty($dateIndex[$day])) continue;
        foreach ($dateIndex[$day] as $r) $out[] = $r;
    }
    return $out;
}

// Given dates, nearest to $anchor first; the earlier one wins a tie.
function dates_by_nearness(array $dates, $anchor)
{
    $a = date_ts($anchor);
    usort($dates, function ($x, $y) use ($a) {
        $dx = abs(date_ts($x) - $a);
        $dy = abs(date_ts($y) - $a);
        return $dx === $dy ? strcmp($x, $y) : $dx <=> $dy;
    });
    return $dates;
}

// The rows nearest to $anchor whatever the distance, for rules that ignore dates.
// Stops once $limit unused rows are in hand - the combination search only ever
// looks at a handful anyway.
function rows_nearest(array $dateIndex, $anchor, array $used, $limit = 60)
{
    $out = [];
    foreach (dates_by_nearness(array_keys($dateIndex), $anchor) as $day) {
        foreach ($dateIndex[$day] as $r) {
            if (isset($used[$r['id']])) continue;
            $out[] = $r;
            if (count($out) >= $limit) return $out;
        }
    }
    return $out;
}

// Day offsets ordered by how close they are: 0, -1, +1, -2, +2 ... so the first
// candidate found is the nearest in date and the search can stop there. The
// earlier date wins a tie, which is what the old every-row scan did too.
function offsets_by_nearness($tol)
{
    $o = [0];
    for ($i = 1; $i <= $tol; $i++) { $o[] = -$i; $o[] = $i; }
    return $o;
}

// --- loading the open items --------------------------------------------------

// Everything not yet finalised as matched.
function load_open($side)
{
    // The spare fields come too, because a rule can group on one of them.
    // no alias on the table here, so none in the conditions either
    return db()->query("SELECT id, txn_date, description, value, " . implode(', ', spare_keys()) . "
                        FROM rec_txns
                        WHERE " . open_where('') . " AND " . file_where($side, '') . not_split()
                        . " ORDER BY txn_date, id")->fetchAll();
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
// $index comes from index_rows() on the bank side.
function find_single(array $lrow, array $index, array $usedB, array $rule)
{
    $target = $rule['sign_mode'] === 'opposite' ? -(float)$lrow['value'] : (float)$lrow['value'];
    $key    = value_key($target);
    if (empty($index[$key])) return null;          // nothing that amount, done

    $tol  = (int)$rule['date_tol'];
    $base = date_ts($lrow['txn_date']);
    // with dates ignored, every date at this amount is in play - still nearest first
    $days = !empty($rule['ignore_date'])
          ? dates_by_nearness(array_keys($index[$key]), $lrow['txn_date'])
          : array_map(fn($off) => date('Y-m-d', $base + $off * 86400), offsets_by_nearness($tol));
    foreach ($days as $day) {
        if (empty($index[$key][$day])) continue;
        foreach ($index[$key][$day] as $b) {
            if (isset($usedB[$b['id']])) continue;
            if ($rule['link_desc'] && !descs_agree($lrow['description'], $b['description'])) continue;
            return $b;      // nearest date first, so the first one found is the best one
        }
    }
    return null;
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
// $near is already narrowed to the date window by rows_near_date(), in date
// order, so this only has to weed out what is taken and what does not match on
// wording.
function find_combination(array $near, array $used, $target, $anchorDate,
                          $maxSize, $anchorDesc, $linkDesc)
{
    $cands = [];
    foreach ($near as $r) {
        if (isset($used[$r['id']])) continue;
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

// A period at a time, both sides together, whether or not it balances.
//
// These are the shapes that can suggest something out of balance. They are for
// files summarised differently from each other, where you know a period belongs
// together but not which line answers which.
//
// A day is the common case: a card settlement file at booking level against an
// acquirer statement carrying one amount per day. Those should balance exactly,
// so the suggestion goes straight through. A month is the looser version for
// when they will not, and you trim it by eye.
//
// Capped only to stop something absurd. A period bigger than this is left alone
// rather than half offered.
const PERIOD_GROUP_CAP = 2000;

// $len is how much of the date makes the period: 10 for a day, 7 for a month.
function group_by_period(array $rows, array $used, $len)
{
    $out = [];
    foreach ($rows as $r) {
        if (isset($used[$r['id']])) continue;
        $out[substr((string)$r['txn_date'], 0, $len)][] = $r;
    }
    return $out;
}

// Groups on ONE side that cancel themselves out - a posting and its reversal
// sitting in the ledger with nothing on the other side to match them against.
// Two lines at least, and they must come to nothing.
function self_contra_sets(array $rows, array $used, $len, $field)
{
    $by  = $len ? group_by_period($rows, $used, $len) : group_by_key($rows, $used, $field ?: 'extra1');
    $out = [];
    foreach ($by as $k => $set) {
        if (count($set) < 2 || count($set) > PERIOD_GROUP_CAP) continue;
        $total = array_sum(array_map(fn($r) => (float)$r['value'], $set));
        if (abs($total) < 0.005) $out[$k] = $set;
    }
    return $out;
}

// Everything a one-sided clear would take for this rule on one side, as
// [['key' => ..., 'tag' => the agreeing values, 'rows' => [...]], ...].
//
// Grouped by the fields that must agree FIRST, so one account's reversal is
// never netted against another's, and then by the rule's key, day or month.
// Kept in one place because the run and the Test button must answer the same.
function self_contra_candidates(array $rule, array $rows, array $used, $side)
{
    if (empty($rule['self_contra'])) return [];
    if ($rule['grouping'] !== 'key' && !period_len($rule['grouping'])) return [];

    $len    = period_len($rule['grouping']);
    $field  = $side === 'ledger' ? ($rule['key_left'] ?? '') : ($rule['key_right'] ?? '');
    $pairs  = agree_pairs($rule);
    $fields = $pairs ? array_column($pairs, $side === 'ledger' ? 0 : 1) : [];
    $parts  = $fields ? partition_by_agreement($rows, $fields) : ['' => $rows];

    $out = [];
    foreach ($parts as $tag => $part) {
        foreach (self_contra_sets($part, $used, $len, $field) as $k => $set) {
            $out[] = ['key' => (string)$k, 'tag' => (string)$tag, 'rows' => $set];
        }
    }
    return $out;
}

// Has migration_016 been run? Until it has, the setting is not offered.
function self_contra_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT self_contra FROM rec_rules LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// Has migration_009 been run? Until it has, the "same key" shape is not offered.
function key_rules_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT key_left FROM rec_rules LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// Which fields a rule may group on. The spare fields are named per file, so the
// labels shown to the user come from the reconciliation being worked on.
function key_fields()
{
    $out = [];
    foreach (spare_keys() as $k) $out[$k] = 'Spare field ' . substr($k, 5);
    return $out + ['description' => 'Description'];
}

// Bucket rows by the value of one field. Trimmed and upper-cased, because a
// reference typed one way in one system and another way in the next is still
// the same reference. Anything with no value in that field is left out - a
// blank key is not a key.
function group_by_key(array $rows, array $used, $field)
{
    $out = [];
    foreach ($rows as $r) {
        if (isset($used[$r['id']])) continue;
        $k = mb_strtoupper(trim((string)($r[$field] ?? '')));
        if ($k === '') continue;
        $out[$k][] = $r;
    }
    return $out;
}

// How a period grouping labels itself, or null if the rule is not one.
function period_len($grouping)
{
    if ($grouping === 'period_day')   return 10;
    if ($grouping === 'period_month') return 7;
    return null;
}

// --- fields that must agree --------------------------------------------------
//
// A rule can insist that, say, the accounting period, the reference and the
// journal type agree on both sides as well as the amount. Up to four such pairs,
// each naming a field on the left file and the one it must equal on the right -
// they can be different spare fields, because each file names its own.
//
// Rather than teach every shape to check them, both sides are first split into
// buckets where those fields agree, and the shape then runs inside each bucket.
// Nothing can be paired across buckets, so every shape honours it for free, and
// smaller buckets make the search quicker rather than slower.

const AGREE_MAX = 4;

// Has migration_011 been run?
function agree_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT agree_left1, ignore_date FROM rec_rules LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// [[left field, right field], ...] for the pairs this rule has filled in.
function agree_pairs(array $rule)
{
    $out = [];
    for ($i = 1; $i <= AGREE_MAX; $i++) {
        $l = trim((string)($rule['agree_left' . $i] ?? ''));
        $r = trim((string)($rule['agree_right' . $i] ?? ''));
        if ($l !== '' && $r !== '') $out[] = [$l, $r];
    }
    return $out;
}

// The values of those fields as one comparable key. Trimmed and upper-cased,
// like the key grouping. A blank in any of them gives null: something with no
// period cannot be said to agree on the period.
function agree_value(array $row, array $fields)
{
    $parts = [];
    foreach ($fields as $f) {
        $v = mb_strtoupper(trim((string)($row[$f] ?? '')));
        if ($v === '') return null;
        $parts[] = $v;
    }
    return implode(' / ', $parts);
}

function partition_by_agreement(array $rows, array $fields)
{
    $out = [];
    foreach ($rows as $r) {
        $k = agree_value($r, $fields);
        if ($k !== null) $out[$k][] = $r;
    }
    return $out;
}

// The buckets a rule works through: one per set of agreeing values, or a
// single bucket holding everything when the rule asks for no agreement.
function rule_buckets(array $rule, array $L, array $B)
{
    $pairs = agree_pairs($rule);
    if (!$pairs) return [['L' => $L, 'B' => $B, 'tag' => '']];

    $pl = partition_by_agreement($L, array_column($pairs, 0));
    $pb = partition_by_agreement($B, array_column($pairs, 1));
    $contra = contra_side($rule['grouping']);
    $keys = $contra === 'ledger' ? array_keys($pl)
          : ($contra === 'bank'  ? array_keys($pb)
          : array_keys(array_intersect_key($pl, $pb)));
    sort($keys, SORT_STRING);

    $out = [];
    foreach ($keys as $k) {
        $out[] = ['L' => $pl[$k] ?? [], 'B' => $pb[$k] ?? [], 'tag' => ' - ' . $k];
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Try a rule without writing anything, so the form can say what it would find.
//
// The commonest disappointment is a rule that finds nothing, and the reason is
// almost always one condition emptying a side - a code written differently, or
// a period range that misses the year. So this counts each side separately
// before it counts pairs, and that is usually enough to see it.
//
// It looks at open items in the reconciliation being worked on, which is what
// Process would work on too.
// -----------------------------------------------------------------------------
function rule_test(array $rule)
{
    $allL = load_open('ledger');
    $allB = load_open('bank');

    // Items already spoken for by an open run are skipped by Process, so the
    // test skips them too - otherwise it promises more than a run would deliver.
    $draft = db()->query("SELECT id FROM rec_runs WHERE status = 'draft'" . rec_and()
                         . " ORDER BY id DESC LIMIT 1")->fetchColumn();
    $usedL = $draft ? ids_used_in_run((int)$draft, 'ledger') : [];
    $usedB = $draft ? ids_used_in_run((int)$draft, 'bank')   : [];
    $held  = 0;
    foreach ([[$allL, $usedL], [$allB, $usedB]] as [$rows, $used]) {
        foreach ($rows as $row) if (isset($used[$row['id']])) $held++;
    }
    $allL = array_values(array_filter($allL, fn($r) => !isset($usedL[$r['id']])));
    $allB = array_values(array_filter($allB, fn($r) => !isset($usedB[$r['id']])));

    $L = array_values(array_filter($allL, fn($r) => row_matches_side($r, $rule, 'l_')));
    $B = array_values(array_filter($allB, fn($r) => row_matches_side($r, $rule, 'b_')));
    $sum = fn($rows) => array_sum(array_map(fn($r) => (float)$r['value'], $rows));

    $out = ['open_l' => count($allL), 'open_b' => count($allB),
            'fit_l'  => count($L),    'fit_b'  => count($B),
            'total_l' => $sum($L),    'total_b' => $sum($B),
            'shape' => grouping_modes()[$rule['grouping']] ?? $rule['grouping'],
            'held'  => $held,
            'groups' => null, 'balance' => null, 'off' => null, 'examples' => [], 'too_big' => [], 'contras' => []];

    // The shapes that gather everything sharing something can be counted exactly:
    // how many keys are on both sides, and how many of those come to the same.
    $len = period_len($rule['grouping']);
    if ($rule['grouping'] === 'key' || $len) {
        $on = $off = 0;
        $tookL = $tookB = [];          // what the two-sided pass would use up
        $keysL = $keysB = [];
        $blankL = $blankB = 0;
        if (!$len) {
            // lines with nothing in the key field cannot be grouped at all, and
            // that is the usual reason a side brings nothing to the party
            foreach ([[$L, $rule['key_left'], 'l'], [$B, $rule['key_right'], 'b']] as [$rows, $field, $sd]) {
                foreach ($rows as $row) {
                    $k = mb_strtoupper(trim((string)($row[$field ?: 'extra1'] ?? '')));
                    if ($k === '') { $sd === 'l' ? $blankL++ : $blankB++; continue; }
                    $sd === 'l' ? $keysL[$k] = true : $keysB[$k] = true;
                }
            }
        }
        $out['keys_l']  = $len ? null : count($keysL);
        $out['keys_b']  = $len ? null : count($keysB);
        $out['blank_l'] = $blankL;
        $out['blank_b'] = $blankB;
        foreach (rule_buckets($rule, $L, $B) as $bucket) {
            $byL = $len ? group_by_period($bucket['L'], [], $len) : group_by_key($bucket['L'], [], $rule['key_left'] ?: 'extra1');
            $byB = $len ? group_by_period($bucket['B'], [], $len) : group_by_key($bucket['B'], [], $rule['key_right'] ?: 'extra1');
            foreach ($byL as $k => $ls) {
                if (empty($byB[$k])) continue;
                if (count($ls) > PERIOD_GROUP_CAP || count($byB[$k]) > PERIOD_GROUP_CAP) {
                    // more lines than a run will group; named as the run names it
                    $out['too_big'][] = $k . ($bucket['tag'] ? ' (' . ltrim($bucket['tag'], ' -') . ')' : '');
                    continue;
                }
                $lt = $sum($ls);
                $bt = $sum($byB[$k]);
                $ok = group_balances($lt, $bt, $rule['sign_mode']);
                if ($ok) {
                    foreach ($ls as $row) $tookL[$row['id']] = 1;
                    foreach ($byB[$k] as $row) $tookB[$row['id']] = 1;
                }
                $ok ? $on++ : $off++;
                if (count($out['examples']) < 5) {
                    // the tag carries the agreeing values, as " - 6715"
                    $out['examples'][] = ['key' => $k, 'agree' => ltrim((string)$bucket['tag'], ' -'),
                                          'l' => $lt, 'b' => $bt, 'ok' => $ok];
                }
            }
        }
        $out['groups']  = $on + $off;
        $out['balance'] = $on;
        $out['off']     = $off;

        // and then the ones that cancel themselves out on one side, if asked for
        foreach ([['ledger', $L, $tookL], ['bank', $B, $tookB]] as [$sd, $rows, $took]) {
            foreach (self_contra_candidates($rule, $rows, $took, $sd) as $c) {
                $out['contras'][] = [$sd, $c['key'] . ($c['tag'] !== '' ? ' (' . $c['tag'] . ')' : ''),
                                     count($c['rows'])];
            }
        }
    }
    return $out;
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
        $tooBig = [];          // keys or periods with more lines than we will group
        $allL = array_values(array_filter($ledger,
                fn($r) => !isset($usedL[$r['id']]) && row_matches_side($r, $rule, 'l_')));
        $allB = array_values(array_filter($bank,
                fn($r) => !isset($usedB[$r['id']]) && row_matches_side($r, $rule, 'b_')));
        $sign   = $rule['sign_mode'] === 'opposite' ? -1 : 1;
        $noDate = !empty($rule['ignore_date']);
        $contra = contra_side($rule['grouping']);

      // one pass per set of agreeing fields (just one pass if the rule has none)
      foreach (rule_buckets($rule, $allL, $allB) as $bucket) {
        $L    = $bucket['L'];
        $B    = $bucket['B'];
        $tag  = $bucket['tag'];
        $name = mb_substr($rule['name'] . $tag, 0, 150);
        // a contra rule only needs its own side to have anything in it
        $haveWork = $contra === 'ledger' ? (bool)$L
                  : ($contra === 'bank' ? (bool)$B : ($L && $B));
        if (!$haveWork) continue;

        if ($rule['grouping'] === 'key') {
            // everything sharing a reference on both sides, whether or not the
            // two sides come to the same
            $kl = $rule['key_left']  ?? 'extra1';
            $kr = $rule['key_right'] ?? 'extra1';
            $byL = group_by_key($L, $usedL, $kl ?: 'extra1');
            $byB = group_by_key($B, $usedB, $kr ?: 'extra1');
            $keys = array_keys($byL);
            sort($keys);
            foreach ($keys as $k) {
                if (empty($byB[$k])) continue;              // needs both sides
                $ls = $byL[$k];
                $bs = $byB[$k];
                if (count($ls) > PERIOD_GROUP_CAP || count($bs) > PERIOD_GROUP_CAP) {
                    $tooBig[] = $k . $tag;
                    continue;
                }

                $lTot = array_sum(array_map(fn($r) => (float)$r['value'], $ls));
                $bTot = array_sum(array_map(fn($r) => (float)$r['value'], $bs));
                $groupNo++;
                $insG->execute([$runId, $groupNo, (string)$rule['id'],
                                mb_substr($rule['name'] . ' - ' . $k . $tag, 0, 150),
                                $lTot, $bTot, $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                foreach ($ls as $r) { $insL->execute([$gid, 'ledger', $r['id'], $r['value']]); $usedL[$r['id']] = 1; }
                foreach ($bs as $r) { $insL->execute([$gid, 'bank',   $r['id'], $r['value']]); $usedB[$r['id']] = 1; }
                $made++;
            }
        } elseif (period_len($rule['grouping'])) {
            $len = period_len($rule['grouping']);
            $byL = group_by_period($L, $usedL, $len);
            $byB = group_by_period($B, $usedB, $len);
            $months = array_keys($byL + $byB);
            sort($months);
            foreach ($months as $ym) {
                $ls = $byL[$ym] ?? [];
                $bs = $byB[$ym] ?? [];
                if (!$ls || !$bs) continue;                    // needs both sides
                if (count($ls) > PERIOD_GROUP_CAP || count($bs) > PERIOD_GROUP_CAP) {
                    $tooBig[] = $ym . $tag;
                    continue;
                }

                $lTot = array_sum(array_map(fn($r) => (float)$r['value'], $ls));
                $bTot = array_sum(array_map(fn($r) => (float)$r['value'], $bs));
                $groupNo++;
                $when = $len === 10 ? date('j F Y', strtotime($ym))
                                    : date('F Y', strtotime($ym . '-01'));
                $insG->execute([$runId, $groupNo, (string)$rule['id'],
                                mb_substr($rule['name'] . ' - ' . $when . $tag, 0, 150),
                                $lTot, $bTot, $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                foreach ($ls as $r) { $insL->execute([$gid, 'ledger', $r['id'], $r['value']]); $usedL[$r['id']] = 1; }
                foreach ($bs as $r) { $insL->execute([$gid, 'bank',   $r['id'], $r['value']]); $usedB[$r['id']] = 1; }
                $made++;
            }
        }

        if (contra_side($rule['grouping'])) {
            // equal and opposite entries on one side only
            $side  = contra_side($rule['grouping']);
            $isL   = $side === 'ledger';
            $tol   = $noDate ? PHP_INT_MAX : (int)$rule['date_tol'];
            $pairs = $isL
                ? find_contra_pairs($L, $usedL, $tol, (int)$rule['link_desc'])
                : find_contra_pairs($B, $usedB, $tol, (int)$rule['link_desc']);
            foreach ($pairs as $pair) {
                $groupNo++;
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $name,
                                0, 0, 'same']);
                $gid = $pdo->lastInsertId();
                foreach ($pair as $r) $insL->execute([$gid, $side, $r['id'], $r['value']]);
                $made++;
            }
        } elseif ($rule['grouping'] === 'many_left') {
            // several ledger lines add up to one bank line
            $byDate = index_by_date($L);
            foreach ($B as $b) {
                if (isset($usedB[$b['id']])) continue;
                $target = $sign * (float)$b['value'];
                $near = $noDate ? rows_nearest($byDate, $b['txn_date'], $usedL)
                                : rows_near_date($byDate, $b['txn_date'], (int)$rule['date_tol']);
                $set = find_combination($near, $usedL, $target, $b['txn_date'],
                                        (int)$rule['max_group'], $b['description'], (int)$rule['link_desc']);
                if (!$set) continue;
                $groupNo++;
                $lTot = array_sum(array_map(fn($r) => (float)$r['value'], $set));
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $name,
                                $lTot, (float)$b['value'], $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                foreach ($set as $r) { $insL->execute([$gid, 'ledger', $r['id'], $r['value']]); $usedL[$r['id']] = 1; }
                $insL->execute([$gid, 'bank', $b['id'], $b['value']]);
                $usedB[$b['id']] = 1;
                $made++;
            }
        } elseif ($rule['grouping'] === 'many_right') {
            // one ledger line splits into several bank lines
            $byDate = index_by_date($B);
            foreach ($L as $l) {
                if (isset($usedL[$l['id']])) continue;
                $target = $sign * (float)$l['value'];
                $near = $noDate ? rows_nearest($byDate, $l['txn_date'], $usedB)
                                : rows_near_date($byDate, $l['txn_date'], (int)$rule['date_tol']);
                $set = find_combination($near, $usedB, $target, $l['txn_date'],
                                        (int)$rule['max_group'], $l['description'], (int)$rule['link_desc']);
                if (!$set) continue;
                $groupNo++;
                $bTot = array_sum(array_map(fn($r) => (float)$r['value'], $set));
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $name,
                                (float)$l['value'], $bTot, $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                $insL->execute([$gid, 'ledger', $l['id'], $l['value']]);
                $usedL[$l['id']] = 1;
                foreach ($set as $r) { $insL->execute([$gid, 'bank', $r['id'], $r['value']]); $usedB[$r['id']] = 1; }
                $made++;
            }
        } else {
            // one to one - the common case
            $bankIndex = index_rows($B);
            foreach ($L as $l) {
                if (isset($usedL[$l['id']])) continue;
                $b = find_single($l, $bankIndex, $usedB, $rule);
                if (!$b) continue;
                $groupNo++;
                $insG->execute([$runId, $groupNo, (string)$rule['id'], $name,
                                (float)$l['value'], (float)$b['value'], $rule['sign_mode']]);
                $gid = $pdo->lastInsertId();
                $insL->execute([$gid, 'ledger', $l['id'], $l['value']]);
                $insL->execute([$gid, 'bank',   $b['id'], $b['value']]);
                $usedL[$l['id']] = 1;
                $usedB[$b['id']] = 1;
                $made++;
            }
        }
      }

        // Then, once for the whole rule, whatever cancels itself out on one side
        // alone. Outside the loop above because that only runs where both sides
        // have something, and these have nothing opposite them at all.
        if (!empty($rule['self_contra'])) {
            $len = period_len($rule['grouping']);
            foreach (['ledger' => $allL, 'bank' => $allB] as $sd => $rows) {
                $used = $sd === 'ledger' ? $usedL : $usedB;
                foreach (self_contra_candidates($rule, $rows, $used, $sd) as $c) {
                    $when = $len === 10 ? date('j F Y', strtotime($c['key']))
                          : ($len ? date('F Y', strtotime($c['key'] . '-01')) : $c['key']);
                    $groupNo++;
                    $insG->execute([$runId, $groupNo, (string)$rule['id'],
                                    mb_substr($rule['name'] . ' - ' . $when
                                              . ($c['tag'] !== '' ? ' - ' . $c['tag'] : '')
                                              . ' - cancels out', 0, 150),
                                    0, 0, 'same']);
                    $gid = $pdo->lastInsertId();
                    foreach ($c['rows'] as $r) {
                        $insL->execute([$gid, $sd, $r['id'], $r['value']]);
                        if ($sd === 'ledger') $usedL[$r['id']] = 1; else $usedB[$r['id']] = 1;
                    }
                    $made++;
                }
            }
        }

        $perRule[] = ['rule' => $rule, 'made' => $made, 'too_big' => $tooBig];
    }
    return $perRule;
}

// --- what a manual match was made on ------------------------------------------

// Has migration_012 been run?
function criteria_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT criteria FROM rec_match_groups LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// Keep the filters that were on screen with the match. $c is
// ['ledger' => [...], 'bank' => [...], 'both' => [...]], each a list of phrases.
function save_match_criteria($groupId, array $c)
{
    if (!criteria_ready()) return;
    $c = array_filter($c);
    if (!$c) return;                      // nothing was filtered - nothing to say
    db()->prepare("UPDATE rec_match_groups SET criteria = ? WHERE id = ?")
        ->execute([json_encode($c, JSON_UNESCAPED_UNICODE), (int)$groupId]);
}

// The banner shown under a match's heading, or '' when it has none.
function criteria_banner(array $g)
{
    $c = json_decode((string)($g['criteria'] ?? ''), true);
    if (!is_array($c) || !$c) return '';
    $parts = [];
    foreach (['ledger', 'bank'] as $side) {
        if (!empty($c[$side])) {
            $parts[] = '<span><b>' . h(side_label($side)) . ':</b> ' . h(implode(', ', $c[$side])) . '</span>';
        }
    }
    if (!empty($c['both'])) $parts[] = '<span><b>Both:</b> ' . h(implode(', ', $c['both'])) . '</span>';
    return '<div class="criteria"><span class="muted">Filtered on when matched</span>'
         . implode('', $parts) . '</div>';
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

    // THE GOLDEN RULE. Only what balances is committed.
    //
    // It used to refuse the whole batch if anything was out, which was right
    // when an unbalanced group meant a bug. Now that a rule can deliberately
    // suggest a month that does not balance, refusing everything would make the
    // good work hostage to the unfinished. So the ones that balance go through
    // and the rest are carried forward to keep working on.
    $ready = [];
    $notYet = [];
    foreach ($groups as $g) {
        if (group_balances($g['ledger_total'], $g['bank_total'], $g['sign_mode'])) $ready[] = $g;
        else $notYet[] = $g;
    }
    if (!$ready) {
        return [false, 'None of the ticked matches balance yet, so there is nothing to commit. '
            . 'Adjust what is in them until each one comes to nothing.'];
    }

    $groups = $ready;
    $pdo->beginTransaction();
    try {
        $lines = $pdo->prepare("SELECT side, txn_id FROM rec_match_lines WHERE group_id = ?");
        $count = 0;
        foreach ($groups as $g) {
            $lines->execute([$g['id']]);
            $ids = array_column($lines->fetchAll(), 'txn_id');
            $count += mark_matched($ids, $run['rec_id'] ?? null, $g['id'], $runId,
                                   $g['rule_ref'], $g['group_no']);
        }
        // Anything unticked is thrown away, so those items come back as open.
        $pdo->prepare("DELETE FROM rec_match_groups WHERE run_id = ? AND accepted = 0")->execute([$runId]);

        // Anything ticked that does not balance yet moves to a fresh run, so a
        // finalised run holds only committed matches and the unfinished work is
        // still there to come back to.
        $carried = 0;
        if ($notYet) {
            $ref = make_run_ref();
            $pdo->prepare("INSERT INTO rec_runs (run_ref, rec_id, note) VALUES (?,?,?)")
                ->execute([$ref, $run['rec_id'] ?? null, 'Carried forward from ' . $run['run_ref']]);
            $newRun = (int)$pdo->lastInsertId();
            $ids = implode(',', array_map(fn($g) => (int)$g['id'], $notYet));
            $pdo->prepare("UPDATE rec_match_groups SET run_id = ? WHERE id IN ($ids)")->execute([$newRun]);
            $carried = count($notYet);
        }

        $pdo->prepare("UPDATE rec_runs SET status='finalised', finalised_at=NOW() WHERE id=?")->execute([$runId]);
        $pdo->commit();

        $msg = count($groups) . " matches committed, covering {$count} transactions.";
        if ($carried) {
            $msg .= ' ' . $carried . ' that did not balance ' . ($carried == 1 ? 'was' : 'were')
                  . ' carried forward to a new run for you to keep working on.';
        }
        return [true, $msg];
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

    // which reconciliation this match belongs to
    $st = $pdo->prepare("SELECT r.rec_id FROM rec_match_groups g
                         JOIN rec_runs r ON r.id = g.run_id WHERE g.id = ?");
    $st->execute([$groupId]);
    $recId = $st->fetchColumn();

    unmark_matched(array_column($lines, 'txn_id'), $recId);
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
        // each affected match belongs to a reconciliation; taking a line out
        // frees it in that one only
        $recOf = $pdo->prepare("SELECT r.rec_id FROM rec_match_groups g
                                JOIN rec_runs r ON r.id = g.run_id WHERE g.id = ?");

        foreach ($sel as $gid => $sides) {
            $recOf->execute([$gid]);
            $recId = $recOf->fetchColumn();
            $freeIds = [];
            foreach (['ledger', 'bank'] as $side) {
                foreach ($sides[$side] ?? [] as $line) {
                    $pdo->prepare("DELETE FROM rec_match_lines WHERE id = ?")->execute([$line['line_id']]);
                    $freeIds[] = $line['txn_id'];
                    $freed++;
                }
            }
            unmark_matched($freeIds, $recId);

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


// Take lines out of a suggested (not yet committed) match, so a group that does
// not balance can be trimmed until it does. What comes out goes back to the
// open list simply by no longer being in the group.
function drop_lines_from_groups($runId, array $lineIds)
{
    $lineIds = array_values(array_filter(array_map('intval', $lineIds)));
    if (!$lineIds) return 0;
    $pdo = db();

    $in = implode(',', array_fill(0, count($lineIds), '?'));
    $st = $pdo->prepare("SELECT DISTINCT l.group_id FROM rec_match_lines l
                         JOIN rec_match_groups g ON g.id = l.group_id
                         WHERE g.run_id = ? AND l.id IN ($in)");
    $st->execute(array_merge([$runId], $lineIds));
    $groupIds = array_column($st->fetchAll(), 'group_id');
    if (!$groupIds) return 0;

    $pdo->prepare("DELETE l FROM rec_match_lines l
                   JOIN rec_match_groups g ON g.id = l.group_id
                   WHERE g.run_id = ? AND l.id IN ($in)")
        ->execute(array_merge([$runId], $lineIds));

    // put each affected group's totals back in step with what it now holds
    $sum = $pdo->prepare("SELECT side, COALESCE(SUM(value),0) total, COUNT(*) n
                          FROM rec_match_lines WHERE group_id = ? GROUP BY side");
    foreach ($groupIds as $gid) {
        $sum->execute([$gid]);
        $tot = ['ledger' => 0.0, 'bank' => 0.0];
        $n = 0;
        foreach ($sum->fetchAll() as $r) { $tot[$r['side']] = (float)$r['total']; $n += (int)$r['n']; }
        if ($n === 0) {
            $pdo->prepare("DELETE FROM rec_match_groups WHERE id = ?")->execute([$gid]);
        } else {
            $pdo->prepare("UPDATE rec_match_groups SET ledger_total = ?, bank_total = ? WHERE id = ?")
                ->execute([$tot['ledger'], $tot['bank'], $gid]);
        }
    }
    return count($lineIds);
}
