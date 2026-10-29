<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchNormalizationTest extends TestCase
{
    public static function folds(): array
    {
        return [
            'latin case'   => ['Cordless DRILL', 'cordless drill'],
            'latin accents'=> ['Crème Brûlée', 'creme brulee'],
            'greek tonos'  => ['Κουτί Ένα', 'κουτι ενα'],
            'final sigma'  => ['Πρίζες', 'πριζεσ'],
            'diaeresis'    => ['Ϊ ϋ ΰ', 'ι υ υ'],
            'eszett'       => ['Straße', 'strasse'],
        ];
    }

    #[DataProvider('folds')]
    public function testFold(string $in, string $out): void
    {
        $this->assertSame($out, fold($in));
    }

    public function testNatkeyOrdersNumbersNaturally(): void
    {
        $names = ['box 10', 'Box 2', 'box 1023', 'box 1', 'attic', 'Κουτί 3'];
        usort($names, fn($a, $b) => strcmp(natkey($a), natkey($b)) ?: strcmp($a, $b));
        $this->assertSame(['attic', 'box 1', 'Box 2', 'box 10', 'box 1023', 'Κουτί 3'], $names);
    }

    public function testNatkeyIsCaseAndAccentInsensitive(): void
    {
        $this->assertSame(natkey('Κουτί 5'), natkey('κουτι 5'));
        $this->assertSame(natkey(' BOX 7 '), natkey('box 7'));
    }

    public function testLikeEscape(): void
    {
        $this->assertSame('50!% off!_now!!', like_escape('50% off_now!'));
    }

    public function testCleanLine(): void
    {
        $this->assertSame('box 1023', clean_line("  box \t\n 1023 ", 120));
        $this->assertSame('abc', clean_line('abcdef', 3));
    }

    public function testFilterValue(): void
    {
        $this->assertSame('12', filter_value('12'));
        $this->assertSame('none', filter_value('none'));
        $this->assertSame('', filter_value('1 OR 1=1'));
        $this->assertSame('', filter_value(['x']));
    }
}
