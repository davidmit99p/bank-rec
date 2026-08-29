<?php
// -----------------------------------------------------------------------------
// Which reconciliation are we working on?
//
// The tool can hold several - one per bank account, or one per supplier
// statement, or whatever else. One of them is the context for the session, and
// every screen shows only that one's transactions, runs and imports.
//
// Rules are the exception: a rule with no reconciliation applies to all of
// them, which is what you want for something like "same day, same amount".
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';

// Has sql/migration_003_reconciliations.sql been run? The site deploys on push,
// so new code can arrive before the database has caught up. Until it has, the
// tool behaves exactly as it did before: one unnamed reconciliation.
function recs_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT id FROM rec_recs LIMIT 1");
            db()->query("SELECT rec_id FROM rec_ledger LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function all_recs($activeOnly = false)
{
    if (!recs_ready()) return [];
    $sql = "SELECT * FROM rec_recs" . ($activeOnly ? " WHERE active = 1" : "")
         . " ORDER BY sort_order, name";
    return db()->query($sql)->fetchAll();
}

// The reconciliation in play, or null when the database has not been migrated.
function current_rec()
{
    static $rec = false;
    if ($rec !== false) return $rec;
    if (!recs_ready()) return $rec = null;

    if (session_status() === PHP_SESSION_NONE) session_start();

    $id = (int)($_SESSION['rec_id'] ?? 0);
    if ($id) {
        $st = db()->prepare("SELECT * FROM rec_recs WHERE id = ?");
        $st->execute([$id]);
        if ($r = $st->fetch()) return $rec = $r;
    }
    // nothing chosen yet, or it has been deleted - fall back to the first one
    $r = db()->query("SELECT * FROM rec_recs WHERE active = 1 ORDER BY sort_order, name LIMIT 1")->fetch();
    if ($r) $_SESSION['rec_id'] = (int)$r['id'];
    return $rec = ($r ?: null);
}

function rec_id()
{
    $r = current_rec();
    return $r ? (int)$r['id'] : null;
}

// What the two sides are called on this reconciliation.
function side_label($side)
{
    $r = current_rec();
    if (!$r) return $side === 'ledger' ? 'Ledger' : 'Bank';
    return $side === 'ledger' ? $r['left_label'] : $r['right_label'];
}

// A WHERE fragment scoping a query to the current reconciliation, or an empty
// string when there is nothing to scope to. $alias is the table alias, if any.
//
//   "SELECT ... FROM rec_ledger t WHERE t.matched_at IS NULL" . rec_and('t')
function rec_and($alias = '')
{
    $id = rec_id();
    if ($id === null) return '';
    $p = $alias === '' ? '' : $alias . '.';
    return " AND {$p}rec_id = " . (int)$id;
}

// The same thing where it is the first condition.
function rec_where($alias = '')
{
    $id = rec_id();
    if ($id === null) return '1=1';
    $p = $alias === '' ? '' : $alias . '.';
    return "{$p}rec_id = " . (int)$id;
}

// Rules belonging to this reconciliation, plus the ones that apply to all.
function rule_and($alias = '')
{
    $id = rec_id();
    if ($id === null) return '';
    $p = $alias === '' ? '' : $alias . '.';
    return " AND ({$p}rec_id IS NULL OR {$p}rec_id = " . (int)$id . ")";
}

// Called from layout.php before any output, so switching can redirect.
function handle_rec_switch()
{
    if (!isset($_GET['switch_rec']) || !recs_ready()) return;
    if (session_status() === PHP_SESSION_NONE) session_start();

    $id = (int)$_GET['switch_rec'];
    $st = db()->prepare("SELECT id, name FROM rec_recs WHERE id = ?");
    $st->execute([$id]);
    if ($r = $st->fetch()) {
        $_SESSION['rec_id'] = (int)$r['id'];
        flash('Now working on ' . $r['name'] . '.');
    }
    // come back to the same page without the switch in the address
    $params = $_GET;
    unset($params['switch_rec']);
    $url = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    if ($params) $url .= '?' . http_build_query($params);
    header('Location: ' . $url);
    exit;
}
