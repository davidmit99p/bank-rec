<?php
// -----------------------------------------------------------------------------
// Say what went wrong.
//
// A blank "500 Internal Server Error" tells you nothing and tells me less. This
// turns a crash into a readable page and writes it to storage/errors.log so
// there is a history to look back at.
//
// The site sits behind a password and is used by one person, so showing the
// detail is worth far more than hiding it. Set 'show_errors' => false in
// config.php to hide it anyway; it still gets logged.
// -----------------------------------------------------------------------------

function error_log_path()
{
    $dir = dirname(__DIR__) . '/storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/errors.log';
}

function record_problem($what, $file, $line, $trace = '')
{
    $entry = sprintf("[%s] %s\n  at %s line %d\n  page: %s\n%s\n",
        date('Y-m-d H:i:s'), $what, $file, $line,
        $_SERVER['REQUEST_URI'] ?? 'cli',
        $trace ? '  ' . str_replace("\n", "\n  ", $trace) . "\n" : '');
    @file_put_contents(error_log_path(), $entry, FILE_APPEND);
}

function show_problem($what, $file, $line, $trace = '')
{
    $show = true;
    if (function_exists('config')) {
        $app  = config('app') ?? [];
        $show = !array_key_exists('show_errors', $app) || $app['show_errors'];
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>';
    echo '<style>body{font:15px/1.5 system-ui;margin:2rem;max-width:60rem;color:#1b1a17}'
       . 'h1{font-size:1.3rem}code,pre{background:#f0ece4;padding:.15rem .3rem;border-radius:3px}'
       . 'pre{padding:.75rem;overflow:auto;font-size:.85rem}a{color:#8a5a2b}</style>';
    echo '<h1>Something went wrong on that page</h1>';
    if ($show) {
        echo '<p><b>' . htmlspecialchars($what, ENT_QUOTES, 'UTF-8') . '</b></p>';
        echo '<p>at <code>' . htmlspecialchars(basename($file), ENT_QUOTES, 'UTF-8')
           . '</code> line ' . (int)$line . '</p>';
        if ($trace) echo '<pre>' . htmlspecialchars($trace, ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '<p class="muted">This is also in <code>storage/errors.log</code>. '
           . 'Nothing has been changed by the page that failed.</p>';
    } else {
        echo '<p>It has been written to the error log.</p>';
    }
    echo '<p><a href="index.php">Back to the start</a></p>';
}

set_exception_handler(function (Throwable $e) {
    record_problem(get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    show_problem(get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
});

// The handler above never sees a fatal error, so catch those on the way out.
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    record_problem($e['message'], $e['file'], $e['line']);
    show_problem($e['message'], $e['file'], $e['line']);
});
