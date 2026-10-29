<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Closure;
use Generator;
use JsonException;
use LengthException;
use RuntimeException;

/**
 * One JSON document read from a stream a piece at a time, so a backup.json
 * larger than PHP's memory can be checked and restored. entries() walks a
 * container, value() builds one value with json_decode() and skip() checks
 * one without keeping it. Values are exactly what json_decode($whole, true,
 * $maxDepth) gives, and whatever json_decode() would refuse (syntax, depth,
 * bad UTF-8 or escapes, text after the end) throws JsonException.
 *
 * $fits guards memory: it is asked for the bytes a value is about to take
 * (while its text grows, and before it is decoded), and a "no" throws
 * LengthException before the memory is used.
 */
final class JsonReader
{
    private const WS = " \t\n\r";

    private const SCALAR = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+-.';

    private string $buf = '';

    private int $pos = 0;

    /** Stream offset of $buf[0]. */
    private int $base;

    private bool $eof = false;

    /** Containers open around the read position. */
    private int $depth = 0;

    /**
     * @param resource $stream read from where it stands
     * @param int $maxDepth json_decode()'s depth for the value at the stream's position
     * @param (Closure(int):bool)|null $fits may $bytes more be taken?
     * @param int $offset the stream's position, for offset()
     */
    public function __construct(
        private readonly mixed $stream,
        private readonly int $maxDepth = 64,
        private readonly int $chunk = 65536,
        private readonly ?Closure $fits = null,
        int $offset = 0,
    ) {
        $this->base = $offset;
    }

    /** The first byte of the next value: '{', '[', or another ('' at the end). */
    public function kind(): string
    {
        $this->ws();

        return $this->buf[$this->pos] ?? '';
    }

    /** Stream offset of the next value (after white space). */
    public function offset(): int
    {
        $this->ws();

        return $this->base + $this->pos;
    }

    /**
     * Walk the container at the read position: yields each key (a list's
     * index; an object's key as a PHP array key). Before the next key the
     * caller reads the value with value(), skip() or entries(); a value it
     * leaves is skipped.
     *
     * @return Generator<int, int|string>
     */
    public function entries(): Generator
    {
        $open = $this->kind();
        if ($open !== '{' && $open !== '[') {
            throw self::bad();
        }
        $this->enter(0);
        $this->pos++;
        $object = $open === '{';
        $close = $object ? '}' : ']';
        if ($this->kind() === $close) {
            $this->pos++;
            $this->depth--;

            return;
        }
        $i = 0;
        while (true) {
            if ($object) {
                if ($this->kind() !== '"') {
                    throw self::bad();
                }
                $raw = '';
                $this->rawString($raw);
                $key = array_key_first([(string)json_decode($raw, false, 1, JSON_THROW_ON_ERROR) => true]);
                if ($this->kind() !== ':') {
                    throw self::bad();
                }
                $this->pos++;
            } else {
                $key = $i++;
            }
            $at = $this->offset();
            if (in_array($this->kind(), ['', ',', ']', '}', ':'], true)) {
                throw self::bad();
            }
            yield $key;
            if ($this->offset() === $at) {
                $this->skip();
            }
            $c = $this->kind();
            if ($c === ',') {
                $this->pos++;
                continue;
            }
            if ($c !== $close) {
                throw self::bad();
            }
            $this->pos++;
            $this->depth--;

            return;
        }
    }

    /** The next value, as json_decode() gives it. */
    public function value(): mixed
    {
        $raw = $this->raw();
        $this->guard(self::decodedSize($raw));

        return json_decode($raw, true, $this->maxDepth - $this->depth, JSON_THROW_ON_ERROR);
    }

    /** Check the next value and pass it; containers are walked, not built. */
    public function skip(): void
    {
        $c = $this->kind();
        if ($c !== '{' && $c !== '[') {
            $this->value();

            return;
        }
        foreach ($this->entries() as $_) {
            $this->skip();
        }
    }

    /** Only white space may follow. */
    public function finish(): void
    {
        if ($this->kind() !== '') {
            throw self::bad();
        }
    }

