<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Closure;

/**
 * The Host allow-list (SEC-M3), a port of legacy src/hosts.php. IP literals,
 * localhost, *.localhost and this machine's own name are always accepted; other
 * names must be PUBLIC_URL's host or listed in ALLOWED_HOSTS ("*" accepts any
 * valid name). Pure: the caller passes the configured names.
 */
final class HostAllowList
{
    /** Legacy request_host_refuse() text. It never repeats the name that was sent. */
    public const REFUSAL = "Unknown host name. Open the app by its address, or add this name to ALLOWED_HOSTS (or PUBLIC_URL).\n";

    /** @param string $self this machine's host name (the container name in Docker) */
    public function __construct(private readonly string $self = '')
    {
    }

    /**
     * A Host header as a lower-case name and an optional port. IPv6 literals must
     * be bracketed ("[::1]:9091"); the name comes back without brackets.
     *
     * @return array{0:string,1:?int}|null null when the value is not a valid host[:port]
     */
    public function split(string $host): ?array
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        $port = null;
        if ($host[0] === '[') {
            if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/D', $host, $m) !== 1) {
                return null;
            }
            if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $name = $m[1];
        } else {
            if (preg_match('/^([a-z0-9_.-]+)(?::(\d{1,5}))?$/D', $host, $m) !== 1) {
                return null;
            }
            $name = rtrim($m[1], '.');
            if ($name === '' || $name[0] === '.' || $name[0] === '-' || str_contains($name, '..')) {
                return null;
            }
        }
        if (isset($m[2])) {
            $port = (int)$m[2];
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return [$name, $port];
    }

    /** An IPv4 or IPv6 literal, without brackets (as split() returns it). */
    public function isIp(string $name): bool
    {
        return filter_var($name, FILTER_VALIDATE_IP) !== false;
    }

    /** IP literals, localhost, *.localhost and this machine's own name. */
    public function isBuiltin(string $name): bool
    {
        if ($this->isIp($name)) {
            return true;
        }
        if ($name === 'localhost' || str_ends_with($name, '.localhost')) {
            return true;
        }

        return $this->self !== '' && $name === strtolower(rtrim($this->self, '.'));
    }

    /**
     * PUBLIC_URL's host plus ALLOWED_HOSTS (comma or space separated; a port is
     * ignored; "*" accepts any host).
     *
     * @return list<string>
     */
    public function configured(string $publicUrl, string $allowedHosts): array
    {
        $out = [];
        $pu = $publicUrl !== '' ? parse_url($publicUrl, PHP_URL_HOST) : null;
        if (is_string($pu) && $pu !== '') {
            $out[] = strtolower(trim($pu, '[]'));
        }
        foreach (preg_split('/[\s,]+/', $allowedHosts, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $h) {
            if ($h === '*') {
                $out[] = '*';
                continue;
            }
            $hp = $this->split($h);
            if ($hp !== null) {
                $out[] = $hp[0];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Whether $hostHeader passes. $configured is a list from configured(), or a
     * closure returning one, so settings are read only for a name that is not built in.
     *
     * @param list<string>|Closure():list<string> $configured
     */
    public function allowed(string $hostHeader, array|Closure $configured): bool
    {
        $hp = $this->split($hostHeader);
        if ($hp === null) {
            return false;
        }
        if ($this->isBuiltin($hp[0])) {
            return true;
        }
        $list = $configured instanceof Closure ? $configured() : $configured;

        return in_array('*', $list, true) || in_array($hp[0], $list, true);
    }
}
