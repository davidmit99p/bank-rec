<?php
// -----------------------------------------------------------------------------
// Is this transaction matched - and matched WHERE?
//
// It used to be a fact about the transaction: one matched_at column, one answer.
// That breaks as soon as a file belongs to more than one reconciliation, because
// the same line can be settled against A and still outstanding against C.
//
// So the answer lives in rec_matched, one row per transaction per
// reconciliation. No row for a reconciliation means open in it.
//
// rec_txns.matched_at is still maintained, but it now means "matched SOMEWHERE",
// which is the right question for things like splitting - you should not split a
// line that has been used in any reconciliation.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';

function matchstate_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT txn_id FROM rec_matched LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// A condition saying this transaction is still open in the reconciliation being
// worked on. $alias is the table alias the caller's query uses.
function open_where($alias)
{
    $rec = rec_id();
    $p = $alias === '' ? '' : $alias . '.';
    if (!matchstate_ready()) return "{$p}matched_at IS NULL";
    if ($rec === null) return '1 = 1';
    return "NOT EXISTS (SELECT 1 FROM rec_matched mm
                        WHERE mm.txn_id = {$p}id AND mm.rec_id = " . (int)$rec . ")";
}

// The other way round.
function matched_where($alias)
{
    $rec = rec_id();
    $p = $alias === '' ? '' : $alias . '.';
    if (!matchstate_ready()) return "{$p}matched_at IS NOT NULL";
    if ($rec === null) return '1 = 0';
    return "EXISTS (SELECT 1 FROM rec_matched mm
                    WHERE mm.txn_id = {$p}id AND mm.rec_id = " . (int)$rec . ")";
}

// Used anywhere at all, in any reconciliation. This is the question splitting
// and removing an import need to ask.
function matched_anywhere($alias)
{
    $p = $alias === '' ? '' : $alias . '.';
    if (!matchstate_ready()) return "{$p}matched_at IS NOT NULL";
    return "EXISTS (SELECT 1 FROM rec_matched mm WHERE mm.txn_id = {$p}id)";
}

// Record that these transactions are matched, in this reconciliation.
function mark_matched(array $txnIds, $recId, $groupId, $runId, $ruleRef, $groupNo)
{
    if (!$txnIds || !matchstate_ready() || $recId === null) return 0;
    $pdo = db();
    $ins = $pdo->prepare("INSERT IGNORE INTO rec_matched
        (txn_id, rec_id, group_id, run_id, rule_ref, group_no, matched_at)
        VALUES (?,?,?,?,?,?,NOW())");
    $n = 0;
    foreach ($txnIds as $id) {
        $ins->execute([(int)$id, (int)$recId, (int)$groupId, (int)$runId, $ruleRef, $groupNo]);
        $n += $ins->rowCount();
    }
    refresh_matched_flag($txnIds);
    return $n;
}

// Take them out of one reconciliation.
function unmark_matched(array $txnIds, $recId)
{
    if (!$txnIds || !matchstate_ready()) return 0;
    $ids = implode(',', array_map('intval', $txnIds));
    $st = db()->prepare("DELETE FROM rec_matched WHERE rec_id = ? AND txn_id IN ($ids)");
    $st->execute([(int)$recId]);
    $n = $st->rowCount();
    refresh_matched_flag($txnIds);
    return $n;
}

// Keep rec_txns.matched_at in step with "matched somewhere", so the one column
// still answers the question it is now asked.
function refresh_matched_flag(array $txnIds)
{
    if (!$txnIds || !matchstate_ready()) return;
    $ids = implode(',', array_map('intval', $txnIds));
    db()->exec("UPDATE rec_txns t SET t.matched_at = CASE
                    WHEN EXISTS (SELECT 1 FROM rec_matched m WHERE m.txn_id = t.id)
                    THEN COALESCE(t.matched_at, NOW()) ELSE NULL END
                WHERE t.id IN ($ids)");
}

// What a transaction is matched to in this reconciliation, for display.
// Returns a LEFT JOIN clause the caller can drop into its query.
function matched_join($alias)
{
    $rec = rec_id();
    if (!matchstate_ready() || $rec === null) return '';
    return " LEFT JOIN rec_matched m ON m.txn_id = {$alias}.id AND m.rec_id = " . (int)$rec;
}
