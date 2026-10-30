<?php

declare(strict_types=1);

namespace Apotheke\Core\Commands;

use Apotheke\Core\Jobs\JobHandler;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Jobs\JobRunner;

/**
 * php spark jobs:work: runs queued jobs until --max-jobs ran or --max-seconds passed (then exits, for the process
 * supervisor to start it again), waiting --sleep ms when the queue is empty (0: stop instead). With pcntl, SIGTERM
 * and SIGINT let the running job checkpoint and go back to the queue before the worker exits.
 */
final class JobsCli
{
    public const USAGE = "Usage: php spark jobs:work [--once] [--max-jobs=N] [--max-seconds=N] [--sleep=MS]\n"
        . "  --once           run at most one job\n"
        . "  --max-jobs=N     stop after N jobs (default: no limit)\n"
        . "  --max-seconds=N  stop after N seconds (default: 3600)\n"
        . "  --sleep=MS       wait MS milliseconds when no job is due (default: 1000; 0: stop instead)\n"
        . "Exit codes: 0 after a clean stop (a limit, an empty queue with --sleep=0, or SIGTERM/SIGINT with pcntl: the\n"
        . "running job checkpoints and goes back to the queue); 1 for a bad argument or no database; without pcntl,\n"
        . "SIGTERM ends the worker at once (143) and its job is taken over when its lease runs out.\n";

    private bool $stop = false;

    /**
     * @param array<string,JobHandler> $handlers
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly JobQueue $queue,
        private readonly array $handlers,
        private readonly string $owner,
        private readonly mixed $out,
        private readonly mixed $err,
    ) {
    }

    /** @param list<string> $args */
    public function work(array $args): int
    {
        $opts = ['max-jobs' => 0, 'max-seconds' => 3600, 'sleep' => 1000];
        foreach ($args as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                fwrite($this->out, self::USAGE);

                return 0;
            }
            if ($arg === '--once') {
                $opts['max-jobs'] = 1;
                $opts['sleep'] = 0;
                continue;
            }
            if (preg_match('/^--(max-jobs|max-seconds|sleep)=(\d{1,9})$/', $arg, $m) !== 1 || ($m[1] === 'max-seconds' && (int)$m[2] === 0)) {
                fwrite($this->err, self::USAGE);

                return 1;
            }
            $opts[$m[1]] = (int)$m[2];
        }

        $restore = $this->trapSignals();
        try {
            $runner = new JobRunner($this->queue, $this->handlers, $this->owner, fn (): bool => $this->stop);
            $runner->work($opts['max-jobs'], $opts['max-seconds'], $opts['sleep'], function (array $r): void {
                fwrite($this->out, 'job ' . $r['id'] . ' (' . $r['type'] . '): ' . $r['status'] . "\n");
            });
        } finally {
            $restore();
        }

        return 0;
    }

    /** @return \Closure():void puts the previous handlers back */
    private function trapSignals(): \Closure
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal_get_handler')) {
            return static function (): void {
            };
        }
        $async = pcntl_async_signals(true);
        $previous = [];
        foreach ([SIGTERM, SIGINT] as $signal) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (): void {
                $this->stop = true;
            });
        }

        return static function () use ($previous, $async): void {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($async);
        };
    }
}
