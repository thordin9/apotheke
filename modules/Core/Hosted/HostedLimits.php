<?php

declare(strict_types=1);

namespace Apotheke\Core\Hosted;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Exceptions\UserError;
use Closure;

/**
 * The hosted service's ceilings (operator console C1). Off on self-hosted installs (APOTHEKE_HOSTED unset): no
 * limits are read and nothing is refused. Hosted, the installation's plan limits are read once per request from the
 * bridge, and the item, photo and account counts are checked inside the unit of work that adds the rows (guard()),
 * so two requests at once cannot both pass a limit. Built per request by Config\Services.
 */
final class HostedLimits
{
    /** The limit keys checked here: the table counted and the word the refusal uses. */
    private const COUNTED = [
        'items' => ['items', 'items'],
        'photos' => ['item_photos', 'photos'],
        'users' => ['users', 'user accounts'],
    ];

    /** Settings a tenant admin cannot raise past a plan limit: the limit key and the bytes per setting unit. */
    private const SETTING_CEILINGS = [
        'max_upload_mb' => ['import_file_bytes', 1048576],
        'attach_max_mb' => ['import_file_bytes', 1048576],
    ];

    /** Settings that only the hosting side sets. */
    private const FIXED_SETTINGS = ['autofill_url'];

    private ?PlanLimits $limits = null;

    /** @param (Closure():PlanLimits)|null $read null: self-hosted */
    private function __construct(private readonly ?Closure $read)
    {
    }

    /** Self-hosted: no limits. */
    public static function off(): self
    {
        return new self(null);
    }

    /** @param Closure():PlanLimits $read the installation's limits; throws UserError when they cannot be read */
    public static function hosted(Closure $read): self
    {
        return new self($read);
    }

    /**
     * Hosted when $config['hosted'] is on: limits from GET $base/limits.
     *
     * @param array<string,mixed> $config ApothekeConfig values
     * @param null|callable(string):string $transport url → raw body (tests); null uses file_get_contents
     */
    public static function fromConfig(array $config, ?callable $transport = null): self
    {
        if (($config['hosted'] ?? false) !== true) {
            return self::off();
        }

        return self::hosted((new BridgeLimits(BridgeLimits::DEFAULT_BASE, $transport))->read(...));
    }

    public function isHosted(): bool
    {
        return $this->read !== null;
    }

    /** This installation's limits (none when self-hosted). Read once per instance. */
    public function limits(): PlanLimits
    {
        if ($this->read === null) {
            return PlanLimits::none();
        }

        return $this->limits ??= ($this->read)();
    }

    /**
     * Extra unit-of-work scope keys for the counted limits that apply, so on PostgreSQL every unit that adds such a
     * row waits for the others (a unit's lock is per table and key). Empty when none applies.
     *
     * @return list<string>
     */
    public function lockKeys(string ...$keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if ($this->isHosted() && isset(self::COUNTED[$key]) && $this->limits()->hard($key) !== null) {
                $out[] = 'plan-limit:' . $key;
            }
        }

        return $out;
    }

    /**
     * Queue the check that $adding more rows still fit the hard limit $key, at this point of $uow: the count is
     * read when the unit runs, so rows queued before this step are counted. Nothing when no limit applies.
     */
    public function guard(UnitOfWork $uow, string $key, int $adding = 1): void
    {
        if (!$this->isHosted() || !isset(self::COUNTED[$key]) || $adding < 1) {
            return;
        }
        $hard = $this->limits()->hard($key);
        if ($hard === null) {
            return;
        }
        [$table, $word] = self::COUNTED[$key];
        $uow->assert('(SELECT COUNT(*) FROM ' . $table . ') + ? <= ?', [$adding, $hard], 'plan limit ' . $key,
            static fn (): UserError => new UserError('Your plan allows ' . $hard . ' ' . $word . '.', 422));
    }

    /** The highest value a tenant admin may give $setting, or null when no plan limit bounds it. */
    public function ceiling(string $setting): ?int
    {
        if (!$this->isHosted() || !isset(self::SETTING_CEILINGS[$setting])) {
            return null;
        }
        [$key, $unit] = self::SETTING_CEILINGS[$setting];
        $hard = $this->limits()->hard($key);

        return $hard === null ? null : intdiv($hard, $unit);
    }

    /** Whether $setting is set by the hosting side only (a tenant admin cannot change it). */
    public function fixed(string $setting): bool
    {
        return $this->isHosted() && in_array($setting, self::FIXED_SETTINGS, true);
    }
}
