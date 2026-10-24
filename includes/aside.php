<?php
// -----------------------------------------------------------------------------
// Set aside: a third answer, beside matched and unmatched.
//
// At volume, a reconciliation ends with a handful of items that cannot be
// settled here at all - a duplicate posting, a payment to the wrong account, a
// reference that does not exist on the other side. They have been looked at.
// They are not work any more; they are a job for whoever owns the source data.
//
// Left in the lists they are noise, and the difference never reaches zero no
// matter how much real work is done. So they can be set aside with a reason,
// hidden while the rest is worked, and brought back at the end as the list of
// things to go and fix.
//
// Two rules keep this honest:
//
//  - the difference on screen always says what it is excluding, and what it
//    would be with those items back in. Zero means "everything is either
//    matched or set aside with a reason", never "there is nothing wrong";
//  - the reconciliation statement is untouched. A set-aside item is still an
//    unsettled difference, so it still appears there, with its reason.
//
// Per reconciliation, like rec_matched: a file shared by two reconciliations
// can be settled in one and still a problem in the other.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/matchstate.php';

// Has migration_025 been run?
function aside_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT txn_id FROM rec_aside LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// Set aside in the reconciliation being worked on. $alias is the caller's alias.
function aside_where($alias)
{
    $rec = rec_id();
    $p = $alias === '' ? '' : $alias . '.';
    if (!aside_ready() || $rec === null) return '1 = 0';
    return "EXISTS (SELECT 1 FROM rec_aside sa
                    WHERE sa.txn_id = {$p}id AND sa.rec_id = " . (int)$rec . ")";
}

// The other way round, which is what the working lists want.
function not_aside_where($alias)
{
    $rec = rec_id();
    $p = $alias === '' ? '' : $alias . '.';
    if (!aside_ready() || $rec === null) return '1 = 1';
    return "NOT EXISTS (SELECT 1 FROM rec_aside sa
                        WHERE sa.txn_id = {$p}id AND sa.rec_id = " . (int)$rec . ")";
}

// The reason, for display beside an item. A LEFT JOIN the caller can drop in.
function aside_join($alias)
{
    $rec = rec_id();
    if (!aside_ready() || $rec === null) return '';
    return " LEFT JOIN rec_aside sa ON sa.txn_id = {$alias}.id AND sa.rec_id = " . (int)$rec;
}

// Set these items aside, with one reason covering them.
//
// Something already matched is refused rather than quietly unmatched: taking it
// out of a committed match is a bigger decision than this, and it has its own
// way of being done.
//
// Returns [howMany, message].
function set_aside(array $txnIds, $reason)
{
    if (!aside_ready()) return [0, 'The database has not been updated for setting items aside yet.'];
    $rec = rec_id();
    if ($rec === null) return [0, 'Choose which reconciliation you are working on first.'];
    $txnIds = array_values(array_unique(array_filter(array_map('intval', $txnIds))));
    if (!$txnIds) return [0, 'Tick some items first.'];

    $in = implode(',', $txnIds);
    $matched = db()->query("SELECT COUNT(*) FROM rec_txns t WHERE t.id IN ($in) AND "
                           . matched_where('t'))->fetchColumn();
    if ($matched) {
        return [0, $matched . ($matched === 1 ? ' of those is' : ' of those are')
            . ' already matched in this reconciliation. Unmatch ' . ($matched === 1 ? 'it' : 'them')
            . ' first - a whole match at a time - then set ' . ($matched === 1 ? 'it' : 'them') . ' aside.'];
    }

    $u = function_exists('current_user') ? current_user() : null;
    $reason = trim((string)$reason);
    $ins = db()->prepare("INSERT INTO rec_aside (txn_id, rec_id, reason, aside_by)
                          VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE reason = VALUES(reason)");
    $n = 0;
    foreach ($txnIds as $id) {
        $ins->execute([$id, (int)$rec, $reason === '' ? null : mb_substr($reason, 0, 500),
                       $u['name'] ?? null]);
        $n++;
    }
    if (function_exists('log_event')) {
        log_event('set items aside', $n . ' item' . ($n === 1 ? '' : 's')
            . ($reason === '' ? '' : ': ' . mb_substr($reason, 0, 160)));
    }
    return [$n, $n . ($n === 1 ? ' item has' : ' items have') . ' been set aside'
        . ($reason === '' ? ' - worth writing a reason against them' : '') . '.'];
}

// Bring them back into the work.
function bring_back(array $txnIds)
{
    if (!aside_ready()) return [0, 'The database has not been updated for setting items aside yet.'];
    $rec = rec_id();
    if ($rec === null) return [0, 'Choose which reconciliation you are working on first.'];
    $txnIds = array_values(array_filter(array_map('intval', $txnIds)));
    if (!$txnIds) return [0, 'Tick some items first.'];

    $in = implode(',', $txnIds);
    $st = db()->prepare("DELETE FROM rec_aside WHERE rec_id = ? AND txn_id IN ($in)");
    $st->execute([(int)$rec]);
    $n = $st->rowCount();
    if ($n && function_exists('log_event')) {
        log_event('brought items back', $n . ' item' . ($n === 1 ? '' : 's') . ' no longer set aside');
    }
    return [$n, $n ? $n . ($n === 1 ? ' item is' : ' items are') . ' back in the lists.'
                   : 'None of those were set aside.'];
}

// Change the reason on one item, from the list itself.
function set_aside_reason($txnId, $reason)
{
    if (!aside_ready() || rec_id() === null) return [false, 'Not available yet.'];
    $reason = trim((string)$reason);
    $st = db()->prepare("UPDATE rec_aside SET reason = ? WHERE txn_id = ? AND rec_id = ?");
    $st->execute([$reason === '' ? null : mb_substr($reason, 0, 500), (int)$txnId, (int)rec_id()]);
    return [true, 'Reason saved.'];
}

// How many are set aside in this reconciliation, and what they come to, per
// side. Unfiltered on purpose: this is the qualifier on the difference, and a
// qualifier that moved with the filters would be no use.
function aside_totals($side)
{
    if (!aside_ready() || rec_id() === null) return ['n' => 0, 'total' => 0.0];
    $sql = "SELECT COUNT(*) n, COALESCE(SUM(t.value), 0) total FROM rec_txns t
            WHERE " . file_where($side, 't') . " AND " . aside_where('t')
            . (function_exists('splits_ready') && splits_ready() ? " AND t.split_at IS NULL" : "");
    $r = db()->query($sql)->fetch();
    return ['n' => (int)$r['n'], 'total' => (float)$r['total']];
}
