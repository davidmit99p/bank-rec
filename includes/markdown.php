<?php
// -----------------------------------------------------------------------------
// A very small Markdown renderer - just enough for the notes files kept in the
// repository. No library, because the site does not have one and does not need
// one for this.
//
// Handles: # ## ### headings, --- rules, - bullets, 1. numbered lists,
// **bold**, `code`, [links](to), and paragraphs. Everything is escaped first,
// so the file's contents can never inject markup.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/db.php';

function markdown_inline($text)
{
    $t = h($text);
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t);
    // only allow ordinary relative or http(s) links
    $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = $m[2];
        if (!preg_match('#^(https?://|[A-Za-z0-9._/-]+)$#', $url)) return $m[1];
        return '<a href="' . $url . '">' . $m[1] . '</a>';
    }, $t);
    return $t;
}

function markdown_to_html($md)
{
    $out    = [];
    $para   = [];
    $list   = null;   // 'ul' or 'ol' while one is open

    $closeList = function () use (&$out, &$list) {
        if ($list) { $out[] = "</{$list}>"; $list = null; }
    };
    $closePara = function () use (&$out, &$para) {
        if ($para) { $out[] = '<p>' . markdown_inline(implode(' ', $para)) . '</p>'; $para = []; }
    };

    foreach (preg_split('/\R/', $md) as $line) {
        $trim = rtrim($line);

        if (trim($trim) === '') { $closePara(); $closeList(); continue; }

        if (preg_match('/^---+$/', trim($trim))) {
            $closePara(); $closeList();
            $out[] = '<hr>';
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $trim, $m)) {
            $closePara(); $closeList();
            $level = strlen($m[1]) + 1;              // # becomes h2, the page has its own h1
            $level = min($level, 5);
            $out[] = "<h{$level}>" . markdown_inline($m[2]) . "</h{$level}>";
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)$/', $trim, $m)) {
            $closePara();
            if ($list !== 'ul') { $closeList(); $out[] = '<ul>'; $list = 'ul'; }
            $out[] = '<li>' . markdown_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $trim, $m)) {
            $closePara();
            if ($list !== 'ol') { $closeList(); $out[] = '<ol>'; $list = 'ol'; }
            $out[] = '<li>' . markdown_inline($m[1]) . '</li>';
            continue;
        }
        // a continuation line inside a list item
        if ($list && preg_match('/^\s{2,}\S/', $trim)) {
            $i = count($out) - 1;
            if ($i >= 0 && substr($out[$i], -5) === '</li>') {
                $out[$i] = substr($out[$i], 0, -5) . ' ' . markdown_inline(trim($trim)) . '</li>';
                continue;
            }
        }
        $closeList();
        $para[] = trim($trim);
    }
    $closePara();
    $closeList();
    return implode("\n", $out);
}
