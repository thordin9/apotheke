<?php
declare(strict_types=1);

/** CSV import: parsing, column guessing, dry run vs commit, creating missing entries. */
final class ImportTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
    }

    private function imp(string $csv, array $map, array $opt = ['create' => true]): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $csv);
        $id = import_upload(['error' => UPLOAD_ERR_OK, 'size' => strlen($csv), 'tmp_name' => $tmp, 'name' => 'x.csv']);
        @unlink($tmp);
        db()->prepare('UPDATE imports SET mapping = ?, options = ? WHERE id = ?')->execute([json_encode($map), json_encode($opt + ['skip_errors' => false]), $id]);
        return import_get($id);
    }

    public function testParseAndGuess(): void
    {
        [$h, $rows, $d] = import_parse("\xEF\xBB\xBFName\tQty\nA\t1\n\nB\t2\n");
        $this->assertSame(["\t", ['Name', 'Qty'], 2], [$d, $h, count($rows)]);
        [, $rows] = import_parse("name;notes\n\"x;y\";\"two\nlines\"\n");
        $this->assertSame([['x;y', "two\nlines"]], $rows);
        [, $rows] = import_parse("name\n" . mb_convert_encoding('Café', 'Windows-1252', 'UTF-8') . "\n");
        $this->assertSame('Café', $rows[0][0], 'Windows-1252 converted');
        $this->assertSame(['name', 'location', '', 'quantity', 'notes'], import_auto_map(['Name', 'Where', 'Colour', 'QTY', 'Description']));
        $this->assertSame(['name', ''], import_auto_map(['name', 'Name']), 'a field is guessed once');
        $this->expectException(UserError::class);
        import_parse("name\n");
    }

    public function testFormulaCellsAreProtected(): void
    {
        foreach (['=1+1', '+49 30 1234', '-5', '@SUM(A1)', "\tTab", "\rCR", "'=already", "''@two"] as $v) {
            $p = csv_protect($v);
            $this->assertSame("'", $p[0], "protected: $v");
            $this->assertSame($v, csv_unprotect($p), "round trip: $v");
        }
        foreach (['Drill', 'a=b', "'quoted", '', '12'] as $v) {
            $this->assertSame($v, csv_protect($v), "unchanged: $v");
            $this->assertSame($v, csv_unprotect($v));
        }
        [, $rows] = import_parse("name;serial\n'=Lamp;'-12\n");
        $this->assertSame([['=Lamp', '-12']], $rows, 'import strips the protecting apostrophe');
    }

    public function testDryRunThenCommit(): void
    {
        $imp = $this->imp("name,location,qty,tags\nSaw,Garage / Wall,1,tools\nBad,,x,\nTent,Attic,1,\n", ['name', 'location', 'quantity', 'tags']);
        $r = import_run($imp, false);
        $this->assertSame([2, 0, 1, false], [$r['created'], $r['updated'], $r['errors'], $r['committed']]);
        $this->assertSame(['Garage', 'Wall', 'Attic'], $r['made']['location']);
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn());
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM locations')->fetchColumn(), 'preview rolled back');
        try { import_commit($imp); $this->fail('committed with errors'); } catch (UserError $e) { $this->assertSame(422, $e->getCode()); }
        $imp['options']['skip_errors'] = true;
        $s = import_commit($imp);
        $this->assertSame([2, 1], [$s['created'], $s['skipped']]);
        $this->assertSame(2, (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn());
        $this->assertSame('Garage', db()->query("SELECT p.name FROM locations l JOIN locations p ON p.id = l.parent_id WHERE l.name = 'Wall'")->fetchColumn());
        $this->assertSame('done', import_get($imp['id'])['status']);
        $this->expectException(UserError::class);
        import_commit(import_get($imp['id']));
    }

    public function testUpdateKeepsUnmappedFields(): void
    {
        $loc = tax_create('location', 'Shed')['id'];
        $id = item_save(null, ['name' => 'Hose', 'location_id' => $loc, 'category_id' => null, 'quantity' => 1, 'notes' => 'green', 'serial' => 'H-1'], null, false);
        $imp = $this->imp("id,name,quantity\n$id,Garden hose,3\n999,New thing,1\n", ['id', 'name', 'quantity'], ['create' => false]);
        $s = import_commit($imp);
        $this->assertSame([1, 1], [$s['created'], $s['updated']]);
        $it = item_get($id);
        $this->assertSame(['Garden hose', 3, 'green', 'H-1', $loc], [$it['name'], (int)$it['quantity'], $it['notes'], $it['serial'], (int)$it['location_id']]);
    }
}
