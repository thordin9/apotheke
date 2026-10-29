<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\SessionIdle;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/** Main #84: the idle limit reads the stored session_idle_hours row and clamps it like legacy session_idle_seconds(). */
final class SessionIdleTest extends CIUnitTestCase
{
    public function testTheStoredNumberIsClampedLikeLegacy(): void
    {
        $cases = [
            [168, null], [168, 0], [168, '0'], [168, 'abc'], [168, true], [168, [1]],
            [1, 1], [1, -5], [1, '-5'], [24, 24], [24, '24'], [24, 24.9], [8760, 8760], [8760, 99999],
        ];
        foreach ($cases as [$hours, $raw]) {
            $this->assertSame(3600 * $hours, (new SessionIdle(static fn (): mixed => $raw))->seconds(), var_export($raw, true));
        }
    }

    public function testAnUnreadableSettingIsTheDefault(): void
    {
        $idle = new SessionIdle(static function (): never {
            throw new RuntimeException('database down');
        });
        $this->assertSame(168 * 3600, $idle->seconds());
    }

    public function testEachCallReadsAgain(): void
    {
        $value = 2;
        $idle = new SessionIdle(static function () use (&$value): int {
            return $value;
        });
        $this->assertSame(7200, $idle->seconds());
        $value = 5;
        $this->assertSame(18000, $idle->seconds());
    }
}
