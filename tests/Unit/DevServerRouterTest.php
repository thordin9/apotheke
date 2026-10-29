<?php

declare(strict_types=1);

use Apotheke\DevServer\FrontRouter;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/Core/Http/OriginalsGuard.php';
require_once dirname(__DIR__, 2) . '/modules/DevServer/StaticFile.php';
require_once dirname(__DIR__, 2) . '/modules/DevServer/FrontRouter.php';

final class DevServerRouterTest extends TestCase
{
    public function testOriginalsMatchAnySpellingAndNotAPrefix(): void
    {
        $router = new FrontRouter();
        $doc = sys_get_temp_dir();
        foreach (['/originals', '/originals/', '/Originals/x', '/foo/originals', '/foo/ORIGINALS/a.jpg'] as $path) {
            $this->assertSame('originals', $router->decide($doc, $path), $path);
        }
        $this->assertSame('app', $router->decide($doc, '/originals.bak'));
        $this->assertSame('app', $router->decide($doc, '/original'));
    }

    public function testStaticFilesRejectPhpTraversalAndTheRoot(): void
    {
        $doc = sys_get_temp_dir() . '/apo-static-' . bin2hex(random_bytes(3));
        mkdir($doc . '/assets', 0775, true);
        file_put_contents($doc . '/assets/ping.txt', 'pong');
        file_put_contents($doc . '/index.php', '<?php');
        $router = new FrontRouter();
        $this->assertSame('static', $router->decide($doc, '/assets/ping.txt'));
        $this->assertSame('app', $router->decide($doc, '/'));
        $this->assertSame('app', $router->decide($doc, '/index.php'));
        $this->assertSame('app', $router->decide($doc, '/assets/../index.php'));
        $outside = dirname($doc) . '/secret.txt';
        file_put_contents($outside, 'nope');
        $this->assertSame('app', $router->decide($doc, '/../secret.txt'));
        $this->assertSame('app', $router->decide($doc, "/assets/ping.txt\0"), 'a NUL byte is never a file');
        @unlink($outside);
    }
}
