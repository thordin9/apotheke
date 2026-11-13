<?php

declare(strict_types=1);

namespace Apotheke\Core\Hosted;

/**
 * An installation's plan limits as the bridge answers them (GET http://d1.internal/limits, from the control plane's
 * plan_limits rows): a soft and a hard value per key. No entry, or a null value, means no limit. No values are
 * built in: they come only from the operator's rows.
 */
final class PlanLimits
{
    /**
     * @param array<string,int> $hard
     * @param array<string,int> $soft
     */
    private function __construct(private readonly array $hard, private readonly array $soft, private readonly ?string $plan)
    {
    }

    public static function none(): self
    {
        return new self([], [], null);
    }

    /**
     * The bridge's body ({ok: true, plan, limits: {key: {soft, hard}}}), or null when it is not one.
     *
     * @param array<array-key,mixed> $body
     */
    public static function fromBridge(array $body): ?self
    {
        if (($body['ok'] ?? null) !== true || !is_array($body['limits'] ?? null)) {
            return null;
        }
        $out = ['hard' => [], 'soft' => []];
        foreach ($body['limits'] as $key => $v) {
            if (!is_string($key) || preg_match('/^[a-z_]{1,40}$/D', $key) !== 1 || !is_array($v)) {
                return null;
            }
            foreach (['hard', 'soft'] as $kind) {
                $n = $v[$kind] ?? null;
                if ($n !== null && (!is_int($n) || $n < 0)) {
                    return null;
                }
                if ($n !== null) {
                    $out[$kind][$key] = $n;
                }
            }
        }
        $plan = $body['plan'] ?? null;

        return new self($out['hard'], $out['soft'], is_string($plan) ? $plan : null);
    }

    public function hard(string $key): ?int
    {
        return $this->hard[$key] ?? null;
    }

    public function soft(string $key): ?int
    {
        return $this->soft[$key] ?? null;
    }

    public function plan(): ?string
    {
        return $this->plan;
    }
}
