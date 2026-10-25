<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Closure;
use Throwable;

/**
 * Undo steps for work this request has started but not finished, such as
 * files written before an upload is complete. The exception handler runs
 * them before it exits (a fatal error included), and the `requestcleanup`
 * after filter runs them at the end of every request. One instance per
 * request (`Services::requestCleanup()`), so nothing outlives it in worker mode.
 */
final class RequestCleanup
{
    /** @var array<string, Closure(): void> */
    private array $pending = [];

    /** @param Closure(): void $undo */
    public function add(string $key, Closure $undo): void
    {
        $this->pending[$key] = $undo;
    }

    /** The work finished: forget its undo step without running it. */
    public function drop(string $key): void
    {
        unset($this->pending[$key]);
    }

    /** Run every pending undo step once, then start empty. One failing step does not stop the rest. */
    public function run(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $undo) {
            try {
                $undo();
            } catch (Throwable) {
                // best effort: the next step still runs
            }
        }
    }
}
