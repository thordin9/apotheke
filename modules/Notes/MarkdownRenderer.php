<?php

declare(strict_types=1);

namespace Apotheke\Notes;

/**
 * The safe Markdown subset for item notes, rendered on the server: a port of
 * legacy src/markdown.php (md_render, md_plain) with byte-identical output.
 *
 * Everything is HTML-escaped first; only these constructs produce markup:
 * # / ## / ### headings (h3 to h5: the item name is the page's h2),
 * paragraphs and line breaks, - / * / + bullet lists, 1. numbered lists,
 * > quotes, ``` fenced code ```, `inline code`, **bold**, *italic* /
 * _italic_, ~~strike~~, [text](https://…) links (http, https and mailto
 * only; rel="nofollow noopener noreferrer") and bare https:// links. Raw
 * HTML is shown as text. No images and no attributes from the input, so the
 * output is CSP-safe. Input past MAX_LEN characters is ignored.
 */
final class MarkdownRenderer
{
    public const MAX_LEN = 20000;

    public function render(string $src): string
    {
        $src = str_replace(["\r\n", "\r"], "\n", mb_substr($src, 0, self::MAX_LEN));
        $lines = explode("\n", $src);
        $out = [];
        $para = [];
        $list = null;
        $quote = [];
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*```/', $line) === 1) {
                $this->flushAll($para, $list, $quote, $out);
                $code = [];
                for ($i++; $i < $n && preg_match('/^\s*```/', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }
                $out[] = '<pre><code>' . self::h(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (trim($line) === '') {
                $this->flushAll($para, $list, $quote, $out);
                continue;
            }
            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m) === 1) {
                $this->flushPara($para, $out);
                $this->flushList($list, $out);
                $quote[] = $m[1];
                continue;
            }
            $this->flushQuote($quote, $out);
            if (preg_match('/^\s{0,3}(#{1,3})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $this->flushAll($para, $list, $quote, $out);
                $lvl = strlen($m[1]) + 2;
                $out[] = "<h$lvl>" . $this->inline($m[2]) . "</h$lvl>";
                continue;
            }
            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
                $this->flushAll($para, $list, $quote, $out);
                $out[] = '<hr>';
                continue;
            }
            if (preg_match('/^\s{0,3}([-*+])\s+(.*)$/', $line, $m) === 1 || preg_match('/^\s{0,3}(\d{1,9})[.)]\s+(.*)$/', $line, $m) === 1) {
                $type = ctype_digit($m[1]) ? 'ol' : 'ul';
                $this->flushPara($para, $out);
                if ($list !== null && $list[0] !== $type) {
                    $this->flushList($list, $out);
                }
                $list ??= [$type, []];
                $list[1][] = $m[2];
                continue;
            }
            if ($list !== null && preg_match('/^\s{2,}(\S.*)$/', $line, $m) === 1) {
                $list[1][count($list[1]) - 1] .= ' ' . $m[1];
                continue;
            }
            $this->flushList($list, $out);
            $para[] = $line;
        }
        $this->flushAll($para, $list, $quote, $out);

        return implode("\n", $out);
    }

    /**
     * @param list<string> $para
     * @param list<string> $out
     */
    private function flushPara(array &$para, array &$out): void
    {
        if ($para !== []) {
            $out[] = '<p>' . implode('<br>', array_map($this->inline(...), $para)) . '</p>';
        }
        $para = [];
    }

    /**
     * @param array{0:string,1:list<string>}|null $list
     * @param-out null $list
     * @param list<string> $out
     */
    private function flushList(?array &$list, array &$out): void
    {
        if ($list !== null) {
            $out[] = "<{$list[0]}>" . implode('', array_map(fn (string $i): string => '<li>' . $this->inline($i) . '</li>', $list[1])) . "</{$list[0]}>";
        }
        $list = null;
    }

    /**
     * @param list<string> $quote
     * @param list<string> $out
     */
    private function flushQuote(array &$quote, array &$out): void
    {
        if ($quote !== []) {
            $out[] = '<blockquote>' . $this->render(implode("\n", $quote)) . '</blockquote>';
        }
        $quote = [];
    }

    /**
     * @param list<string> $para
     * @param array{0:string,1:list<string>}|null $list
     * @param-out null $list
     * @param list<string> $quote
     * @param list<string> $out
     */
    private function flushAll(array &$para, ?array &$list, array &$quote, array &$out): void
    {
        $this->flushPara($para, $out);
        $this->flushList($list, $out);
        $this->flushQuote($quote, $out);
    }

    /** Plain text for search and history (markup characters stay out; the rest is unchanged). */
    public function plain(string $src): string
    {
        return trim(preg_replace('/[*_`~#>]+/', '', $src) ?? '');
    }

    /** Inline markup on one line. */
    private function inline(string $s): string
    {
        $s = str_replace(["\x01", "\x02"], '', $s);
        $codes = [];
        // inline code first: its content is never formatted
        $s = (string)preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::h($m[1]) . '</code>';

            return "\x01" . (count($codes) - 1) . "\x01";
        }, $s);
        $links = [];
        $s = (string)preg_replace_callback('/\[([^\]\n]{1,300})\]\(\s*((?:https?:\/\/|mailto:)[^\s)<>"]{1,1000})\s*\)/i', static function (array $m) use (&$links): string {
            $links[] = [$m[1], $m[2]];

            return "\x02" . (count($links) - 1) . "\x02";
        }, $s);
        $s = self::h($s);
        $s = (string)preg_replace_callback('#(?<![\w"=/])(https?://[^\s<>"\x01\x02]{2,1000})#i', static function (array $m): string {
            $url = rtrim($m[1], '.,;:!?)');
            $tail = substr($m[1], strlen($url));
            $raw = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return self::link($raw, self::h($raw)) . $tail;
        }, $s);
        $s = self::emphasis($s);
        $s = (string)preg_replace_callback("/\x02(\\d+)\x02/", static fn (array $m): string => self::link($links[(int)$m[1]][1], self::emphasis(self::h($links[(int)$m[1]][0]))), $s);

        return (string)preg_replace_callback("/\x01(\\d+)\x01/", static fn (array $m): string => $codes[(int)$m[1]], $s);
    }

    private static function emphasis(string $s): string
    {
        $s = (string)preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $s);
        $s = (string)preg_replace('/__(?=\S)(.+?)(?<=\S)__/u', '<strong>$1</strong>', $s);
        $s = (string)preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '<em>$1</em>', $s);
        $s = (string)preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u', '<em>$1</em>', $s);

        return (string)preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<del>$1</del>', $s);
    }

    private static function link(string $url, string $html): string
    {
        if (preg_match('#^(https?://|mailto:)#i', $url) !== 1) {
            return $html;
        }

        return '<a href="' . self::h($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . $html . '</a>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
