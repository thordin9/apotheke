<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Media\ImageStore;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * A few demo items with generated photos (legacy seed_demo(), for
 * `bin/seed.php --demo`). Skips an item whose name already exists.
 */
final class DemoItems
{
    /** name, location, category, quantity, photo colour (none: no photo), notes */
    private const ITEMS = [
        ['Cordless drill', 'Garage', 'Tools', 1, [200, 80, 40], '18V, two batteries.'],
        ['HDMI cables', 'Box 1', 'Electronics', 4, [40, 90, 200], ''],
        ['Cast iron pan', 'Kitchen', 'Kitchen', 2, [90, 60, 50], ''],
        ['Passport', 'Living room', 'Documents', 1, [120, 40, 60], 'Expires 2031.'],
        ['Camping stove', 'Attic', 'Hobby', 1, [30, 140, 90], ''],
        ['Raspberry Pi 4', 'Box 1', 'Electronics', 3, [180, 40, 90], ''],
        ['Screwdriver set', 'Garage', 'Tools', 1, [220, 160, 30], ''],
        ['Extension cord', 'Basement', 'General', 2, null, ''],
    ];

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ItemRepository $items,
        private readonly TaxonomyRepository $taxonomy,
        private readonly ImageStore $images,
    ) {
    }

    /** @return int items created */
    public function create(): int
    {
        $n = 0;
        foreach (self::ITEMS as [$name, $location, $category, $quantity, $rgb, $notes]) {
            if ((int)($this->db->query('SELECT COUNT(*) AS n FROM items WHERE name = ?', [$name])->getRowArray()['n'] ?? 0) > 0) {
                continue;
            }
            $image = $rgb !== null ? $this->photo(...$rgb) : null;
            try {
                $this->items->save(null, ['name' => $name, 'location_id' => $this->taxonomy->create('location', $location)['id'],
                    'category_id' => $this->taxonomy->create('category', $category)['id'], 'quantity' => $quantity, 'notes' => $notes], $image, false);
            } catch (Throwable $e) {
                $this->images->delete($image);
                throw $e;
            }
            $n++;
        }

        return $n;
    }

    /** A 900×900 gradient with a disc and a square (legacy seed_image()), stored like an upload. */
    private function photo(int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor(900, 900);
        for ($y = 0; $y < 900; $y++) {
            imageline($im, 0, $y, 899, $y, (int)imagecolorallocate($im, min(255, $r + intdiv($y, 9)), min(255, $g + intdiv($y, 12)), min(255, $b + intdiv($y, 15))));
        }
        imagefilledellipse($im, 450, 470, 460, 460, (int)imagecolorallocatealpha($im, 255, 255, 255, 70));
        imagefilledrectangle($im, 300, 330, 600, 610, (int)imagecolorallocate($im, intdiv($r, 2), intdiv($g, 2), intdiv($b, 2)));
        $tmp = (string)tempnam(sys_get_temp_dir(), 'apo');
        imagejpeg($im, $tmp, 85);
        try {
            return $this->images->store($tmp, (int)filesize($tmp));
        } finally {
            @unlink($tmp);
        }
    }
}
