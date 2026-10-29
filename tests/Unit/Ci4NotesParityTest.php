<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/Notes/MarkdownRenderer.php';

use Apotheke\Notes\MarkdownRenderer;

/**
 * Golden (PLAN step 8, Notes): CI4's MarkdownRenderer equals legacy
 * md_render() and md_plain() byte for byte on a corpus (every construct,
 * the link and XSS cases, the 20000-character cap) and on seeded random
 * input built from the markup characters.
 */
final class Ci4NotesParityTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function corpus(): array
    {
        $cases = [
            'empty' => '',
            'blank lines' => "\n\n  \n\t\n",
            'blocks' => "# A\n## B\n### C\n\npara one\nline two\n\n- x\n- y\n\n1. one\n2. two\n\n> quoted\n\n---\n\n```\n<b>code</b>\n```",
            'h4 is text' => "#### four\n#nospace\n  ## indented ##  \n    ### too far",
            'heading closing hashes' => "# Title ###\n## *em* #",
            'inline' => '**b** *i* _u_ ~~s~~ `c *d*` __strong__',
            'snake and maths' => 'snake_case_name and 2*3*4 and a * b * c',
            'nested emphasis' => '***both*** **_mix_** *a **b** c* ~~**x**~~',
            'unclosed' => '**open *half `tick ~~strike [link](',
            'link' => '[the **docs**](https://x.org/a?b=1&c=2)',
            'mailto' => '[mail](mailto:a@b.c) and [Up](HTTPS://X.ORG)',
            'link spaces' => '[x]( https://x.org/a ) [y](https://x.org/a b)',
            'link text too long' => '[' . str_repeat('t', 301) . '](https://x.org)',
            'bare url' => 'see https://example.org/x. and (https://e.org/a), https://e.org/?a=1&b=2; http://e.org!',
            'bare url in link text' => '[https://a.org](https://b.org)',
            'bare url entities' => 'https://e.org/&quot;x https://e.org/&lt;y https://e.org/a&amp;b',
            'bare url after quote or slash' => '"https://a.org" =https://a.org /https://a.org xhttps://a.org',
            'code protects' => '`[x](https://a.org)` `**no**` `<i>` `` `',
            'control chars' => "\x01 0 \x01 \x02 1 \x02 `\x01 0 \x01` **<b>**",
            'lists' => "- a\n* b\n+ c\n1. one\n2) two\n10. ten\n- back",
            'list continuation' => "- a\n  more a\n    even more\n- b\nplain after\n\n1. x\n   y",
            'list then para' => "- item\nparagraph\n\npara\n- item",
            'too many digits' => "1234567890. not a list\n123456789. a list",
            'quotes' => "> a\n> b\n>c\n> - list\n> > nested\n\n> # head\n   > indented\n    > code-ish",
            'quote then text' => "> q\ntext\n> q2",
            'hr variants' => "---\n***\n___\n- - -\n * * * \n--\n-*-",
            'fence unclosed' => "```\n<script>x</script>\nnever closed",
            'fence info and indent' => "  ```php\n\$a = '<b>';\n   ```\nafter",
            'crlf' => "line1\r\nline2\rline3\r\n\r\n- a\r\n- b",
            'unicode' => "Ünïcode **ßtrong** _ελληνικά_ ~~日本~~ 🧪 [ссылка](https://пример.рф/путь)",
            'invalid utf8' => "bad \xff\xfe bytes **\xc3(** [x](https://a.org/\xff)",
            'entities' => '&amp; &lt;tag&gt; &#60; &quot;q&quot; \' "',
            'raw html' => '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>',
            'js links' => '[x](javascript:alert(1)) [x](JAVASCRIPT:alert(1)) [x](data:text/html;base64,AAAA) [x](//evil.org)',
            'attr break' => '[x](https://a.org/" onmouseover="alert(1)) https://x.org/"onmouseover=alert(1)',
            'svg in strong' => '**<svg onload=alert(1)>**',
            'brackets' => '[a] [b](c) [](https://a.org) [x](https://a.org)) ((https://a.org))',
            'underscores' => '_a_b_ __init__ foo_bar_ _ spaced _ *spaced *',
            'tabs' => "\t- tab list\n\t# tab head\n\t> tab quote",
        ];
        $cases['cap exact'] = str_repeat('a', MarkdownRenderer::MAX_LEN);
        $cases['cap over'] = str_repeat('a', MarkdownRenderer::MAX_LEN) . '**cut**';
        $cases['cap multibyte'] = str_repeat('é', MarkdownRenderer::MAX_LEN - 2) . '**bold**';
        $cases['cap inside fence'] = "```\n" . str_repeat("x\n", 10000) . "```\n**after**";
        $cases['long lines'] = str_repeat('**a** ', 3000) . "\n" . str_repeat('https://a.org/' . str_repeat('p', 50) . ' ', 200);

        return array_map(static fn (string $s): array => [$s], $cases);
    }

    #[DataProvider('corpus')]
    public function testTheCorpusMatchesLegacy(string $src): void
    {
        $r = new MarkdownRenderer();
        $this->assertSame(md_render($src), $r->render($src));
        $this->assertSame(md_plain($src), $r->plain($src));
    }

    public function testTheCapIsLegacys(): void
    {
        $this->assertSame(MD_MAX_LEN, MarkdownRenderer::MAX_LEN);
    }

    /** Seeded random input from the characters the parser cares about. */
    public function testRandomInputMatchesLegacy(): void
    {
        $alphabet = ['*', '**', '_', '__', '~~', '`', '```', '#', '## ', '> ', '- ', '+ ', '1. ', '2) ', '---', "\n", "\n\n", "\r\n", '  ', "\t",
            '[', ']', '(', ')', '[t](https://a.org/x)', 'https://b.org/q?a=1&b=2', 'mailto:a@b.c', '<', '>', '&', '"', "'", '=', '/',
            "\x01", "\x02", '0', '1', 'a', 'b', 'word', ' ', 'é', '🧪', "\xff", 'javascript:'];
        $r = new MarkdownRenderer();
        mt_srand(20260825);
        for ($case = 0; $case < 3000; $case++) {
            $src = '';
            for ($j = 0, $n = mt_rand(1, 40); $j < $n; $j++) {
                $src .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $this->assertSame(md_render($src), $r->render($src), 'render ' . bin2hex($src));
            $this->assertSame(md_plain($src), $r->plain($src), 'plain ' . bin2hex($src));
        }
    }
}
