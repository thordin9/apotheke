<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/dev_server.php';

final class DevServerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/public';
    }

    public function testServesAssetsUnderDocroot(): void
    {
        $this->assertTrue(dev_server_static_file($this->root, '/assets/icon.svg'));
        $this->assertTrue(dev_server_static_file($this->root, '/manifest.webmanifest'));
    }

    public function testRejectsPhpAndMissing(): void
    {
        $this->assertFalse(dev_server_static_file($this->root, '/index.php'));
        $this->assertFalse(dev_server_static_file($this->root, '/'));
        $this->assertFalse(dev_server_static_file($this->root, '/no-such-file.css'));
    }

    public function testRejectsPathTraversalOutsideDocroot(): void
    {
        $this->assertFalse(dev_server_static_file($this->root, '/../composer.json'));
        $this->assertFalse(dev_server_static_file($this->root, '/assets/../../composer.json'));
        $this->assertFalse(dev_server_static_file($this->root, '/../config.example.php'));
        $this->assertFalse(dev_server_static_file($this->root, '/../README.md'));
    }
}
