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

// --- and the other way a live page breaks ------------------------------------
//
// The live server has no mbstring, so every mb_ function this app uses must
// have a stand-in in includes/compat.php. A missing one works perfectly here
// and dies there - which is how a broken Transactions screen reached David
// rather than me.
$compat = (string)@file_get_contents("$root/includes/compat.php");
$used   = [];
foreach (array_merge(glob("$root/public/*.php"), glob("$root/includes/*.php")) as $f) {
    if (realpath($f) === realpath("$root/includes/compat.php")) continue;
    preg_match_all('/\b(mb_[a-z0-9_]+)\s*\(/i', (string)@file_get_contents($f), $mm);
    foreach ($mm[1] as $fn) $used[strtolower($fn)][basename($f)] = true;
}
$noStandIn = [];
foreach ($used as $fn => $where) {
    if (stripos($compat, "function {$fn}(") === false) $noStandIn[$fn] = array_keys($where);
}
if ($noStandIn) {
    $problems++;
    foreach ($noStandIn as $fn => $where) {
        printf("  %-22s no stand-in in compat.php - used in %s\n", $fn . '()', implode(', ', $where));
    }
    echo "  The live server has no mbstring: add a stand-in, or use a plain string function.\n";
}

echo $problems
    ? "\n{$problems} problem(s) found.\n"
    : "Every page can reach everything it calls, and every mb_ function has a stand-in.\n";
exit($problems ? 1 : 0);
