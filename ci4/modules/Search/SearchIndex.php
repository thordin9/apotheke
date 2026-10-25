<?php

declare(strict_types=1);

namespace Apotheke\Search;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Text;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;

/**
 * Full-text search (legacy src/search.php): search_docs (migration 0019)
 * holds one folded document per item, rebuilt when a cheap fingerprint of the
 * catalog changes. Engines: SQLite FTS5, PostgreSQL tsvector (+ pg_trgm for
 * typos), else substring matching. Same statements and ranking as legacy, so
 * both databases give the same hits. Engine probes are cached per instance
 * (one request).
 */
final class SearchIndex
{
    public const MAX_HITS = 5000;

    private ?string $engine = null;

    private ?bool $trgm = null;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db, private readonly TaxonomyRepository $taxonomy, private readonly bool $typos)
    {
    }

    /** 'fts5', 'pg' or 'like' (legacy search_engine()). */
    public function engine(): string
    {
        if ($this->engine !== null) {
            return $this->engine;
        }
        if ($this->db->DBDriver === 'Postgre') {
            return $this->engine = 'pg';
        }

        return $this->engine = $this->db->query('SELECT rowid FROM search_fts LIMIT 0') === false ? 'like' : 'fts5';
    }

    /** Whether pg_trgm is usable; probed inside a savepoint when a transaction is open (legacy search_trgm()). */
    public function trgm(): bool
    {
        if ($this->trgm !== null) {
            return $this->trgm;
        }
        if ($this->db->DBDriver !== 'Postgre') {
            return $this->trgm = false;
        }
        $tx = (int)$this->db->transDepth > 0;
        if ($tx) {
            Writes::check($this->db->query('SAVEPOINT trgm_probe'), 'trgm probe');
        }
        $ok = $this->db->query("SELECT word_similarity('a', 'a')") !== false;
        if ($tx) {
            Writes::check($this->db->query($ok ? 'RELEASE SAVEPOINT trgm_probe' : 'ROLLBACK TO SAVEPOINT trgm_probe'), 'trgm probe');
        }

        return $this->trgm = $ok;
    }

    /** Cheap fingerprint of everything that goes into the documents. */
    public function fingerprint(): string
    {
        $h = hash_init('md5');
        foreach ([
            'SELECT id, updated_at, location_id, category_id, lent_to, barcode, serial FROM items ORDER BY id',
            'SELECT id, name, parent_id FROM locations ORDER BY id',
            'SELECT id, name FROM categories ORDER BY id',
            'SELECT id, name FROM tags ORDER BY id',
            'SELECT item_id, tag_id FROM item_tags ORDER BY item_id, tag_id',
        ] as $sql) {
            foreach ($this->rows($sql) as $r) {
                hash_update($h, implode("\x1f", array_map(static fn (mixed $v): string => (string)$v, array_values($r))) . "\x1e");
            }
            hash_update($h, "\x1d");
        }

        return hash_final($h);
    }

    /** Rebuild the documents when the catalog changed since the last build (a checked transaction). */
    public function ensure(): void
    {
        $fp = $this->fingerprint();
        if (($this->rows("SELECT v FROM search_meta WHERE k = 'fingerprint'")[0]['v'] ?? null) === $fp) {
            return;
        }
        Writes::transaction($this->db, function () use ($fp): void {
            $this->rebuild();
            Writes::check($this->db->query("DELETE FROM search_meta WHERE k = 'fingerprint'"), 'search fingerprint');
            Writes::check($this->db->query("INSERT INTO search_meta (k, v) VALUES ('fingerprint', ?)", [$fp]), 'search fingerprint');
        });
    }

    /** @return array<int,array{name:string,tags:string,other:string}> item id => original texts */
    public function documents(): array
    {
        $tags = [];
        foreach ($this->rows('SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY t.sort_key, t.id') as $r) {
            $tags[(int)$r['item_id']][] = (string)$r['name'];
        }
        $cats = [];
        foreach ($this->rows('SELECT id, name FROM categories') as $r) {
            $cats[(int)$r['id']] = (string)$r['name'];
        }
        $locs = $this->taxonomy->locationsById();
        $docs = [];
        foreach ($this->rows('SELECT id, name, notes, serial, barcode, lent_to, location_id, category_id FROM items ORDER BY id') as $r) {
            $lines = [];
            foreach ([
                'Location' => $r['location_id'] !== null ? (string)($locs[(int)$r['location_id']]['path'] ?? '') : '',
                'Category' => $r['category_id'] !== null ? ($cats[(int)$r['category_id']] ?? '') : '',
                'Notes' => trim((string)$r['notes']),
                'Serial' => (string)($r['serial'] ?? ''),
                'Barcode' => (string)($r['barcode'] ?? ''),
                'Lent to' => (string)($r['lent_to'] ?? ''),
            ] as $label => $v) {
                if ($v !== '') {
                    $lines[] = $label . ': ' . preg_replace('/\s+/u', ' ', $v);
                }
            }
            $docs[(int)$r['id']] = ['name' => (string)$r['name'], 'tags' => implode(', ', $tags[(int)$r['id']] ?? []), 'other' => implode("\n", $lines)];
        }

        return $docs;
    }

    private function rebuild(): void
    {
        $fts = $this->engine() === 'fts5';
        Writes::check($this->db->query('DELETE FROM search_docs'), 'search rebuild');
        if ($fts) {
            Writes::check($this->db->query('DELETE FROM search_fts'), 'search rebuild');
        }
        foreach ($this->documents() as $id => $d) {
            $f = [Text::fold($d['name']), Text::fold($d['tags']), Text::fold((string)preg_replace('/^[A-Za-z ]+: /m', '', $d['other']))];
            Writes::check($this->db->query('INSERT INTO search_docs (item_id, name_f, tags_f, other_f, other) VALUES (?,?,?,?,?)', [$id, $f[0], $f[1], $f[2], $d['other']]), 'search rebuild');
            if ($fts) {
                Writes::check($this->db->query('INSERT INTO search_fts (rowid, name, tags, other) VALUES (?,?,?,?)', [$id, ...$f]), 'search rebuild');
            }
        }
    }

    /** @return list<string> words of a query, folded */
    public static function tokens(string $q): array
    {
        return array_values(array_unique(preg_split('/\s+/u', Text::fold(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** @return list<string> letter/digit runs of a token */
    public static function parts(string $tok): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $tok, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<int> */
    private function idsLike(string $tok): array
    {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $tok) . '%';

        return $this->ids("SELECT item_id FROM search_docs WHERE name_f LIKE ? ESCAPE '!' OR tags_f LIKE ? ESCAPE '!' OR other_f LIKE ? ESCAPE '!'", [$like, $like, $like]);
    }

    /** @return list<int> */
    private function idsPrefix(string $tok): array
    {
        $parts = self::parts($tok);
        // only plain words; "<b>" or "mkt-dhp482" are matched as substrings
        if (count($parts) !== 1 || $parts[0] !== $tok) {
            return [];
        }
        if ($this->engine() === 'fts5') {
            return $this->ids('SELECT rowid AS item_id FROM search_fts WHERE search_fts MATCH ?', [implode(' AND ', array_map(self::ftsTerm(...), $parts))]);
        }
        if ($this->engine() === 'pg') {
            return $this->ids("SELECT item_id FROM search_docs WHERE tsv @@ to_tsquery('simple', ?)", [implode(' & ', array_map(self::tsTerm(...), $parts))]);
        }

        return [];
    }

    private static function ftsTerm(string $p): string
    {
        return '"' . str_replace('"', '""', $p) . '"*';
    }

    private static function tsTerm(string $p): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $p) . "':*";
    }

    private static function typoBudget(string $tok): int
    {
        $n = mb_strlen($tok);

        return $n < 4 ? 0 : ($n < 8 ? 1 : 2);
    }

    /** @return list<string> close words from the index vocabulary, best first */
    private function fuzzyTerms(string $tok): array
    {
        $max = self::typoBudget($tok);
        if ($max === 0 || preg_match('/^[\p{L}\p{N}]+$/u', $tok) !== 1) {
            return [];
        }
        $n = mb_strlen($tok);
        if ($this->engine() === 'fts5') {
            $vocab = array_column($this->rows('SELECT term FROM search_fts_v WHERE length(term) BETWEEN CAST(? AS INTEGER) AND CAST(? AS INTEGER)', [max(1, $n - $max), $n + $max + 6]), 'term');
        } else {
            $vocab = [];
            foreach ($this->rows('SELECT name_f, tags_f, other_f FROM search_docs') as $r) {
                foreach (self::parts(implode(' ', array_map(static fn (mixed $v): string => (string)$v, array_values($r)))) as $w) {
                    $vocab[$w] = true;
                }
            }
            $vocab = array_keys($vocab);
        }
        $best = [];
        foreach ($vocab as $w) {
            $w = (string)$w;
            $m = mb_strlen($w);
            if ($m < $n - $max) {
                continue;
            }
            // whole word, or the word's start (so "screwdrivr" finds "screwdrivers")
            $d = min(self::lev($tok, $w), $m > $n ? self::lev($tok, mb_substr($w, 0, $n)) + 1 : PHP_INT_MAX);
            if ($d <= $max) {
                $best[$w] = $d;
            }
        }
        asort($best);

        return array_map('strval', array_slice(array_keys($best), 0, 5));
    }

    /** Levenshtein distance on characters. */
    public static function lev(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $prev = range(0, count($b));
        foreach ($a as $i => $ca) {
            $cur = [$i + 1];
            foreach ($b as $j => $cb) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
            }
            $prev = $cur;
        }

        return $prev[count($b)];
    }

    /**
     * Match a query (legacy search_match()): ids best first, the folded terms
     * to highlight, and typo => the word it was read as.
     *
     * @return array{ids: list<int>, terms: list<string>, fixed: array<string,string>}
     */
    public function match(string $q): array
    {
        $tokens = self::tokens($q);
        if ($tokens === []) {
            return ['ids' => [], 'terms' => [], 'fixed' => []];
        }
        $this->ensure();
        $sets = [];
        $terms = [];
        $fixed = [];
        $weak = [];
        foreach ($tokens as $tok) {
            $ids = array_flip($this->idsLike($tok));
            foreach ($this->idsPrefix($tok) as $id) {
                $ids[$id] = true;
            }
            $terms[] = $tok;
            if ($ids === [] && $this->typos) {
                if ($this->trgm()) {
                    foreach ($this->ids("SELECT item_id FROM search_docs WHERE ? <% (name_f || ' ' || tags_f || ' ' || other_f)", [$tok]) as $id) {
                        $ids[$id] = true;
                    }
                }
                $close = $this->fuzzyTerms($tok);
                foreach ($close as $w) {
                    foreach ($this->idsLike($w) as $id) {
                        $ids[$id] = true;
                    }
                }
                if ($ids !== [] && $close !== []) {
                    $fixed[$tok] = $close[0];
                    $weak[$tok] = $close;
                    array_push($terms, ...$close);
                }
            }
            $sets[] = $ids;
        }
        $hit = array_shift($sets);
        foreach ($sets as $s) {
            $hit = array_intersect_key($hit, $s);
        }
        if ($hit === []) {
            return ['ids' => [], 'terms' => $terms, 'fixed' => $fixed];
        }
        $ids = array_slice(array_keys($hit), 0, self::MAX_HITS);
        // rank: field weights in PHP (same on both databases) plus the engine's own score
        $score = array_fill_keys($ids, 0.0);
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->rows('SELECT item_id, name_f, tags_f, other_f FROM search_docs WHERE item_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                [$n, $t, $o] = [(string)$r['name_f'], (string)$r['tags_f'], (string)$r['other_f']];
                $s = 0.0;
                foreach ($tokens as $tok) {
                    $k = isset($weak[$tok]) ? 0.5 : 1.0;
                    $best = 0.0;
                    foreach ($weak[$tok] ?? [$tok] as $w) {
                        foreach ([[$n, 10], [$t, 5], [$o, 2]] as [$field, $wt]) {
                            $pos = mb_strpos($field, $w);
                            if ($pos === false) {
                                continue;
                            }
                            $start = $pos === 0 || preg_match('/[\p{L}\p{N}]/u', mb_substr($field, $pos - 1, 1)) !== 1;
                            $best = max($best, $wt * ($start ? 1.5 : 1.0) * ($field === $n && $n === $w ? 2 : 1));
                        }
                    }
                    $s += $best * $k;
                }
                $score[(int)$r['item_id']] = $s;
            }
        }
        $parts = array_merge(...array_map(self::parts(...), $terms));
        if ($this->engine() === 'fts5' && $parts !== []) {
            foreach ($this->rows('SELECT rowid AS id, bm25(search_fts, 10.0, 5.0, 1.0) AS r FROM search_fts WHERE search_fts MATCH ?', [implode(' OR ', array_map(self::ftsTerm(...), $parts))]) as $r) {
                if (isset($score[(int)$r['id']])) {
                    $score[(int)$r['id']] += min(5.0, -(float)$r['r']);
                }
            }
        } elseif ($this->engine() === 'pg' && $parts !== []) {
            $tq = implode(' | ', array_map(self::tsTerm(...), $parts));
            foreach ($this->rows("SELECT item_id AS id, ts_rank(tsv, to_tsquery('simple', ?)) AS r FROM search_docs WHERE tsv @@ to_tsquery('simple', ?)", [$tq, $tq]) as $r) {
                if (isset($score[(int)$r['id']])) {
                    $score[(int)$r['id']] += min(5.0, (float)$r['r'] * 10);
                }
            }
        }
        // stable: score desc, then id desc (newer first)
        uksort($score, static fn (int $a, int $b): int => [$score[$b], $b] <=> [$score[$a], $a]);

        return ['ids' => array_keys($score), 'terms' => array_values(array_unique($terms)), 'fixed' => $fixed];
    }

    /**
     * Escape $text and wrap every match of the folded $terms in <mark>.
     *
     * @param list<string> $terms
     */
    public static function highlight(string $text, array $terms): string
    {
        $chars = mb_str_split($text);
        if ($chars === [] || $terms === []) {
            return self::e($text);
        }
        $folded = '';
        $map = [];
        foreach ($chars as $i => $c) {
            foreach (mb_str_split(Text::fold($c)) as $fc) {
                $folded .= $fc;
                $map[] = $i;
            }
        }
        $on = array_fill(0, count($chars), false);
        $fl = mb_strlen($folded);
        foreach ($terms as $t) {
            $tl = mb_strlen($t);
            if ($tl === 0) {
                continue;
            }
            $off = 0;
            while (($p = mb_strpos($folded, $t, $off)) !== false) {
                for ($k = $p; $k < min($fl, $p + $tl); $k++) {
                    $on[$map[$k]] = true;
                }
                $off = $p + 1;
            }
        }
        $o = '';
        $open = false;
        foreach ($chars as $i => $c) {
            if ($on[$i] && !$open) {
                $o .= '<mark>';
                $open = true;
            }
            if (!$on[$i] && $open) {
                $o .= '</mark>';
                $open = false;
            }
            $o .= self::e($c);
        }

        return $o . ($open ? '</mark>' : '');
    }

    /**
     * A short highlighted excerpt of the first matching line (tags or other
     * fields); '' when the name matched.
     *
     * @param list<string> $terms
     */
    public function snippet(int $id, array $terms): string
    {
        $r = $this->rows('SELECT name_f, tags_f, other FROM search_docs WHERE item_id = ?', [$id])[0] ?? null;
        if ($r === null) {
            return '';
        }
        $lines = [];
        $tags = implode(', ', array_column($this->rows('SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = ? ORDER BY t.sort_key, t.id', [$id]), 'name'));
        if ($tags !== '') {
            $lines[] = 'Tags: ' . $tags;
        }
        foreach (explode("\n", (string)$r['other']) as $l) {
            if ($l !== '') {
                $lines[] = $l;
            }
        }
        foreach ($terms as $t) {
            if (str_contains((string)$r['name_f'], $t)) {
                continue;
            }
            foreach ($lines as $l) {
                [$label, $val] = array_pad(explode(': ', $l, 2), 2, '');
                $p = mb_strpos(Text::fold($val), $t);
                if ($p === false) {
                    continue;
                }
                $from = max(0, $p - 24);
                $cut = mb_substr($val, $from, 72);

                return '<span class="snip-k">' . self::e($label) . '</span> ' . ($from > 0 ? '…' : '') . self::highlight($cut, $terms) . ($from + 72 < mb_strlen($val) ? '…' : '');
            }
        }

        return '';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param list<int|string|null> $binds
     * @return list<int>
     */
    private function ids(string $sql, array $binds): array
    {
        return array_map(static fn (array $r): int => (int)$r['item_id'], $this->rows($sql, $binds));
    }

    /**
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db, $sql, $binds);
    }
}
