<?php

declare(strict_types=1);

namespace Apotheke\ImportExport;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Text;

/**
 * CSV cells and files (legacy csv_protect(), csv_unprotect() and
 * import_parse()). Spreadsheets run cells that start with = + - @ TAB or CR
 * as formulas: an exported cell gets a leading apostrophe (shown as text,
 * never evaluated); a cell that already starts with apostrophes before such
 * a character gets one more, so unprotect() on import restores every value.
 */
final class CsvCells
{
    public const MAX_ROWS = 5000;

    private const FORMULA = "/^'*[=+\\-@\t\r]/";

    public static function protect(mixed $v): string
    {
        $s = is_scalar($v) ? (string)$v : '';

        return preg_match(self::FORMULA, $s) === 1 ? "'" . $s : $s;
    }

    public static function unprotect(string $s): string
    {
        return str_starts_with($s, "'") && preg_match(self::FORMULA, $s) === 1 ? substr($s, 1) : $s;
    }

    /**
     * CSV text as [header, rows, delimiter]: detects , ; or tab, strips a
     * BOM, reads Windows-1252 as such.
     *
     * @return array{0:list<string>,1:list<list<string>>,2:string}
     */
    public static function parse(string $csv): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = (string)mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $first = strtok($csv, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delim = (string)array_key_first($counts);
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not read the file.');
        }
        fwrite($fh, $csv);
        rewind($fh);
        $rows = [];
        try {
            while (($r = fgetcsv($fh, null, $delim, '"', '')) !== false) {
                if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) {
                    continue;
                }
                $rows[] = array_map(static fn (?string $v): string => self::unprotect((string)$v), $r);
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw new UserError('That file has more than ' . number_format(self::MAX_ROWS) . ' rows. Split it into smaller files.', 422);
                }
            }
        } finally {
            fclose($fh);
        }
        if (count($rows) < 2) {
            throw new UserError('The file needs a header row and at least one item.', 422);
        }
        $header = array_map(static fn (string $h): string => Text::cleanLine($h, 80), array_shift($rows));

        return [$header, $rows, $delim];
    }

    /**
     * One CSV line as legacy fputcsv(..., ',', '"', '\\') writes it.
     *
     * @param list<string> $cells
     */
    public static function line(array $cells): string
    {
        $fh = fopen('php://memory', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not write the file.');
        }
        fputcsv($fh, $cells, ',', '"', '\\');
        rewind($fh);
        $s = (string)stream_get_contents($fh);
        fclose($fh);

        return $s;
    }
}
