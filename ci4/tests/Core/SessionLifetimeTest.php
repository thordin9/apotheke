<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\SessionLifetime;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestDatabase;

/** SEC-N9: the session lifetime follows session_days like legacy session_lifetime(). */
final class SessionLifetimeTest extends CIUnitTestCase
{
    /** @return iterable<string,array{0:mixed,1:int}> */
    public static function days(): iterable
    {
        yield 'default 30' => [30, 30];
        yield 'one week' => [7, 7];
        yield 'minimum' => [1, 1];
        yield 'maximum' => [365, 365];
        yield 'zero means 30' => [0, 30];
        yield 'above the range' => [400, 365];
        yield 'below the range' => [-5, 1];
        yield 'numeric string' => ['10', 10];
        yield 'null means 30' => [null, 30];
        yield 'array means 30' => [[3], 30];
    }

    #[DataProvider('days')]
    public function testDaysAreClampedAsInLegacy(mixed $days, int $expected): void
    {
        $this->assertSame(86400 * $expected, (new SessionLifetime(static fn (): mixed => $days))->seconds());
    }

    public function testAnUnreadableSettingFallsBackTo30Days(): void
    {
        $lifetime = new SessionLifetime(static function (): mixed {
            throw new RuntimeException('database unavailable');
        });
        $this->assertSame(2592000, $lifetime->seconds());
    }

    public function testTheSettingIsReadOncePerInstance(): void
    {
        $reads = 0;
        $lifetime = new SessionLifetime(static function () use (&$reads): mixed {
            $reads++;

            return 14;
        });
        $this->assertSame(14 * 86400, $lifetime->seconds());
        $this->assertSame(14 * 86400, $lifetime->seconds());
        $this->assertSame(1, $reads);
    }

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    #[DataProvider('drivers')]
    public function testServicesReadSessionDaysThroughSettingsService(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $settings = new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null);
            Services::injectMock('settings', $settings);
            $this->assertSame(30 * 86400, Services::sessionLifetime(false)->seconds(), 'default');
            $this->assertSame([], $settings->save(['session_days' => 7]));
            $this->assertSame(7 * 86400, Services::sessionLifetime(false)->seconds());
            $this->assertSame(Services::sessionLifetime(), Services::sessionLifetime(), 'one instance per request');
        } finally {
            Services::reset();
            $database->drop();
        }
    }
}
