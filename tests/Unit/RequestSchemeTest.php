<?php

declare(strict_types=1);

use Apotheke\Core\Http\RequestScheme;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/Core/Http/RequestScheme.php';

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

    public function testTrustProxyEnvironmentEnablesForwardedHttps(): void
    {
        $old = getenv('TRUST_PROXY');
        try {
            putenv('TRUST_PROXY=1');
            $scheme = RequestScheme::fromSetting(false);
            $header = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'app.example.test'];
            $this->assertTrue($scheme->isHttps($header));
            $this->assertTrue($scheme->secureCookie($header));
            $this->assertSame('https://app.example.test', $scheme->publicBase($header, static fn (string $host): bool => true));
            putenv('TRUST_PROXY=0');
            $this->assertFalse(RequestScheme::fromSetting(false)->isHttps($header), 'TRUST_PROXY=0 does not force trust');
            putenv('TRUST_PROXY');
            $this->assertFalse(RequestScheme::fromSetting(false)->isHttps($header), 'unset leaves the setting off');
            $this->assertTrue(RequestScheme::fromSetting(true)->isHttps($header), 'the Control Panel setting still works');
        } finally {
            if ($old === false) {
                putenv('TRUST_PROXY');
            } else {
                putenv('TRUST_PROXY=' . $old);
            }
        }
    }
}
