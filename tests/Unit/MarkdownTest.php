<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Notes Markdown subset: formatting works, nothing from the input becomes live HTML. */
final class MarkdownTest extends TestCase
{
    public function testBlocks(): void
    {
        $h = md_render("# A\n## B\n### C\n\npara one\nline two\n\n- x\n- y\n\n1. one\n2. two\n\n> quoted\n\n---\n\n```\n<b>code</b>\n```");
        foreach (['<h3>A</h3>', '<h4>B</h4>', '<h5>C</h5>', '<p>para one<br>line two</p>', '<ul><li>x</li><li>y</li></ul>', '<ol><li>one</li><li>two</li></ol>',
                  '<blockquote><p>quoted</p></blockquote>', '<hr>', '<pre><code>&lt;b&gt;code&lt;/b&gt;</code></pre>'] as $want) {
            $this->assertStringContainsString($want, $h);
        }
    }

    public function testInline(): void
    {
        $this->assertSame('<p><strong>b</strong> <em>i</em> <em>u</em> <del>s</del> <code>c *d*</code></p>', md_render('**b** *i* _u_ ~~s~~ `c *d*`'));
        $this->assertSame('<p>snake_case_name and 2*3*4</p>', md_render('snake_case_name and 2*3*4'));
        $this->assertStringContainsString('<a href="https://x.org/a?b=1&amp;c=2" rel="nofollow noopener noreferrer" target="_blank">the <strong>docs</strong></a>', md_render('[the **docs**](https://x.org/a?b=1&c=2)'));
        $this->assertStringContainsString('<a href="mailto:a@b.c"', md_render('[mail](mailto:a@b.c)'));
        $this->assertStringContainsString('<a href="https://example.org/x"', md_render('see https://example.org/x.'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attacks')]
    public function testNoLiveHtml(string $src): void
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . md_render($src) . '</div>', LIBXML_NONET);
        $allowed = ['html', 'body', 'div', 'p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'a', 'ul', 'ol', 'li', 'blockquote', 'h3', 'h4', 'h5', 'hr'];
        foreach ($doc->getElementsByTagName('*') as $el) {
            $this->assertContains($el->nodeName, $allowed, 'element ' . $el->nodeName);
            foreach ($el->attributes as $at) {
                $this->assertContains($at->name, ['href', 'rel', 'target'], 'attribute ' . $at->name);
                if ($at->name === 'href') $this->assertMatchesRegularExpression('#^(https?://|mailto:)#i', $at->value);
            }
        }
    }

    public static function attacks(): array
    {
        return [
            ['<script>alert(1)</script>'], ['<img src=x onerror=alert(1)>'], ['[x](javascript:alert(1))'], ['[x](JAVASCRIPT:alert(1))'],
            ['[x](data:text/html;base64,AAAA)'], ['[x](https://a.org/" onmouseover="alert(1))'], ["`\x01 0 \x01` **<b>**"],
            ['<a href="javascript:alert(1)">x</a>'], ['**<svg onload=alert(1)>**'], ['https://x.org/"onmouseover=alert(1)'],
        ];
    }
}
