<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\StreamedResponse;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * StreamedResponse (L-370): a copy of the controller's response that writes
 * its chunks when sent, passing the output buffer on every FLUSH bytes.
 */
final class StreamedResponseTest extends CIUnitTestCase
{
    public function testItCopiesTheResponseAndWritesTheChunksInOrder(): void
    {
        $base = new Response(config(App::class));
        $base->setStatusCode(201)->setHeader('Content-Type', 'text/csv; charset=utf-8')->setHeader('X-One', 'a')
            ->addHeader('X-One', 'b')->setCookie(new Cookie('c', 'v'));
        $r = StreamedResponse::from($base, config(App::class), ['x', 'yz']);
        $this->assertSame(201, $r->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $r->getHeaderLine('Content-Type'));
        $one = $r->header('X-One');
        $this->assertIsArray($one);
        $this->assertSame(['a', 'b'], array_map(static fn ($h): string => $h->getValueLine(), $one));
        $this->assertSame($base->getHeaderLine('Cache-Control'), $r->getHeaderLine('Cache-Control'));
        $this->assertTrue($r->hasCookie('c', 'v'));
        $this->assertSame('', (string)$r->getBody(), 'after-filters see no body');

        $r->setBody('echoed;');
        ob_start();
        ob_start();
        $r->sendBody();
        $this->assertSame('', ob_get_clean());
        $this->assertSame('echoed;xyz', ob_get_clean());
    }

    public function testTheOutputBufferIsPassedOnAsItFills(): void
    {
        $chunk = str_repeat('c', 20000);
        $seen = [];
        $chunks = (static function () use ($chunk, &$seen): \Generator {
            for ($i = 0; $i < 10; $i++) {
                $seen[] = (int)ob_get_length();
                yield $chunk;
            }
        })();
        $r = StreamedResponse::from(new Response(config(App::class)), config(App::class), $chunks);
        ob_start();          // what reaches the client
        ob_start();          // Config/Events' pre_system buffer
        $r->sendBody();
        $left = (string)ob_get_clean();
        $sent = (string)ob_get_clean();
        $this->assertSame('', $left, 'the last chunk is flushed too');
        $this->assertSame(str_repeat($chunk, 10), $sent);
        $this->assertLessThan(StreamedResponse::FLUSH, max($seen));
        $this->assertSame([0, 20000, 40000, 60000, 0, 20000, 40000, 60000, 0, 20000], $seen);
    }
}
