<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\HostAllowList;
use Apotheke\Core\Http\HostCheck;
use PHPUnit\Framework\TestCase;

/**
 * SEC-M3: the Host allow-list. The cases are main's tests/Unit/HostAllowListTest.php
 * (legacy hosts.php), so CI4 accepts and refuses exactly the same names.
 */
final class HostAllowListTest extends TestCase
{
    public function testAddressesAndLocalhostAcceptedWithoutConfig(): void
    {
        foreach (['127.0.0.1', '192.168.1.10:9091', '[::1]', '[::1]:9091', '[fd00::10]:443', 'localhost', 'LOCALHOST:9091', 'app.localhost'] as $h) {
            $this->assertTrue($this->list()->allowed($h, []), $h);
        }
    }

    public function testUnknownHostnameRejectedWithoutConfig(): void
    {
        foreach (['other.example', 'other.example:9091', 'apotheke.lan', '192.168.1.10.nip.example', 'localhost.example'] as $h) {
            $this->assertFalse($this->list()->allowed($h, []), $h);
        }
    }

    public function testContainerHostnameAccepted(): void
    {
        $this->assertTrue((new HostAllowList('3f2a9c1b7d4e'))->allowed('3f2a9c1b7d4e', []));
        $this->assertTrue((new HostAllowList('nas'))->allowed('NAS:9091', []));
        $this->assertFalse((new HostAllowList('nas'))->allowed('other', []));
    }

    public function testConfiguredPublicUrlAndAllowedHosts(): void
    {
        $list = $this->list()->configured('https://inv.example.org:8443', 'apotheke.lan, stock.example.net:9091  shelf.lan');
        $this->assertSame(['inv.example.org', 'apotheke.lan', 'stock.example.net', 'shelf.lan'], $list);
        foreach (['inv.example.org', 'INV.example.org:8443', 'apotheke.lan:9091', 'stock.example.net', 'shelf.lan.'] as $h) {
            $this->assertTrue($this->list()->allowed($h, $list), $h);
        }
        foreach (['example.org', 'x.inv.example.org', 'inv.example.org.other.example', 'lan'] as $h) {
            $this->assertFalse($this->list()->allowed($h, $list), $h);
        }
        $this->assertTrue($this->list()->allowed('10.0.0.5:9091', $list), 'addresses keep working when names are configured');
        $this->assertSame(['fd00::1'], $this->list()->configured('http://[fd00::1]:9091', ''));
        $this->assertSame([], $this->list()->configured('', ''));
    }

    public function testPortHandling(): void
    {
        $this->assertSame(['apotheke.lan', 9091], $this->list()->split('apotheke.lan:9091'));
        $this->assertSame(['apotheke.lan', null], $this->list()->split('Apotheke.LAN'));
        $this->assertSame(['::1', 8080], $this->list()->split('[::1]:8080'));
        foreach (['localhost:0', 'localhost:65536', 'localhost:99999', 'localhost:', 'localhost:abc', 'a:1:2'] as $h) {
            $this->assertNull($this->list()->split($h), $h);
            $this->assertFalse($this->list()->allowed($h, ['*']), $h);
        }
        $this->assertTrue($this->list()->allowed('localhost:65535', []));
    }

    public function testIpv6NeedsBrackets(): void
    {
        $this->assertTrue($this->list()->allowed('[2001:db8::1]', []));
        $this->assertTrue($this->list()->allowed('[::ffff:192.168.1.10]:9091', []));
        $this->assertFalse($this->list()->allowed('::1', []), 'a Host header must bracket IPv6');
        $this->assertFalse($this->list()->allowed('2001:db8::1', []));
        $this->assertFalse($this->list()->allowed('[not-an-ip]', []));
        $this->assertFalse($this->list()->allowed('[::1', []));
    }

    public function testMalformedHostsRejected(): void
    {
        foreach (['', ' ', 'evil.example/x', 'evil example', 'a..b', '.lan', '-x.lan', 'user@host', "host\r\nX: y", 'h%6fst'] as $h) {
            $this->assertFalse($this->list()->allowed($h, ['*']), var_export($h, true));
        }
    }

    public function testWildcardAcceptsAnyValidHost(): void
    {
        $list = $this->list()->configured('', '*');
        $this->assertTrue($this->list()->allowed('anything.example', $list));
        $this->assertFalse($this->list()->allowed('bad host', $list));
    }

    public function testConfiguredListIsReadOnlyForUnknownNames(): void
    {
        $calls = 0;
        $list = function () use (&$calls): array { $calls++; return ['apotheke.lan']; };
        $this->assertTrue($this->list()->allowed('192.168.1.10:9091', $list));
        $this->assertTrue($this->list()->allowed('localhost', $list));
        $this->assertSame(0, $calls, 'built-in names never read the settings');
        $this->assertTrue($this->list()->allowed('apotheke.lan', $list));
        $this->assertSame(1, $calls);
    }

    public function testARequestWithoutHostPassesAndOthersReadTheListLazily(): void
    {
        $calls = 0;
        $check = new HostCheck(new HostAllowList('nas'), function () use (&$calls): array {
            $calls++;

            return ['apotheke.lan'];
        });
        $this->assertTrue($check->passes(''), 'HTTP/1.0 tools send no Host');
        $this->assertTrue($check->passes('[::1]:9091'));
        $this->assertTrue($check->passes('NAS'));
        $this->assertSame(0, $calls);
        $this->assertTrue($check->passes('apotheke.lan:9091'));
        $this->assertFalse($check->passes('other.example'));
        $this->assertSame(2, $calls);
    }

    private function list(): HostAllowList
    {
        return new HostAllowList();
    }
}
