<?php
// -----------------------------------------------------------------------------
// Rolling files.
//
// Most files are loaded once. Some are loaded again and again - a booking
// system extract, a ledger that is still being posted to - and a record loaded
// last week can come back this week with something changed.
//
// Loading such a file again as a fresh import would give you two of everything.
// So a rolling file is loaded into rec_stage first, fingerprinted, and compared
// with what is already there:
//
//   same fingerprint                 the record has not changed; ignored
//   same key, different fingerprint  AMENDED
//   no record with that key          NEW
//   amended amount, already matched  BLOCKED - applying it would leave a
//                                    committed match that no longer balances,
//                                    which the tool must never do
//
// You then see what it found, with everything safe ticked, and let through what
// you want. Nothing is applied until you say so.
//
// What this does NOT do, deliberately: records that disappear from the source,
// and records whose key changes. Both are indistinguishable from other things
// (a shorter load, a new record), so they are left to the eye. A new record
// that looks like one already there is flagged, which catches the common case.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/matchstate.php';
require_once __DIR__ . '/importer.php';   // applying new records uses the normal import

// Has migration_023 been run?
function rolling_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT rolling, key_fields FROM rec_files LIMIT 1");
            db()->query("SELECT fingerprint, row_key FROM rec_txns LIMIT 1");
            db()->query("SELECT id FROM rec_stage LIMIT 1");
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// Which fields may be used to identify a record, for one file: the three core
// ones and whichever spare fields it has named.
function rolling_key_choices($fileId)
{
    return ['txn_date' => 'Date', 'description' => 'Description', 'value' => 'Amount']
         + file_extra_labels($fileId);
}

// Is this file loaded again and again?
function file_is_rolling($fileId)
{
    if (!rolling_ready()) return false;
    $f = get_file($fileId);
    return $f && !empty($f['rolling']);
}

// The fields this file says identify a record, as column names.
function file_key_fields($fileId)
{
    if (!rolling_ready()) return [];
    $f = get_file($fileId);
    $allowed = rolling_key_choices($fileId);
    $out = [];
    foreach (explode(',', (string)($f['key_fields'] ?? '')) as $c) {
        $c = trim($c);
        if ($c !== '' && isset($allowed[$c])) $out[] = $c;
    }
    return $out;
}

// --- fingerprints -------------------------------------------------------------
//
// Everything the record holds, normalised, in one string: dates as yyyy-mm-dd,
// amounts to the penny, text trimmed and upper-cased. So "ACME  LTD " and
// "Acme Ltd" are the same record, which is what you would say looking at them.

function rolling_norm($v)
{
    return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string)$v)));
}

// $row is ['txn_date' => ..., 'description' => ..., 'value' => ..., 'extra1' => ...]
function row_fingerprint(array $row)
{
    $parts = [substr((string)($row['txn_date'] ?? ''), 0, 10),
              rolling_norm($row['description'] ?? ''),
              number_format((float)($row['value'] ?? 0), 2, '.', '')];
    foreach (spare_keys() as $k) $parts[] = rolling_norm($row[$k] ?? '');
    return md5(implode('|', $parts));
}

// The key value of a record, or null when any of the key fields is empty: a
// record with no reference cannot be recognised, so it is always treated as new.
function row_key_value(array $row, array $keyFields)
{
    if (!$keyFields) return null;
    $parts = [];
    foreach ($keyFields as $c) {
        $v = $c === 'value' ? number_format((float)($row['value'] ?? 0), 2, '.', '')
           : ($c === 'txn_date' ? substr((string)($row['txn_date'] ?? ''), 0, 10)
           : rolling_norm($row[$c] ?? ''));
        if ($v === '') return null;
        $parts[] = $v;
    }
    return mb_substr(implode('|', $parts), 0, 255);
}

