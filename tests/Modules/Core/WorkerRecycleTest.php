<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\WorkerRecycle;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** L-1979: the FrankenPHP worker loop recycles itself after APOTHEKE_WORKER_MAX_REQUESTS requests. */
final class WorkerRecycleTest extends CIUnitTestCase
{
    /** @return iterable<string, array{string|false|null, int}> */
    public static function limits(): iterable
    {
        yield 'unset (getenv false)' => [false, WorkerRecycle::DEFAULT];
        yield 'null' => [null, WorkerRecycle::DEFAULT];
        yield 'empty' => ['', WorkerRecycle::DEFAULT];
        yield 'spaces around' => ['  250 ', 250];
        yield 'zero is no cap' => ['0', 0];
        yield 'one' => ['1', 1];
        yield 'negative falls back' => ['-5', WorkerRecycle::DEFAULT];
        yield 'not a number falls back' => ['lots', WorkerRecycle::DEFAULT];
        yield 'decimal falls back' => ['2.5', WorkerRecycle::DEFAULT];
        yield 'too long falls back' => ['1234567890', WorkerRecycle::DEFAULT];
    }

    #[DataProvider('limits')]
    public function testLimit(string|false|null $value, int $expected): void
    {
        $this->assertSame($expected, WorkerRecycle::limit($value));
    }

    public function testTheDefaultIsACap(): void
    {
        $this->assertGreaterThan(0, WorkerRecycle::DEFAULT);
    }

    public function testKeepServingStopsAtTheLimit(): void
    {
        $served = 0;
        while (WorkerRecycle::keepServing($served, 3)) {
            $served++;
            $this->assertLessThanOrEqual(3, $served);
        }
        $this->assertSame(3, $served);
        $this->assertFalse(WorkerRecycle::keepServing(4, 3));
    }

    public function testZeroNeverStops(): void
    {
        $this->assertTrue(WorkerRecycle::keepServing(0, 0));
        $this->assertTrue(WorkerRecycle::keepServing(PHP_INT_MAX, 0));
    }

    /** The worker script uses both, before frankenphp_handle_request(), and logs one line per boot. */
    public function testTheWorkerLoopUsesTheCap(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/public/frankenphp-worker.php');
        $this->assertStringContainsString("WorkerRecycle::limit(getenv('APOTHEKE_WORKER_MAX_REQUESTS'))", $script);
        $this->assertStringContainsString(
            'for ($served = 0; WorkerRecycle::keepServing($served, $maxRequests) && frankenphp_handle_request($handler); $served++) {',
            $script,
        );
        $this->assertStringContainsString("'apotheke: PHP worker started (recycles after '", $script);
    }
}
