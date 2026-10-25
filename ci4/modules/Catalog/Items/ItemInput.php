<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Apotheke\Core\Exceptions\UserError;
use DateTimeImmutable;

/**
 * The item form's field rules (legacy parse_money(), money_input(),
 * parse_date_ymd(), parse_expiry_date(), parse_min_qty() and
 * barcode_clean()), with legacy's messages. Pure: "today" is passed in.
 */
final class ItemInput
{
    public const BARCODE_MAX = 64;

    /** "12.5", "1,234.50", "1.234,50", "€ 12" → cents; '' → null. */
    public static function money(string $s): ?int
    {
        $s = preg_replace('/[\p{Sc}\s]+/u', '', $s) ?? '';
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{1,3}(?:([.,])\d{3})*|\d+)(?:([.,])(\d{1,2}))?$/D', $s, $m) !== 1) {
            throw new UserError('Value must be a number like 49.90.');
        }
        [, $int, $group, $sep, $dec] = array_pad($m, 5, '');   // groups PHP leaves out when they did not match
        if ($group !== '' && $group === $sep) {
            throw new UserError('Value must be a number like 49.90.');
        }
        if ($group !== '' && $dec === '') {
            throw new UserError('Value is ambiguous – write it like 1234.50.');
        }
        $cents = (int)preg_replace('/\D/', '', $int) * 100 + ($dec !== '' ? (int)str_pad($dec, 2, '0') : 0);
        if ($cents > 100_000_000_000) {
            throw new UserError('That value is too large.');
        }

        return $cents;
    }

    /** The form's value for stored cents. */
    public static function moneyInput(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }

    /** A purchase date: valid, from 1900, not after tomorrow ($tomorrow is the app clock's date + 1 day). */
    public static function purchaseDate(string $s, string $tomorrow): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s || $s < '1900-01-01') {
            throw new UserError('Purchase date must be a valid date (YYYY-MM-DD).');
        }
        if ($s > $tomorrow) {
            throw new UserError('Purchase date cannot be in the future.');
        }

        return $s;
    }

    /** An expiry or warranty date, 1900-01-01 to 2999-12-31. */
    public static function expiryDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s || $s < '1900-01-01' || $s > '2999-12-31') {
            throw new UserError('Expiry date must be a valid date (YYYY-MM-DD).');
        }

        return $s;
    }

    /** The low-stock level: '' → null (no alert), else 0…1,000,000. */
    public static function minQty(string $s): ?int
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $v = filter_var($s, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if ($v === false) {
            throw new UserError('“Running low at” must be a whole number (0 or more).');
        }

        return $v;
    }

    /** No whitespace or control characters, at most 64 characters. */
    public static function barcode(string $code): string
    {
        return mb_substr((string)preg_replace('/[\s\x00-\x1F\x7F]+/u', '', $code), 0, self::BARCODE_MAX);
    }
}
