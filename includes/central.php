<?php
// -----------------------------------------------------------------------------
// Several clients, one installation.
//
// A CENTRAL database holds the people and the list of clients. Each CLIENT has
// a database of its own, and everything about a reconciliation lives there.
// One client's work cannot appear on another's screen because it is not
// reachable, rather than because every query remembered to filter.
//
// A company is a grouping INSIDE a client, not a database of its own.
//
// Until the config file has a 'central' section none of this applies and the
// app works exactly as it did: one database, its own users.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';

// Is this installation running centrally?
function central_on()
{
    $c = config('central');
    return is_array($c) && !empty($c['name']);
}

// The central connection. Separate from db(), which is whichever client you are
// working in.
function central_db()
{
    static $pdo;
    if ($pdo === null) {
        $d = config('central');
        $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset=" . ($d['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// Are the central tables there yet?
// Only a yes is remembered: the tables may be made during this very request,
// and a remembered no would outlive them.
function central_ready()
{
    static $ok = false;
    if ($ok) return true;
    if (!central_on()) return false;
    try { central_db()->query("SELECT id FROM cen_users LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

// Make them. Safe to call twice - every statement is CREATE TABLE IF NOT EXISTS.
function central_install()
{
    $sql = file_get_contents(__DIR__ . '/../sql/central.sql');
    run_sql_script(central_db(), $sql);
}

// Run a file of statements. Ours are plain enough to split on semicolons at the
// end of a line, which keeps this to a few lines rather than a parser.
//
// Two allowances, so the same files work on either server we are likely to meet:
//
//  - MariaDB understands "ADD COLUMN IF NOT EXISTS"; MySQL does not. On MySQL
//    the words are taken out, and the duplicate is forgiven instead.
//  - A change that is already there is not a failure. These files were written
//    to be run by hand, so some will already have been applied.
//
// Anything else is thrown, and the caller stops and says where.
function run_sql_script(PDO $pdo, $sql)
{
    $mariadb = stripos((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    $sql = preg_replace('/^\s*--.*$/m', '', (string)$sql);
    $already = 0;
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
        $stmt = trim($stmt, " \t\r\n;");
        if ($stmt === '') continue;
        if (!$mariadb) {
            $stmt = preg_replace('/\b(ADD\s+(?:COLUMN|INDEX|KEY))\s+IF\s+NOT\s+EXISTS\b/i', '$1', $stmt);
            $stmt = preg_replace('/\b(DROP\s+(?:COLUMN|INDEX|KEY))\s+IF\s+EXISTS\b/i', '$1', $stmt);
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            if (!preg_match('/already exists|Duplicate column|Duplicate key name|Duplicate entry/i', $e->getMessage())) {
                throw $e;
            }
            $already++;
        }
    }
    return $already;
}

// --- the clients --------------------------------------------------------------

function all_clients($activeOnly = true)
{
    if (!central_ready()) return [];
    $sql = "SELECT * FROM cen_clients" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY name";
    return central_db()->query($sql)->fetchAll();
}

function get_client($id)
{
    if (!central_ready() || !$id) return null;
    $st = central_db()->prepare("SELECT * FROM cen_clients WHERE id = ?");
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

// The connection details for a client, from the config file - never from the
// database, so a client's database password is not sitting in a table.
function client_config($cfgKey)
{
    $all = config('clients');
    return is_array($all) && isset($all[$cfgKey]) ? $all[$cfgKey] : null;
}

// The clients one person may work on. The owner reaches all of them.
function clients_for_user($user)
{
    if (!central_ready() || !$user) return [];
    if (($user['role'] ?? '') === 'owner') return all_clients();
    $st = central_db()->prepare("SELECT c.* FROM cen_clients c
                                 JOIN cen_access a ON a.client_id = c.id
                                 WHERE a.user_id = ? AND c.active = 1 ORDER BY c.name");
    $st->execute([(int)$user['id']]);
    return $st->fetchAll();
}

function user_may_reach($user, $clientId)
{
    foreach (clients_for_user($user) as $c) if ((int)$c['id'] === (int)$clientId) return true;
    return false;
}

// --- which client this page is about -------------------------------------------
//
// Held in the session, like the reconciliation is. db() asks for it, so it must
// not itself use db() - that would go round in a circle.
function current_client_id()
{
    if (!central_on()) return null;
    if (session_status() === PHP_SESSION_NONE) session_start();
    return (int)($_SESSION['client_id'] ?? 0) ?: null;
}

function set_current_client($id)
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['client_id'] = (int)$id ?: null;
    reset_db();                                  // the next query goes to the new client
}

function current_client()
{
    return get_client(current_client_id());
}

// The connection details db() should use: the chosen client's, or the single
// 'db' entry when this is not a central installation.
function active_db_config()
{
    if (!central_on()) return config('db');
    $client = current_client();
    if (!$client) return null;                   // nobody has chosen one yet
    return client_config($client['cfg_key']);
}

// --- setting up a new client's database ----------------------------------------

// Every schema file in the order it must run: the original schema, then each
// numbered migration. Used when a new client's database is empty.
function schema_files()
{
    $dir = __DIR__ . '/../sql';
    $out = ['schema.sql'];
    $files = glob($dir . '/migration_*.sql') ?: [];
    sort($files, SORT_NATURAL);
    foreach ($files as $f) $out[] = basename($f);
    return $out;
}

// What has already been run in a client's database. The table is made on first
// use, so an existing database can simply be told what it already has.
function applied_files(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS rec_migrations (
                    file       VARCHAR(120) NOT NULL PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $pdo->query("SELECT file FROM rec_migrations")->fetchAll(PDO::FETCH_COLUMN);
}

// Run whatever has not been run yet. Returns [files run, message].
//
// A migration that fails because the change is already there is recorded rather
// than retried for ever: these files were written to be run by hand, so some of
// them will already have been applied to a database that has been in use.
function bring_up_to_date(array $cfg, $pretend = false)
{
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=" . ($cfg['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $done = array_flip(applied_files($pdo));
    $ran  = [];
    $note = [];
    foreach (schema_files() as $file) {
        if (isset($done[$file])) continue;
        if ($pretend) { $ran[] = $file; continue; }
        try {
            run_sql_script($pdo, file_get_contents(__DIR__ . '/../sql/' . $file));
        } catch (Throwable $e) {
            // already there is not a failure; anything else is
            $msg = $e->getMessage();
            if (!preg_match('/already exists|Duplicate column|Duplicate key/i', $msg)) {
                return [$ran, 'Stopped at ' . $file . ': ' . $msg];
            }
            $note[] = $file . ' was already applied';
        }
        $pdo->prepare("INSERT INTO rec_migrations (file) VALUES (?)")->execute([$file]);
        $ran[] = $file;
    }
    return [$ran, $note ? implode('; ', $note) : null];
}

// Tell a database that has been in use for months that it is already up to
// date, without running anything.
function adopt_as_up_to_date(array $cfg)
{
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=" . ($cfg['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'],
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $done = array_flip(applied_files($pdo));
    $n = 0;
    $ins = $pdo->prepare("INSERT INTO rec_migrations (file) VALUES (?)");
    foreach (schema_files() as $file) {
        if (isset($done[$file])) continue;
        $ins->execute([$file]);
        $n++;
    }
    return $n;
}
