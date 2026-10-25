<?php

declare(strict_types=1);

namespace Tests\Notes;

use Apotheke\Notes\MarkdownRenderer;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Notes Markdown subset: formatting works, nothing from the input becomes live HTML. */
final class MarkdownRendererTest extends TestCase
{
    public function testBlocks(): void
    {
        $h = (new MarkdownRenderer())->render("# A\n## B\n### C\n\npara one\nline two\n\n- x\n- y\n\n1. one\n2. two\n\n> quoted\n\n---\n\n```\n<b>code</b>\n```");
        $this->assertSame(implode("\n", ['<h3>A</h3>', '<h4>B</h4>', '<h5>C</h5>', '<p>para one<br>line two</p>', '<ul><li>x</li><li>y</li></ul>',
            '<ol><li>one</li><li>two</li></ol>', '<blockquote><p>quoted</p></blockquote>', '<hr>', '<pre><code>&lt;b&gt;code&lt;/b&gt;</code></pre>']), $h);
    }

    public function testListsQuotesAndFences(): void
    {
        $r = new MarkdownRenderer();
        $this->assertSame('<ul><li>a more</li></ul>' . "\n" . '<ol><li>b</li></ol>', $r->render("- a\n  more\n1) b"));
        $this->assertSame('<blockquote><p>a</p>' . "\n" . '<blockquote><p>b</p></blockquote></blockquote>' . "\n" . '<p>c</p>', $r->render("> a\n>\n> > b\nc"));
        $this->assertSame('<pre><code>open &amp; never closed</code></pre>', $r->render("```js\nopen & never closed"));
        $this->assertSame('<p>#### four</p>', $r->render('#### four'));
        $this->assertSame("<p>a</p>\n<p>b</p>", $r->render("a\r\n\r\nb"));
    }

    public function testInline(): void
    {
        $r = new MarkdownRenderer();
        $this->assertSame('<p><strong>b</strong> <em>i</em> <em>u</em> <del>s</del> <code>c *d*</code></p>', $r->render('**b** *i* _u_ ~~s~~ `c *d*`'));
        $this->assertSame('<p>snake_case_name and 2*3*4</p>', $r->render('snake_case_name and 2*3*4'));
        $this->assertSame('<p><a href="https://x.org/a?b=1&amp;c=2" rel="nofollow noopener noreferrer" target="_blank">the <strong>docs</strong></a></p>', $r->render('[the **docs**](https://x.org/a?b=1&c=2)'));
        $this->assertStringContainsString('<a href="mailto:a@b.c"', $r->render('[mail](mailto:a@b.c)'));
        $this->assertSame('<p>see <a href="https://example.org/x" rel="nofollow noopener noreferrer" target="_blank">https://example.org/x</a>.</p>', $r->render('see https://example.org/x.'));
        $this->assertSame('<p>[x](javascript:alert(1))</p>', $r->render('[x](javascript:alert(1))'));
    }

    public function testInputPastTheCapIsIgnored(): void
    {
        $r = new MarkdownRenderer();
        $this->assertSame('<p>' . str_repeat('é', MarkdownRenderer::MAX_LEN) . '</p>', $r->render(str_repeat('é', MarkdownRenderer::MAX_LEN) . '**cut**'));
    }

    public function testPlain(): void
    {
        $this->assertSame('Head bold and code &gt; none', (new MarkdownRenderer())->plain('  # Head **bold** and `code` &gt; none> '));
    }

    #[DataProvider('attacks')]
    public function testNoLiveHtml(string $src): void
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . (new MarkdownRenderer())->render($src) . '</div>', LIBXML_NONET);
        $allowed = ['html', 'body', 'div', 'p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'a', 'ul', 'ol', 'li', 'blockquote', 'h3', 'h4', 'h5', 'hr'];
        foreach ($doc->getElementsByTagName('*') as $el) {
            $this->assertContains($el->nodeName, $allowed, 'element ' . $el->nodeName);
            foreach ($el->attributes ?? [] as $at) {
                $this->assertContains($at->name, ['href', 'rel', 'target'], 'attribute ' . $at->name);
                if ($at->name === 'href') {
                    $this->assertMatchesRegularExpression('#^(https?://|mailto:)#i', $at->value);
                }
            }
        }
    }

    /** @return list<array{0:string}> */
    public static function attacks(): array
    {
        return [
            ['<script>alert(1)</script>'], ['<img src=x onerror=alert(1)>'], ['[x](javascript:alert(1))'], ['[x](JAVASCRIPT:alert(1))'],
            ['[x](data:text/html;base64,AAAA)'], ['[x](https://a.org/" onmouseover="alert(1))'], ["`\x01 0 \x01` **<b>**"],
            ['<a href="javascript:alert(1)">x</a>'], ['**<svg onload=alert(1)>**'], ['https://x.org/"onmouseover=alert(1)'],
            ["> <script>x</script>\n> [x](javascript:y)"], ["```\n<iframe>\n```"],
        ];
    }
}