// Give every transaction in a file its fingerprint and key. Done when a file is
// made rolling, or when its key fields change, so the first comparison has
// something to compare against.
function fingerprint_file($fileId)
{
    if (!rolling_ready()) return 0;
    $keys = file_key_fields($fileId);
    $rows = db()->prepare("SELECT * FROM rec_txns WHERE file_id = ?");
    $rows->execute([(int)$fileId]);
    $up = db()->prepare("UPDATE rec_txns SET fingerprint = ?, row_key = ? WHERE id = ?");
    $n = 0;
    foreach ($rows->fetchAll() as $t) {
        $up->execute([row_fingerprint($t), row_key_value($t, $keys), $t['id']]);
        $n++;
    }
    return $n;
}

// --- staging a fresh load ------------------------------------------------------

// What is waiting for this file, by verdict: ['new' => n, 'amended' => n, ...]
function stage_summary($fileId)
{
    if (!rolling_ready()) return [];
    $st = db()->prepare("SELECT verdict, COUNT(*) n FROM rec_stage WHERE file_id = ? GROUP BY verdict");
    $st->execute([(int)$fileId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['verdict']] = (int)$r['n'];
    return $out;
}

// Any file with a load waiting, for the reminder on the Import screen.
function stage_files()
{
    if (!rolling_ready()) return [];
    return db()->query("SELECT s.file_id, f.name, COUNT(*) n, MAX(s.loaded_at) loaded_at,
                               SUM(s.verdict = 'same') same_n
                        FROM rec_stage s LEFT JOIN rec_files f ON f.id = s.file_id
                        GROUP BY s.file_id, f.name ORDER BY f.name")->fetchAll();
}

function clear_stage($fileId)
{
    if (!rolling_ready()) return;
    db()->prepare("DELETE FROM rec_stage WHERE file_id = ?")->execute([(int)$fileId]);
}

// Put a freshly read file into staging and work out what each record is.
//
// $rows are as build_transactions() returns them: [date, description, value,
// then one element per spare field].
function stage_load($fileId, array $rows, $sourceFile)
{
    if (!rolling_ready()) return [false, 'The database has not been updated for rolling files yet.'];
    $keys = file_key_fields($fileId);
    if (!$keys) {
        return [false, 'Say which fields identify a record in this file before loading it again.'];
    }

    $pdo  = db();
    $cols = spare_keys();

    // what is already in the file, by fingerprint and by key
    $have  = [];
    $byKey = [];
    $st = $pdo->prepare("SELECT id, fingerprint, row_key, txn_date, description, value, matched_at, "
                        . implode(', ', $cols) . " FROM rec_txns WHERE file_id = ? AND split_at IS NULL");
    $st->execute([(int)$fileId]);
    foreach ($st->fetchAll() as $t) {
        // a row loaded before the file was made rolling has no fingerprint yet
        $fp = $t['fingerprint'] ?: row_fingerprint($t);
        $rk = $t['row_key'] !== null ? $t['row_key'] : row_key_value($t, $keys);
        $have[$fp] = true;
        if ($rk !== null && !isset($byKey[$rk])) $byKey[$rk] = $t;
    }
    // and what it holds by date and amount, for the "looks familiar" warning
    $familiar = [];
    foreach ($byKey as $t) $familiar[substr((string)$t['txn_date'], 0, 10) . '|'
        . number_format((float)$t['value'], 2, '.', '')] = true;

    $pdo->beginTransaction();
    try {
        clear_stage($fileId);
        $ins = $pdo->prepare("INSERT INTO rec_stage
            (file_id, source_file, txn_date, description, value, " . implode(', ', $cols) . ",
             fingerprint, row_key, verdict, txn_id, note)
            VALUES (?,?,?,?,?" . str_repeat(',?', count($cols)) . ",?,?,?,?,?)");

        $counts = ['same' => 0, 'new' => 0, 'amended' => 0, 'blocked' => 0];
        foreach ($rows as $r) {
            $row = ['txn_date' => $r[0], 'description' => $r[1], 'value' => $r[2]];
            foreach ($cols as $i => $k) $row[$k] = $r[3 + $i] ?? null;

            $fp = row_fingerprint($row);
            $rk = row_key_value($row, $keys);
            $old = ($rk !== null && isset($byKey[$rk])) ? $byKey[$rk] : null;

            $note = null;
            if (isset($have[$fp])) {
                $verdict = 'same';
            } elseif ($old) {
                $sameAmount = abs((float)$old['value'] - (float)$row['value']) < 0.005;
                if (!$sameAmount && matched_somewhere((int)$old['id'])) {
                    $verdict = 'blocked';
                    $note = 'the amount has changed and this line is already matched';
                } else {
                    $verdict = 'amended';
                    if (!$sameAmount) $note = 'the amount has changed';
                }
            } else {
                $verdict = 'new';
                $look = substr((string)$row['txn_date'], 0, 10) . '|'
                      . number_format((float)$row['value'], 2, '.', '');
                if (isset($familiar[$look])) {
                    $note = 'looks like one you already have, under a different key';
                }
            }
            $counts[$verdict]++;

            $vals = [(int)$fileId, $sourceFile, $row['txn_date'], $row['description'], $row['value']];
            foreach ($cols as $k) $vals[] = $row[$k];
            $vals[] = $fp;
            $vals[] = $rk;
            $vals[] = $verdict;
            $vals[] = $old ? (int)$old['id'] : null;
            $vals[] = $note;
            $ins->execute($vals);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Could not stage that load: ' . $e->getMessage()];
    }

    return [true, $counts];
}

// Is this transaction matched in any reconciliation?
function matched_somewhere($txnId)
{
    static $st = null;
    if ($st === null) {
        $st = db()->prepare("SELECT COUNT(*) FROM rec_matched WHERE txn_id = ?");
    }
    $st->execute([(int)$txnId]);
    return (int)$st->fetchColumn() > 0;
}

// The staged records to look at, newest verdicts first so the decisions come
// before the noise. 'same' is counted but never listed - there is nothing to
// decide about a record that has not changed.
function stage_rows($fileId, $limit = 2000)
{
    if (!rolling_ready()) return [];
    $st = db()->prepare("SELECT s.*, t.txn_date AS old_date, t.description AS old_desc,
                                t.value AS old_value, t.matched_at AS old_matched, "
                        . implode(', ', array_map(fn($k) => "t.{$k} AS old_{$k}", spare_keys())) . "
                         FROM rec_stage s LEFT JOIN rec_txns t ON t.id = s.txn_id
                         WHERE s.file_id = ? AND s.verdict <> 'same'
                         ORDER BY FIELD(s.verdict, 'blocked', 'amended', 'new'), s.txn_date, s.id
                         LIMIT " . (int)$limit);
    $st->execute([(int)$fileId]);
    return $st->fetchAll();
}

// Which fields differ between a staged record and the line it is amending.
function stage_changes(array $s)
{
    $out = [];
    if (substr((string)$s['txn_date'], 0, 10) !== substr((string)$s['old_date'], 0, 10)) $out[] = 'date';
    if (rolling_norm($s['description']) !== rolling_norm($s['old_desc'])) $out[] = 'description';
    if (abs((float)$s['value'] - (float)$s['old_value']) >= 0.005) $out[] = 'amount';
    foreach (spare_keys() as $k) {
        if (rolling_norm($s[$k] ?? '') !== rolling_norm($s['old_' . $k] ?? '')) $out[] = $k;
    }
    return $out;
}

// --- letting them through --------------------------------------------------------

// Apply the staged records whose ids are given. Blocked ones are refused here as
// well as on screen: part two of this work will offer to unmatch and apply.
//
// Returns [howMany, message].
function apply_stage($fileId, array $stageIds)
{
    if (!rolling_ready()) return [0, 'The database has not been updated for rolling files yet.'];
    $stageIds = array_values(array_filter(array_map('intval', $stageIds)));
    if (!$stageIds) return [0, 'Nothing was ticked.'];

    $pdo  = db();
    $cols = spare_keys();
    $in   = implode(',', array_fill(0, count($stageIds), '?'));
    $st = $pdo->prepare("SELECT * FROM rec_stage WHERE file_id = ? AND id IN ($in)");
    $st->execute(array_merge([(int)$fileId], $stageIds));
    $rows = $st->fetchAll();

    $newRows = [];
    $amended = [];
    $refused = 0;
    foreach ($rows as $s) {
        if ($s['verdict'] === 'blocked') { $refused++; continue; }
        if ($s['verdict'] === 'new') {
            $line = [$s['txn_date'], $s['description'], $s['value']];
            foreach ($cols as $k) $line[] = $s[$k];
            $newRows[] = $line;
        } elseif ($s['verdict'] === 'amended' && $s['txn_id']) {
            $amended[] = $s;
        }
    }

    $added = 0;
    if ($newRows) {
        $added = insert_transactions($fileId, $newRows, (string)($rows[0]['source_file'] ?? 'reload'));
        // the new lines need their fingerprint and key for the next comparison
        fingerprint_file($fileId);
    }

    $changed = 0;
    foreach ($amended as $s) {
        $old = db()->prepare("SELECT * FROM rec_txns WHERE id = ? AND file_id = ?");
        $old->execute([(int)$s['txn_id'], (int)$fileId]);
        $old = $old->fetch();
        if (!$old) continue;

        $set  = [];
        $args = [];
        // the date and the description keep what the file first said, so the
        // star and its hover show where the line came from
        if (substr((string)$old['txn_date'], 0, 10) !== substr((string)$s['txn_date'], 0, 10)) {
            if ($old['orig_txn_date'] === null) { $set[] = 'orig_txn_date = ?'; $args[] = $old['txn_date']; }
            $set[] = 'txn_date = ?';
            $args[] = $s['txn_date'];
        }
        if (rolling_norm($old['description']) !== rolling_norm($s['description'])) {
            if ($old['orig_description'] === null) { $set[] = 'orig_description = ?'; $args[] = $old['description']; }
            $set[] = 'description = ?';
            $args[] = $s['description'];
        }
        if (abs((float)$old['value'] - (float)$s['value']) >= 0.005) {
            $set[] = 'prev_value = ?';
            $args[] = $old['value'];
            $set[] = 'value = ?';
            $args[] = $s['value'];
        }
        foreach ($cols as $k) {
            if (rolling_norm($old[$k] ?? '') !== rolling_norm($s[$k] ?? '')) {
                $set[] = "{$k} = ?";
                $args[] = $s[$k];
            }
        }
        if (!$set) continue;

        $set[] = 'restated_at = NOW()';
        $set[] = 'fingerprint = ?';
        $args[] = $s['fingerprint'];
        $set[] = 'row_key = ?';
        $args[] = $s['row_key'];
        $set[] = 'amended_by = ?';
        $args[] = 'the reloaded file';
        $set[] = 'amend_why = ?';
        $args[] = 'restated by a reload of ' . mb_substr((string)$s['source_file'], 0, 120);
        $set[] = 'amended_at = CASE WHEN orig_txn_date IS NOT NULL OR orig_description IS NOT NULL
                                    THEN NOW() ELSE amended_at END';
        $args[] = (int)$s['txn_id'];
        db()->prepare("UPDATE rec_txns SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
        $changed++;
    }

    // what has been let through is done with
    $applied = array_map(fn($s) => (int)$s['id'],
                         array_filter($rows, fn($s) => $s['verdict'] !== 'blocked'));
    if ($applied) {
        $pdo->prepare("DELETE FROM rec_stage WHERE id IN ("
                      . implode(',', array_fill(0, count($applied), '?')) . ")")->execute($applied);
    }
    // records that have not changed never needed a decision
    $pdo->prepare("DELETE FROM rec_stage WHERE file_id = ? AND verdict = 'same'")->execute([(int)$fileId]);

    $bits = [];
    if ($added)   $bits[] = $added . ' added';
    if ($changed) $bits[] = $changed . ' amended';
    if ($refused) $bits[] = $refused . ' left alone - already matched, so they need unmatching first';
    $msg = $bits ? ucfirst(implode(', ', $bits)) . '.' : 'Nothing to apply.';

    if (function_exists('log_event')) {
        log_event('applied a reload', get_file($fileId)['name'] . ': ' . $msg);
    }
    return [$added + $changed, $msg];
}
