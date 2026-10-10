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
// THE KEY IS A JUDGEMENT, NOT A SETTING. A record is recognised by the fields
// you say identify it, and you cannot always tell which of them the source
// system leaves alone until you have seen a real load. If a key field changes,
// the old record looks gone and the new one looks new - a duplicate. So the key
// is adjustable while you are looking at the load: compare, read the counts,
// change the key, compare again. The file keeps a starting point, nothing more.
//
// Four things carry the weight of that:
//
//   - the key and the ignored fields in use belong to the LOAD (rec_stage_load),
//     not to the file;
//   - an ignored field is left out of the comparison altogether, so a change in
//     it never raises an amendment;
//   - a record that still looks new can be paired by hand with the record it is
//     really an amendment of, and is offered a likely one to accept;
//   - at the moment of applying you say which fields may be written, so a
//     description can be let through while an amount is refused.
//
// What this does NOT do, deliberately: records that disappear from the source.
// An absence is indistinguishable from a shorter load, so it is left to the eye.
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

// And migration_024, which is what lets a load be tuned rather than simply
// taken as it comes. Without it the rest still works, as it did before.
function rolling_tune_ready()
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query("SELECT ignore_fields FROM rec_files LIMIT 1");
            db()->query("SELECT applied_at, suggest_txn_id, paired_by_hand FROM rec_stage LIMIT 1");
            db()->query("SELECT file_id FROM rec_stage_load LIMIT 1");
            $ok = rolling_ready();
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

// Read a comma-separated list of column names, keeping only ones that exist.
function rolling_field_list($raw, $fileId)
{
    $allowed = rolling_key_choices($fileId);
    $out = [];
    foreach (explode(',', (string)$raw) as $c) {
        $c = trim($c);
        if ($c !== '' && isset($allowed[$c]) && !in_array($c, $out, true)) $out[] = $c;
    }
    return $out;
}

// The file's starting point: what identifies a record, and what to ignore.
function file_key_fields($fileId)
{
    if (!rolling_ready()) return [];
    return rolling_field_list(get_file($fileId)['key_fields'] ?? '', $fileId);
}

function file_ignore_fields($fileId)
{
    if (!rolling_tune_ready()) return [];
    return rolling_field_list(get_file($fileId)['ignore_fields'] ?? '', $fileId);
}

// What the load being looked at is actually using, which starts as the file's
// setting and then follows whatever you tune it to.
function stage_load_meta($fileId)
{
    if (!rolling_tune_ready()) return null;
    $st = db()->prepare("SELECT * FROM rec_stage_load WHERE file_id = ?");
    $st->execute([(int)$fileId]);
    return $st->fetch() ?: null;
}

function active_key_fields($fileId)
{
    $m = stage_load_meta($fileId);
    if ($m && $m['key_fields'] !== null) return rolling_field_list($m['key_fields'], $fileId);
    return file_key_fields($fileId);
}

function active_ignore_fields($fileId)
{
    $m = stage_load_meta($fileId);
    if ($m && $m['ignore_fields'] !== null) return rolling_field_list($m['ignore_fields'], $fileId);
    return file_ignore_fields($fileId);
}

// --- fingerprints -------------------------------------------------------------
//
// Everything the record holds, normalised, in one string: dates as yyyy-mm-dd,
// amounts to the penny, text trimmed and upper-cased. So "ACME  LTD " and
// "Acme Ltd" are the same record, which is what you would say looking at them.
//
// A field named in $ignore is left out, which is how "never mind if this one
// changes" is said.

function rolling_norm($v)
{
    return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string)$v)));
}

// $row is ['txn_date' => ..., 'description' => ..., 'value' => ..., 'extra1' => ...]
function row_fingerprint(array $row, array $ignore = [])
{
    $skip  = array_flip($ignore);
    $parts = [
        isset($skip['txn_date'])    ? '' : substr((string)($row['txn_date'] ?? ''), 0, 10),
        isset($skip['description']) ? '' : rolling_norm($row['description'] ?? ''),
        isset($skip['value'])       ? '' : number_format((float)($row['value'] ?? 0), 2, '.', ''),
    ];
    foreach (spare_keys() as $k) $parts[] = isset($skip[$k]) ? '' : rolling_norm($row[$k] ?? '');
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
    $keys   = file_key_fields($fileId);
    $ignore = file_ignore_fields($fileId);
    $rows = db()->prepare("SELECT * FROM rec_txns WHERE file_id = ?");
    $rows->execute([(int)$fileId]);
    $up = db()->prepare("UPDATE rec_txns SET fingerprint = ?, row_key = ? WHERE id = ?");
    $n = 0;
    foreach ($rows->fetchAll() as $t) {
        $up->execute([row_fingerprint($t, $ignore), row_key_value($t, $keys), $t['id']]);
        $n++;
    }
    return $n;
}

