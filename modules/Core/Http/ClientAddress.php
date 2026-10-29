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
