<?php
// Loads config and gives us a single database connection to reuse.

function config($key = null)
{
    static $config;
    if ($config === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            exit('Setup needed: copy includes/config.sample.php to includes/config.php and fill in your database details.');
        }
        $config = require $path;
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function db()
{
    static $pdo;
    if ($pdo === null) {
        $d = config('db');
        $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
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
