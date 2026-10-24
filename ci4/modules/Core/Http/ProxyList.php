<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * Your own reverse proxies (TRUSTED_PROXIES / trusted_proxies): IP addresses
 * and CIDR blocks, IPv4 and IPv6 (SEC-M2). A port of main's config_proxy_list(),
 * config_ip_normalize(), config_ip_matches() and config_proxy_allows().
 */
final class ProxyList
{
    /**
     * A comma- or space-separated string, or a list. Anything that is not an
     * address or a block is dropped.
     *
     * @return list<string>
     */
    public function parse(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $item = trim((string)$item);
            if ($item === '' || !$this->ruleOk($item)) {
                continue;
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    /** Canonical IPv4 or IPv6 text, or null when $ip is not an address. */
    public function normalize(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip !== '' && $ip[0] === '[' && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $packed = inet_pton($ip);

        return $packed === false ? null : (inet_ntop($packed) ?: null);
    }

    /** @param list<string> $proxies */
    public function allows(string $ip, array $proxies): bool
    {
        foreach ($proxies as $rule) {
            if ($this->matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    public function matches(string $ip, string $rule): bool
    {
        $ip = $this->normalize($ip);
        if ($ip === null) {
            return false;
        }
        if (!str_contains($rule, '/')) {
            $ruleIp = $this->normalize($rule);

            return $ruleIp !== null && $ip === $ruleIp;
        }
        [$net, $bits] = explode('/', $rule, 2);
        if ($bits === '' || !ctype_digit($bits)) {
            return false;
        }
        $netIp = $this->normalize($net);
        if ($netIp === null) {
            return false;
        }
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($netIp);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bitsN = (int)$bits;
        if ($bitsN > strlen($ipBin) * 8) {
            return false;
        }
        $bytes = intdiv($bitsN, 8);
        $rest = $bitsN % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    private function ruleOk(string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $this->normalize($rule) !== null;
        }
        [$net, $bits] = explode('/', $rule, 2);
        if ($bits === '' || !ctype_digit($bits)) {
            return false;
        }
        $ip = $this->normalize($net);
        if ($ip === null) {
            return false;
        }

        return (int)$bits <= (str_contains($ip, ':') ? 128 : 32);
    }
}
