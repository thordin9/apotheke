<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\JsonReader;
use CodeIgniter\Test\CIUnitTestCase;
use JsonException;
use LengthException;

/**
 * The streaming reader restore uses for backup.json: on every input,
 * whole documents read through it agree with json_decode(…, true, 64), in
 * what they accept and in the values, at any buffer size; a value over the
 * memory guard is refused before it is built.
 */
final class JsonReaderTest extends CIUnitTestCase
{
    /** Read a whole document through the reader, containers streamed, scalars decoded. */
    private static function walk(JsonReader $r): mixed
    {
        $kind = $r->kind();
        if ($kind !== '{' && $kind !== '[') {
            return $r->value();
        }
        $out = [];
        foreach ($r->entries() as $k) {
            $out[$k] = self::walk($r);
        }

        return $out;
    }

    /** @return array{0:bool,1:mixed} accepted, value; by the reader with $chunk-byte reads, $mode: walk, value or skip */
    private static function read(string $json, int $chunk, string $mode): array
    {
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, $json);
        rewind($fh);
        try {
            $r = new JsonReader($fh, 64, $chunk);
            $v = match ($mode) {
                'walk' => self::walk($r),
                'value' => $r->value(),
                default => $r->skip(),
            };
            $r->finish();

            return [true, $mode === 'skip' ? null : $v];
        } catch (JsonException) {
            return [false, null];
        } finally {
            fclose($fh);
        }
    }

    public function testItAgreesWithJsonDecode(): void
    {
        $cases = ['', ' ', '1', '-0', '01', '1.', '.5', '1e5', '1E+2', '-1.5e-3', '12345678901234567890', '"a"', '"\u00e9\n\"\\\\"', "\"\x01\"", "\"\xff\"", '"\ud800"',
            'true', 'false', 'null', 'tru', 'nul', 'True', '[]', '{}', '[1,]', '[,1]', '[1 2]', '{"a":1,}', '{"a" 1}', '{1:2}', '{"a":1,"a":2}', '{"a":[1,{"b":null}],"c":"d"}',
            '[1]x', "[1]\n\t ", "\xef\xbb\xbf[1]", '[[[]]]', '{"":0}', '["\\/"]', '[1,[2,[3]]]', '"unterminated', '[', ']', '{"a":', '{"a"}', '[true false]',
            str_repeat('[', 63) . str_repeat(']', 63), str_repeat('[', 64) . str_repeat(']', 64), '{"x":' . str_repeat('[', 62) . str_repeat(']', 62) . '}', '{"x":' . str_repeat('[', 63) . str_repeat(']', 63) . '}',
            '[' . str_repeat('"' . str_repeat('ab\\"', 50) . '",', 20) . '0]', "[1,\r\n2]", '[1,\f2]', '{"a":1}{', '[0x1]', '[Infinity]', '[-]', '["a\\x"]'];
        mt_srand(4421);
        $alphabet = ['[', ']', '{', '}', ',', ':', '"', '\\', 'a', '1', '-', '.', 'e', ' ', 'n', 'u', 'l', 't', 'r', 'f', "\xc3\xa9", '0'];
        for ($i = 0; $i < 1500; $i++) {
            $s = '';
            for ($n = mt_rand(1, 14); $n > 0; $n--) {
                $s .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $cases[] = $s;
        }
        // Valid documents, then each with one byte dropped.
        for ($i = 0; $i < 200; $i++) {
            $doc = (string)json_encode(self::randomValue(4));
            $cases[] = $doc;
            $at = mt_rand(0, strlen($doc) - 1);
            $cases[] = substr($doc, 0, $at) . substr($doc, $at + 1);
        }
        foreach ($cases as $json) {
            try {
                $expected = [true, json_decode($json, true, 64, JSON_THROW_ON_ERROR)];
            } catch (JsonException) {
                $expected = [false, null];
            }
            foreach ([1, 3, 7, 65536] as $chunk) {
                foreach (['walk', 'value', 'skip'] as $mode) {
                    $want = $mode === 'skip' ? [$expected[0], null] : $expected;
                    $this->assertSame($want, self::read($json, $chunk, $mode), json_encode($json) . " chunk $chunk $mode");
                }
            }
        }
    }

    public function testEntriesSkipWhatTheCallerLeaves(): void
    {
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, '{"a":[1,2,{"x":"y"}],"b":{"c":"d"},"e":5}');
        rewind($fh);
        $r = new JsonReader($fh, 64, 4);
        $seen = [];
        foreach ($r->entries() as $k) {
            $seen[] = $k;
            if ($k === 'e') {
                $this->assertSame(5, $r->value());
            }
        }
        $r->finish();
        $this->assertSame(['a', 'b', 'e'], $seen);
        fclose($fh);
    }

    public function testOffsetsLetAReaderStartAtAValue(): void
    {
        $json = '{"skip":[1,2,3], "here" : {"rows":[[1,"x"],[2,"y"]]}}';
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, $json);
        rewind($fh);
        $r = new JsonReader($fh, 64, 5);
        $at = null;
        foreach ($r->entries() as $k) {
            if ($k === 'here') {
                $at = $r->offset();
            }
            $r->skip();
        }
        $this->assertSame(strpos($json, '{"rows"'), $at);
        fseek($fh, (int)$at);
        $again = new JsonReader($fh, 63, 3, null, (int)$at);
        $this->assertSame(['rows' => [[1, 'x'], [2, 'y']]], $again->value());
        $this->assertSame(strlen($json) - 1, $again->offset());
        fclose($fh);
    }

    public function testTheMemoryGuardRefusesALargeValueBeforeItIsBuilt(): void
    {
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, '["' . str_repeat('x', 100_000) . '",[' . str_repeat('1,', 50_000) . '1]]');
        rewind($fh);
        $asked = [];
        $r = new JsonReader($fh, 64, 4096, static function (int $bytes) use (&$asked): bool {
            $asked[] = $bytes;

            return $bytes < 50_000;
        });
        $it = $r->entries();
        $it->current();
        try {
            $r->value();
            $this->fail('a 100 kB string was read');
        } catch (LengthException) {
            $this->assertLessThan(60_000, max($asked), 'refused while it was being read');
        }
        fclose($fh);

        // A small text that would decode to much more is refused on the estimate.
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, '[' . str_repeat('[1],', 5000) . '[1]]');
        rewind($fh);
        $r = new JsonReader($fh, 64, 4096, static fn (int $bytes): bool => $bytes < 100_000);
        try {
            $r->value();
            $this->fail('decoded');
        } catch (LengthException) {
            $this->addToAssertionCount(1);
        }
        fclose($fh);
    }

    private static function randomValue(int $depth): mixed
    {
        $pick = mt_rand(0, $depth > 0 ? 7 : 4);

        return match ($pick) {
            0 => mt_rand(-1000, 1000),
            1 => mt_rand(0, 1) === 1 ? mt_rand() / 7 : null,
            2 => ['', 'a', "é\n\"\\/", "\u{1F600}", str_repeat('z', mt_rand(0, 40))][mt_rand(0, 4)],
            3 => (bool)mt_rand(0, 1),
            4 => PHP_INT_MAX,
            5, 6 => array_map(static fn (): mixed => self::randomValue($depth - 1), range(0, mt_rand(0, 4))),
            default => array_combine(array_map(static fn (int $i): string => 'k' . $i . mt_rand(0, 9), range(0, $n = mt_rand(0, 3))), array_map(static fn (): mixed => self::randomValue($depth - 1), range(0, $n))),
        };
    }
}
