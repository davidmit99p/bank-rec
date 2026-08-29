<?php
// -----------------------------------------------------------------------------
// The live server does not have the mbstring extension, so the mb_* functions
// this app uses do not exist there. These stand-ins keep it working.
//
// They are byte-based rather than character-based, which is only a difference
// for accented or non-Latin text. Bank narratives are plain ASCII in practice,
// and mb_substr_safe below makes sure a truncated string is never left with
// half a character on the end.
//
// If the host enables mbstring, none of this is used - the real functions win.
// -----------------------------------------------------------------------------

if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen((string)$s); }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s, $enc = null) { return strtoupper((string)$s); }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}

if (!function_exists('mb_strpos')) {
    function mb_strpos($h, $n, $offset = 0, $enc = null) {
        return strpos((string)$h, (string)$n, (int)$offset);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        $out = $length === null ? substr((string)$s, (int)$start)
                                : substr((string)$s, (int)$start, (int)$length);
        return trim_broken_utf8($out === false ? '' : $out);
    }
}

// Cutting a UTF-8 string by bytes can leave a partial character at the end,
// which browsers render as a black diamond. Drop any incomplete sequence.
function trim_broken_utf8($s)
{
    if ($s === '' || preg_match('//u', $s)) return $s;   // already valid
    for ($i = 0; $i < 3 && $s !== ''; $i++) {
        $s = substr($s, 0, -1);
        if (preg_match('//u', $s)) break;
    }
    return $s;
}
