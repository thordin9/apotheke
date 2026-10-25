<?php

declare(strict_types=1);

namespace Apotheke\Activity;

use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * The `activity` table writer (legacy activity_log()). The actor is the
 * signed-in account's name, or null for the command line, sign-in off and the
 * guest (legacy current_actor()). Call it inside the change's own
 * transaction. The /activity pages come with PLAN step 11a.
 */
final class ActivityLogger implements ItemHistory
{
    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param Closure(): ?string $actor
     */
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock, private readonly Closure $actor)
    {
    }

    /** @param array<string,mixed> $details */
    public function log(int $itemId, string $itemName, string $action, array $details = []): void
    {
        $this->db->table('activity')->insert([
            'item_id' => $itemId,
            'item_name' => $itemName,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $this->clock->now(),
            'actor' => ($this->actor)(),
        ]);
    }
}
