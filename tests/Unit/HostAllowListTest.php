<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** SEC-M3: the Host allow-list and the same-origin rule for state-changing requests with sign-in off. */
final class HostAllowListTest extends TestCase
{
    public function testAddressesAndLocalhostAcceptedWithoutConfig(): void
    {
        foreach (['127.0.0.1', '192.168.1.10:9091', '[::1]', '[::1]:9091', '[fd00::10]:443', 'localhost', 'LOCALHOST:9091', 'app.localhost'] as $h) {
            $this->assertTrue(host_allowed($h, []), $h);
        }
    }

    public function testUnknownHostnameRejectedWithoutConfig(): void
    {
        foreach (['other.example', 'other.example:9091', 'apotheke.lan', '192.168.1.10.nip.example', 'localhost.example'] as $h) {
            $this->assertFalse(host_allowed($h, []), $h);
        }
    }

    public function testContainerHostnameAccepted(): void
    {
        $this->assertTrue(host_allowed('3f2a9c1b7d4e', [], '3f2a9c1b7d4e'));
        $this->assertTrue(host_allowed('NAS:9091', [], 'nas'));
        $this->assertFalse(host_allowed('other', [], 'nas'));
    }

    public function testConfiguredPublicUrlAndAllowedHosts(): void
    {
        $list = host_configured('https://inv.example.org:8443', 'apotheke.lan, stock.example.net:9091  shelf.lan');
        $this->assertSame(['inv.example.org', 'apotheke.lan', 'stock.example.net', 'shelf.lan'], $list);
        foreach (['inv.example.org', 'INV.example.org:8443', 'apotheke.lan:9091', 'stock.example.net', 'shelf.lan.'] as $h) {
            $this->assertTrue(host_allowed($h, $list), $h);
        }
        foreach (['example.org', 'x.inv.example.org', 'inv.example.org.other.example', 'lan'] as $h) {
            $this->assertFalse(host_allowed($h, $list), $h);
        }
        $this->assertTrue(host_allowed('10.0.0.5:9091', $list), 'addresses keep working when names are configured');
        $this->assertSame(['fd00::1'], host_configured('http://[fd00::1]:9091', ''));
        $this->assertSame([], host_configured('', ''));
    }

    public function testPortHandling(): void
    {
        $this->assertSame(['apotheke.lan', 9091], host_split('apotheke.lan:9091'));
        $this->assertSame(['apotheke.lan', null], host_split('Apotheke.LAN'));
        $this->assertSame(['::1', 8080], host_split('[::1]:8080'));
        foreach (['localhost:0', 'localhost:65536', 'localhost:99999', 'localhost:', 'localhost:abc', 'a:1:2'] as $h) {
            $this->assertNull(host_split($h), $h);
            $this->assertFalse(host_allowed($h, ['*']), $h);
        }
        $this->assertTrue(host_allowed('localhost:65535', []));
    }

    public function testIpv6NeedsBrackets(): void
    {
        $this->assertTrue(host_allowed('[2001:db8::1]', []));
        $this->assertTrue(host_allowed('[::ffff:192.168.1.10]:9091', []));
        $this->assertFalse(host_allowed('::1', []), 'a Host header must bracket IPv6');
        $this->assertFalse(host_allowed('2001:db8::1', []));
        $this->assertFalse(host_allowed('[not-an-ip]', []));
        $this->assertFalse(host_allowed('[::1', []));
    }

    public function testMalformedHostsRejected(): void
    {
        foreach (['', ' ', 'evil.example/x', 'evil example', 'a..b', '.lan', '-x.lan', 'user@host', "host\r\nX: y", 'h%6fst'] as $h) {
            $this->assertFalse(host_allowed($h, ['*']), var_export($h, true));
        }
    }

    public function testWildcardAcceptsAnyValidHost(): void
    {
        $list = host_configured('', '*');
        $this->assertTrue(host_allowed('anything.example', $list));
        $this->assertFalse(host_allowed('bad host', $list));
    }

    public function testConfiguredListIsReadOnlyForUnknownNames(): void
    {
        $calls = 0;
        $list = function () use (&$calls): array { $calls++; return ['apotheke.lan']; };
        $this->assertTrue(host_allowed('192.168.1.10:9091', $list));
        $this->assertTrue(host_allowed('localhost', $list));
        $this->assertSame(0, $calls, 'built-in names never read the settings');
        $this->assertTrue(host_allowed('apotheke.lan', $list));
        $this->assertSame(1, $calls);
    }

    public function testSafeMethods(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'get'] as $m) $this->assertTrue(method_is_safe($m), $m);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) $this->assertFalse(method_is_safe($m), $m);
    }

    public function testSameOriginBySecFetchSite(): void
    {
        $s = ['HTTP_HOST' => '192.168.1.10:9091'];
        $this->assertTrue(request_same_origin($s + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], false));
        $this->assertTrue(request_same_origin($s + ['HTTP_SEC_FETCH_SITE' => 'none'], false));
        $this->assertFalse(request_same_origin($s + ['HTTP_SEC_FETCH_SITE' => 'same-site'], false));
        $this->assertFalse(request_same_origin($s + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], false));
        $this->assertFalse(request_same_origin($s + ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://192.168.1.10:9091'], false), 'Sec-Fetch-Site wins over Origin');
    }

    public function testSameOriginByOrigin(): void
    {
        $s = ['HTTP_HOST' => '192.168.1.10:9091'];
        $this->assertTrue(request_same_origin($s + ['HTTP_ORIGIN' => 'http://192.168.1.10:9091'], false));
        $this->assertTrue(request_same_origin($s + ['HTTP_ORIGIN' => 'HTTP://192.168.1.10:9091'], false), 'case-insensitive');
        $this->assertFalse(request_same_origin($s + ['HTTP_ORIGIN' => 'https://192.168.1.10:9091'], false), 'other scheme');
        $this->assertFalse(request_same_origin($s + ['HTTP_ORIGIN' => 'http://192.168.1.10'], false), 'other port');
        $this->assertFalse(request_same_origin($s + ['HTTP_ORIGIN' => 'http://other.example'], false));
        $this->assertFalse(request_same_origin($s + ['HTTP_ORIGIN' => 'null'], false));
        $this->assertTrue(request_same_origin(['HTTP_HOST' => 'inv.example.org', 'HTTP_ORIGIN' => 'https://inv.example.org'], true));
        // behind a TLS proxy that is not trusted, the app sees http; PUBLIC_URL's origin still matches
        $this->assertTrue(request_same_origin(['HTTP_HOST' => 'inv.example.org', 'HTTP_ORIGIN' => 'https://inv.example.org'], false, 'https://inv.example.org'));
        $this->assertTrue(request_same_origin(['HTTP_HOST' => 'x', 'HTTP_ORIGIN' => 'http://[fd00::1]:9091'], false, 'http://[fd00::1]:9091'));
        $this->assertFalse(request_same_origin(['HTTP_HOST' => 'inv.example.org', 'HTTP_ORIGIN' => 'https://other.example'], false, 'https://inv.example.org'));
    }

    public function testMissingHeadersAllowed(): void
    {
        $this->assertTrue(request_same_origin(['HTTP_HOST' => '192.168.1.10:9091'], false), 'curl and old clients send neither header');
        $this->assertTrue(request_same_origin([], false));
    }
}
