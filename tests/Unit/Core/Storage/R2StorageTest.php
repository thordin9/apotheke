<?php

declare(strict_types=1);

use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Core\Storage\R2StorageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class R2StorageTest extends TestCase
{
    /** @var list<array{method:string,url:string,body:?string}> */
    private array $calls = [];

    private function storage(array|string|\Throwable $response): R2Storage
    {
        $this->calls = [];
        $response = is_array($response) ? $response : ['status' => 200, 'body' => $response];
        $transport = function (string $method, string $url, $body) use ($response): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => is_resource($body) ? self::readBody($body) : null];
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        };
        return new R2Storage(new R2Bridge(transport: $transport));
    }

    /** @param resource $body */
    private static function readBody($body): string
    {
        $content = stream_get_contents($body);
        if ($content === false) {
            throw new RuntimeException('Could not read test request');
        }
        rewind($body);

        return $content;
    }

    private static function ok(array $extra = []): string
    {
        return json_encode(['ok' => true] + $extra, JSON_THROW_ON_ERROR);
    }

    public function testReadEscapesKeyAndPreservesBinaryBytes(): void
    {
        $s = $this->storage(self::ok(['found' => true, 'bytes' => base64_encode("\0\xffdemo")]));
        $this->assertSame("\0\xffdemo", $s->read('media/full/a b/Å&%.jpg'));
        $this->assertSame('http://r2.internal/media/full/a%20b/%C3%85%26%25.jpg', $this->calls[0]['url']);
    }

    public function testReadMissingHasNoPayload(): void
    {
        $this->assertNull($this->storage(['status' => 404, 'body' => 'bucket-secret'])->read('empty.txt'));
        $this->assertNull($this->storage(self::ok(['found' => false]))->read('empty.txt'));
    }

    public function testWriteStreamSpoolsTheEntireSourceBeforeSending(): void
    {
        $s = $this->storage(self::ok());
        $payload = str_repeat('payload-', 600);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "\0" . $payload . "\xff");
        rewind($stream);
        $s->writeStream('demo/a.txt', $stream);
        fclose($stream);
        $this->assertSame('PUT', $this->calls[0]['method']);
        $this->assertSame("\0" . $payload . "\xff", $this->calls[0]['body']);
    }

    public function testWriteStreamRejectsANonStreamBeforeCallingTransport(): void
    {
        $s = $this->storage(self::ok());
        $this->expectException(\InvalidArgumentException::class);
        try {
            $s->writeStream('demo/a.txt', 'not a stream');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public function testExistsDeleteAndModifiedFollowStorageContract(): void
    {
        $this->assertTrue($this->storage(self::ok(['found' => true]))->exists('a'));
        $this->assertFalse($this->storage(self::ok(['found' => false]))->exists('a'));
        $this->assertSame(123456789, $this->storage(self::ok(['found' => true, 'modified' => 123456789]))->modifiedAt('a'));
        $this->assertNull($this->storage(['status' => 404, 'body' => 'must not disclose'])->modifiedAt('a'));
        $this->storage(['status' => 404, 'body' => ''])->delete('a');
        $this->storage(self::ok())->delete('a');
        $this->assertSame('DELETE', $this->calls[0]['method']);
    }

    #[DataProvider('invalidKeys')]
    public function testUnsafeKeysAreRefusedBeforeNetwork(string $key): void
    {
        $s = $this->storage(self::ok());
        $this->assertNull($s->read($key));
        $this->assertFalse($s->exists($key));
        $this->assertNull($s->modifiedAt($key));
        $s->delete($key);
        $this->assertSame([], $this->calls);
        $this->expectException(\InvalidArgumentException::class);
        try {
            $s->write($key, 'not stored');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public static function invalidKeys(): iterable
    {
        foreach (['', '/etc/passwd', '../secret', 'a/../secret', 'a//b', './a', 'a/.', 'a\\b', "a\0b", "a\nb", 'a?b', 'a#b'] as $key) {
            yield [$key];
        }
    }

    #[DataProvider('failures')]
    public function testGenericFailureDoesNotLeakTransportOrResponse(array|string|\Throwable $response): void
    {
        $s = $this->storage($response);
        try {
            $s->read('health');
            $this->fail('Expected a storage failure');
        } catch (R2StorageException $e) {
            $this->assertSame('Object storage request failed.', $e->getMessage());
            $this->assertStringNotContainsString('bucket-secret', $e->getMessage());
            $this->assertStringNotContainsString('reset_credentials', $e->getMessage());
        }
    }

    public static function failures(): iterable
    {
        yield 'transport exception' => [new \RuntimeException('reset_credentials')];
        yield 'redirect must not be followed' => [['status' => 302, 'body' => 'reset_credentials']];
        yield 'authentication redirection response forbidden' => [['status' => 401, 'body' => 'bucket-secret']];
        yield 'server failure' => [['status' => 500, 'body' => 'bucket-secret']];
        yield 'malformed JSON' => ['not-json'];
        yield 'root mismatch' => ['[]'];
        yield 'false status' => [self::succeedingJson(false)];
        yield 'absent success flag' => ['{"found":true,"bytes":""}'];
        yield 'bytes required on read' => [self::ok(['found' => true])];
        yield 'incomplete response status' => [['status' => '200', 'body' => self::ok()]];
        yield 'missing presence flag' => [self::ok(['bytes' => ''])];
        yield 'invalid presence flag' => [self::ok(['found' => 0, 'bytes' => ''])];
        yield 'payload must be a string' => [self::ok(['found' => true, 'bytes' => []])];
        yield 'noncanonical Base64 must fail closed' => [self::ok(['found' => true, 'bytes' => '!!!not_base64!!!'])];
        yield 'oversized response' => [['status' => 200, 'body' => str_repeat('x', 67108865)]];
        yield 'type error is not leaked' => [new R2StorageException('internal-secret')];
    }

    private static function succeedingJson(bool $ok): string
    {
        return json_encode(['ok' => $ok, 'found' => true, 'bytes' => 'anything'], JSON_THROW_ON_ERROR);
    }

    #[DataProvider('invalidBaseUrls')]
    public function testBaseUrlIsNotARedirectOrArbitraryUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new R2Bridge($url, static fn (): array => ['status' => 200, 'body' => self::ok()]);
    }

    public static function invalidBaseUrls(): iterable
    {
        foreach (['https://user:pass@r2.internal', 'http://r2.internal/path', 'http://r2.internal?x=1', 'http://r2.internal/#anchor', 'ftp://r2.internal', 'http:///missing', 'http://r2.internal@evil.example'] as $url) {
            yield [$url];
        }
    }

    public function testPresenceRequiresValidModifiedMetadata(): void
    {
        foreach (['1', -1, 1.5] as $value) {
            try {
                $this->storage(self::ok(['found' => true, 'modified' => $value]))->modifiedAt('a');
                $this->fail('Expected invalid metadata to be refused');
            } catch (R2StorageException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse($this->storage(self::ok(['found' => false]))->exists('a'));
    }

    public function testLargeWriteStreamSpoolsWithoutGrowingPhpMemory(): void
    {
        $transport = function (): array {
            $this->calls[] = ['method' => 'PUT', 'url' => 'http://r2.internal/large.bin', 'body' => null];

            return ['status' => 200, 'body' => self::ok()];
        };
        $storage = new R2Storage(new R2Bridge(transport: $transport));
        $stream = fopen('php://temp', 'w+b');
        $chunk = str_repeat('x', 65536);
        for ($i = 0; $i < 80; ++$i) {
            fwrite($stream, $chunk);
        }
        rewind($stream);
        $before = memory_get_usage(true);
        $storage->writeStream('large.bin', $stream);
        fclose($stream);
        $this->assertLessThan(2097152, memory_get_usage(true) - $before);
        $this->assertSame('http://r2.internal/large.bin', $this->calls[0]['url']);
    }

    public function testWriteStreamAcceptsANonSeekableSourceAndBlankBytes(): void
    {
        $s = $this->storage(self::ok());
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        fwrite($pair[1], "\0stream-body\xff");
        fclose($pair[1]);
        $s->writeStream('demo/socket', $pair[0]);
        fclose($pair[0]);
        $this->assertSame("\0stream-body\xff", $this->calls[0]['body']);

        $s->write('empty.bin', '');
        $this->assertSame('', $this->calls[1]['body']);
    }

    public function testStreamSourceReadFailureHasNoPartialWrite(): void
    {
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', R2FailingStream::class);
        $stream = fopen('php://memory', 'rb');
        stream_wrapper_restore('php');
        $storage = $this->storage(self::ok());
        try {
            $storage->writeStream('failed.bin', $stream);
            $this->fail('Expected stream failure');
        } catch (R2StorageException) {
            $this->assertSame([], $this->calls);
        } finally {
            fclose($stream);
        }
    }

    public function testBridgeReadAlsoValidatesKeyWithoutMaskingTransportMisuse(): void
    {
        $calls = [];
        $bridge = new R2Bridge(transport: static function (...$args) use (&$calls): array {
            $calls[] = $args;

            return ['status' => 200, 'body' => self::ok()];
        });
        try {
            $bridge->request('GET', '/absolute');
            $this->fail('An invalid bridge key must be refused before transport');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Invalid object storage key.', $e->getMessage());
        }
        $this->assertSame([], $calls);
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new R2Bridge(timeout: 0, transport: static fn (): array => ['status' => 200, 'body' => self::ok()]);
    }

    public function testBridgeAlsoRefusesUnsafeKeysWithoutAnyTransportCall(): void
    {
        $storage = $this->storage(self::ok());
        foreach (self::invalidKeys() as [$key]) {
            try {
                R2Bridge::validateKey($key);
                $this->fail('Bridge should refuse key ' . bin2hex($key));
            } catch (\InvalidArgumentException) {
                $storage->delete($key);
            }
        }
        $this->assertSame([], $this->calls);
$bridge = new R2Bridge(transport: static function () use (&$calls): array {
            $calls[] = func_get_args();

            return ['status' => 200, 'body' => self::ok()];
        });
        foreach (self::invalidKeys() as [$key]) {
            try {
                $bridge->request('GET', $key);
                $this->fail('A request path must validate before the transport');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        R2Bridge::validateKey('a/key');
        $this->addToAssertionCount(count(iterator_to_array(self::invalidKeys())));
    }

    public function testAllowsExplicitInternalTestEndpointAndEscapesAllSegments(): void
    {
        $calls = [];
        $bridge = new R2Bridge('http://127.0.0.1:8090', static function (...$args) use (&$calls): array {
            $calls[] = $args;

            return ['status' => 200, 'body' => self::ok(['found' => false])];
        });
        $s = new R2Storage($bridge);
        $this->assertNull($s->read('folder/a+b ü'));
        $this->assertSame('http://127.0.0.1:8090/folder/a%2Bb%20%C3%BC', $calls[0][1]);
    }
}


/** Native fread failures must not leak a partial object request. */
final class R2FailingStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }
}
