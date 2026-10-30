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

    /** API plan §2.4, edge: X-Apotheke-Client-IP counts only with the matching proxy secret. */
    public function testTheEdgeHeaderNeedsTheSharedSecret(): void
    {
        $c = new ClientAddress();
        $edge = ['REMOTE_ADDR' => '10.0.0.9', 'HTTP_X_APOTHEKE_CLIENT_IP' => '198.51.100.20', 'HTTP_X_APOTHEKE_PROXY' => 'edge-secret-1'];
        $this->assertSame('198.51.100.20', $c->forServer($edge, false, [], '', 'edge-secret-1'));
        $this->assertSame('10.0.0.9', $c->forServer($edge, false, [], '', 'edge-secret-2'), 'wrong secret');
        $this->assertSame('10.0.0.9', $c->forServer($edge, false, [], '', ''), 'no secret configured');
        $this->assertSame('10.0.0.9', $c->forServer(['HTTP_X_APOTHEKE_PROXY' => ''] + $edge, false, [], '', 'edge-secret-1'), 'empty secret sent');
        $this->assertSame('10.0.0.9', $c->forServer(['HTTP_X_APOTHEKE_CLIENT_IP' => 'junk'] + $edge, false, [], '', 'edge-secret-1'), 'not an address');
        $this->assertSame('2001:db8::5', $c->forServer(['HTTP_X_APOTHEKE_CLIENT_IP' => '2001:0db8::0005'] + $edge, false, [], '', 'edge-secret-1'));
    }

    /** API plan §2.4, self-host: REAL_IP_HEADER counts only from a listed proxy with trust_proxy on. */
    public function testTheRealIpHeaderNeedsATrustedPeer(): void
    {
        $c = new ClientAddress();
        $server = ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.20', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];
        $this->assertSame('198.51.100.20', $c->forServer($server, true, ['192.0.2.0/24'], 'CF-Connecting-IP', ''));
        $this->assertSame('192.0.2.1', $c->forServer($server, false, ['192.0.2.0/24'], 'CF-Connecting-IP', ''), 'trust_proxy off');
        $this->assertSame('203.0.113.4', $c->forServer(['REMOTE_ADDR' => '203.0.113.4'] + $server, true, ['192.0.2.0/24'], 'CF-Connecting-IP', ''), 'untrusted peer');
        $this->assertSame('192.0.2.1', $c->forServer($server, true, [], 'CF-Connecting-IP', ''), 'no proxies listed');
        $this->assertSame('203.0.113.9', $c->forServer(['HTTP_CF_CONNECTING_IP' => 'junk'] + $server, true, ['192.0.2.0/24'], 'CF-Connecting-IP', ''), 'bad header: X-Forwarded-For as before');
        $this->assertSame('203.0.113.9', $c->forServer($server, true, ['192.0.2.0/24'], '', ''), 'no header set: X-Forwarded-For as before');
        $this->assertSame('cli', $c->forServer([], true, ['192.0.2.0/24'], 'CF-Connecting-IP', 'edge-secret-1'));
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
