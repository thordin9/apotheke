<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Catalog\Items\Attachments;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Media\ImageStore;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Generator;
use RuntimeException;
use ZipArchive;

/**
 * The portable backup zip (legacy src/backup.php, backup_create()):
 *
 *   backup.json            {"format":"apotheke-backup","version":1,"created_at":…,"app":…,"driver":…,
 *                           "schema":[…],"tables":{"<table>":{"columns":[…],"rows":[[…],…]},…},"settings":{…}}
 *   images/, thumbs/       every served photo the data names
 *   originals/index.json   served name → kept camera file, and those files
 *   files/                 every attachment the data names
 *
 * backup.json has legacy's bytes, but it is written row by row to a file in
 * DATA_DIR/tmp (SQLite steps through one statement, PostgreSQL fetches from
 * a cursor in a read-only snapshot), and the zip adds every file by its path,
 * so memory stays the same for any size of catalog. The zip itself is a file
 * that the caller streams or keeps.
 */
final class Backups
{
    public const FORMAT = 'apotheke-backup';

    public const VERSION = 1;

    /** Rows per fetch from the PostgreSQL cursor. */
    public const BATCH = 500;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    private const CURSOR = 'apotheke_backup';

    /** Integer columns on PostgreSQL (its driver reads every value as text; legacy's PDO reads these as numbers). */
    private const PG_INTEGERS = ['smallint', 'integer', 'bigint'];

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SettingsService $settings,
        private readonly string $dataDir,
        private readonly string $originalsDir,
    ) {
    }

    /**
     * Tables in foreign-key order (parents first). Restore deletes in reverse.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return ['locations', 'categories', 'tags', 'items', 'item_photos', 'item_tags', 'item_attachments', 'app_settings', 'activity', 'saved_filters', 'stocktakes', 'stocktake_lines'];
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public function originalsDir(): string
    {
        return $this->originalsDir;
    }

    public function driver(): string
    {
        return $this->db->DBDriver === 'Postgre' ? 'pgsql' : 'sqlite';
    }

    public function tableExists(string $table): bool
    {
        $sql = $this->driver() === 'pgsql'
            ? 'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?'
            : "SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = ?";

        return (int)(Writes::rows($this->db, $sql, [$table])[0]['n'] ?? 0) > 0;
    }

    /** @return list<string> the table's columns in their order */
    public function columns(string $table): array
    {
        return array_keys($this->columnTypes($table));
    }

    /**
     * Row counts of the catalog tables there are (legacy backup_counts()).
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $out = [];
        foreach (self::tables() as $t) {
            if ($this->tableExists($t)) {
                $out[$t] = (int)(Writes::rows($this->db, "SELECT COUNT(*) AS n FROM $t")[0]['n'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * "12 items, 3 locations, …, 4 photos" (legacy backup_summary_text()).
     *
     * @param array<string,int> $counts
     */
    public static function summaryText(array $counts, int $photos): string
    {
        $labels = ['items' => 'items', 'locations' => 'locations', 'categories' => 'categories', 'tags' => 'tags', 'activity' => 'history entries'];
        $parts = [];
        foreach ($labels as $t => $l) {
            if (isset($counts[$t])) {
                $parts[] = $counts[$t] . ' ' . $l;
            }
        }
        $parts[] = $photos . ' photos';

        return implode(', ', $parts);
    }

    /**
     * DATA_DIR/tmp, where an hour-old restore upload is removed (legacy
     * backup_tmp_dir()), and an hour-old entry a restore copied out and
     * could not remove (it was stopped).
     */
    public function tmpDir(): string
    {
        $d = $this->dataDir . '/tmp';
        if (!is_dir($d)) {
            @mkdir($d, 0770, true);
        }
        foreach (array_merge(glob($d . '/restore-*.zip') ?: [], glob($d . '/restore-*.part') ?: []) as $f) {
            if (@filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }

        return $d;
    }

    /**
     * Write a backup zip to $zipPath (legacy backup_create()).
     *
     * @return array{tables:array<string,int>,photos:int,missing:list<string>,bytes:int}
     */
    public function create(string $zipPath): array
    {
        // N-446: a folder that is not there is this message, not a server error, before any work.
        if (!is_dir(dirname($zipPath)) || !is_writable(dirname($zipPath))) {
            throw new RuntimeException("Cannot write $zipPath");
        }
        $json = $this->tmpDir() . '/backup-' . bin2hex(random_bytes(8)) . '.json';
        try {
            [$counts, $photos, $attachments] = $this->writeDump($json);
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot write $zipPath");
            }
            $zip->addFile($json, 'backup.json');
            $missing = [];
            $n = 0;
            foreach ($photos as $f) {
                $full = $this->dataDir . '/images/' . $f;
                if (!is_file($full)) {
                    $missing[] = $f;
                    continue;
                }
                $this->stored($zip, $full, 'images/' . $f);
                if (is_file($this->dataDir . '/thumbs/' . $f)) {
                    $this->stored($zip, $this->dataDir . '/thumbs/' . $f, 'thumbs/' . $f);
                }
                $n++;
            }
            $index = $this->originalIndex($photos);
            if ($index !== []) {
                $zip->addFromString('originals/index.json', json_encode($index, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                foreach ($index as $base) {
                    $full = $this->originalsDir . '/' . $base;
                    if (!is_file($full)) {
                        $missing[] = $base;
                        continue;
                    }
                    $this->stored($zip, $full, 'originals/' . $base);
                }
            }
            foreach ($attachments as $f) {
                $full = $this->dataDir . '/files/' . $f;
                if (!is_file($full)) {
                    $missing[] = $f;
                    continue;
                }
                $zip->addFile($full, 'files/' . $f);
            }
            // N-446: the zip holds the whole catalog, so only its owner may read it (0600).
            $umask = umask(0077);
            try {
                $closed = @$zip->close();
            } finally {
                umask($umask);
            }
            if (!$closed) {
                throw new RuntimeException("Cannot write $zipPath");
            }
        } finally {
            @unlink($json);
        }
        clearstatcache(true, $zipPath);

        return ['tables' => $counts, 'photos' => $n, 'missing' => $missing, 'bytes' => (int)filesize($zipPath)];
    }

    /** A JPEG (they do not compress) added without compression, as legacy does. */
    private function stored(ZipArchive $zip, string $path, string $entry): void
    {
        $zip->addFile($path, $entry);
        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
    }

    /**
     * backup.json, written to $path with the bytes of legacy's json_encode()
     * of backup_dump(). Also collects the photo and attachment names the
     * rows refer to (legacy backup_photo_files() and backup_attachment_files()).
     *
     * @return array{0:array<string,int>,1:list<string>,2:list<string>}
     */
    private function writeDump(string $path): array
    {
        $out = @fopen($path, 'xb');
        if ($out === false) {
            throw new RuntimeException('Cannot write the backup.');
        }
        try {
            $put = static function (string $s) use ($out): void {
                if (@fwrite($out, $s) !== strlen($s)) {   // a full disk is this message (N-452)
                    throw new RuntimeException('Cannot write the backup.');
                }
            };
            $schema = [];
            if ($this->tableExists('schema_migrations')) {   // legacy migrations_applied()
                foreach (Writes::rows($this->db, 'SELECT version FROM schema_migrations ORDER BY version') as $r) {
                    $schema[(string)$r['version']] = true;   // array_keys(), as legacy: "1000" would read as a number
                }
            }
            $put('{"format":' . self::enc(self::FORMAT) . ',"version":' . self::VERSION . ',"created_at":' . self::enc($this->clock->now())
                . ',"app":"Apotheke","driver":' . self::enc($this->driver()) . ',"schema":' . self::enc(array_keys($schema)) . ',"tables":');
            $counts = $photos = $attachments = [];
            $first = true;
            foreach (self::tables() as $t) {
                if (!$this->tableExists($t)) {
                    continue;
                }
                $types = $this->columnTypes($t);
                $cols = array_keys($types);
                $put(($first ? '{' : ',') . self::enc($t) . ':{"columns":' . self::enc($cols) . ',"rows":[');
                $first = false;
                $watch = array_filter(['item_photos' => 'file', 'items' => 'image', 'item_attachments' => 'file'], static fn (string $c, string $table): bool => $table === $t && in_array($c, $cols, true), ARRAY_FILTER_USE_BOTH);
                $at = $watch === [] ? null : array_search($watch[$t], $cols, true);
                $n = 0;
                foreach ($this->rows($t, $types) as $row) {
                    $put(($n === 0 ? '' : ',') . self::enc($row));
                    $n++;
                    if ($at !== null && is_string($v = $row[$at] ?? null)) {
                        if ($t === 'item_attachments') {
                            if (Attachments::validName($v)) {
                                $attachments[$v] = true;
                            }
                        } elseif (ImageStore::validName($v)) {
                            $photos[$v] = true;
                        }
                    }
                }
                $put(']}');
                $counts[$t] = $n;
            }
            $put($first ? '[]' : '}');   // legacy json_encode() of no tables at all: []
            $put(',"settings":' . self::enc($this->settings->export()['settings']) . '}');
        } finally {
            fclose($out);
        }
        ksort($photos, SORT_STRING);
        ksort($attachments, SORT_STRING);

        return [$counts, array_map('strval', array_keys($photos)), array_map('strval', array_keys($attachments))];
    }

    /**
     * Every row of $table in legacy's order (by id, else its first two
     * columns), with legacy PDO's types: numbers for integer columns.
     *
     * @param array<string,string> $types column => type
     * @return Generator<int, list<mixed>>
     */
    private function rows(string $table, array $types): Generator
    {
        $cols = array_keys($types);
        $order = in_array('id', $cols, true) ? 'id' : implode(', ', array_slice($cols, 0, 2));
        $sql = 'SELECT ' . implode(', ', $cols) . " FROM $table ORDER BY $order";
        if ($this->driver() !== 'pgsql') {
            $result = $this->db->query($sql);
            if (!$result instanceof BaseResult) {
                throw new WriteFailed('Database read failed');
            }
            while (is_array($r = $result->getUnbufferedRow('array'))) {
                yield array_values($r);
            }

            return;
        }
        $ints = [];
        foreach (array_values($types) as $i => $type) {
            if (in_array($type, self::PG_INTEGERS, true)) {
                $ints[] = $i;
            }
        }
        // A read-only snapshot for the cursor; nothing is written.
        if (!$this->db->transBegin()) {
            throw new WriteFailed('Database read failed');
        }
        try {
            Writes::check($this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'), 'backup snapshot');
            Writes::check($this->db->query('DECLARE ' . self::CURSOR . ' NO SCROLL CURSOR FOR ' . $sql), 'backup cursor');
            while (($rows = Writes::rows($this->db, 'FETCH FORWARD ' . self::BATCH . ' FROM ' . self::CURSOR)) !== []) {
                foreach ($rows as $r) {
                    $r = array_values($r);
                    foreach ($ints as $i) {
                        if ($r[$i] !== null) {
                            $r[$i] = (int)$r[$i];
                        }
                    }
                    yield $r;
                }
            }
        } finally {
            $this->db->transRollback();
        }
    }

    /** @return array<string,string> column => its type, in the table's order (legacy backup_columns()) */
    private function columnTypes(string $table): array
    {
        if ($this->driver() === 'pgsql') {
            $rows = Writes::rows($this->db, 'SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', [$table]);
            $out = [];
            foreach ($rows as $r) {
                $out[(string)$r['column_name']] = (string)$r['data_type'];
            }

            return $out;
        }
        if (preg_match('/^[a-z_]+$/D', $table) !== 1) {
            return [];
        }
        $out = [];
        foreach (Writes::rows($this->db, 'PRAGMA table_info(' . $table . ')') as $r) {
            $out[(string)$r['name']] = strtolower((string)$r['type']);
        }

        return $out;
    }

    /**
     * Served photo name → the kept camera file's basename, for photos that
     * still have one (legacy backup_original_index()).
     *
     * @param list<string> $photos
     * @return array<string,string>
     */
    private function originalIndex(array $photos): array
    {
        $index = [];
        foreach ($photos as $f) {
            $map = $this->originalsDir . '/map/' . substr($f, 0, 32);
            if (!is_file($map)) {
                continue;
            }
            $base = trim((string)@file_get_contents($map));
            if (Restorer::originalNameOk($base) && is_file($this->originalsDir . '/' . $base)) {
                $index[$f] = $base;
            }
        }
        ksort($index, SORT_STRING);

        return $index;
    }

    private static function enc(mixed $v): string
    {
        return json_encode($v, self::JSON);
    }
}
