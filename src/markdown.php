<?php
declare(strict_types=1);

/*
 * Apotheke – a small, safe Markdown subset for item notes, rendered on the server.
 *
 * Everything is HTML-escaped first; only these constructs produce markup:
 *   # / ## / ### headings · paragraphs and line breaks · - / * / + bullet lists · 1. numbered lists · > quotes
 *   ``` fenced code ``` · `inline code` · **bold** · *italic* / _italic_ · ~~strike~~
 *   [text](https://…) links (http, https and mailto only; rel="nofollow noopener noreferrer") · bare https:// links
 * Raw HTML is shown as text. No images, no attributes from the input, so the output is CSP-safe.
 */

const MD_MAX_LEN = 20000;

function md_render(string $src): string
{
    $src = str_replace(["\r\n", "\r"], "\n", mb_substr($src, 0, MD_MAX_LEN));
    $lines = explode("\n", $src);
    $out = [];
    $para = [];
    $list = null;   // ['ul'|'ol', items[]]
    $quote = [];
    $flushPara = function () use (&$para, &$out): void {
        if ($para) $out[] = '<p>' . implode('<br>', array_map('md_inline', $para)) . '</p>';
        $para = [];
    };
    $flushList = function () use (&$list, &$out): void {
        if ($list) $out[] = "<{$list[0]}>" . implode('', array_map(fn($i) => '<li>' . md_inline($i) . '</li>', $list[1])) . "</{$list[0]}>";
        $list = null;
    };
    $flushQuote = function () use (&$quote, &$out): void {
        if ($quote) $out[] = '<blockquote>' . md_render(implode("\n", $quote)) . '</blockquote>';
        $quote = [];
    };
    $flush = function () use ($flushPara, $flushList, $flushQuote): void { $flushPara(); $flushList(); $flushQuote(); };
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = $lines[$i];
        if (preg_match('/^\s*```/', $line)) {
            $flush();
            $code = [];
            for ($i++; $i < $n && !preg_match('/^\s*```/', $lines[$i]); $i++) $code[] = $lines[$i];
            $out[] = '<pre><code>' . h(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (trim($line) === '') { $flush(); continue; }
        if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m)) { $flushPara(); $flushList(); $quote[] = $m[1]; continue; }
        $flushQuote();
        if (preg_match('/^\s{0,3}(#{1,3})\s+(.+?)\s*#*\s*$/', $line, $m)) {
            $flush();
            $lvl = strlen($m[1]) + 2;   // # → h3: the item name is the page's h2
            $out[] = "<h$lvl>" . md_inline($m[2]) . "</h$lvl>";
            continue;
        }
        if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line)) { $flush(); $out[] = '<hr>'; continue; }
        if (preg_match('/^\s{0,3}([-*+])\s+(.*)$/', $line, $m) || preg_match('/^\s{0,3}(\d{1,9})[.)]\s+(.*)$/', $line, $m)) {
            $type = ctype_digit($m[1]) ? 'ol' : 'ul';
            $flushPara();
            if ($list && $list[0] !== $type) $flushList();
            $list ??= [$type, []];
            $list[1][] = $m[2];
            continue;
        }
        if ($list && preg_match('/^\s{2,}(\S.*)$/', $line, $m)) { $list[1][count($list[1]) - 1] .= ' ' . $m[1]; continue; }
        $flushList();
        $para[] = $line;
    }
    $flush();
    return implode("\n", $out);
}

/** Inline markup on one escaped line. */
function md_inline(string $s): string
{
    $s = str_replace(["\x01", "\x02"], '', $s);
    $codes = [];
    // inline code first: its content is never formatted
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . h($m[1]) . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $s);
    $links = [];
    $s = preg_replace_callback('/\[([^\]\n]{1,300})\]\(\s*((?:https?:\/\/|mailto:)[^\s)<>"]{1,1000})\s*\)/i', function ($m) use (&$links) {
        $links[] = [$m[1], $m[2]];
        return "\x02" . (count($links) - 1) . "\x02";
    }, $s);
    $s = h($s);
    $s = preg_replace_callback('#(?<![\w"=/])(https?://[^\s<>"\x01\x02]{2,1000})#i', function ($m) {
        $url = rtrim($m[1], '.,;:!?)');
        $tail = substr($m[1], strlen($url));
        return md_link(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), h(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . $tail;
    }, $s);
    $s = md_emphasis($s);
    $s = preg_replace_callback("/\x02(\\d+)\x02/", fn($m) => md_link($links[(int)$m[1]][1], md_emphasis(h($links[(int)$m[1]][0]))), $s);
    return preg_replace_callback("/\x01(\\d+)\x01/", fn($m) => $codes[(int)$m[1]], $s);
}

function md_emphasis(string $s): string
{
    $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/__(?=\S)(.+?)(?<=\S)__/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '<em>$1</em>', $s);
    $s = preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u', '<em>$1</em>', $s);
    return preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<del>$1</del>', $s);
}

function md_link(string $url, string $html): string
{
    if (!preg_match('#^(https?://|mailto:)#i', $url)) return $html;
    return '<a href="' . h($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . $html . '</a>';
}

/** Plain text for search / history (markup characters stay, they are harmless there). */
function md_plain(string $src): string
{
    return trim(preg_replace('/[*_`~#>]+/', '', $src) ?? '');
}
