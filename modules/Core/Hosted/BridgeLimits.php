<?php

declare(strict_types=1);

namespace Apotheke\Core\Hosted;

use Apotheke\Core\Exceptions\UserError;
use JsonException;
use RuntimeException;

/**
 * Reads the installation's plan limits from the bridge: GET http://d1.internal/limits, answered by the Worker for
 * the installation the container belongs to (the request names none). Anything but a well-formed answer is refused
 * with a 503, so a limit is never skipped because it could not be read.
 */
final class BridgeLimits
{
    public const DEFAULT_BASE = 'http://d1.internal';

    /** @param null|callable(string):string $transport url → raw body; null uses file_get_contents */
    public function __construct(
        private readonly string $base = self::DEFAULT_BASE,
        private readonly mixed $transport = null,
        private readonly int $timeout = 10,
    ) {
        if (preg_match('#^https?://[A-Za-z0-9._-]+(?::\d+)?$#', $this->base) !== 1) {
            throw new RuntimeException('The limits base must be an http(s) host with no path or userinfo.');
        }
    }

    public function read(): PlanLimits
    {
        $url = $this->base . '/limits';
        $raw = $this->transport !== null ? ($this->transport)($url) : $this->fileGet($url);
        try {
            $body = $raw === null ? null : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = null;
        }
        $limits = is_array($body) ? PlanLimits::fromBridge($body) : null;

        return $limits ?? throw self::unavailable();
    }

    private static function unavailable(): UserError
    {
        return new UserError('Your plan’s limits cannot be read right now. Try again in a moment.', 503);
    }

    private function fileGet(string $url): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => $this->timeout,
        ]]);
        $out = @file_get_contents($url, false, $ctx);

        return is_string($out) ? $out : null;
    }
}
