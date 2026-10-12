<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CopyHelpersTest extends TestCase
{
    public function testItemNoun(): void
    {
        $this->assertSame('0 items', item_noun(0));
        $this->assertSame('1 item', item_noun(1));
        $this->assertSame('8 items', item_noun(8));
    }

    public function testStatsBrief(): void
    {
        $this->assertSame('1 item · 1 pc', stats_brief(1, 1));
        $this->assertSame('8 items · 19 pcs', stats_brief(8, 19));
        $this->assertSame('0 items · 0 pcs', stats_brief(0, 0));
    }
}
