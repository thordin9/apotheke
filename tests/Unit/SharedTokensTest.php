<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * public/assets/app.css copies the shared custom properties from
 * frontend/src/styles/tokens.css. The self-hosted app does not serve that file.
 */
final class SharedTokensTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_LIGHT = [
        '--color-paper', '--color-ink', '--color-accent', '--color-header', '--color-header-ink',
        '--color-focus', '--space-xl', '--app-text-md', '--radius-card', '--section-gap', '--gutter', '--shadow-rest',
    ];

    /** @var list<string> */
    private const REQUIRED_DARK = [
        '--color-paper', '--color-ink', '--color-header', '--color-header-ink', '--brand-primary', '--shadow-rest',
    ];

    public function testSharedCustomPropertiesMatchTheTokenFile(): void
    {
        $tokens = $this->blocks($this->root() . '/frontend/src/styles/tokens.css');
        $app = $this->blocks($this->root() . '/public/assets/app.css');
        foreach (['light', 'dark', 'scheme'] as $mode) {
            $shared = array_intersect(array_keys($tokens[$mode]), array_keys($app[$mode]));
            $this->assertNotEmpty($shared, $mode);
            $drift = [];
            foreach ($shared as $name) {
                if ($tokens[$mode][$name] !== $app[$mode][$name]) {
                    $drift[] = $name;
                }
            }
            $this->assertSame([], $drift, $mode . ' drifted: ' . implode(', ', $drift));
            $required = $mode === 'light' ? self::REQUIRED_LIGHT : self::REQUIRED_DARK;
            foreach ($required as $name) {
                $this->assertArrayHasKey($name, $app[$mode], $mode . ' is missing ' . $name);
                $this->assertArrayHasKey($name, $tokens[$mode], $mode . ' tokens are missing ' . $name);
            }
        }
    }

    /** @return array{light: array<string, string>, dark: array<string, string>, scheme: array<string, string>} */
    private function blocks(string $path): array
    {
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', (string)file_get_contents($path)) ?? '';
        return [
            'light' => $this->declarations($this->block($css, '/(^|\})\s*:root\s*\{/')),
            'dark' => $this->declarations($this->block($css, '/:root\[data-theme="?dark"?\]\s*\{/')),
            'scheme' => $this->declarations($this->block($css, '/:root:not\(\[data-theme="?light"?\]\):not\(\[data-theme="?dark"?\]\)\s*\{/')),
        ];
    }

    private function block(string $css, string $pattern): string
    {
        if (!preg_match($pattern, $css, $match, PREG_OFFSET_CAPTURE)) {
            $this->fail('missing block ' . $pattern);
        }
        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $i = $start;
        $length = strlen($css);
        while ($depth > 0 && $i < $length) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
            }
            $i++;
        }

        return substr($css, $start, $i - $start - 1);
    }

    /** @return array<string, string> */
    private function declarations(string $body): array
    {
        $out = [];
        if (preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $out[$match[1]] = preg_replace('/\s+/', ' ', trim($match[2])) ?? trim($match[2]);
            }
        }

        return $out;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