    /**
     * About what json_decode() of $json takes, on the high side: the text's
     * bytes, plus a value's zval and string header (48) for every comma, a
     * list's table (200) for every bracket and a map's (450) for every
     * brace. Measured on PHP 8.4, from rows of one integer to long notes.
     */
    public static function decodedSize(string $json): int
    {
        return strlen($json) + 48 * substr_count($json, ',') + 200 * substr_count($json, '[') + 450 * substr_count($json, '{');
    }

    /** The next value's text, whole. */
    private function raw(): string
    {
        $c = $this->kind();
        $out = '';
        if ($c === '"') {
            $this->rawString($out);

            return $out;
        }
        if ($c === '{' || $c === '[') {
            $this->rawContainer($out);

            return $out;
        }
        while (true) {
            $n = strspn($this->buf, self::SCALAR, $this->pos);
            $out .= substr($this->buf, $this->pos, $n);
            $this->pos += $n;
            $this->guard(strlen($out));
            if ($this->pos < strlen($this->buf) || !$this->fill()) {
                break;
            }
        }
        if ($out === '') {
            throw self::bad();
        }

        return $out;
    }

    /** Append the string at the read position, quotes included, to $out. */
    private function rawString(string &$out): void
    {
        $start = $this->pos;
        $j = $this->pos + 1;
        while (true) {
            $j += strcspn($this->buf, '"\\', $j);
            if ($j < strlen($this->buf) && $this->buf[$j] === '"') {
                $out .= substr($this->buf, $start, $j + 1 - $start);
                $this->pos = $j + 1;

                return;
            }
            if ($j + 1 < strlen($this->buf)) {   // a backslash and the character it escapes
                $j += 2;
                continue;
            }
            // The buffer ends inside the string (or between a backslash and its character).
            $out .= substr($this->buf, $start, $j - $start);
            $this->guard(strlen($out));
            $this->pos = $j;
            if (!$this->fill()) {
                throw self::bad();
            }
            $start = $j = $this->pos;
        }
    }

    /** Append the container at the read position to $out. */
    private function rawContainer(string &$out): void
    {
        $start = $j = $this->pos;
        $level = 0;
        while (true) {
            $j += strcspn($this->buf, '"[]{}', $j);
            if ($j >= strlen($this->buf)) {
                $out .= substr($this->buf, $start, $j - $start);
                $this->guard(strlen($out));
                $this->pos = $j;
                if (!$this->fill()) {
                    throw self::bad();
                }
                $start = $j = $this->pos;
                continue;
            }
            $c = $this->buf[$j];
            if ($c === '"') {
                $out .= substr($this->buf, $start, $j - $start);
                $this->pos = $j;
                $this->rawString($out);
                $this->guard(strlen($out));
                $start = $j = $this->pos;
                continue;
            }
            $j++;
            if ($c === '[' || $c === '{') {
                $this->enter(++$level);
                continue;
            }
            if (--$level === 0) {
                $out .= substr($this->buf, $start, $j - $start);
                $this->pos = $j;

                return;
            }
        }
    }

    /** A container opens $inner levels below the read position (0: at it). */
    private function enter(int $inner): void
    {
        if ($inner === 0) {
            $this->depth++;
        }
        if ($this->depth + $inner >= $this->maxDepth) {
            throw new JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        }
    }

    private function guard(int $bytes): void
    {
        if ($this->fits !== null && !($this->fits)($bytes)) {
            throw new LengthException('A value is too large for the memory left.');
        }
    }

    private function ws(): void
    {
        while (true) {
            $this->pos += strspn($this->buf, self::WS, $this->pos);
            if ($this->pos < strlen($this->buf) || !$this->fill()) {
                return;
            }
        }
    }

    /** Read more; false at the end of the stream. */
    private function fill(): bool
    {
        if ($this->eof) {
            return false;
        }
        $data = @fread($this->stream, $this->chunk);
        if ($data === false) {
            throw new RuntimeException('The backup could not be read.');
        }
        if ($data === '') {
            $this->eof = feof($this->stream);
            if (!$this->eof) {
                throw new RuntimeException('The backup could not be read.');
            }

            return false;
        }
        if ($this->pos > 0) {
            $this->buf = substr($this->buf, $this->pos);
            $this->base += $this->pos;
            $this->pos = 0;
        }
        $this->buf .= $data;

        return true;
    }

    private static function bad(): JsonException
    {
        return new JsonException('Syntax error', JSON_ERROR_SYNTAX);
    }
}
