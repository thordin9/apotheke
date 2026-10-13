<?php
declare(strict_types=1);

/*
 * Full-text search across name, notes, tags, custom fields (serial, barcode, lent to), category and the location path.
 *
 * search_docs (migration 0019) holds one document per item, folded (lower case, accents removed) for matching plus the
 * original text for snippets. It is rebuilt automatically whenever a cheap fingerprint of the catalog changes, so every
 * write path (forms, bulk actions, renames, imports, restores, the CLI) is covered without hooks.
 *
 * Engines: SQLite FTS5 (prefix matching, bm25 ranking, its vocabulary for typo tolerance); PostgreSQL tsvector with
 * ts_rank and pg_trgm word similarity for typos. Without FTS5 / pg_trgm the search falls back to substring matching
 * (and, for typos, a vocabulary built in PHP). Every word must match; substring matches always count, so "rill" still
 * finds "drill". Results are ranked: name > tags > everything else, word starts above substrings, typo matches lower.
 */

const SEARCH_MAX_HITS = 5000;

/** 'fts5', 'pg' or 'like' (the fallback). */
function search_engine(?string $force = null): string
{
    static $e = null;
    if ($force !== null) $e = $force === '' ? null : $force;   // tests: simulate a missing extension ('' = probe again)
    if ($e !== null) return $e;
    $pdo = db();
    if (db_driver() === 'pgsql') return $e = 'pg';
    try { $pdo->query('SELECT rowid FROM search_fts LIMIT 0'); return $e = 'fts5'; } catch (PDOException) { return $e = 'like'; }
}

/** Is pg_trgm usable (PostgreSQL only)? Probed once, inside a savepoint so a failure can't abort a transaction. */
function search_trgm(?bool $force = null, bool $reset = false): bool
{
    static $ok = null;
    if ($reset) $ok = null;
    if ($force !== null) $ok = $force;
    if ($ok !== null) return $ok;
    if (db_driver() !== 'pgsql') return $ok = false;
    $pdo = db();
    $tx = $pdo->inTransaction();
    try {
        if ($tx) $pdo->exec('SAVEPOINT trgm_probe');
        $pdo->query("SELECT word_similarity('a', 'a')")->fetchColumn();
        if ($tx) $pdo->exec('RELEASE SAVEPOINT trgm_probe');
        return $ok = true;
    } catch (PDOException) {
        if ($tx) $pdo->exec('ROLLBACK TO SAVEPOINT trgm_probe');
        return $ok = false;
    }
}

/** Human description for the Control Panel (Settings → System). */
function search_engine_label(): string
{
    return match (search_engine()) {
        'fts5' => 'SQLite FTS5 (ranked, prefix + substring, typo tolerance)',
        'pg' => 'PostgreSQL full-text (tsvector, ranked)' . (search_trgm() ? ' + pg_trgm (typo tolerance)' : ' – pg_trgm not installed: typos via the built-in word list'),
        default => 'Substring search (FTS5 not available in this SQLite build)',
    };
}

/* ---------- index ---------- */

/** Cheap fingerprint of everything that goes into the documents. */
function search_fingerprint(): string
{
    $pdo = db();
    $h = hash_init('md5');
    foreach ([
        'SELECT id, updated_at, location_id, category_id, lent_to, barcode, serial FROM items ORDER BY id',
        'SELECT id, name, parent_id FROM locations ORDER BY id',
        'SELECT id, name FROM categories ORDER BY id',
        'SELECT id, name FROM tags ORDER BY id',
        'SELECT item_id, tag_id FROM item_tags ORDER BY item_id, tag_id',
    ] as $sql) {
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) hash_update($h, implode("\x1f", array_map('strval', $r)) . "\x1e");
        hash_update($h, "\x1d");
    }
    return hash_final($h);
}

/** Rebuild the documents when the catalog changed since the last build. */
function search_ensure(): void
{
    $fp = search_fingerprint();
    $pdo = db();
    $st = $pdo->prepare("SELECT v FROM search_meta WHERE k = 'fingerprint'");
    $st->execute();
    if ($st->fetchColumn() === $fp) return;
    db_tx(function (PDO $pdo) use ($fp) {
        search_rebuild();
        $pdo->prepare("DELETE FROM search_meta WHERE k = 'fingerprint'")->execute();
        $pdo->prepare("INSERT INTO search_meta (k, v) VALUES ('fingerprint', ?)")->execute([$fp]);
    });
}

