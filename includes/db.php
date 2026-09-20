<?php
// Loads config and gives us a single database connection to reuse.

// Turn a blank 500 into something that says what happened.
require_once __DIR__ . '/errors.php';

// Stand-ins for PHP extensions the live server does not have.
require_once __DIR__ . '/compat.php';

// Where the config file may live, in the order we look for it.
//
// Normally it sits in this folder, and the document root points at public/ so
// nobody on the web can reach it. If the app has to be installed INSIDE a web
// folder instead, put the config somewhere above that folder and it will be
// found here - the password then never sits anywhere the web can serve.
function config_paths()
{
    $paths = [];
    if ($env = getenv('BANKREC_CONFIG')) $paths[] = $env;
    $paths[] = __DIR__ . '/config.php';                       // the usual place
    $paths[] = dirname(__DIR__, 2) . '/bank-rec-config.php';  // one level above the app
    $paths[] = dirname(__DIR__, 3) . '/bank-rec-config.php';  // two levels above
    return $paths;
}

function config($key = null)
{
    static $config;
    if ($config === null) {
        $found = null;
        foreach (config_paths() as $path) {
            if ($path && file_exists($path)) { $found = $path; break; }
        }
        if ($found === null) {
            http_response_code(500);
            exit('Setup needed: copy includes/config.sample.php to includes/config.php and fill in your database details.');
        }
        $config = require $found;
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

// The database this page is working in.
//
// Ordinarily the single one named in the config file. On an installation with
// several clients it is whichever client you are signed in to - see
// includes/central.php - and nothing here can reach another client's data,
// because the connection does not go there.
// The open connection, kept in one place so it can be let go when the client
// changes. A static inside db() itself could never be cleared.
function db_handle($pdo = null, $clear = false)
{
    static $held;
    if ($clear) { $held = null; return null; }
    if ($pdo !== null) $held = $pdo;
    return $held;
}

function db()
{
    $pdo = db_handle();
    if ($pdo === null) {
        $d = function_exists('active_db_config') ? active_db_config() : config('db');
        if (!$d) {
            // signed in, but no client chosen yet
            throw new RuntimeException('No client has been chosen for this session.');
        }
        $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset=" . ($d['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        db_handle($pdo);
    }
    return $pdo;
}

// Let the connection go, so the next db() opens the newly chosen client's.
// Always followed by a redirect, so nothing else is holding what it read.
function reset_db()
{
    db_handle(null, true);
}

// Short, readable reference for a matching run, e.g. RUN-20260828-K7Q2
function make_run_ref()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no confusing 0/O/1/I
    $tail = '';
    for ($i = 0; $i < 4; $i++) {
        $tail .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'RUN-' . date('Ymd') . '-' . $tail;
}

// Money for display: -3062.53 becomes -3,062.53
function money($n)
{
    return number_format((float)$n, 2);
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Simple one-shot messages between pages.
function flash($msg = null)
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if ($msg !== null) { $_SESSION['flash'][] = $msg; return; }
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}
