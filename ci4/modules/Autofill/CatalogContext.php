<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Category and tag names the service may reuse. The same rows as legacy
 * `autofill_context()` (categories by name, tags that are in use). Direct
 * SQL until the Taxonomy module exists; both drivers use this statement.
 */
final class CatalogContext
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array{categories:list<string>,tags:list<string>,locale:string}
     */
    public function names(): array
    {
        return [
            'categories' => array_slice($this->column('SELECT name FROM categories ORDER BY name'), 0, 200),
            'tags' => array_slice($this->column(
                'SELECT t.name FROM tags t JOIN item_tags it ON it.tag_id = t.id GROUP BY t.id, t.name, t.sort_key ORDER BY t.sort_key, t.id',
            ), 0, 300),
            'locale' => 'en',
        ];
    }

    /** @return list<string> */
    private function column(string $sql): array
    {
        try {
            $result = $this->db->query($sql);
            if ($result === false) {
                return [];
            }
            $out = [];
            foreach ($result->getResultArray() as $row) {
                $out[] = (string)($row['name'] ?? '');
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