/** @return array<int,array{name:string,tags:string,other:string}> item id => original texts */
function search_documents(): array
{
    $pdo = db();
    $tags = [];
    foreach ($pdo->query('SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY t.sort_key, t.id')->fetchAll() as $r) {
        $tags[(int)$r['item_id']][] = (string)$r['name'];
    }
    $cats = [];
    foreach ($pdo->query('SELECT id, name FROM categories')->fetchAll() as $r) $cats[(int)$r['id']] = (string)$r['name'];
    $locs = locations_by_id();
    $docs = [];
    foreach ($pdo->query('SELECT id, name, notes, serial, barcode, lent_to, location_id, category_id FROM items ORDER BY id')->fetchAll() as $r) {
        $lines = [];
        foreach ([
            'Location' => $r['location_id'] !== null ? (string)($locs[(int)$r['location_id']]['path'] ?? '') : '',
            'Category' => $r['category_id'] !== null ? ($cats[(int)$r['category_id']] ?? '') : '',
            'Notes' => trim((string)$r['notes']),
            'Serial' => (string)($r['serial'] ?? ''),
            'Barcode' => (string)($r['barcode'] ?? ''),
            'Lent to' => (string)($r['lent_to'] ?? ''),
        ] as $label => $v) {
            if ($v !== '') $lines[] = $label . ': ' . preg_replace('/\s+/u', ' ', $v);
        }
        $docs[(int)$r['id']] = ['name' => (string)$r['name'], 'tags' => implode(', ', $tags[(int)$r['id']] ?? []), 'other' => implode("\n", $lines)];
    }
    return $docs;
}

function search_rebuild(): void
{
    $pdo = db();
    $fts = search_engine() === 'fts5';
    $pdo->exec('DELETE FROM search_docs');
    if ($fts) $pdo->exec('DELETE FROM search_fts');
    $ins = $pdo->prepare('INSERT INTO search_docs (item_id, name_f, tags_f, other_f, other) VALUES (?,?,?,?,?)');
    $insF = $fts ? $pdo->prepare('INSERT INTO search_fts (rowid, name, tags, other) VALUES (?,?,?,?)') : null;
    foreach (search_documents() as $id => $d) {
        $f = [fold($d['name']), fold($d['tags']), fold(preg_replace('/^[A-Za-z ]+: /m', '', $d['other']))];
        $ins->execute([$id, $f[0], $f[1], $f[2], $d['other']]);
        $insF?->execute([$id, ...$f]);
    }
}

/* ---------- query ---------- */

