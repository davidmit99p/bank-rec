<?php
// -----------------------------------------------------------------------------
// Group notes.
//
// Several items - on one side or both - that are all part of the same issue,
// with one note written once rather than typed against each of them. Every item
// in the group carries its reference, so the related items can be picked out
// together afterwards.
//
// A group is not a match. Its items usually do not balance; that is generally
// why there is a note. Matching is untouched by any of this: a group is only a
// way of saying "these belong to the same question".
//
// Called issues in the database, to keep them apart from rec_match_groups.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/matchstate.php';

// Has migration_018 been run?
function issues_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT id FROM rec_issues LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// The next reference for this reconciliation: G1, G2, G3...
function next_issue_ref()
{
    $st = db()->prepare("SELECT ref FROM rec_issues WHERE " . (recs_ready() ? 'rec_id <=> ?' : '1=1')
                        . " ORDER BY id DESC LIMIT 1");
    $st->execute(recs_ready() ? [rec_id()] : []);
    $last = (string)$st->fetchColumn();
    $n = (int)ltrim($last, 'G');
    return 'G' . ($n + 1);
}

// Every group in this reconciliation, with what is in it.
//
// The totals are per side, so a group reads like a small reconciliation of its
// own: what the ledger says, what the bank says, and the difference.
function list_issues()
{
    if (!issues_ready()) return [];
    $lWhere = file_where('ledger', 't');
    $bWhere = file_where('bank', 't');
    $sql = "SELECT i.*,
                   (SELECT COUNT(*) FROM rec_txns t WHERE t.issue_id = i.id) n,
                   (SELECT COALESCE(SUM(t.value),0) FROM rec_txns t WHERE t.issue_id = i.id AND {$lWhere}) l_total,
                   (SELECT COUNT(*)                 FROM rec_txns t WHERE t.issue_id = i.id AND {$lWhere}) l_n,
                   (SELECT COALESCE(SUM(t.value),0) FROM rec_txns t WHERE t.issue_id = i.id AND {$bWhere}) b_total,
                   (SELECT COUNT(*)                 FROM rec_txns t WHERE t.issue_id = i.id AND {$bWhere}) b_n,
                   (SELECT COUNT(*) FROM rec_txns t WHERE t.issue_id = i.id AND " . open_where('t') . ") still_open
            FROM rec_issues i
            WHERE " . (recs_ready() ? 'i.rec_id <=> ?' : '1=1') . "
            ORDER BY i.id DESC";
    $st = db()->prepare($sql);
    $st->execute(recs_ready() ? [rec_id()] : []);
    return $st->fetchAll();
}

// One group, or null.
function get_issue($id)
{
    if (!issues_ready() || !$id) return null;
    $st = db()->prepare("SELECT * FROM rec_issues WHERE id = ?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

// For the tags on the transactions screen: [id => ['ref' => ..., 'note' => ...]]
function issues_by_id()
{
    static $all = null;
    if ($all !== null) return $all;
    $all = [];
    if (!issues_ready()) return $all;
    foreach (list_issues() as $i) $all[(int)$i['id']] = $i;
    return $all;
}

// Make a group out of these items. Returns [ok, message, id].
function create_issue($note, array $txnIds)
{
    if (!issues_ready()) return [false, 'The database has not been updated for group notes yet.', null];
    $note = trim((string)$note);
    if ($note === '')  return [false, 'Write the note first.', null];
    if (!$txnIds)      return [false, 'Tick the items the note is about.', null];

    $pdo = db();
    $ref = next_issue_ref();
    $pdo->prepare("INSERT INTO rec_issues (rec_id, ref, note, created_by) VALUES (?,?,?,?)")
        ->execute([recs_ready() ? rec_id() : null, $ref, $note,
                   function_exists('current_user_id') ? current_user_id() : null]);
    $id = (int)$pdo->lastInsertId();
    $n  = set_issue_on($id, $txnIds);
    return [true, $ref . ' made, against ' . $n . ' item' . ($n === 1 ? '' : 's') . '.', $id];
}

// Put these items in a group - or, with null, take them out of whatever group
// they are in. Only items of this reconciliation's two files are touched.
function set_issue_on($issueId, array $txnIds)
{
    $ids = array_values(array_filter(array_map('intval', $txnIds)));
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("UPDATE rec_txns SET issue_id = ?
                         WHERE id IN ($in) AND (" . file_where('ledger', '') . " OR " . file_where('bank', '') . ")");
    $st->execute(array_merge([$issueId ?: null], $ids));
    return $st->rowCount();
}

function update_issue_note($id, $note)
{
    if (!issues_ready()) return [false, 'Not available yet.'];
    $note = trim((string)$note);
    if ($note === '') return [false, 'A group note cannot be empty. Delete the group instead.'];
    db()->prepare("UPDATE rec_issues SET note = ? WHERE id = ?")->execute([$note, (int)$id]);
    return [true, 'Note saved.'];
}

// Remove the group itself. Its items keep everything else and simply stop
// carrying the reference.
function delete_issue($id)
{
    if (!issues_ready()) return [false, 'Not available yet.'];
    $g = get_issue($id);
    if (!$g) return [false, 'That group no longer exists.'];
    $pdo = db();
    $pdo->prepare("UPDATE rec_txns SET issue_id = NULL WHERE issue_id = ?")->execute([(int)$id]);
    $pdo->prepare("DELETE FROM rec_issues WHERE id = ?")->execute([(int)$id]);
    return [true, $g['ref'] . ' removed. Its items are no longer grouped.'];
}
