<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Apotheke\Core\Storage\Storage;
use Apotheke\Settings\SettingsService;
use Closure;
use JsonException;

/**
 * The saved service's last /v1/info, and the warn-once clamp pair, in one
 * storage object (`autofill/info.json`). The pair is the file: a new
 * service instance sees it, and deleting the file makes the next clamp
 * warn again. No static and no process-wide memory.
 */
final class InfoCache
{
    public const KEY = 'autofill/info.json';

    /** @param Closure():int $now unix time */
    public function __construct(
        private readonly Storage $storage,
        private readonly SettingsService $settings,
        private readonly Closure $now,
    ) {
    }

    /** @param array<string,mixed> $info */
    public function put(array $info): void
    {
        $prev = $this->read();
        $row = ['at' => ($this->now)(), 'url' => (string)$this->settings->get('autofill_url'), 'info' => $info];
        if (($prev['url'] ?? '') === $row['url'] && is_array($prev['clamp'] ?? null)) {
            $row['clamp'] = $prev['clamp'];
        }
        $this->write($row);
    }

    /**
     * Cached info for the saved address, or null when it is missing, for another address, or older than $maxAge.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $maxAge = 3600): ?array
    {
        $d = $this->read();
        if (($d['url'] ?? '') !== (string)$this->settings->get('autofill_url')) {
            return null;
        }
        if (($this->now)() - (int)($d['at'] ?? 0) > $maxAge) {
            return null;
        }
        $info = $d['info'] ?? null;

        return is_array($info) ? $info : null;
    }

    /**
     * Remember this from/to/field triple. False when that exact triple is
     * already in the file (do not log again).
     */
    public function rememberClamp(int $from, int $to, string $field): bool
    {
        $d = $this->read();
        $prev = $d['clamp'] ?? null;
        if (is_array($prev) && ($prev['from'] ?? null) === $from && ($prev['to'] ?? null) === $to && ($prev['field'] ?? null) === $field) {
            return false;
        }
        $d['clamp'] = ['from' => $from, 'to' => $to, 'field' => $field];
        $this->write($d);

        return true;
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        $raw = $this->storage->read(self::KEY);
        if ($raw === null || $raw === '') {
            return [];
        }
        try {
            $d = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($d) ? $d : [];
    }

    /** @param array<string,mixed> $data */
    private function write(array $data): void
    {
        try {
            $json = json_encode($data);
            if (is_string($json)) {
                $this->storage->write(self::KEY, $json);
            }
        } catch (\Throwable) {
            // A cache that cannot be written must not fail the suggestion.
        }
    }
}
