<?php

declare(strict_types=1);

namespace Tests\Migrations;

use Apotheke\Migrations\D1SchemaDump;
use CodeIgniter\Test\CIUnitTestCase;

/** N-1760: D1 schema dumps emit CREATE VIRTUAL TABLE only, never FTS5 shadows. */
final class D1SchemaDumpTest extends CIUnitTestCase
{
    public function testSkipsFts5ShadowTablesAndKeepsVirtual(): void
    {
        $rows = [
            ['type' => 'table', 'name' => 'items', 'tbl_name' => 'items', 'sql' => 'CREATE TABLE items (id INTEGER PRIMARY KEY)'],
            ['type' => 'table', 'name' => 'search_fts_backup', 'tbl_name' => 'search_fts_backup', 'sql' => 'CREATE TABLE search_fts_backup (id INTEGER PRIMARY KEY)'],
            ['type' => 'table', 'name' => 'search_fts', 'tbl_name' => 'search_fts', 'sql' => "CREATE VIRTUAL TABLE search_fts USING fts5(name, tags, other, tokenize = 'unicode61 remove_diacritics 2')"],
            ['type' => 'table', 'name' => 'search_fts_data', 'tbl_name' => 'search_fts_data', 'sql' => 'CREATE TABLE \'search_fts_data\'(id INTEGER PRIMARY KEY)'],
            ['type' => 'table', 'name' => 'search_fts_idx', 'tbl_name' => 'search_fts_idx', 'sql' => 'CREATE TABLE \'search_fts_idx\'(segid, term)'],
            ['type' => 'table', 'name' => 'search_fts_content', 'tbl_name' => 'search_fts_content', 'sql' => 'CREATE TABLE \'search_fts_content\'(id)'],
            ['type' => 'table', 'name' => 'search_fts_docsize', 'tbl_name' => 'search_fts_docsize', 'sql' => 'CREATE TABLE \'search_fts_docsize\'(id)'],
            ['type' => 'table', 'name' => 'search_fts_config', 'tbl_name' => 'search_fts_config', 'sql' => 'CREATE TABLE \'search_fts_config\'(k,v)'],
            ['type' => 'table', 'name' => 'search_fts_v', 'tbl_name' => 'search_fts_v', 'sql' => "CREATE VIRTUAL TABLE search_fts_v USING fts5vocab(search_fts, 'row')"],
            ['type' => 'index', 'name' => 'items_name', 'tbl_name' => 'items', 'sql' => 'CREATE INDEX items_name ON items(name)'],
            ['type' => 'index', 'name' => 'search_fts_data_idx', 'tbl_name' => 'search_fts_data', 'sql' => 'CREATE INDEX search_fts_data_idx ON search_fts_data(id)'],
            ['type' => 'table', 'name' => 'sqlite_sequence', 'tbl_name' => 'sqlite_sequence', 'sql' => 'CREATE TABLE sqlite_sequence(name,seq)'],
            ['type' => 'table', 'name' => '_cf_KV', 'tbl_name' => '_cf_KV', 'sql' => 'CREATE TABLE _cf_KV (key TEXT)'],
        ];
        $ddl = D1SchemaDump::fromRows($rows);
        $joined = implode("\n", $ddl);
        $this->assertStringContainsString('CREATE TABLE items', $joined);
        $this->assertStringContainsString('CREATE TABLE search_fts_backup', $joined, 'N-1789: ordinary tables that share a virtual prefix are kept');
        $this->assertStringContainsString('CREATE VIRTUAL TABLE search_fts USING fts5', $joined);
        $this->assertStringContainsString('CREATE VIRTUAL TABLE search_fts_v USING fts5vocab', $joined);
        $this->assertStringContainsString('CREATE INDEX items_name', $joined);
        $this->assertStringNotContainsString('search_fts_data', $joined);
        $this->assertStringNotContainsString('search_fts_idx', $joined);
        $this->assertStringNotContainsString('search_fts_content', $joined);
        $this->assertStringNotContainsString('search_fts_docsize', $joined);
        $this->assertStringNotContainsString('search_fts_config', $joined);
        $this->assertStringNotContainsString('sqlite_sequence', $joined);
        $this->assertStringNotContainsString('_cf_KV', $joined);
        $fts = array_values(array_filter($ddl, static fn (string $s): bool => str_contains($s, 'VIRTUAL TABLE')));
        $this->assertCount(2, $fts);
        $this->assertStringContainsString('USING fts5(', $fts[0]);
        $this->assertStringContainsString('fts5vocab', $fts[1]);
    }

    public function testIsFtsShadowRecognisesSuffixes(): void
    {
        $this->assertTrue(D1SchemaDump::isFtsShadow('search_fts_config', ['search_fts']));
        $this->assertTrue(D1SchemaDump::isFtsShadow('search_fts_data', ['search_fts']));
        $this->assertFalse(D1SchemaDump::isFtsShadow('search_fts', ['search_fts']));
        $this->assertFalse(D1SchemaDump::isFtsShadow('items', ['search_fts']));
        $this->assertFalse(D1SchemaDump::isFtsShadow('search_fts_backup', ['search_fts']), 'N-1789: prefix alone is not a shadow');
        $this->assertFalse(D1SchemaDump::isFtsShadow('search_fts_extra_idx', ['search_fts']), 'only the five literal suffixes');
        // N-1791: no parent list → never a shadow (even a name that ends in a known suffix).
        $this->assertFalse(D1SchemaDump::isFtsShadow('orphan_config', []));
        $this->assertFalse(D1SchemaDump::isFtsShadow('search_fts_config', []));
    }
}
