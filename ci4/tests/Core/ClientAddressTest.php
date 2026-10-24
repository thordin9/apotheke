<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\ClientAddress;
use Apotheke\Core\Http\ProxyList;
use PHPUnit\Framework\TestCase;

/** SEC-M2: the client address. The cases are main's PermissionsTest and config helpers. */
final class ClientAddressTest extends TestCase
{
    public function testForwardedForCountsOnlyFromConfiguredProxies(): void
    {
        $proxies = ['192.0.2.1', '2001:db8::1', '192.0.2.0/24'];
        $c = new ClientAddress();
        $this->assertSame('192.0.2.1', $c->from('192.0.2.1', '198.51.100.20', false, $proxies), 'trust_proxy off');
        $this->assertSame('203.0.113.4', $c->from('203.0.113.4', '198.51.100.20', true, $proxies), 'peer is not a proxy');
        $this->assertSame('192.0.2.1', $c->from('192.0.2.1', '198.51.100.20', true, []), 'no proxies configured');
        $this->assertSame('198.51.100.20', $c->from('192.0.2.1', '198.51.100.20', true, $proxies));
        $this->assertSame('198.51.100.20', $c->from('192.0.2.1', '203.0.113.9, 198.51.100.20', true, $proxies), 'rightmost non-proxy hop');
        $this->assertSame('198.51.100.20', $c->from('192.0.2.1', '198.51.100.20, 2001:db8::1', true, $proxies));
        $this->assertSame('198.51.100.20', $c->from('192.0.2.15', '198.51.100.20', true, $proxies), 'peer inside a block');
        $this->assertSame('192.0.3.15', $c->from('192.0.3.15', '198.51.100.20', true, $proxies));
        $this->assertSame('192.0.2.1', $c->from('192.0.2.1', '192.0.2.1', true, $proxies), 'only proxies: the peer');
        $this->assertSame('cli', $c->from('', '198.51.100.20', true, $proxies));
        $this->assertSame('2001:db8::5', $c->from('2001:0db8::0005', '', false, []), 'canonical IPv6');
    }

    public function testBlocksAndAddresses(): void
    {
        $list = new ProxyList();
        $this->assertTrue($list->matches('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse($list->matches('11.1.2.3', '10.0.0.0/8'));
        $this->assertTrue($list->matches('192.0.2.130', '192.0.2.128/25'));
        $this->assertFalse($list->matches('192.0.2.127', '192.0.2.128/25'));
        $this->assertTrue($list->matches('fd00::1', 'fd00::/8'));
        $this->assertTrue($list->matches('[::1]', '::1'));
        $this->assertTrue($list->matches('1.2.3.4', '0.0.0.0/0'));
        $this->assertFalse($list->matches('1.2.3.4', '::/0'), 'no IPv4 inside an IPv6 block');
        $this->assertFalse($list->matches('1.2.3.4', '1.2.3.4/33'));
        $this->assertFalse($list->matches('not-an-ip', '0.0.0.0/0'));
        $this->assertSame(['10.0.0.0/8', '::1'], $list->parse("10.0.0.0/8,::1 , junk"));
        $this->assertSame([], $list->parse(null));
    }
}
