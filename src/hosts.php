<?php
declare(strict_types=1);

/*
 * Request hardening (SEC-M3): the Host allow-list and the same-origin rule for no-login mode.
 * The parsing helpers are pure so they can be unit-tested; request_*() read $_SERVER.
 */

/**
 * Split a Host header into a lower-case name and an optional port.
 * IPv6 literals must be bracketed ("[::1]:9091"); the name is returned without brackets.
 * @return array{0:string,1:?int}|null null when the value is not a valid host[:port]
 */
function host_split(string $host): ?array
{
    $host = strtolower(trim($host));
    if ($host === '') return null;
    $port = null;
    if ($host[0] === '[') {
        if (!preg_match('/^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/D', $host, $m)) return null;
        if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return null;
        $name = $m[1];
        if (isset($m[2])) $port = (int)$m[2];
    } else {
        if (!preg_match('/^([a-z0-9_.-]+)(?::(\d{1,5}))?$/D', $host, $m)) return null;
        $name = rtrim($m[1], '.');
        if ($name === '' || $name[0] === '.' || $name[0] === '-' || str_contains($name, '..')) return null;
        if (isset($m[2])) $port = (int)$m[2];
    }
    if ($port !== null && ($port < 1 || $port > 65535)) return null;
    return [$name, $port];
}

/** An IPv4 or IPv6 literal (as returned by host_split(), so without brackets). */
function host_is_ip(string $name): bool
{
    return filter_var($name, FILTER_VALIDATE_IP) !== false;
}

/**
 * Host names that are always accepted: IP literals (a browser only sends one when the page itself was opened
 * by address), localhost and this machine's own host name (the container name in Docker).
 */
function host_is_builtin(string $name, string $self): bool
{
    if (host_is_ip($name)) return true;
    if ($name === 'localhost' || str_ends_with($name, '.localhost')) return true;
    return $self !== '' && $name === strtolower(rtrim($self, '.'));
}

/**
 * The configured names: PUBLIC_URL's host plus ALLOWED_HOSTS (comma or space separated; a port is ignored;
 * "*" accepts any host).
 * @return list<string>
 */
function host_configured(string $publicUrl, string $allowedHosts): array
{
    $out = [];
    $pu = $publicUrl !== '' ? parse_url($publicUrl, PHP_URL_HOST) : null;
    if (is_string($pu) && $pu !== '') $out[] = strtolower(trim($pu, '[]'));
    foreach (preg_split('/[\s,]+/', $allowedHosts, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $h) {
        if ($h === '*') { $out[] = '*'; continue; }
        $hp = host_split($h);
        if ($hp !== null) $out[] = $hp[0];
    }
    return array_values(array_unique($out));
}

/**
 * Is $hostHeader acceptable? $configured is a list from host_configured(), or a closure returning it,
 * so the database is only read for a name that is not built in.
 * @param list<string>|Closure():list<string> $configured
 */
function host_allowed(string $hostHeader, array|Closure $configured, string $self = ''): bool
{
    $hp = host_split($hostHeader);
    if ($hp === null) return false;
    if (host_is_builtin($hp[0], $self)) return true;
    $list = $configured instanceof Closure ? $configured() : $configured;
    return in_array('*', $list, true) || in_array($hp[0], $list, true);
}

/** The configured names for this install. Falls back to the config value if the database can't be read. */
function request_configured_hosts(): array
{
    try { $pu = (string)setting('public_url'); } catch (Throwable) { $pu = (string)(cfg()['public_url'] ?? ''); }
    return host_configured($pu, (string)(cfg()['allowed_hosts'] ?? ''));
}

/** The request's Host header passes the allow-list. A request without Host (HTTP/1.0 tools) is accepted. */
function request_host_ok(): bool
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return true;
    return host_allowed($host, request_configured_hosts(...), (string)gethostname());
}

/** Answer a request for an unknown host name with 421 and stop. The name is not echoed back. */
function request_host_refuse(): never
{
    http_response_code(421);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo "Unknown host name. Open the app by its address, or add this name to ALLOWED_HOSTS (or PUBLIC_URL).\n";
    exit;
}

/** GET, HEAD and OPTIONS never change anything. */
function method_is_safe(string $method): bool
{
    return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
}

/**
 * Did a state-changing request come from this app's own pages?
 * Sec-Fetch-Site decides when the browser sends it (same-origin or none = typed or bookmarked).
 * Otherwise Origin must be this request's scheme + host, or PUBLIC_URL's origin.
 * Without either header (curl, scripts, old clients) the request is allowed.
 * @param array<string,mixed> $server
 */
function request_same_origin(array $server, bool $https, string $publicUrl = ''): bool
{
    $site = strtolower(trim((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site !== '') return $site === 'same-origin' || $site === 'none';
    $origin = strtolower(trim((string)($server['HTTP_ORIGIN'] ?? '')));
    if ($origin === '') return true;
    $own = [($https ? 'https' : 'http') . '://' . strtolower((string)($server['HTTP_HOST'] ?? ''))];
    if ($publicUrl !== '' && ($p = parse_url($publicUrl)) && isset($p['scheme'], $p['host'])) {
        $own[] = strtolower($p['scheme'] . '://' . (str_contains($p['host'], ':') && $p['host'][0] !== '[' ? '[' . $p['host'] . ']' : $p['host']) . (isset($p['port']) ? ':' . $p['port'] : ''));
    }
    return in_array(rtrim($origin, '/'), $own, true);
}
