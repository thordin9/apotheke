<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

final class ExpiryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function day(int $offset): string
    {
        return (new DateTimeImmutable(local_today()))->modify("$offset days")->format('Y-m-d');
    }

    private function item(string $name, ?string $exp): int
    {
        return item_save(null, ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => '', 'expires_on' => $exp], null, false);
    }

    public function testStateAndDays(): void
    {
        $window = (int)cfg()['expiry_soon_days'];
        $this->assertSame('', expiry_state(['expires_on' => null]));
        $this->assertSame('expired', expiry_state(['expires_on' => $this->day(-1)]));
        $this->assertSame('soon', expiry_state(['expires_on' => $this->day(0)]));
        $this->assertSame('soon', expiry_state(['expires_on' => $this->day($window)]));
        $this->assertSame('', expiry_state(['expires_on' => $this->day($window + 1)]));
        $this->assertSame(-3, expiry_days($this->day(-3)));
        $this->assertSame(12, expiry_days($this->day(12)));
        $this->assertSame($this->day($window), expiry_soon_until());
    }

    public function testParse(): void
    {
        $this->assertNull(parse_expiry_date(' '));
        $this->assertSame('2031-01-31', parse_expiry_date('2031-01-31'), 'future dates are fine');
        foreach (['2024-02-30', '31.01.2031', '1899-12-31', '3000-01-01'] as $bad) {
            try {
                parse_expiry_date($bad);
                $this->fail("$bad accepted");
            } catch (UserError $e) {
                $this->assertStringContainsString('Expiry date', $e->getMessage());
            }
        }
    }

    public function testFiltersTotalsHistoryAndCopies(): void
    {
        $milk = $this->item('Milk', $this->day(-2));
        $this->item('Battery', $this->day(5));
        $this->item('TV', $this->day(900));
        $this->item('Spoon', null);
        $names = fn(string $v) => array_column(items_search('', ['loc' => '', 'cat' => '', 'exp' => $v], 'name', 1)['items'], 'name');
        $this->assertSame(['Milk'], $names('expired'));
        $this->assertSame(['Battery'], $names('soon'));
        $this->assertSame(['Battery', 'Milk', 'Spoon', 'TV'], $names(''));
        $t = stats_totals();
        $this->assertSame([1, 1], [$t['expiring'], $t['expired']]);
        $this->assertSame($this->day(-2), item_get($milk)['expires_on']);
        item_save(item_get($milk), ['expires_on' => $this->day(3)] + item_input(item_get($milk)), null, false);
        $last = activity_list($milk, 1, 1)[0];
        $this->assertSame([$this->day(-2), $this->day(3)], (is_array($last['details']) ? $last['details'] : json_decode((string)$last['details'], true))['changes']['expires_on'] ?? null);
        $this->assertSame($this->day(3), item_input(item_get($milk))['expires_on'], 'bulk/duplicate input keeps it');
        $copy = item_duplicate($milk, false);
        $this->assertSame($this->day(3), item_get($copy)['expires_on']);
        $this->assertSame('soon', filters_from(['exp' => 'soon'])['exp']);
        $this->assertSame('', filters_from(['exp' => 'whenever'])['exp']);
    }
}
