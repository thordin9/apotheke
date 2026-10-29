<?php

declare(strict_types=1);

namespace Tests\Core;

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/** SEC-M3: CI4's baseURL never uses a Host that fails the allow-list. */
final class AppBaseUrlTest extends CIUnitTestCase
{
    /** @var array<string,mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        putenv('PUBLIC_URL');
        putenv('ALLOWED_HOSTS');
        parent::tearDown();
    }

    public function testBaseUrlUsesOnlyAnAllowedHost(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'rebind-check.example';
        $this->assertSame('http://localhost/', (new App())->baseURL);
        $_SERVER['HTTP_HOST'] = '192.168.1.10:9091';
        $this->assertSame('http://192.168.1.10:9091/', (new App())->baseURL);
        $_SERVER['HTTP_HOST'] = 'Shelf.LAN';
        $this->assertSame('http://localhost/', (new App())->baseURL);
        putenv('ALLOWED_HOSTS=shelf.lan, *');
        $app = new App();
        $this->assertSame('http://shelf.lan/', $app->baseURL);
        $this->assertSame(['shelf.lan'], $app->allowedHostnames, '"*" is not a host name for CI4');
    }

    public function testPublicUrlWins(): void
    {
        putenv('PUBLIC_URL=https://inv.example.org:8443/');
        putenv('ALLOWED_HOSTS=apotheke.lan');
        $_SERVER['HTTP_HOST'] = '192.168.1.10:9091';
        $app = new App();
        $this->assertSame('https://inv.example.org:8443/', $app->baseURL);
        $this->assertSame(['inv.example.org', 'apotheke.lan'], $app->allowedHostnames);
    }
}
