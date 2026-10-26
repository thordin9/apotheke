<?php

declare(strict_types=1);

namespace Apotheke\Labels;

use InvalidArgumentException;

/*
 * Minimal QR Code encoder (byte mode, versions 1-40, ECC L/M/Q/H), pure PHP,
 * no dependencies: legacy src/qr-encode.php, unchanged, so the matrix and the
 * SVG are byte-identical (tests/Unit/Ci4LabelsQrParityTest.php). The
 * algorithm follows ISO/IEC 18004 as laid out in Project Nayuki's
 * MIT-licensed reference implementation
 * (https://www.nayuki.io/page/qr-code-generator-library).
 *
 * Load this class only after QrJit::guard() (function JIT miscompiles the
 * finder loops; see QrJit).
 */
final class QrCode
{
    private const ECC_CODEWORDS = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    private const ECC_BLOCKS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];
    private const FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    public readonly int $version;
    public readonly int $size;
    public readonly int $mask;
    /** @var array<int,array<int,bool>> [y][x] */
    private array $modules = [];
    /** @var array<int,array<int,bool>> [y][x]: function patterns, never masked */
    private array $isFunc = [];

    public function __construct(string $data, private string $ecl = 'M')
    {
        if (!isset(self::FORMAT_BITS[$ecl])) throw new InvalidArgumentException('ECC level must be L, M, Q or H');
        $len = strlen($data);
        for ($ver = 1; ; $ver++) {
            if ($ver > 40) throw new InvalidArgumentException('Data too long for a QR code');
            $capBits = self::dataCodewords($ver, $ecl) * 8;
            $used = 4 + ($ver <= 9 ? 8 : 16) + 8 * $len;
            if ($used <= $capBits) break;
        }
        $this->version = $ver;
        $this->size = $ver * 4 + 17;

        // bit stream: mode 0100 (byte), count, data, terminator, padding
        $bits = [];
        $push = static function (int $val, int $n) use (&$bits): void { for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $push(4, 4);
        $push($len, $ver <= 9 ? 8 : 16);
        for ($i = 0; $i < $len; $i++) $push(ord($data[$i]), 8);
        $push(0, min(4, $capBits - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capBits; $pad ^= 0xEC ^ 0x11) $push($pad, 8);
        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) $codewords[] = (int)bindec(implode('', $byte));

        $all = $this->addEccAndInterleave($codewords);
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunc = array_fill(0, $this->size, $row);
        $this->drawFunctionPatterns();
        $this->drawCodewords($all);

        $best = 0; $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $this->applyMask($m);
            $this->drawFormatBits($m);
            $p = $this->penalty();
            if ($p < $bestPenalty) { $best = $m; $bestPenalty = $p; }
            $this->applyMask($m);   // XOR again = undo
        }
        $this->mask = $best;
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    public function get(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size && $this->modules[$y][$x];
    }

    /** Scalable SVG (black on white, with the standard 4-module quiet zone). */
    public function svg(int $border = 4, string $title = ''): string
    {
        $n = $this->size + 2 * $border;
        $d = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) $d .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
            }
        }
        return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" shape-rendering="crispEdges" role="img"'
            . ($title !== '' ? ' aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '') . '>'
            . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
    }

    /* ---------------------------------------------------------------- internals */

    private static function rawModules(int $ver): int
    {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $na = intdiv($ver, 7) + 2;
            $r -= (25 * $na - 10) * $na - 55;
            if ($ver >= 7) $r -= 36;
        }
        return $r;
    }

    private static function dataCodewords(int $ver, string $ecl): int
    {
        return intdiv(self::rawModules($ver), 8) - self::ECC_CODEWORDS[$ecl][$ver] * self::ECC_BLOCKS[$ecl][$ver];
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $ver = $this->version;
        $numBlocks = self::ECC_BLOCKS[$this->ecl][$ver];
        $eccLen = self::ECC_CODEWORDS[$this->ecl][$ver];
        $raw = intdiv(self::rawModules($ver), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $div = self::rsDivisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $n = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $n);
            $k += $n;
            $ecc = self::rsRemainder($dat, $div);
            if ($i < $numShort) $dat[] = 0;
            $blocks[] = array_merge($dat, $ecc);
        }
        $out = [];
        $blen = count($blocks[0]);
        for ($i = 0; $i < $blen; $i++) {
            foreach ($blocks as $j => $b) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) $out[] = $b[$i];
            }
        }
        return $out;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) $r[$j] ^= $r[$j + 1];
            }
            $root = self::gfMul($root, 0x02);
        }
        return $r;
    }

    /**
     * @param list<int> $data
     * @param list<int> $div
     * @return list<int>
     */
    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $c) $r[$i] ^= self::gfMul($c, $factor);
        }
        return $r;
    }

    private function setFunc(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunc[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->setFunc(6, $i, $i % 2 === 0);
            $this->setFunc($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $xx = $cx + $dx; $yy = $cy + $dy;
                    if ($xx < 0 || $yy < 0 || $xx >= $s || $yy >= $s) continue;
                    $dist = max(abs($dx), abs($dy));
                    $this->setFunc($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) $this->setFunc($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
        $this->drawFormatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFunc($a, $b, $bit);
                $this->setFunc($b, $a, $bit);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) return [];
        $na = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $na * 3 + 5, $na * 4 - 4) * 2;
        $r = [];
        for ($i = 0, $pos = $this->size - 7; $i < $na - 1; $i++, $pos -= $step) array_unshift($r, $pos);
        array_unshift($r, 6);
        return $r;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS[$this->ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) $this->setFunc(8, $i, $bit($i));
        $this->setFunc(8, 7, $bit(6));
        $this->setFunc(8, 8, $bit(7));
        $this->setFunc(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->setFunc(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $this->setFunc($s - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->setFunc(8, $s - 15 + $i, $bit($i));
        $this->setFunc(8, $s - 8, true);
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $s = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $up = (($right + 1) & 2) === 0;
                    $y = $up ? $s - 1 - $vert : $vert;
                    if (!$this->isFunc[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $m): void
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($this->isFunc[$y][$x]) continue;
                $inv = match ($m) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($inv) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    /** Penalty (rules 1, 2 and 4 of the standard; rule 3 omitted – any mask decodes, this only picks a good-looking one). */
    private function penalty(): int
    {
        $s = $this->size; $p = 0; $dark = 0;
        for ($a = 0; $a < $s; $a++) {
            $runR = 1; $runC = 1;
            for ($b = 1; $b < $s; $b++) {
                if ($this->modules[$a][$b] === $this->modules[$a][$b - 1]) { if (++$runR === 5) $p += 3; elseif ($runR > 5) $p++; } else $runR = 1;
                if ($this->modules[$b][$a] === $this->modules[$b - 1][$a]) { if (++$runC === 5) $p += 3; elseif ($runC > 5) $p++; } else $runC = 1;
            }
        }
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($this->modules[$y][$x]) $dark++;
                if ($x + 1 < $s && $y + 1 < $s) {
                    $c = $this->modules[$y][$x];
                    if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) $p += 3;
                }
            }
        }
        $total = $s * $s;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $p + max(0, $k) * 10;
    }
}
