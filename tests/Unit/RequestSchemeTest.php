<?php

declare(strict_types=1);

use Apotheke\Core\Http\RequestScheme;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Http/RequestScheme.php';

final class RequestSchemeTest extends TestCase
{
    public function testDirectHttpsAndPort443(): void
    {
        $scheme = new RequestScheme(false);
        $this->assertTrue($scheme->isHttps(['HTTPS' => 'on']));
        $this->assertTrue($scheme->isHttps(['HTTPS' => '1', 'SERVER_PORT' => 80]));
        $this->assertFalse($scheme->isHttps(['HTTPS' => 'off']));
        $this->assertTrue($scheme->isHttps(['SERVER_PORT' => 443]));
        $this->assertTrue($scheme->secureCookie(['HTTPS' => 'on']));
    }

    public function testForwardedProtoNeedsTrustAndUsesTheFirstValue(): void
    {
        $off = new RequestScheme(false);
        $on = new RequestScheme(true);
        $header = ['HTTP_X_FORWARDED_PROTO' => ' HTTPS , http'];
        $this->assertFalse($off->isHttps($header));
        $this->assertTrue($on->isHttps($header));
        $this->assertFalse($on->isHttps(['HTTP_X_FORWARDED_PROTO' => 'http, https']));
        $this->assertTrue($on->secureCookie(['HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testPublicBasePrefersTheConfiguredUrl(): void
    {
        $scheme = new RequestScheme(false);
        $any = static fn (string $host): bool => true;
        $none = static fn (string $host): bool => false;
        $this->assertSame('https://catalog.example', $scheme->publicBase(['HTTP_HOST' => 'ignored'], $any, 'https://catalog.example/'));
        $this->assertSame('http://phone.local', $scheme->publicBase(['HTTP_HOST' => 'phone.local'], $any));
        $this->assertSame('https://phone.local', $scheme->publicBase(['HTTP_HOST' => 'phone.local', 'HTTPS' => 'on'], $any));
        $this->assertSame('http://localhost', $scheme->publicBase(['HTTP_HOST' => 'phone.local'], $none), 'a Host that fails the allow-list is never used');
        $this->assertSame('http://localhost', $scheme->publicBase([], $any));
        $this->assertSame('http://phone.local:9091', $scheme->publicBase(['HTTP_HOST' => 'Phone.LOCAL:9091'], $any));
    }
}
