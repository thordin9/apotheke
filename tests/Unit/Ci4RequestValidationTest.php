<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Web requests get their consent, sign-in and CSRF answers first: a small body that fills no form fields (JSON,
 * text) is not "too large", and only a body past post_max_size is 413.
 */
final class Ci4RequestValidationTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('request-validation');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4', null, ['post_max_size' => '1M']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    public function testASmallBodyWithoutFormFieldsGetsTheUsualAnswerNot413(): void
    {
        $c = self::$server->client();
        $fetch = ['Accept: application/json', 'X-Requested-With: fetch'];
        foreach (['application/json' => '{"a":1}', 'text/plain' => 'hello', 'application/x-www-form-urlencoded' => ''] as $type => $body) {
            $page = $c->req('POST', '/login', [], [], ['Content-Type: ' . $type], $body);
            $this->assertSame(419, $page['status'], $type . ': the sign-in form asks for its token');
            $this->assertStringNotContainsString('too large', $page['body'], $type);
            $json = $c->req('POST', '/login', [], [], array_merge(['Content-Type: ' . $type], $fetch), $body);
            $this->assertSame(419, $json['status'], $type);
            $this->assertFalse($json['json']['ok'] ?? null, $type);
            foreach (['/item/1/fav', '/save'] as $path) {
                $this->assertSame(303, $c->req('POST', $path, [], [], ['Content-Type: ' . $type], $body)['status'], $type . ' ' . $path . ': signed out');
                $r = $c->req('POST', $path, [], [], array_merge(['Content-Type: ' . $type], $fetch), $body);
                $this->assertSame([401, 'Not signed in'], [$r['status'], $r['json']['error'] ?? null], $type . ' ' . $path);
            }
            $this->assertSame(403, $c->req('POST', '/consent', [], [], ['Content-Type: ' . $type], $body)['status'], $type . ': /consent answers as before');
        }
    }

    public function testABodyPastPostMaxSizeIs413(): void
    {
        $c = self::$server->client();
        foreach (['/login', '/save', '/item/1/fav'] as $path) {
            $r = $c->req('POST', $path, [], [], ['Content-Type: application/x-www-form-urlencoded'], 'a=' . str_repeat('x', 1100000));
            $this->assertSame(413, $r['status'], $path);
            $this->assertStringContainsString('Upload too large (max 1M).', $r['body'], $path);
            $r = $c->req('POST', $path, [], [], ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: fetch'], str_repeat('x', 1100000));
            $this->assertSame([413, false], [$r['status'], $r['json']['ok'] ?? null], $path);
        }
    }
}
