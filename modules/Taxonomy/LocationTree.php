<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy;

/**
 * The location tree (legacy location_tree_order(), tax_option_label()). Pure:
 * rows in, rows out.
 */
final class LocationTree
{
    public const SEP = ' › ';

    /** Levels below a top-level location. */
    public const MAX_DEPTH = 6;

    /**
     * Order rows depth-first (siblings keep their order) and add depth, path
     * and total. Rows whose parent is missing become roots; rows reachable only
     * through a cycle are shown as top-level entries.
     *
     * @param list<array<string,mixed>> $rows id, name, is_default, parent_id, n
     * @return list<array{id:int,name:string,is_default:int,n:int,parent_id:?int,depth:int,path:string,total:int}>
     */
    public static function order(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int)$r['id']] = true;
        }
        $kids = [];
        foreach ($rows as $r) {
            $p = $r['parent_id'] !== null && isset($ids[(int)$r['parent_id']]) && (int)$r['parent_id'] !== (int)$r['id'] ? (int)$r['parent_id'] : 0;
            $kids[$p][] = $r;
        }
        $out = [];
        $seen = [];
        $walk = static function (int $pid, int $depth, string $prefix) use (&$walk, &$kids, &$out, &$seen): int {
            $sum = 0;
            foreach ($kids[$pid] ?? [] as $r) {
                $id = (int)$r['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $row = ['id' => $id, 'name' => (string)$r['name'], 'is_default' => (int)$r['is_default'], 'n' => (int)$r['n'],
                    'parent_id' => $pid > 0 ? $pid : null, 'depth' => $depth, 'path' => $prefix . (string)$r['name'], 'total' => 0];
                $at = count($out);
                $out[] = $row;
                $total = $row['n'] + $walk($id, $depth + 1, $row['path'] . self::SEP);
                $out[$at]['total'] = $total;
                $sum += $total;
            }

            return $sum;
        };
        $walk(0, 0, '');
        foreach ($rows as $r) {
            if (isset($seen[(int)$r['id']])) {
                continue;
            }
            $kids[-(int)$r['id']] = [$r];
            $walk(-(int)$r['id'], 0, '');
        }

        return $out;
    }

    /**
     * Option label for a <select>: indented by depth so the tree stays visible.
     *
     * @param array{name:string,depth?:int} $l
     */
    public static function optionLabel(array $l): string
    {
        $d = (int)($l['depth'] ?? 0);

        return ($d > 0 ? str_repeat("\u{00A0}\u{00A0}\u{00A0}", $d) . '↳ ' : '') . $l['name'];
    }
}
