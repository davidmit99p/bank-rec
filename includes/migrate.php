<?php
// -----------------------------------------------------------------------------
// Keeping databases up to date.
//
// Every change to the shape of the database is a numbered file in sql/. Until
// now they were run by hand in phpMyAdmin, which meant remembering to, and
// doing it once per client.
//
// Here the app reads the same files, sees which have been run in each database,
// and offers to run the rest. What has been run is recorded in rec_migrations
// in the database itself, so the answer travels with the data rather than with
// the code.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/central.php';

// Every database this installation looks after: each client, or the single one.
// [['id' => ..., 'label' => ..., 'cfg' => [...]], ...]
function migrate_targets()
{
    if (!central_on()) {
        return [['id' => 0, 'label' => 'This installation', 'cfg' => config('db')]];
    }
    $out = [];
    foreach (all_clients(false) as $c) {
        $cfg = client_config($c['cfg_key']);
        if ($cfg) $out[] = ['id' => (int)$c['id'], 'label' => $c['name'], 'cfg' => $cfg];
    }
    return $out;
}

// What one database has had, and what it is still waiting for.
// ['applied' => n, 'waiting' => [file, ...], 'error' => why it could not be read]
function migrate_status(array $cfg)
{
    try {
        $pdo  = migrate_connect($cfg);
        $done = array_flip(applied_files($pdo));
        $wait = [];
        foreach (schema_files() as $f) if (!isset($done[$f])) $wait[] = $f;
        return ['applied' => count($done), 'waiting' => $wait, 'error' => null];
    } catch (Throwable $e) {
        return ['applied' => 0, 'waiting' => [], 'error' => $e->getMessage()];
    }
}

function migrate_connect(array $cfg)
{
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=" . ($cfg['charset'] ?? 'utf8mb4');
    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// How many changes are waiting across everything, for the quiet reminder in the
// header. Counted once per request, and never allowed to break a page.
function changes_waiting()
{
    static $n = null;
    if ($n !== null) return $n;
    $n = 0;
    try {
        foreach (migrate_targets() as $t) {
            $s = migrate_status($t['cfg']);
            $n += count($s['waiting']);
        }
    } catch (Throwable $e) {
        $n = 0;
    }
    return $n;
}

// When each file was run, newest first, for the record.
function migrate_history(array $cfg, $limit = 100)
{
    try {
        $pdo = migrate_connect($cfg);
        applied_files($pdo);                       // makes the table if it is missing
        return $pdo->query("SELECT file, applied_at FROM rec_migrations
                            ORDER BY applied_at DESC, file DESC LIMIT " . (int)$limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
