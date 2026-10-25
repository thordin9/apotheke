<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #101 N-74: every entry of CI4_UNPORTED (tests/visual/fixtures.ts) names a
 * spec that exists and a describe › title path in it (each describe in
 * order, then the test; N-85), so an entry for a renamed, split or removed
 * test does not linger; the hand-off lists each.
 */
final class Ci4E2eSkipListTest extends TestCase
{
    public function testEverySkipNamesARealTest(): void
    {
        $root = dirname(__DIR__, 2);
        $fixtures = (string)file_get_contents($root . '/tests/visual/fixtures.ts');
        $this->assertSame(1, preg_match('/export const CI4_UNPORTED[^{]*\{(.*?)\n\};/s', $fixtures, $m), 'CI4_UNPORTED in fixtures.ts');
        preg_match_all("/^\\s*'([a-z0-9-]+\\.spec\\.ts): ((?:[^'\\\\]|\\\\.)+)': '((?:[^'\\\\]|\\\\.)+)',\$/m", $m[1], $entries, PREG_SET_ORDER);
        $lines = array_filter(array_map('trim', explode("\n", trim($m[1]))), static fn (string $l): bool => $l !== '');
        $this->assertCount(count($lines), $entries, 'every line of CI4_UNPORTED is "<spec>: <describe> › <title>": "<page>"');
        $handoff = (string)file_get_contents($root . '/docs/HANDOFF.md');
        foreach ($entries as [, $file, $path]) {
            $spec = $root . '/tests/visual/' . $file;
            $this->assertFileExists($spec, $file);
            $src = (string)file_get_contents($spec);
            $parts = explode(' › ', stripslashes($path));
            $this->assertGreaterThanOrEqual(2, count($parts), $file . ': ' . $path . ' (describe › title)');
            $this->assertTrue(self::findsPath($src, $parts), "$file has no test.describe(…) › test(…) path \"$path\" in that order");
            $this->assertStringContainsString(end($parts), $handoff, 'docs/HANDOFF.md lists the skipped "' . end($parts) . '"');
        }
    }

    /** N-85: a path is found only with each describe in order and then the test, never another call or order. */
    public function testThePathMustBeInOrder(): void
    {
        $src = "test.describe('outer', () => {\n  test('first', async () => {});\n  test.describe('inner', () => {\n    test(\"second\", async () => {});\n  });\n});\ntest.describe('later', () => {});\n";
        $this->assertTrue(self::findsPath($src, ['outer', 'first']));
        $this->assertTrue(self::findsPath($src, ['outer', 'inner', 'second']));
        $this->assertFalse(self::findsPath($src, ['inner', 'outer', 'second']), 'describes out of order');
        $this->assertFalse(self::findsPath($src, ['later', 'first']), 'the test comes before the describe');
        $this->assertFalse(self::findsPath($src, ['outer', 'inner']), 'a describe is not a test');
        $this->assertFalse(self::findsPath($src, ['first', 'second']), 'a test is not a describe');
        $this->assertFalse(self::findsPath($src, ['outer', 'missing']));
    }

    /** @param list<string> $parts describe, …, title */
    private static function findsPath(string $src, array $parts): bool
    {
        $at = 0;
        foreach ($parts as $i => $part) {
            $call = $i < count($parts) - 1 ? 'test\.describe' : 'test';
            if (preg_match('/\b' . $call . '\(\s*([\'"`])' . preg_quote($part, '/') . '\1/', $src, $hit, PREG_OFFSET_CAPTURE, $at) !== 1) {
                return false;
            }
            $at = $hit[0][1] + 1;
        }

        return true;
    }
}
