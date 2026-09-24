<?php
// -----------------------------------------------------------------------------
// The reconciliation statement, and snaps of it.
//
// The transactions screen answers "which item goes with which". This answers
// the question an auditor asks: as at this date, the ledger says one thing and
// the bank says another - why?
//
// The arithmetic is the one every bank reconciliation has ever used, written
// out both ways round:
//
//     adjusted ledger = ledger balance + the BANK's open items
//     adjusted bank   = bank balance   + the LEDGER's open items
//     unexplained     = adjusted ledger - adjusted bank
//
// Each side is corrected by what the OTHER side knows and it does not: bank
// charges correct the ledger, unpresented cheques correct the bank. Done
// properly the two adjusted figures come to the same thing and the unexplained
// line is zero. Anything else is a real problem nobody has identified yet.
//
// Which is why the balances matter. If both are added up from the items
// themselves the unexplained line is always zero - it has to be, because both
// sides are then made of the same matched items plus their own open ones. It
// only becomes a check when at least one balance is typed in from the
// statement or the trial balance.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/splits.php';
require_once __DIR__ . '/matchstate.php';
require_once __DIR__ . '/issues.php';

// Has migration_019 been run?
function statements_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT id FROM rec_statements LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// --- the figures --------------------------------------------------------------

// Everything on a side up to the date, matched or not. This is what the side's
// balance would be if the file were complete and nothing had been left out -
// which is why it is offered, and why it is not the default.
function derived_balance($side, $asAt)
{
    $st = db()->prepare("SELECT COALESCE(SUM(t.value), 0) FROM rec_txns t
                         WHERE " . file_where($side, 't') . " AND t.txn_date <= ?" . not_split('t'));
    $st->execute([$asAt]);
    return (float)$st->fetchColumn();
}

// One side's still-open items up to the date: the reconciling items.
//
// Open means open now, not open on the date. A March reconciliation is
// prepared in April, and everything cleared while preparing it is properly
// reconciled - dating the matching itself would make the statement go stale
// every time someone did the work it is reporting on.
function open_items_as_at($side, $asAt)
{
    $sql = "SELECT t.id, t.txn_date, t.description, t.value, t.issue_id
            FROM rec_txns t
            WHERE " . file_where($side, 't') . "
              AND " . open_where('t') . "
              AND t.txn_date <= ?" . not_split('t') . "
            ORDER BY t.txn_date, t.id";
    $st = db()->prepare($sql);
    $st->execute([$asAt]);
    return $st->fetchAll();
}

// Reconciling items gathered up by group note, so the statement reads the way a
// person would explain it: one line saying "G3 - five April cheques not yet
// presented" rather than five lines saying nothing.
//
// Items with no group stay as themselves. Returns a flat list of blocks:
//   ['ref' => 'G3'|null, 'note' => ..., 'total' => ..., 'items' => [...]]
function group_blocks(array $items)
{
    $groups = issues_by_id();
    $blocks = [];
    foreach ($items as $it) {
        $gid = (int)($it['issue_id'] ?? 0);
        $has = $gid && isset($groups[$gid]);
        $key = $has ? 'g' . $gid : 'x' . $it['id'];
        if (!isset($blocks[$key])) {
            $blocks[$key] = [
                'ref'   => $has ? $groups[$gid]['ref'] : null,
                'note'  => $has ? (string)$groups[$gid]['note'] : '',
                'total' => 0.0,
                'items' => [],
            ];
        }
        $blocks[$key]['total'] += (float)$it['value'];
        $blocks[$key]['items'][] = $it;
    }
    return array_values($blocks);
}

// The whole statement. Pass null for a balance to add it up from the items.
function statement_figures($asAt, $lBal, $bBal)
{
    $lDerived = ($lBal === null || $lBal === '');
    $bDerived = ($bBal === null || $bBal === '');
    $lBal = $lDerived ? derived_balance('ledger', $asAt) : (float)$lBal;
    $bBal = $bDerived ? derived_balance('bank',   $asAt) : (float)$bBal;

    $lItems = open_items_as_at('ledger', $asAt);
    $bItems = open_items_as_at('bank',   $asAt);
    $lOpen  = 0.0; foreach ($lItems as $r) $lOpen += (float)$r['value'];
    $bOpen  = 0.0; foreach ($bItems as $r) $bOpen += (float)$r['value'];

    $lAdj = $lBal + $bOpen;          // the ledger corrected by what the bank knows
    $bAdj = $bBal + $lOpen;          // and the other way round

    return [
        'as_at'     => $asAt,
        'l_label'   => side_label('ledger'),
        'b_label'   => side_label('bank'),
        'l_bal'     => $lBal,      'b_bal'     => $bBal,
        'l_derived' => $lDerived,  'b_derived' => $bDerived,
        'l_items'   => $lItems,    'b_items'   => $bItems,
        'l_blocks'  => group_blocks($lItems),
        'b_blocks'  => group_blocks($bItems),
        'l_open'    => $lOpen,     'b_open'    => $bOpen,
        'l_adj'     => $lAdj,      'b_adj'     => $bAdj,
        'unexplained' => $lAdj - $bAdj,
    ];
}

// --- snaps --------------------------------------------------------------------
//
// A snap is a copy, not a view. Nothing in it is looked up again afterwards:
// match something tomorrow and last month's snap still says what it said,
// which is the whole point of taking one.

function save_snap(array $f, $note)
{
    if (!statements_ready()) return [false, 'The database has not been updated for statements yet.', null];
    $pdo = db();
    $pdo->prepare("INSERT INTO rec_statements
        (rec_id, as_at, left_balance, right_balance, left_derived, right_derived,
         left_open, right_open, left_adj, right_adj, unexplained,
         left_label, right_label, note, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            recs_ready() ? rec_id() : null, $f['as_at'],
            $f['l_bal'], $f['b_bal'], $f['l_derived'] ? 1 : 0, $f['b_derived'] ? 1 : 0,
            $f['l_open'], $f['b_open'], $f['l_adj'], $f['b_adj'], $f['unexplained'],
            $f['l_label'], $f['b_label'], (trim((string)$note) === '' ? null : trim((string)$note)),
            function_exists('current_user_id') ? current_user_id() : null,
        ]);
    $id = (int)$pdo->lastInsertId();

    $groups = issues_by_id();
    $ins = $pdo->prepare("INSERT INTO rec_statement_lines
        (statement_id, side, txn_id, txn_date, description, value, group_ref, group_note)
        VALUES (?,?,?,?,?,?,?,?)");
    foreach (['ledger' => 'l_items', 'bank' => 'b_items'] as $side => $key) {
        foreach ($f[$key] as $it) {
            $gid = (int)($it['issue_id'] ?? 0);
            $g   = $gid && isset($groups[$gid]) ? $groups[$gid] : null;
            $ins->execute([$id, $side, (int)$it['id'], $it['txn_date'],
                           substr((string)$it['description'], 0, 255), $it['value'],
                           $g ? $g['ref'] : null, $g ? $g['note'] : null]);
        }
    }
    $n = count($f['l_items']) + count($f['b_items']);
    if (function_exists('log_event')) {
        log_event('took a snap', 'As at ' . $f['as_at'] . ', unexplained '
                  . number_format((float)$f['unexplained'], 2) . ', ' . $n . ' reconciling items',
                  recs_ready() ? rec_id() : null);
    }
    return [true, 'Snapped as at ' . date('j M Y', strtotime($f['as_at'])) . ', with '
                  . $n . ' reconciling item' . ($n === 1 ? '' : 's') . '.', $id];
}

// Every snap of this reconciliation, newest first.
function list_snaps()
{
    if (!statements_ready()) return [];
    $st = db()->prepare("SELECT s.*, (SELECT COUNT(*) FROM rec_statement_lines l
                                      WHERE l.statement_id = s.id) n
                         FROM rec_statements s
                         WHERE " . (recs_ready() ? 's.rec_id <=> ?' : '1=1') . "
                         ORDER BY s.as_at DESC, s.id DESC");
    $st->execute(recs_ready() ? [rec_id()] : []);
    return $st->fetchAll();
}

function get_snap($id)
{
    if (!statements_ready() || !$id) return null;
    $st = db()->prepare("SELECT * FROM rec_statements WHERE id = ?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

// A snap's copied items, one side at a time.
function snap_lines($id, $side)
{
    if (!statements_ready()) return [];
    $st = db()->prepare("SELECT * FROM rec_statement_lines
                         WHERE statement_id = ? AND side = ? ORDER BY txn_date, id");
    $st->execute([(int)$id, $side]);
    return $st->fetchAll();
}

// The same gathering-up as on the live page, from copied lines.
function snap_blocks($id, $side)
{
    $blocks = [];
    foreach (snap_lines($id, $side) as $l) {
        $key = $l['group_ref'] ? 'g' . $l['group_ref'] : 'x' . $l['id'];
        if (!isset($blocks[$key])) {
            $blocks[$key] = ['ref' => $l['group_ref'], 'note' => (string)$l['group_note'],
                             'total' => 0.0, 'items' => []];
        }
        $blocks[$key]['total'] += (float)$l['value'];
        $blocks[$key]['items'][] = ['id'          => $l['txn_id'],
                                    'txn_date'    => $l['txn_date'],
                                    'description' => $l['description'],
                                    'value'       => $l['value'],
                                    'issue_id'    => null];
    }
    return array_values($blocks);
}

// Snaps are meant to be permanent, so removing one is an administrator's job,
// and impossible once it has been approved.
function delete_snap($id)
{
    if (!statements_ready()) return [false, 'Not available yet.'];
    $s = get_snap($id);
    if (!$s) return [false, 'That snap no longer exists.'];
    if (!empty($s['approved_at'])) return [false, 'That snap has been approved and cannot be removed.'];
    if (function_exists('is_admin') && !is_admin()) return [false, 'Only an administrator can remove a snap.'];
    $pdo = db();
    $pdo->prepare("DELETE FROM rec_statement_lines WHERE statement_id = ?")->execute([(int)$id]);
    $pdo->prepare("DELETE FROM rec_statements WHERE id = ?")->execute([(int)$id]);
    if (function_exists('log_event')) {
        log_event('removed a snap', 'As at ' . $s['as_at'] . ', taken ' . $s['created_at'], $s['rec_id']);
    }
    return [true, 'Snap of ' . date('j M Y', strtotime($s['as_at'])) . ' removed.'];
}

// --- approving a snap ---------------------------------------------------------
//
// A snap says what the position was. An approval says somebody other than the
// preparer has looked at it and accepts it - the second pair of eyes that makes
// a reconciliation worth anything to an auditor.
//
// Anyone signed in can approve, because plenty of these will be prepared and
// reviewed by the same small team, and one person working alone still needs to
// be able to sign off. Approving your own snap is allowed but recorded as such,
// which is the honest way round: the page does not pretend a second person
// looked at it.

// Migration 020 adds somewhere for the approver to write what they checked.
function approval_notes_ready()
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query("SELECT approved_note FROM rec_statements LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function approve_snap($id, $note)
{
    if (!statements_ready()) return [false, 'Not available yet.'];
    $s = get_snap($id);
    if (!$s) return [false, 'That snap no longer exists.'];
    if (!empty($s['approved_at'])) {
        return [false, 'That snap was already approved by '
                       . (user_name($s['approved_by']) ?: 'someone') . '.'];
    }
    $me   = function_exists('current_user_id') ? current_user_id() : null;
    $note = trim((string)$note);

    if (approval_notes_ready()) {
        db()->prepare("UPDATE rec_statements SET approved_at = NOW(), approved_by = ?, approved_note = ?
                       WHERE id = ?")->execute([$me, $note === '' ? null : $note, (int)$id]);
    } else {
        db()->prepare("UPDATE rec_statements SET approved_at = NOW(), approved_by = ? WHERE id = ?")
            ->execute([$me, (int)$id]);
    }
    if (function_exists('log_event')) {
        log_event('approved a snap', 'Snap as at ' . $s['as_at'] . ', unexplained '
                  . number_format((float)$s['unexplained'], 2), $s['rec_id']);
    }
    $own = $me !== null && (int)$s['created_by'] === (int)$me;
    return [true, 'Approved' . ($own ? ' - recorded as your own snap, approved by you.' : '.')];
}

// An approval that could be quietly undone would not be worth having, so this
// is an administrator's job and it is written to the log.
function withdraw_approval($id)
{
    if (!statements_ready()) return [false, 'Not available yet.'];
    $s = get_snap($id);
    if (!$s) return [false, 'That snap no longer exists.'];
    if (empty($s['approved_at'])) return [false, 'That snap has not been approved.'];
    if (function_exists('is_admin') && !is_admin()) {
        return [false, 'Only an administrator can withdraw an approval.'];
    }
    $cols = "approved_at = NULL, approved_by = NULL" . (approval_notes_ready() ? ", approved_note = NULL" : "");
    db()->prepare("UPDATE rec_statements SET {$cols} WHERE id = ?")->execute([(int)$id]);
    if (function_exists('log_event')) {
        log_event('withdrew a snap approval',
                  'Snap as at ' . $s['as_at'] . ', approved ' . $s['approved_at']
                  . ' by ' . (user_name($s['approved_by']) ?: 'someone'), $s['rec_id']);
    }
    return [true, 'Approval withdrawn. The snap itself is unchanged.'];
}

// Was this snap approved by the person who took it? Worth saying out loud.
function self_approved(array $s)
{
    return !empty($s['approved_at']) && $s['approved_by'] !== null
           && (int)$s['approved_by'] === (int)$s['created_by'];
}