/** Words of a query, folded; quotes and FTS syntax are just characters to us. @return string[] */
function search_tokens(string $q): array
{
    return array_values(array_unique(preg_split('/\s+/u', fold(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: []));
}

/** Letter/digit runs of a token ("mkt-dhp482" → mkt, dhp482). @return string[] */
function search_parts(string $tok): array
{
    return preg_split('/[^\p{L}\p{N}]+/u', $tok, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** Item ids containing $tok as a substring of any field. @return int[] */
function search_ids_like(string $tok): array
{
    $like = '%' . like_escape($tok) . '%';
    $st = db()->prepare("SELECT item_id FROM search_docs WHERE name_f LIKE ? ESCAPE '!' OR tags_f LIKE ? ESCAPE '!' OR other_f LIKE ? ESCAPE '!'");
    $st->execute([$like, $like, $like]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Item ids whose words start with every part of $tok (FTS5 prefix query / tsquery prefix). @return int[] */
function search_ids_prefix(string $tok): array
{
    $parts = search_parts($tok);
    // only plain words; "<b>" or "mkt-dhp482" are matched as substrings (else "<b>" would find every word starting with b)
    if (count($parts) !== 1 || $parts[0] !== $tok) return [];
    if (search_engine() === 'fts5') {
        $st = db()->prepare('SELECT rowid FROM search_fts WHERE search_fts MATCH ?');
        $st->execute([implode(' AND ', array_map(fn($p) => '"' . str_replace('"', '""', $p) . '"*', $parts))]);
    } elseif (search_engine() === 'pg') {
        $st = db()->prepare("SELECT item_id FROM search_docs WHERE tsv @@ to_tsquery('simple', ?)");
        $st->execute([implode(' & ', array_map(fn($p) => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $p) . "':*", $parts))]);
    } else {
        return [];
    }
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** How many typos a word of this length may have. */
function search_typo_budget(string $tok): int
{
    $n = mb_strlen($tok);
    return $n < 4 ? 0 : ($n < 8 ? 1 : 2);
}

/** Close words from the index vocabulary for a token that matched nothing. @return string[] best first */
function search_fuzzy_terms(string $tok): array
{
    $max = search_typo_budget($tok);
    if ($max === 0 || !preg_match('/^[\p{L}\p{N}]+$/u', $tok)) return [];
    $n = mb_strlen($tok);
    if (search_engine() === 'fts5') {
        $st = db()->prepare('SELECT term FROM search_fts_v WHERE length(term) BETWEEN CAST(? AS INTEGER) AND CAST(? AS INTEGER)');
        $st->execute([max(1, $n - $max), $n + $max + 6]);
        $vocab = $st->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $vocab = [];
        foreach (db()->query('SELECT name_f, tags_f, other_f FROM search_docs')->fetchAll(PDO::FETCH_NUM) as $r) {
            foreach (search_parts(implode(' ', $r)) as $w) $vocab[$w] = true;
        }
        $vocab = array_keys($vocab);
    }
    $best = [];
    foreach ($vocab as $w) {
        $w = (string)$w;
        $m = mb_strlen($w);
        if ($m < $n - $max) continue;
        // whole word, or the word's start (so "screwdrivr" finds "screwdrivers")
        $d = min(search_lev($tok, $w), $m > $n ? search_lev($tok, mb_substr($w, 0, $n)) + 1 : PHP_INT_MAX);
        if ($d <= $max) $best[$w] = $d;
    }
    asort($best);
    return array_slice(array_keys($best), 0, 5);
}

/** Levenshtein distance on characters (PHP's levenshtein() counts bytes). */
function search_lev(string $a, string $b): int
{
    if ($a === $b) return 0;
    $a = mb_str_split($a);
    $b = mb_str_split($b);
    $prev = range(0, count($b));
    foreach ($a as $i => $ca) {
        $cur = [$i + 1];
        foreach ($b as $j => $cb) $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
        $prev = $cur;
    }
    return $prev[count($b)];
}

/**
 * Match a query. @return array{ids: int[], terms: string[], fixed: array<string,string>}
 *   ids best first; terms = folded words to highlight; fixed = typo => the word it was read as.
 */
function search_match(string $q): array
{
    $tokens = search_tokens($q);
    if (!$tokens) return ['ids' => [], 'terms' => [], 'fixed' => []];
    search_ensure();
    $fuzzy = (bool)setting('search_typos');
    $sets = [];
    $terms = [];
    $fixed = [];
    $weak = [];     // token => terms found only through typo tolerance
    foreach ($tokens as $tok) {
        $ids = array_flip(search_ids_like($tok));
        foreach (search_ids_prefix($tok) as $id) $ids[$id] = true;
        $terms[] = $tok;
        if (!$ids && $fuzzy) {
            if (search_trgm()) {
                $st = db()->prepare("SELECT item_id FROM search_docs WHERE ? <% (name_f || ' ' || tags_f || ' ' || other_f)");
                $st->execute([$tok]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
            }
            $close = search_fuzzy_terms($tok);
            foreach ($close as $w) foreach (search_ids_like($w) as $id) $ids[$id] = true;
            if ($ids && $close) { $fixed[$tok] = $close[0]; $weak[$tok] = $close; array_push($terms, ...$close); }
        }
        $sets[] = $ids;
    }
    $hit = array_shift($sets);
    foreach ($sets as $s) $hit = array_intersect_key($hit, $s);
    if (!$hit) return ['ids' => [], 'terms' => $terms, 'fixed' => $fixed];
    $ids = array_slice(array_keys($hit), 0, SEARCH_MAX_HITS);
    // rank: field weights in PHP (same on both databases) plus the engine's own score
    $score = array_fill_keys($ids, 0.0);
    foreach (array_chunk($ids, 500) as $chunk) {
        $st = db()->prepare('SELECT item_id, name_f, tags_f, other_f FROM search_docs WHERE item_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $n, $t, $o]) {
            $s = 0.0;
            foreach ($tokens as $tok) {
                $words = $weak[$tok] ?? [$tok];
                $k = isset($weak[$tok]) ? 0.5 : 1.0;
                $best = 0.0;
                foreach ($words as $w) {
                    foreach ([[$n, 10], [$t, 5], [$o, 2]] as [$field, $wt]) {
                        $pos = mb_strpos((string)$field, $w);
                        if ($pos === false) continue;
                        $start = $pos === 0 || !preg_match('/[\p{L}\p{N}]/u', mb_substr((string)$field, $pos - 1, 1));
                        $best = max($best, $wt * ($start ? 1.5 : 1.0) * ($field === $n && $n === $w ? 2 : 1));
                    }
                }
                $s += $best * $k;
            }
            $score[(int)$id] = $s;
        }
    }
    if (search_engine() === 'fts5') {
        $expr = implode(' OR ', array_map(fn($p) => '"' . str_replace('"', '""', $p) . '"*', array_merge(...array_map('search_parts', $terms))));
        if ($expr !== '') {
            $st = db()->prepare('SELECT rowid, bm25(search_fts, 10.0, 5.0, 1.0) FROM search_fts WHERE search_fts MATCH ?');
            $st->execute([$expr]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $r]) if (isset($score[(int)$id])) $score[(int)$id] += min(5.0, -(float)$r);
        }
    } elseif (search_engine() === 'pg') {
        $parts = array_merge(...array_map('search_parts', $terms));
        if ($parts) {
            $st = db()->prepare("SELECT item_id, ts_rank(tsv, to_tsquery('simple', ?)) FROM search_docs WHERE tsv @@ to_tsquery('simple', ?)");
            $tq = implode(' | ', array_map(fn($p) => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $p) . "':*", $parts));
            $st->execute([$tq, $tq]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $r]) if (isset($score[(int)$id])) $score[(int)$id] += min(5.0, (float)$r * 10);
        }
    }
    // stable: score desc, then id desc (newer first)
    uksort($score, fn($a, $b) => [$score[$b], $b] <=> [$score[$a], $a]);
    return ['ids' => array_keys($score), 'terms' => array_values(array_unique($terms)), 'fixed' => $fixed];
}

/* ---------- highlighting (always escaped) ---------- */

/** Escape $text and wrap every match of the folded $terms in <mark>. */
function search_highlight(string $text, array $terms): string
{
    $chars = mb_str_split($text);
    if (!$chars || !$terms) return h($text);
    // fold per character, remembering which original character each folded character came from
    $folded = '';
    $map = [];
    foreach ($chars as $i => $c) {
        foreach (mb_str_split(fold($c)) as $fc) { $folded .= $fc; $map[] = $i; }
    }
    $on = array_fill(0, count($chars), false);
    $fl = mb_strlen($folded);
    foreach ($terms as $t) {
        $t = (string)$t;
        $tl = mb_strlen($t);
        if ($tl === 0) continue;
        $off = 0;
        while (($p = mb_strpos($folded, $t, $off)) !== false) {
            for ($k = $p; $k < min($fl, $p + $tl); $k++) $on[$map[$k]] = true;
            $off = $p + 1;
        }
    }
    $o = '';
    $open = false;
    foreach ($chars as $i => $c) {
        if ($on[$i] && !$open) { $o .= '<mark>'; $open = true; }
        if (!$on[$i] && $open) { $o .= '</mark>'; $open = false; }
        $o .= h($c);
    }
    return $o . ($open ? '</mark>' : '');
}

/** A short excerpt of the first line (tags or other fields) that matches, highlighted; '' when the name matched. */
function search_snippet(int $id, array $terms): string
{
    $st = db()->prepare('SELECT name_f, tags_f, other FROM search_docs WHERE item_id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_NUM);
    if (!$r) return '';
    [$nameF, $tagsF, $other] = $r;
    $st = db()->prepare('SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = ? ORDER BY t.sort_key, t.id');
    $st->execute([$id]);
    $lines = [];
    $tags = implode(', ', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($tags !== '') $lines[] = 'Tags: ' . $tags;
    foreach (explode("\n", (string)$other) as $l) if ($l !== '') $lines[] = $l;
    foreach ($terms as $t) {
        if (str_contains((string)$nameF, (string)$t)) continue;
        foreach ($lines as $l) {
            [$label, $val] = array_pad(explode(': ', $l, 2), 2, '');
            $fv = fold($val);
            $p = mb_strpos($fv, (string)$t);
            if ($p === false) continue;
            $from = max(0, $p - 24);
            $cut = mb_substr($val, $from, 72);
            return '<span class="snip-k">' . h($label) . '</span> ' . ($from > 0 ? '…' : '') . search_highlight($cut, $terms) . ($from + 72 < mb_strlen($val) ? '…' : '');
        }
    }
    return '';
}
