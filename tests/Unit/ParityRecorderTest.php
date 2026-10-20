<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/parity/recorder.php';

/** Feature 12 PR 3: differential recorder (install, healthz, settings) and the quirk allowlist. */
final class ParityRecorderTest extends TestCase
{
    public function testAllowlistHoldsOnlyTheFourApprovedQuirks(): void
    {
        $entries = parity_allowlist_load();
        $this->assertSame(['N-2', 'QA L-2', 'N-4', 'I-3'], array_column($entries, 'id'));
        $this->assertSame(
            'identical to legacy (original never sent)',
            $entries[0]['expect']
        );
        foreach ($entries as $entry) {
            $this->assertStringContainsString('identical to legacy', $entry['expect']);
            $this->assertNotSame('', $entry['behaviour']);
        }
    }

    public function testAllowlistRejectsAFileThatDropsAQuirk(): void
    {
        $path = $this->allowlistCopy();
        $text = (string)file_get_contents($path);
        file_put_contents($path, preg_replace('/^  - id: I-3\n(?:    .*\n)*/m', '', $text));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('I-3');
        parity_allowlist_load($path);
    }

    public function testNormalisedHtmlDropsCsrfTokens(): void
    {
        $html = '<meta name="csrf" content="aa"><input name="csrf" value="bb">';
        $html = str_replace('aa', str_repeat('a', 64), $html);
        $html = str_replace('bb', str_repeat('b', 64), $html);
        $other = str_replace(str_repeat('a', 64), str_repeat('c', 64), $html);
        $other = str_replace(str_repeat('b', 64), str_repeat('d', 64), $other);
        $this->assertSame(parity_normalize_html($html), parity_normalize_html($other));
        $this->assertStringContainsString('content="CSRF"', parity_normalize_html($html));
    }

    public function testDiffIsEmptyOnlyWhenSnapshotsMatch(): void
    {
        $snap = ['install' => ['status' => 200], 'healthz' => ['body' => 'ok']];
        $this->assertSame([], parity_diff($snap, $snap));
        $changed = $snap;
        $changed['healthz']['body'] = 'no';
        $this->assertArrayHasKey('healthz.body', parity_diff($snap, $changed));
    }

    public function testLegacyPairAgreesOnInstallHealthzAndSettings(): void
    {
        $diff = parity_legacy_pair();
        $this->assertSame([], $diff, json_encode($diff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function testCorePairAgreesOnHealthAndOriginals(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $diff = parity_core_pair();
        $this->assertSame([], $diff, json_encode($diff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function allowlistCopy(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'allow');
        $this->assertNotFalse($path);
        copy(dirname(__DIR__) . '/parity/allowlist.yml', $path);
        return $path;
    }
}
