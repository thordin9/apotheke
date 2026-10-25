<?php

declare(strict_types=1);

namespace Apotheke\Favourites;

use Apotheke\Catalog\ItemList;
use Apotheke\Catalog\RecentItems;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Database\BaseConnection;

/**
 * Favourites and the "Recently viewed" list (legacy src/favourites.php).
 * The list's scope is the whole install, or this device's `apo_dev` cookie;
 * reading and clearing never set the cookie; recording a view (the item
 * page) creates it.
 */
final class Favourites implements RecentItems
{
    public const COOKIE = 'apo_dev';

    private const COOKIE_SECONDS = 400 * 86400;

    private const MAX_AGE_DAYS = 90;

    /** A device id this request created (legacy writes it into $_COOKIE). */
    private ?string $created = null;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param mixed $deviceCookie the request's apo_dev cookie
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ItemList $items,
        private readonly string $scopeSetting,
        private readonly int $limit,
        private readonly mixed $deviceCookie,
    ) {
    }

    /** Star or unstar an item; updated_at stays, so the list order stays put. */
    public function set(int $id, bool $on): void
    {
        if ($this->items->find($id) === null) {
            throw new UserError('Item not found.', 404);
        }
        Writes::check($this->db->table('items')->where('id', $id)->update(['favourite' => $on ? 1 : 0]), 'favourite');
    }

    public function count(): int
    {
        return $this->items->favouriteCount();
    }

    /** '*' for the whole install, 'd:<id>' for this device, null when the device has no list yet. */
    public function scope(): ?string
    {
        if ($this->scopeSetting === 'install') {
            return '*';
        }
        $v = $this->created ?? (is_string($this->deviceCookie) ? $this->deviceCookie : '');

        return preg_match('/^[a-f0-9]{32}$/D', $v) === 1 ? 'd:' . $v : null;
    }

    public function items(): array
    {
        $scope = $this->scope();
        if ($this->limit <= 0 || $scope === null) {
            return [];
        }

        return Writes::rows($this->db, "SELECT i.id, i.name, i.image, i.favourite, COALESCE(l.name, '') AS location FROM recent_views r JOIN items i ON i.id = r.item_id
            LEFT JOIN locations l ON l.id = i.location_id WHERE r.scope = ? ORDER BY r.seq DESC LIMIT " . $this->limit, [$scope]);
    }

    public function record(int $id): ?Cookie
    {
        if ($this->limit <= 0) {
            return null;
        }
        $cookie = null;
        $scope = $this->scope();
        if ($scope === null) {
            $this->created = bin2hex(random_bytes(16));
            $scope = 'd:' . $this->created;
            // As legacy setcookie(): 400 days, path /, HttpOnly, SameSite=Lax, not Secure.
            $cookie = new Cookie(self::COOKIE, $this->created, ['expires' => time() + self::COOKIE_SECONDS, 'path' => '/', 'domain' => '', 'prefix' => '',
                'secure' => false, 'httponly' => true, 'samesite' => Cookie::SAMESITE_LAX, 'raw' => false]);
        }
        $clock = new Clock();
        Writes::transaction($this->db, function () use ($id, $scope, $clock): void {
            $seq = (int)(Writes::rows($this->db, 'SELECT COALESCE(MAX(seq), 0) AS s FROM recent_views WHERE scope = ?', [$scope])[0]['s'] ?? 0) + 1;
            Writes::check($this->db->table('recent_views')->where('scope', $scope)->where('item_id', $id)->delete(), 'recent');
            Writes::check($this->db->table('recent_views')->insert(['scope' => $scope, 'item_id' => $id, 'seq' => $seq, 'viewed_at' => $clock->now()]), 'recent');
            Writes::check($this->db->table('recent_views')->where('scope', $scope)->where('seq <=', $seq - $this->limit)->delete(), 'recent');
            if ($seq % 50 === 0) {
                $cut = (int)strtotime($clock->now() . ' UTC') - self::MAX_AGE_DAYS * 86400;
                Writes::check($this->db->table('recent_views')->where('viewed_at <', gmdate('Y-m-d H:i:s', $cut))->delete(), 'recent');
            }
        });

        return $cookie;
    }

    /** Forget this device's (or the shared) list. */
    public function clearRecent(): void
    {
        $scope = $this->scope();
        if ($scope !== null) {
            Writes::check($this->db->table('recent_views')->where('scope', $scope)->delete(), 'clear recent');
        }
    }
}
