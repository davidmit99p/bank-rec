<?php
/*
 * Does every page load the files it needs?
 *
 *   php tools/check_includes.php
 *
 * PHP will happily lint a page that calls a function it can never reach - the
 * mistake only shows up when someone opens that page and gets "Call to
 * undefined function". This walks each page's require_once chain, works out
 * which of our own functions it can actually see, and compares that against
 * what it calls.
 *
 * Worth running before pushing anything that moves a function between files.
 */

$root = dirname(__DIR__);

function defined_in($path)
{
    $out = [];
    preg_match_all('/^\s*function\s+([a-z_][a-z0-9_]*)\s*\(/mi',
                   (string)@file_get_contents($path), $m);
    foreach ($m[1] as $fn) $out[strtolower($fn)] = true;
    return $out;
}

function requires_of($path)
{
    $out = [];
    preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*\'([^\']+)\'/',
                   (string)@file_get_contents($path), $m);
    foreach ($m[1] as $rel) {
        $full = realpath(dirname($path) . $rel);
        if ($full) $out[] = $full;
    }
    return $out;
}

function reachable($path, array &$seen = [])
{
    $path = realpath($path);
    if (!$path || isset($seen[$path])) return [];
    $seen[$path] = true;
    $fns = defined_in($path);
    foreach (requires_of($path) as $r) $fns += reachable($r, $seen);
    return $fns;
}

// everything we define ourselves, so built-in functions are left alone
$ours = [];
foreach (glob("$root/includes/*.php") as $f) $ours += defined_in($f);

$pages = array_merge(glob("$root/public/*.php"), glob("$root/tools/*.php"));
$problems = 0;

foreach ($pages as $page) {
    if (realpath($page) === realpath(__FILE__)) continue;
    $seen = [];
    $have = reachable($page, $seen);
    preg_match_all('/(?<![>$:\w])([a-z_][a-z0-9_]*)\s*\(/i',
                   (string)@file_get_contents($page), $m);

    $missing = [];
    foreach ($m[1] as $fn) {
        $fn = strtolower($fn);
        if (isset($ours[$fn]) && !isset($have[$fn])) $missing[$fn] = true;
    }
    if ($missing) {
        $problems++;
        printf("  %-20s cannot reach: %s\n", basename($page), implode(', ', array_keys($missing)));
    }
}

echo $problems
    ? "\n{$problems} page(s) call something they cannot reach.\n"
    : "Every page can reach everything it calls.\n";
exit($problems ? 1 : 0);
