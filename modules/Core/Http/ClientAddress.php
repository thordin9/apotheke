<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * The browser's address, for the sign-in and registration throttles (SEC-M2,
 * main's client_address_from()). X-Forwarded-For counts only when trust_proxy
 * is on and the connection came from a configured proxy; the client is then the
 * rightmost hop that is not itself a configured proxy. Otherwise it is
 * REMOTE_ADDR. CI4's own getIPAddress() never reads the header here
 * (Config\App::$proxyIPs is empty).
 */
final class ClientAddress
{
    public function __construct(private readonly ProxyList $proxies = new ProxyList())
    {
    }

    /**
     * This request's client (API plan §2.4): X-Apotheke-Client-IP when the edge
     * proxy sent the matching PROXY_SHARED_SECRET; else REAL_IP_HEADER from a
     * listed proxy with trust_proxy on; else from() over X-Forwarded-For.
     *
     * @param array<string,mixed> $server $_SERVER
     * @param list<string> $proxies trusted_proxies
     */
    public function forServer(array $server, bool $trustProxy, array $proxies, string $realIpHeader, string $secret): string
    {
        $get = static fn (string $key): string => is_string($server[$key] ?? null) ? $server[$key] : '';
        $remote = trim($get('REMOTE_ADDR'));
        if ($secret !== '' && hash_equals($secret, $get('HTTP_X_APOTHEKE_PROXY'))) {
            $ip = $this->proxies->normalize(trim($get('HTTP_X_APOTHEKE_CLIENT_IP')));
            if ($ip !== null) {
                return $ip;
            }
        }
        $realIpHeader = trim($realIpHeader);
        if ($realIpHeader !== '' && $trustProxy && $proxies !== [] && $remote !== '' && $this->proxies->allows($remote, $proxies)) {
            $ip = $this->proxies->normalize(trim($get('HTTP_' . strtoupper(str_replace('-', '_', $realIpHeader)))));
            if ($ip !== null) {
                return $ip;
            }
        }

        return $this->from($remote, $get('HTTP_X_FORWARDED_FOR'), $trustProxy, $proxies);
    }

    /** @param list<string> $proxies trusted_proxies */
    public function from(string $remote, string $forwarded, bool $trustProxy, array $proxies): string
    {
        $remote = trim($remote);
        if ($remote === '') {
            $remote = 'cli';
        }
        if (!$trustProxy || $proxies === [] || !$this->proxies->allows($remote, $proxies)) {
            return $this->pick($remote);
        }
        $hops = [];
        foreach (explode(',', $forwarded) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $hops[] = $part;
            }
        }
        $hops[] = $remote;
        $untrusted = array_values(array_filter($hops, fn (string $hop): bool => !$this->proxies->allows($hop, $proxies)));
        if ($untrusted === []) {
            return $this->pick($remote);
        }

        return $this->pick($untrusted[count($untrusted) - 1]);
    }

    private function pick(string $ip): string
    {
        return $this->proxies->normalize($ip) ?? $ip;
    }
}
