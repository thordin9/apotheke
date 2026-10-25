<?php

declare(strict_types=1);

namespace Tests\Taxonomy;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\Text;
use Apotheke\Taxonomy\Kind;
use Apotheke\Taxonomy\LocationTree;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * PLAN step 8: locations, categories and tags on both drivers. The pages are
 * compared with legacy in tests/Unit/Ci4TaxonomyParityTest.php.
 */
final class TaxonomyTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    private ?string $actor = 'ada';

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        putenv('APOTHEKE_CLOCK');
        parent::tearDown();
    }

    public function testKinds(): void
    {
        $this->assertSame('location', Kind::fromSegment('locations'));
        $this->assertSame('category', Kind::fromSegment('categories'));
        $this->assertNull(Kind::fromSegment('tags'));
        $this->assertSame(['locations', 'location_id', '/locations', 'loc'], [Kind::get('location')['table'], Kind::get('location')['col'], Kind::get('location')['path'], Kind::get('location')['param']]);
        $this->expectException(InvalidArgumentException::class);
        Kind::get('tag');
    }

    public function testNatkeyPadsNumbers(): void
    {
        $this->assertSame('box 000000000002', Text::natkey(' Box 2 '));
        $this->assertLessThan(0, strcmp(Text::natkey('box 2'), Text::natkey('box 10')));
        $this->assertSame(400, mb_strlen(Text::natkey(str_repeat('1 ', 300))));
    }

    #[DataProvider('drivers')]
    public function testCreateFindRenameAndDefault(string $driver): void
    {
        $repo = $this->repo($driver);
        putenv('APOTHEKE_CLOCK=2026-05-01 12:00:00');
        $box = $repo->create('location', "  Box\t 2 ");
        $this->assertSame(['name' => 'Box 2', 'created' => true], ['name' => $box['name'], 'created' => $box['created']]);
        $this->assertSame(['id' => $box['id'], 'name' => 'Box 2', 'created' => false], $repo->create('location', 'BOX 2'));
        $row = $this->db()->table('locations')->where('id', $box['id'])->get()->getRowArray();
        $this->assertSame(['box 2', 'box 000000000002', '2026-05-01 12:00:00'], [$row['name_key'] ?? null, $row['sort_key'] ?? null, $row['created_at'] ?? null]);
        $this->assertSame($box['id'], $repo->find('location', 'box 2')['id'] ?? null);

        $tools = $repo->create('category', 'Tools')['id'];
        $food = $repo->create('category', 'Food')['id'];
        $this->assertSame(['Food', 'Tools'], array_column($repo->all('category'), 'name'), 'natural order');
        try {
            $repo->rename('category', $tools, 'food');
            $this->fail('a taken name');
        } catch (UserError $e) {
            $this->assertSame(['A category called “Food” already exists.', 409], [$e->getMessage(), $e->getCode()]);
        }
        $repo->rename('category', $tools, 'TOOLS');
        $this->assertSame('TOOLS', $repo->get('category', $tools)['name'] ?? null);
        try {
            $repo->create('category', " \t ");
            $this->fail('an empty name');
        } catch (UserError $e) {
            $this->assertSame('Category name cannot be empty.', $e->getMessage());
        }

        $this->assertNull($repo->defaultId('category'));
        $repo->setDefault('category', $tools, true);
        $repo->setDefault('category', $food, true);
        $this->assertSame($food, $repo->defaultId('category'), 'only one default');
        $repo->setDefault('category', $tools, false);
        $this->assertSame($food, $repo->defaultId('category'), 'clearing another row leaves the default');
        $repo->setDefault('category', $food, false);
        $this->assertNull($repo->defaultId('category'));
    }

    #[DataProvider('drivers')]
    public function testTheTreeAndMoves(string $driver): void
    {
        $repo = $this->repo($driver);
        $garage = $repo->create('location', 'Garage')['id'];
        $shelf = $repo->createUnder('location', 'Shelf', $garage)['id'];
        $box = $repo->createUnder('location', 'Box', $shelf)['id'];
        $this->item('Hammer', $box);
        $this->item('Saw', $garage);
        $this->assertSame([[$garage, 0, 'Garage', 2], [$shelf, 1, 'Garage › Shelf', 1], [$box, 2, 'Garage › Shelf › Box', 1]],
            array_map(static fn (array $l): array => [$l['id'], $l['depth'], $l['path'], $l['total']], $repo->all('location')));
        $this->assertSame('Garage › Shelf', $repo->locationPath($shelf));
        $this->assertSame('', $repo->locationPath(999));
        $this->assertSame([$garage, $shelf, $box], $repo->subtreeIds($garage));

        $this->assertFalse($repo->setParent($shelf, $garage), 'unchanged');
        foreach ([[$garage, $box, 'A location cannot be put inside itself or one of its own sub-locations.'], [$garage, $garage, 'A location cannot be put inside itself or one of its own sub-locations.'],
            [$garage, 999, 'That parent location no longer exists.'], [999, null, 'Location not found.']] as [$id, $parent, $message]) {
            try {
                $repo->setParent($id, $parent);
                $this->fail($message);
            } catch (UserError $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
        $parent = $box;
        for ($i = 3; $i <= LocationTree::MAX_DEPTH; $i++) {
            $parent = $repo->createUnder('location', 'L' . $i, $parent)['id'];
        }
        try {
            $repo->createUnder('location', 'Too deep', $parent);
            $this->fail('seven levels');
        } catch (UserError $e) {
            $this->assertSame('Locations can be nested at most 6 levels deep.', $e->getMessage());
        }
        $this->assertNull($repo->find('location', 'Too deep'), 'the add is rolled back with the move');
        $this->assertTrue($repo->setParent($shelf, null));
        $this->assertSame(0, $repo->all('location')[1]['depth'] ?? null);
    }

    #[DataProvider('drivers')]
    public function testDeleteMovesItemsAndSubLocationsAndLogsThem(string $driver): void
    {
        $repo = $this->repo($driver);
        $garage = $repo->create('location', 'Garage')['id'];
        $shelf = $repo->createUnder('location', 'Shelf', $garage)['id'];
        $box = $repo->createUnder('location', 'Box', $shelf)['id'];
        $attic = $repo->create('location', 'Attic')['id'];
        $hammer = $this->item('Hammer', $shelf);
        $this->item('Saw', $garage);
        $this->item('Loose', null);
        $this->assertSame(1, $repo->noneCount('location'));
        $this->assertSame(3, $repo->noneCount('category'), 'no item has a category');
        $this->assertSame(1, $repo->get('location', $shelf)['n'] ?? null);

        $this->assertSame(1, $repo->delete('location', $shelf, $attic));
        $this->assertNull($repo->get('location', $shelf));
        $this->assertSame($garage, (int)($this->db()->table('locations')->where('id', $box)->get()->getRowArray()['parent_id'] ?? 0), 'the box moves up a level');
        $this->assertSame($attic, (int)($this->db()->table('items')->where('id', $hammer)->get()->getRowArray()['location_id'] ?? 0));
        $log = $this->db()->table('activity')->get()->getResultArray();
        $this->assertCount(1, $log);
        $this->assertSame([$hammer, 'Hammer', 'moved', '{"from":"Shelf","to":"Attic","reason":"location deleted"}', 'ada'],
            [(int)$log[0]['item_id'], $log[0]['item_name'], $log[0]['action'], $log[0]['details'], $log[0]['actor']]);

        $tools = $repo->create('category', 'Tools & “Co”')['id'];
        $this->db()->table('items')->where('id', $hammer)->update(['category_id' => $tools]);
        $this->actor = null;
        $this->assertSame(1, $repo->delete('category', $tools, null));
        $last = $this->db()->table('activity')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame(['edited', '{"changes":{"category":["Tools & “Co”",""]},"reason":"category deleted"}', null], [$last['action'] ?? null, $last['details'] ?? null, $last !== null && array_key_exists('actor', $last) ? $last['actor'] : 'x']);
        $this->assertSame(0, $repo->delete('location', $attic + 100, null), 'a missing entry changes nothing');
    }

    #[DataProvider('drivers')]
    public function testTags(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $tags = new TagRepository($this->db(), new Clock());
        $a = $this->item('A', null);
        $b = $this->item('B', null);
        $tags->set($a, Text::tags('Red, blue 10; blue 2'));
        $tags->set($b, ['red']);
        $this->assertSame(['blue 2', 'blue 10', 'Red'], array_column($tags->forItem($a), 'name'), 'natural order, first spelling kept');
        $this->assertSame([['blue 2', 1], ['blue 10', 1], ['Red', 2]], array_map(static fn (array $t): array => [$t['name'], $t['n']], $tags->inUse()));
        $tags->set($a, []);
        $this->assertSame([], $tags->forItem($a));
        $this->assertSame(['Red'], array_column($tags->inUse(), 'name'), 'unused tags are dropped');
        $this->assertSame(1, $this->db()->table('tags')->countAllResults());
    }

    private function repo(string $driver): TaxonomyRepository
    {
        $this->database = TestDatabase::create($driver);

        return new TaxonomyRepository($this->db(), new Clock(), new ActivityLogger($this->db(), new Clock(), fn (): ?string => $this->actor));
    }

    private function item(string $name, ?int $location): int
    {
        $this->db()->table('items')->insert(['name' => $name, 'location_id' => $location, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        return (int)$this->db()->insertID();
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }
}
