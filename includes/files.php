<?php
// -----------------------------------------------------------------------------
// Files.
//
// A file is something you loaded - a bank statement, a ledger extract - with its
// own name and its own spare field names. Transactions belong to a file.
//
// A reconciliation pairs two files. That is the whole of the relationship: the
// file does not know or care what it is being reconciled against, which is why
// you can load one before deciding.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/context.php';

function files_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT id FROM rec_files LIMIT 1");
            db()->query("SELECT file_id FROM rec_txns LIMIT 1");
            db()->query("SELECT left_file_id FROM rec_recs LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function all_files($activeOnly = false)
{
    if (!files_ready()) return [];
    return db()->query("SELECT * FROM rec_files" . ($activeOnly ? " WHERE active = 1" : "")
                       . " ORDER BY name")->fetchAll();
}

function get_file($id)
{
    if (!files_ready() || !$id) return null;
    $st = db()->prepare("SELECT * FROM rec_files WHERE id = ?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

// Which file sits on one side of the reconciliation being worked on.
function side_file_id($side)
{
    if (!files_ready()) return null;
    $rec = current_rec();
    if (!$rec) return null;
    $id = (int)($side === 'ledger' ? ($rec['left_file_id'] ?? 0) : ($rec['right_file_id'] ?? 0));
    return $id ?: null;
}

function side_file($side)
{
    return get_file(side_file_id($side));
}

// A WHERE fragment tying transactions to one side's file. When no file has been
// chosen for that side there is nothing to show, which is not the same as
// showing everything - so it says so plainly.
function file_where($side, $alias = 't')
{
    $id = side_file_id($side);
    $p  = $alias === '' ? '' : $alias . '.';
    return $id === null ? '1 = 0' : "{$p}file_id = " . (int)$id;
}

// The spare fields a file has been given names for: [column => label].
function file_extra_labels($fileId)
{
    $f = get_file($fileId);
    if (!$f) return [];
    $out = [];
    for ($i = 1; $i <= 3; $i++) {
        $label = trim((string)($f['extra' . $i] ?? ''));
        if ($label !== '') $out['extra' . $i] = $label;
    }
    return $out;
}

// How many rows a file holds, and what they come to.
function file_stats($fileId)
{
    if (!files_ready()) return ['n' => 0, 'open_n' => 0, 'total' => 0, 'open_total' => 0];
    $st = db()->prepare("SELECT COUNT(*) n,
                                COALESCE(SUM(matched_at IS NULL), 0) open_n,
                                COALESCE(SUM(value), 0) total,
                                COALESCE(SUM(CASE WHEN matched_at IS NULL THEN value ELSE 0 END), 0) open_total
                         FROM rec_txns WHERE file_id = ? AND split_at IS NULL");
    $st->execute([(int)$fileId]);
    return $st->fetch();
}

// Which reconciliations use a file, so it is obvious what unpicking one affects.
function file_used_by($fileId)
{
    if (!files_ready()) return [];
    $st = db()->prepare("SELECT id, name, left_file_id, right_file_id FROM rec_recs
                         WHERE left_file_id = ? OR right_file_id = ? ORDER BY name");
    $st->execute([(int)$fileId, (int)$fileId]);
    return $st->fetchAll();
}