// --- staging a fresh load ------------------------------------------------------

// What is waiting for this file, by verdict, plus how many of the load have
// already been let through: ['new' => n, 'amended' => n, ..., 'done' => n]
function stage_summary($fileId)
{
    if (!rolling_ready()) return [];
    $done = rolling_tune_ready() ? ' AND applied_at IS NULL' : '';
    $st = db()->prepare("SELECT verdict, COUNT(*) n FROM rec_stage
                         WHERE file_id = ?{$done} GROUP BY verdict");
    $st->execute([(int)$fileId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['verdict']] = (int)$r['n'];
    if (rolling_tune_ready()) {
        $st = db()->prepare("SELECT COUNT(*) FROM rec_stage WHERE file_id = ? AND applied_at IS NOT NULL");
        $st->execute([(int)$fileId]);
        $out['done'] = (int)$st->fetchColumn();
    }
    return $out;
}

// Any file with a load waiting, for the picker at the top of the screen.
function stage_files()
{
    if (!rolling_ready()) return [];
    $open = rolling_tune_ready() ? "AND s.applied_at IS NULL" : "";
    return db()->query("SELECT s.file_id, f.name, COUNT(*) n, MAX(s.loaded_at) loaded_at,
                               SUM(s.verdict = 'same') same_n
                        FROM rec_stage s LEFT JOIN rec_files f ON f.id = s.file_id
                        WHERE 1 $open
                        GROUP BY s.file_id, f.name ORDER BY f.name")->fetchAll();
}

function clear_stage($fileId)
{
    if (!rolling_ready()) return;
    db()->prepare("DELETE FROM rec_stage WHERE file_id = ?")->execute([(int)$fileId]);
    if (rolling_tune_ready()) {
        db()->prepare("DELETE FROM rec_stage_load WHERE file_id = ?")->execute([(int)$fileId]);
    }
}

// Put a freshly read file into staging, then judge it.
//
// $rows are as build_transactions() returns them: [date, description, value,
// then one element per spare field].
function stage_load($fileId, array $rows, $sourceFile)
{
    if (!rolling_ready()) return [false, 'The database has not been updated for rolling files yet.'];
    if (!file_key_fields($fileId) && !active_key_fields($fileId)) {
        return [false, 'Say which fields identify a record in this file before loading it again.'];
    }

    $pdo  = db();
    $cols = spare_keys();

    $pdo->beginTransaction();
    try {
        clear_stage($fileId);
        if (rolling_tune_ready()) {
            // the load starts from the file's setting and is tuned from there
            $pdo->prepare("INSERT INTO rec_stage_load (file_id, source_file, key_fields, ignore_fields)
                           VALUES (?,?,?,?)")
                ->execute([(int)$fileId, $sourceFile,
                           implode(',', file_key_fields($fileId)) ?: null,
                           implode(',', file_ignore_fields($fileId)) ?: null]);
        }
        $ins = $pdo->prepare("INSERT INTO rec_stage
            (file_id, source_file, txn_date, description, value, " . implode(', ', $cols) . ")
            VALUES (?,?,?,?,?" . str_repeat(',?', count($cols)) . ")");
        foreach ($rows as $r) {
            $vals = [(int)$fileId, $sourceFile, $r[0], $r[1], $r[2]];
            foreach ($cols as $i => $k) $vals[] = $r[3 + $i] ?? null;
            $ins->execute($vals);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Could not stage that load: ' . $e->getMessage()];
    }

    return [true, classify_stage($fileId)];
}

// Judge - or judge again - everything in staging that has not been applied,
// using the key and the ignored fields in use for this load.
//
// This is the heart of it, and it is deliberately re-runnable: changing the key
// and running it again is how you tune a load.
function classify_stage($fileId)
{
    $counts = ['same' => 0, 'new' => 0, 'amended' => 0, 'blocked' => 0];
    if (!rolling_ready()) return $counts;

    $pdo    = db();
    $keys   = active_key_fields($fileId);
    $ignore = active_ignore_fields($fileId);
    $cols   = spare_keys();

    // what is already in the file
    $have = $byKey = $byId = $byDateVal = $byDescVal = $byDateDesc = [];
    $firstDate = $lastDate = null;
    $st = $pdo->prepare("SELECT * FROM rec_txns WHERE file_id = ? AND split_at IS NULL");
    $st->execute([(int)$fileId]);
    foreach ($st->fetchAll() as $t) {
        $have[row_fingerprint($t, $ignore)] = true;
        $byId[(int)$t['id']] = $t;
        $rk = row_key_value($t, $keys);
        if ($rk !== null && !isset($byKey[$rk])) $byKey[$rk] = $t;
        $d = substr((string)$t['txn_date'], 0, 10);
        $v = number_format((float)$t['value'], 2, '.', '');
        $byDateVal[$d . '|' . $v][] = (int)$t['id'];
        $byDescVal[rolling_norm($t['description']) . '|' . $v][] = (int)$t['id'];
        $byDateDesc[$d . '|' . rolling_norm($t['description'])][] = (int)$t['id'];
        if ($d !== '') {
            if ($firstDate === null || $d < $firstDate) $firstDate = $d;
            if ($lastDate === null || $d > $lastDate)   $lastDate = $d;
        }
    }

    $open = rolling_tune_ready() ? 'AND applied_at IS NULL' : '';
    $st = $pdo->prepare("SELECT * FROM rec_stage WHERE file_id = ? $open ORDER BY id");
    $st->execute([(int)$fileId]);
    $staged = $st->fetchAll();

    // first pass: which existing record each staged record belongs to
    $claimed = [];
    $pairs   = [];
    foreach ($staged as $s) {
        $old = null;
        if (rolling_tune_ready() && !empty($s['paired_by_hand']) && $s['txn_id']) {
            $old = $byId[(int)$s['txn_id']] ?? null;      // your pairing wins
        }
        if (!$old) {
            $rk = row_key_value($s, $keys);
            if ($rk !== null && isset($byKey[$rk])) $old = $byKey[$rk];
        }
        $pairs[(int)$s['id']] = $old;
        if ($old) $claimed[(int)$old['id']] = true;
    }

    $up = $pdo->prepare("UPDATE rec_stage SET fingerprint = ?, row_key = ?, verdict = ?,
                         txn_id = ?, note = ?" . (rolling_tune_ready() ? ", suggest_txn_id = ?" : "")
                         . " WHERE id = ?");

    foreach ($staged as $s) {
        $fp  = row_fingerprint($s, $ignore);
        $rk  = row_key_value($s, $keys);
        $old = $pairs[(int)$s['id']];
        $note = null;
        $suggest = null;

        if (!$old && isset($have[$fp])) {
            // nothing to decide: a record exactly like this one is already here
            $verdict = 'same';
        } elseif ($old) {
            $sameFp = row_fingerprint($old, $ignore) === $fp;
            if ($sameFp) {
                $verdict = 'same';
            } else {
                $sameAmount = in_array('value', $ignore, true)
                    || abs((float)$old['value'] - (float)$s['value']) < 0.005;
                if (!$sameAmount && matched_somewhere((int)$old['id'])) {
                    $verdict = 'blocked';
                    $note = 'the amount has changed and this line is already matched';
                } else {
                    $verdict = 'amended';
                    if (!$sameAmount) $note = 'the amount has changed';
                }
                if (!empty($s['paired_by_hand'])) {
                    $note = trim(($note ? $note . '; ' : '') . 'paired by you');
                }
            }
        } else {
            $verdict = 'new';
            // could this be a record whose key changed? Same date and amount is
            // the strongest hint; same description and amount the next.
            $v = number_format((float)$s['value'], 2, '.', '');
            $d = substr((string)$s['txn_date'], 0, 10);
            foreach ([$byDateVal[$d . '|' . $v] ?? [],
                      $byDescVal[rolling_norm($s['description']) . '|' . $v] ?? [],
                      $byDateDesc[$d . '|' . rolling_norm($s['description'])] ?? []] as $candidates) {
                foreach ($candidates as $cid) {
                    if (!isset($claimed[$cid])) { $suggest = $cid; break 2; }
                }
            }
            if ($suggest) {
                $o = $byId[$suggest];
                $note = 'could be an amendment of ' . rolling_record_words($o, $fileId)
                      . ', whose key no longer matches';
            } elseif ($firstDate !== null && $d !== '' && $d >= $firstDate && $d <= $lastDate) {
                // nothing recognised it, and it is not simply a later record: its
                // date sits inside what you have already loaded. That is exactly
                // how a record whose key changed looks, so it is worth a word.
                $note = 'nothing recognised this one, and its date sits inside what you have already '
                      . 'loaded - check it is not an existing record under a new key';
            }
        }
        $counts[$verdict]++;

        $args = [$fp, $rk, $verdict, $old ? (int)$old['id'] : null, $note];
        if (rolling_tune_ready()) $args[] = $suggest;
        $args[] = (int)$s['id'];
        $up->execute($args);
    }
    return $counts;
}

// A record in a few words, for a message: "B001, 5 Jan 2026, 100.00".
function rolling_record_words(array $t, $fileId)
{
    $keys = active_key_fields($fileId) ?: file_key_fields($fileId);
    $bits = [];
    foreach ($keys as $c) {
        if ($c === 'txn_date' || $c === 'value') continue;
        if (!empty($t[$c])) $bits[] = (string)$t[$c];
    }
    $bits[] = date('j M Y', strtotime((string)$t['txn_date']));
    $bits[] = money($t['value']);
    return implode(', ', $bits);
}

// Change the key, or what to ignore, and judge the load again. Nothing is
// applied, so this can be done as often as you like.
function recompare($fileId, array $keys, array $ignore, $remember = false)
{
    if (!rolling_tune_ready()) return [false, 'The database has not been updated for tuning a load yet.'];
    $keys   = rolling_field_list(implode(',', $keys), $fileId);
    $ignore = rolling_field_list(implode(',', $ignore), $fileId);
    if (!$keys) return [false, 'At least one field has to identify a record.'];
    // a field cannot both identify a record and be ignored
    $ignore = array_values(array_diff($ignore, $keys));

    db()->prepare("UPDATE rec_stage_load SET key_fields = ?, ignore_fields = ? WHERE file_id = ?")
        ->execute([implode(',', $keys), implode(',', $ignore) ?: null, (int)$fileId]);
    if ($remember) {
        db()->prepare("UPDATE rec_files SET key_fields = ?, ignore_fields = ? WHERE id = ?")
            ->execute([implode(',', $keys), implode(',', $ignore) ?: null, (int)$fileId]);
        fingerprint_file($fileId);
    }
    $counts = classify_stage($fileId);
    if (function_exists('log_event')) {
        log_event('tuned a reload', get_file($fileId)['name'] . ': recognised by '
            . implode(' + ', $keys) . ($ignore ? ', ignoring ' . implode(' + ', $ignore) : '')
            . ' - ' . json_encode($counts));
    }
    return [true, $counts];
}

// Say that a staged record is really an amendment of a particular existing one.
function pair_stage($fileId, $stageId, $txnId)
{
    if (!rolling_tune_ready()) return [false, 'The database has not been updated for pairing yet.'];
    $st = db()->prepare("SELECT id FROM rec_txns WHERE id = ? AND file_id = ?");
    $st->execute([(int)$txnId, (int)$fileId]);
    if (!$st->fetchColumn()) return [false, 'That record is not in this file.'];

    db()->prepare("UPDATE rec_stage SET txn_id = ?, paired_by_hand = 1, suggest_txn_id = NULL
                   WHERE id = ? AND file_id = ? AND applied_at IS NULL")
        ->execute([(int)$txnId, (int)$stageId, (int)$fileId]);
    classify_stage($fileId);
    return [true, 'Paired. It is now an amendment of that record rather than a new one.'];
}

// Undo that, and let the key decide again.
function unpair_stage($fileId, $stageId)
{
    if (!rolling_tune_ready()) return [false, 'The database has not been updated for pairing yet.'];
    db()->prepare("UPDATE rec_stage SET txn_id = NULL, paired_by_hand = 0
                   WHERE id = ? AND file_id = ? AND applied_at IS NULL")
        ->execute([(int)$stageId, (int)$fileId]);
    classify_stage($fileId);
    return [true, 'That pairing has been undone.'];
}

// Existing records you could pair a staged record with: the ones in this file
// that no staged record has already claimed, nearest date first.
function pair_candidates($fileId, array $near = null, $limit = 400)
{
    if (!rolling_ready()) return [];
    $pdo = db();
    $taken = [];
    $st = $pdo->prepare("SELECT txn_id FROM rec_stage WHERE file_id = ? AND txn_id IS NOT NULL");
    $st->execute([(int)$fileId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $taken[(int)$id] = true;

    $st = $pdo->prepare("SELECT id, txn_date, description, value, " . implode(', ', spare_keys()) . "
                         FROM rec_txns WHERE file_id = ? AND split_at IS NULL
                         ORDER BY txn_date DESC, id DESC LIMIT " . (int)$limit);
    $st->execute([(int)$fileId]);
    $all = $st->fetchAll();
    $out = [];
    foreach ($all as $t) if (!isset($taken[(int)$t['id']])) $out[] = $t;
    // if the load accounts for every record, there is nothing unclaimed to offer -
    // but you may still know better than the key does, so offer them all
    return $out ?: $all;
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

// The staged records to look at, decisions first. 'same' is counted but never
// listed - there is nothing to decide about a record that has not changed.
function stage_rows($fileId, $limit = 2000)
{
    if (!rolling_ready()) return [];
    $open = rolling_tune_ready() ? 'AND s.applied_at IS NULL' : '';
    $st = db()->prepare("SELECT s.*, t.txn_date AS old_date, t.description AS old_desc,
                                t.value AS old_value, t.matched_at AS old_matched, "
                        . implode(', ', array_map(fn($k) => "t.{$k} AS old_{$k}", spare_keys())) . "
                         FROM rec_stage s LEFT JOIN rec_txns t ON t.id = s.txn_id
                         WHERE s.file_id = ? AND s.verdict <> 'same' $open
                         ORDER BY FIELD(s.verdict, 'blocked', 'amended', 'new'), s.txn_date, s.id
                         LIMIT " . (int)$limit);
    $st->execute([(int)$fileId]);
    return $st->fetchAll();
}

// Which fields differ between a staged record and the line it is amending.
// An ignored field is not a difference, by definition.
function stage_changes(array $s, array $ignore = [])
{
    $skip = array_flip($ignore);
    $out = [];
    if (!isset($skip['txn_date'])
        && substr((string)$s['txn_date'], 0, 10) !== substr((string)$s['old_date'], 0, 10)) $out[] = 'txn_date';
    if (!isset($skip['description'])
        && rolling_norm($s['description']) !== rolling_norm($s['old_desc'])) $out[] = 'description';
    if (!isset($skip['value'])
        && abs((float)$s['value'] - (float)$s['old_value']) >= 0.005) $out[] = 'value';
    foreach (spare_keys() as $k) {
        if (!isset($skip[$k]) && rolling_norm($s[$k] ?? '') !== rolling_norm($s['old_' . $k] ?? '')) $out[] = $k;
    }
    return $out;
}

// --- what a held-back record would cost -----------------------------------------

// The matches a transaction is in, across every reconciliation, with how many
// lines each one holds. Shown before anything is unpicked, because a shared file
// means the match being undone may be in a reconciliation you are not looking at.
function matches_holding($txnId)
{
    if (!rolling_ready()) return [];
    $st = db()->prepare("SELECT m.group_id, m.rec_id, g.group_no, g.rule_ref, g.rule_name,
                                r.run_ref, c.name AS rec_name,
                                (SELECT COUNT(*) FROM rec_match_lines l WHERE l.group_id = m.group_id) lines_n
                         FROM rec_matched m
                         JOIN rec_match_groups g ON g.id = m.group_id
                         JOIN rec_runs r ON r.id = m.run_id
                         LEFT JOIN rec_recs c ON c.id = m.rec_id
                         WHERE m.txn_id = ?
                         ORDER BY c.name, g.group_no");
    $st->execute([(int)$txnId]);
    return $st->fetchAll();
}

// That, in one sentence, for the warning the user reads before pressing.
function unmatch_cost_words(array $holds)
{
    if (!$holds) return 'It is not matched anywhere, so nothing would be unpicked.';
    $bits = [];
    foreach ($holds as $h) {
        $bits[] = 'match ' . (int)$h['group_no'] . ' in ' . ($h['rec_name'] ?? 'a reconciliation')
                . ' (' . (int)$h['lines_n'] . ' lines, ' . $h['run_ref'] . ')';
    }
    return 'Undoing ' . implode(' and ', $bits)
        . '. Every line in ' . (count($holds) === 1 ? 'that match' : 'those matches')
        . ' becomes unmatched again, and the rules can pick them up on the next run.';
}

// Unpick whatever is holding these records, then apply them.
//
// A whole match at a time, never half of one, so nothing is ever left matched
// and out of balance. The amendment is applied afterwards by the ordinary path.
function unmatch_and_apply($fileId, array $stageIds, array $allow = null)
{
    if (!rolling_ready()) return [0, 'The database has not been updated for rolling files yet.'];
    $stageIds = array_values(array_filter(array_map('intval', $stageIds)));
    if (!$stageIds) return [0, 'Nothing was ticked.'];

    $pdo = db();
    $in  = implode(',', array_fill(0, count($stageIds), '?'));
    $st  = $pdo->prepare("SELECT * FROM rec_stage
                          WHERE file_id = ? AND verdict = 'blocked' AND id IN ($in)");
    $st->execute(array_merge([(int)$fileId], $stageIds));
    $rows = $st->fetchAll();
    if (!$rows) return [0, 'Nothing held back was ticked.'];

    $undone = 0;
    $freed  = 0;
    $ready  = [];
    foreach ($rows as $s) {
        foreach (matches_holding((int)$s['txn_id']) as $h) {
            $freed += unmatch_whole_group((int)$h['group_id']);
            $undone++;
        }
        // it is no longer held back: it is an ordinary amendment
        $pdo->prepare("UPDATE rec_stage SET verdict = 'amended', note = NULL WHERE id = ?")
            ->execute([(int)$s['id']]);
        $ready[] = (int)$s['id'];
    }

    [$n, $msg] = apply_stage($fileId, $ready, $allow);
    $words = $undone
        ? $undone . ' match' . ($undone === 1 ? '' : 'es') . ' undone, freeing '
          . $freed . ' lines. '
        : '';
    if (function_exists('log_event')) {
        log_event('unmatched for a reload', get_file($fileId)['name'] . ': ' . $words . $msg);
    }
    return [$n, $words . $msg];
}

// --- letting them through --------------------------------------------------------

// Every field that could be written, for the tick boxes at the moment of
// applying. Unticking one means a difference in it is not written - and so
// keeps showing on the next load, until it is dealt with or ignored.
function rolling_writable_fields($fileId)
{
    return rolling_key_choices($fileId);
}

// Apply the staged records whose ids are given.
//
// $allow, when given, is the list of fields that may be written. A new record
// arrives whole or not at all, so the list applies to amendments only.
//
// Blocked ones are refused here as well as on screen: unmatch_and_apply() is
// the way through for those.
//
// Returns [howMany, message].
function apply_stage($fileId, array $stageIds, array $allow = null)
{
    if (!rolling_ready()) return [0, 'The database has not been updated for rolling files yet.'];
    $stageIds = array_values(array_filter(array_map('intval', $stageIds)));
    if (!$stageIds) return [0, 'Nothing was ticked.'];

    $pdo  = db();
    $cols = spare_keys();
    $all  = array_keys(rolling_writable_fields($fileId));
    $allow = $allow === null ? $all : array_values(array_intersect($all, $allow));
    $may  = array_flip($allow);
    $ignore = active_ignore_fields($fileId);

    $in = implode(',', array_fill(0, count($stageIds), '?'));
    $st = $pdo->prepare("SELECT * FROM rec_stage WHERE file_id = ? AND id IN ($in)");
    $st->execute(array_merge([(int)$fileId], $stageIds));
    $rows = $st->fetchAll();

    $newRows = [];
    $amended = [];
    $refused = 0;
    foreach ($rows as $s) {
        if (!empty($s['applied_at'])) continue;
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
    }

    $changed = 0;
    $held    = 0;
    foreach ($amended as $s) {
        $get = $pdo->prepare("SELECT * FROM rec_txns WHERE id = ? AND file_id = ?");
        $get->execute([(int)$s['txn_id'], (int)$fileId]);
        $old = $get->fetch();
        if (!$old) continue;

        $set  = [];
        $args = [];
        $skipped = 0;

        // the date and the description keep what the file first said, so the
        // star and its hover show where the line came from
        if (!in_array('txn_date', $ignore, true)
            && substr((string)$old['txn_date'], 0, 10) !== substr((string)$s['txn_date'], 0, 10)) {
            if (isset($may['txn_date'])) {
                if ($old['orig_txn_date'] === null) { $set[] = 'orig_txn_date = ?'; $args[] = $old['txn_date']; }
                $set[] = 'txn_date = ?';
                $args[] = $s['txn_date'];
            } else { $skipped++; }
        }
        if (!in_array('description', $ignore, true)
            && rolling_norm($old['description']) !== rolling_norm($s['description'])) {
            if (isset($may['description'])) {
                if ($old['orig_description'] === null) { $set[] = 'orig_description = ?'; $args[] = $old['description']; }
                $set[] = 'description = ?';
                $args[] = $s['description'];
            } else { $skipped++; }
        }
        if (!in_array('value', $ignore, true)
            && abs((float)$old['value'] - (float)$s['value']) >= 0.005) {
            if (isset($may['value'])) {
                $set[] = 'prev_value = ?';
                $args[] = $old['value'];
                $set[] = 'value = ?';
                $args[] = $s['value'];
            } else { $skipped++; }
        }
        foreach ($cols as $k) {
            if (in_array($k, $ignore, true)) continue;
            if (rolling_norm($old[$k] ?? '') !== rolling_norm($s[$k] ?? '')) {
                if (isset($may[$k])) {
                    $set[] = "{$k} = ?";
                    $args[] = $s[$k];
                } else { $skipped++; }
            }
        }
        if ($skipped) $held++;
        if (!$set) continue;

        $set[] = 'restated_at = NOW()';
        $set[] = 'amended_by = ?';
        $args[] = 'the reloaded file';
        $set[] = 'amend_why = ?';
        $args[] = 'restated by a reload of ' . mb_substr((string)$s['source_file'], 0, 120);
        $set[] = 'amended_at = CASE WHEN orig_txn_date IS NOT NULL OR orig_description IS NOT NULL
                                    THEN NOW() ELSE amended_at END';
        $args[] = (int)$s['txn_id'];
        $pdo->prepare("UPDATE rec_txns SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);

        // the fingerprint has to describe the line AS IT NOW IS, not as the file
        // said it should be - otherwise a refused field would look settled
        $get->execute([(int)$s['txn_id'], (int)$fileId]);
        $now = $get->fetch();
        $pdo->prepare("UPDATE rec_txns SET fingerprint = ?, row_key = ? WHERE id = ?")
            ->execute([row_fingerprint($now, $ignore),
                       row_key_value($now, active_key_fields($fileId)), (int)$s['txn_id']]);
        $changed++;
    }

    // the new lines need their fingerprint and key for the next comparison
    if ($newRows) fingerprint_file($fileId);

    // what has been let through is done with, but stays in the load so the rest
    // of it can still be judged again
    $applied = [];
    foreach ($rows as $s) {
        if (empty($s['applied_at']) && $s['verdict'] !== 'blocked') $applied[] = (int)$s['id'];
    }
    if ($applied) {
        $in2 = implode(',', array_fill(0, count($applied), '?'));
        if (rolling_tune_ready()) {
            $pdo->prepare("UPDATE rec_stage SET applied_at = NOW() WHERE id IN ($in2)")->execute($applied);
        } else {
            $pdo->prepare("DELETE FROM rec_stage WHERE id IN ($in2)")->execute($applied);
        }
    }
    if (!rolling_tune_ready()) {
        $pdo->prepare("DELETE FROM rec_stage WHERE file_id = ? AND verdict = 'same'")->execute([(int)$fileId]);
    }

    $bits = [];
    if ($added)   $bits[] = $added . ' added';
    if ($changed) $bits[] = $changed . ' amended';
    if ($held)    $bits[] = $held . ' with a change you did not allow, left as they were';
    if ($refused) $bits[] = $refused . ' left alone - already matched, so they need unmatching first';
    $msg = $bits ? ucfirst(implode(', ', $bits)) . '.' : 'Nothing to apply.';

    if (function_exists('log_event')) {
        log_event('applied a reload', get_file($fileId)['name'] . ': ' . $msg
            . (count($allow) < count($all) ? ' Fields allowed: ' . implode(', ', $allow) . '.' : ''));
    }
    return [$added + $changed, $msg];
}
