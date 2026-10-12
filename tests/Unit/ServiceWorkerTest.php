<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ServiceWorkerTest extends TestCase
{
    public function testTemplateIsFilledAndVersioned(): void
    {
        $js = service_worker_js();
        $this->assertMatchesRegularExpression("/const VERSION = '[a-f0-9]{12}';/", $js);
        $this->assertStringNotContainsString('__PRECACHE__', $js);
        preg_match('/const PRECACHE = (\[.*\]);/', $js, $m);
        $list = json_decode($m[1] ?? 'null', true);
        $this->assertSame(sw_precache(), $list);
        $this->assertContains('/offline', $list);
        $this->assertContains(asset('app.js'), $list);
        $this->assertSame($js, service_worker_js(), 'deterministic');
    }

    public function testNeverCachesSensitiveRoutes(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 2) . '/src/sw.js');
        preg_match('#const NEVER = /(.*)/;#', $js, $m);
        $re = '#' . str_replace('#', '\#', $m[1]) . '#';
        foreach (['/backup', '/backup/restore', '/backup.zip', '/export.csv', '/login', '/logout', '/sw.js', '/bulk'] as $p) $this->assertMatchesRegularExpression($re, $p);
        foreach (['/', '/item/3', '/lent', '/backups-are-fine-name'] as $p) $this->assertDoesNotMatchRegularExpression($re, $p);
        $this->assertStringContainsString("if (req.method !== 'GET') return;", $js);
    }
}
