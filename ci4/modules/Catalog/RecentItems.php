<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

/**
 * The "Recently viewed" strip's items for this device or install. Catalog
 * only names it; the Favourites module implements it (Favourites may depend
 * on Catalog, not the other way round).
 */
interface RecentItems
{
    /** @return list<array<string,mixed>> id, name, image, favourite, location; newest first */
    public function items(): array;
}
