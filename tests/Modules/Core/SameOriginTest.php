<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\SameOrigin;
use PHPUnit\Framework\TestCase;

/** SEC-M3: the same-origin rule for no-login mode. The cases are main's tests/Unit/HostAllowListTest.php. */
final class SameOriginTest extends TestCase
{
    public function testSafeMethods(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'get'] as $m) $this->assertTrue($this->rule()->isSafe($m), $m);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) $this->assertFalse($this->rule()->isSafe($m), $m);
    }

    public function testSameOriginBySecFetchSite(): void
    {
        $s = ['HTTP_HOST' => '192.168.1.10:9091'];
        $this->assertTrue($this->rule()->check($s + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], false));
        $this->assertTrue($this->rule()->check($s + ['HTTP_SEC_FETCH_SITE' => 'none'], false));
        $this->assertFalse($this->rule()->check($s + ['HTTP_SEC_FETCH_SITE' => 'same-site'], false));
        $this->assertFalse($this->rule()->check($s + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], false));
        $this->assertFalse($this->rule()->check($s + ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://192.168.1.10:9091'], false), 'Sec-Fetch-Site wins over Origin');
    }

    public function testSameOriginByOrigin(): void
    {
        $s = ['HTTP_HOST' => '192.168.1.10:9091'];
        $this->assertTrue($this->rule()->check($s + ['HTTP_ORIGIN' => 'http://192.168.1.10:9091'], false));
        $this->assertTrue($this->rule()->check($s + ['HTTP_ORIGIN' => 'HTTP://192.168.1.10:9091'], false), 'case-insensitive');
        $this->assertFalse($this->rule()->check($s + ['HTTP_ORIGIN' => 'https://192.168.1.10:9091'], false), 'other scheme');
        $this->assertFalse($this->rule()->check($s + ['HTTP_ORIGIN' => 'http://192.168.1.10'], false), 'other port');
        $this->assertFalse($this->rule()->check($s + ['HTTP_ORIGIN' => 'http://other.example'], false));
        $this->assertFalse($this->rule()->check($s + ['HTTP_ORIGIN' => 'null'], false));
        $this->assertTrue($this->rule()->check(['HTTP_HOST' => 'inv.example.org', 'HTTP_ORIGIN' => 'https://inv.example.org'], true));
        // behind a TLS proxy that is not trusted, the app sees http; PUBLIC_URL's origin still matches
        $this->assertTrue($this->rule()->check(['HTTP_HOST' => 'inv.example.org', 'HTTP_ORIGIN' => 'https://inv.example.org'], false, 'https://inv.example.org'));
        $this->assertTrue($this->rule()->check(['HTTP_HOST' => 'x', 'HTTP_ORIGIN' => 'http://[fd00::1]:9091'], false, 'http://[fd00::1]:9091'));
        $this->assertFalse($this->rule()->check(['HTTP_HOST' => 'inv.example.org', 'HTTP_ORIGIN' => 'https://other.example'], false, 'https://inv.example.org'));
    }

    public function testMissingHeadersAllowed(): void
    {
        $this->assertTrue($this->rule()->check(['HTTP_HOST' => '192.168.1.10:9091'], false), 'curl and old clients send neither header');
        $this->assertTrue($this->rule()->check([], false));
    }

    private function rule(): SameOrigin
    {
        return new SameOrigin();
    }
}
