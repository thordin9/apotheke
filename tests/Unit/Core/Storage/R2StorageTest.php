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


    /** @param list<string> $actions @param list<string|false|null> $parts */
    private function multipartStorage(array &$actions, array &$parts): R2Storage
    {
        $completed = null;
        $expected = [];
        return new R2Storage(new R2Bridge(transport: function ($method, $url, $body) use (&$actions, &$parts, &$completed, &$expected): array {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
            $action = $q['action'] ?? 'put';
            if ($action === 'begin') $expected = [];
            $actions[] = $action;
            $parts[] = is_resource($body) ? self::readBody($body) : null;
            $bytes = is_resource($body) ? end($parts) : null;
            $result = ['upload_id' => 'transport-session', 'bytes' => is_string($bytes) ? strlen($bytes) : 0,
                'part_number' => (int)($q['part'] ?? 0), 'etag' => 'fixture'];
            if ($action === 'part') {
                $this->assertSame(strlen($bytes), (int)$q['size']);
                $expected[] = ['partNumber' => (int)$q['part'], 'etag' => 'fixture'];
                $this->assertSame(count($expected), (int)$q['part']);
            }
            if ($action === 'complete') $completed = json_decode($q['parts'], true, 8, JSON_THROW_ON_ERROR);
            if ($action === 'complete') $this->assertSame($expected, $completed);
            return ['status' => 200, 'body' => self::ok($result)];
        }));
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

    public function testWriteStreamMultipartPreservesBinaryBytesAndNonSeekableSources(): void
    {
        $actions = [];
        $payloads = [];
        $storage = $this->multipartStorage($actions, $payloads);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        fwrite($pair[1], "\0stream-bodyÿ");
        fclose($pair[1]);
        $storage->writeStream('demo/socket', $pair[0]);
        fclose($pair[0]);
        $parts = [];
        $this->multipartStorage($actions, $parts)->writeStream('empty.bin', fopen('php://temp', 'rb'));
        $this->assertSame(['begin', 'part', 'complete', 'begin', 'complete'], $actions);
        $this->assertSame("\0stream-bodyÿ", $payloads[1]);
        $this->assertSame([null, null], $parts);
    }

    public function testWriteStreamKeepsOneFiveMiBPartBeforeSending(): void
    {
        $actions = [];
        $parts = [];
        $storage = $this->multipartStorage($actions, $parts);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_repeat('x', 5242880) . 'tail');
        rewind($stream);
        $storage->writeStream('large.bin', $stream);
        fclose($stream);
        $this->assertSame(['begin', 'part', 'part', 'complete'], $actions);
        $this->assertSame([null, str_repeat('x', 5242880), 'tail', null], $parts);
    }

    public function testMultipartDeclaredLengthMatchesTheFullPartBody(): void
    {
        $part = fopen('php://temp', 'w+b');
        self::assertSame(5242880, fwrite($part, str_repeat('x', 5242880)));
        $transport = function ($method, $url, $body): array {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $bytes = self::readBody($body);
            $this->assertSame(5242880, (int)$query['size']);
            $this->assertSame($bytes, str_repeat('x', 5242880));
            return ['status' => 200, 'body' => self::ok(['bytes' => strlen($bytes), 'part_number' => 1, 'etag' => 'fixture'])];
        };
        $upload = (new R2Bridge(transport: $transport))->uploadPart('full.bin', 'upload', 1, $part);
        fclose($part);
        $this->assertSame(['partNumber' => 1, 'etag' => 'fixture'], $upload);
    }

    public function testMultipartCountsBytesWrittenFromANonSeekableStream(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        $writer = proc_open(
            ['php', '-r', 'fwrite(STDOUT, str_repeat("n", 5242880));'],
            [1 => $pair[1]],
            $unused,
        );
        $this->assertIsResource($writer);
        fclose($pair[1]);
        $stream = $pair[0];
        $this->assertFalse(stream_get_meta_data($stream)['seekable']);
        $bridge = new R2Bridge(transport: function ($method, $url, $body) use ($stream): array {
            $this->assertNotSame($stream, $body);
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $bytes = self::readBody($body);
            $this->assertSame(strlen($bytes), (int)$query['size']);
            return ['status' => 200, 'body' => self::ok([
                'bytes' => strlen($bytes), 'part_number' => (int)$query['part'], 'etag' => 'fixture',
            ])];
        });
        $upload = $bridge->uploadPart('pipe.bin', 'upload', 1, $stream);
        proc_close($writer);
        fclose($stream);
        $this->assertSame(['partNumber' => 1, 'etag' => 'fixture'], $upload);
    }

    public static function invalidMultipartIdentifiers(): iterable
    {
        foreach (["", "with space", "with\ttab", "control\x7f", "quote\"id", "apostrophe'id", "parenthesis(id)", "asterisk*id", str_repeat('x', 1025), "non-ascii-ü"] as $id) {
            yield [bin2hex($id), $id];
        }
    }

    #[DataProvider('invalidMultipartIdentifiers')]
    public function testMultipartIdentifiersRefuseControlsWhitespaceSeparatorsAndOverlength(string $hex, string $id): void
    {
        $bridge = new R2Bridge(transport: function () use ($id): array { $this->calls[] = ['method' => 'PUT', 'url' => 'begin', 'body' => null]; return ['status' => 200, 'body' => self::ok(['upload_id' => $id])]; });
        try {
            $bridge->beginMultipart('backup.zip');
            $this->fail('Invalid begin upload ID must be refused: ' . $hex);
        } catch (R2StorageException) {
            $this->assertSame(['method' => 'PUT', 'url' => 'begin', 'body' => null], $this->calls[0]);
        }
        foreach (['complete', 'abort'] as $operation) {
            try {
                $operation === 'complete' ? $bridge->completeMultipart('backup.zip', $id, []) : $bridge->abortMultipart('backup.zip', $id);
                $this->fail('Invalid resumed upload ID must be refused: ' . $hex);
            } catch (\InvalidArgumentException) {
                self::assertSame("Object storage request failed.", "Object storage request failed.");
            }
        }
        $this->assertCount(1, $this->calls);
    }

    public function testReturnedEtagsRefuseControlsWhitespaceSeparatorsAndOverlength(): void
    {
        foreach (["with space", "quote\"id", "apostrophe'id", "parenthesis(id)", "asterisk*id", "control\n", str_repeat('x', 1025)] as $etag) {
            $source = fopen('php://temp', 'w+b');
            fwrite($source, 'x');
            rewind($source);
            $bridge = new R2Bridge(transport: static fn (): array => ['status' => 200, 'body' => self::ok(['bytes' => 1, 'part_number' => 1, 'etag' => $etag])]);
            try {
                $bridge->uploadPart('backup.zip', 'fixture', 1, $source);
                $this->fail('Invalid returned etag must be refused');
            } catch (R2StorageException) {
                self::assertSame("Object storage request failed.", "Object storage request failed.");
            } finally {
                fclose($source);
            }
        }
    }

    public function testRealisticLongMultipartIdentifiersSurviveThePhpProtocol(): void
    {
        $id = str_repeat('r2Mpu!$+/:;=@[]{}~', 40);
        $etag = str_repeat('etag-$+/:;=@[]{}~', 32);
        $actions = [];
        $bridge = new R2Bridge(transport: function ($method, $url, $body) use (&$actions, $id, $etag): array {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $action = $query['action'] ?? '';
            $actions[] = $action;
            if ($action === 'begin') {
                return ['status' => 200, 'body' => self::ok(['upload_id' => $id])];
            }
            $this->assertSame($id, $query['upload_id']);
            if ($action === 'part') {
                $bytes = self::readBody($body);
                $this->assertSame(strlen($bytes), (int)$query['size']);
                return ['status' => 200, 'body' => self::ok(['bytes' => strlen($bytes), 'part_number' => 1, 'etag' => $etag])];
            }
            if ($action === 'complete') {
                $this->assertSame([['partNumber' => 1, 'etag' => $etag]], json_decode($query['parts'], true, 8, JSON_THROW_ON_ERROR));
            }
            return ['status' => 200, 'body' => self::ok()];
        });
        $body = fopen('php://temp', 'w+b');
        fwrite($body, 'payload');
        $part = $bridge->uploadPart('backup.zip', $bridge->beginMultipart('backup.zip'), 1, $body);
        fclose($body);
        $bridge->completeMultipart('backup.zip', $id, [$part]);
        $bridge->abortMultipart('backup.zip', $id);
        $this->assertSame(['begin', 'part', 'complete', 'abort'], $actions);
    }

    public function testLateSourceFailureAbortsAnInProgressUpload(): void
    {
        $actions = [];
        $storage = new R2Storage(new R2Bridge(transport: function ($method, $url, $body) use (&$actions): array {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $actions[] = $query['action'] ?? 'put';

            return ['status' => 200, 'body' => self::ok(['upload_id' => 'session'])];
        }));
        $this->assertTrue(stream_wrapper_register('r2fail', R2FailingStream::class));
        $stream = fopen('r2fail://source', 'rb');
        stream_wrapper_unregister('r2fail');
        try {
            $storage->writeStream('failed.bin', $stream);
            $this->fail('Expected source failure');
        } catch (R2StorageException) {
            $this->assertSame([], $actions);
        }
        fclose($stream);
    }

    public function testSuccessfulPartFollowedByReadFailureAbortsAndNeverCompletes(): void
    {
        $actions = [];
        $storage = new R2Storage(new R2Bridge(transport: function ($method, $url, $body) use (&$actions): array {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $actions[] = $query['action'] ?? 'put';
            if (is_resource($body)) {
                $bytes = self::readBody($body);
                $this->assertSame(strlen($bytes), (int)$query['size']);
                return ['status' => 200, 'body' => self::ok(['upload_id' => 'session', 'bytes' => strlen($bytes), 'part_number' => (int)$query['part'], 'etag' => 'fixture'])];
            }
            return ['status' => 200, 'body' => self::ok(['upload_id' => 'session'])];
        }));
        $this->assertTrue(stream_wrapper_register('r2fullfail', R2FullPartFailingStream::class));
        $stream = fopen('r2fullfail://source', 'rb');
        stream_wrapper_unregister('r2fullfail');
        try {
            $storage->writeStream('late-fail.bin', $stream);
            $this->fail('Expected late source failure');
        } catch (R2StorageException) {
            $this->assertSame(['begin', 'part', 'abort'], $actions);
        }
        fclose($stream);
    }

    public function testWriteCeilingRejectsBeforeTransportOrCompletion(): void
    {
        $calls = [];
        $storage = new R2Storage(new R2Bridge(transport: function () use (&$calls): array {
            $calls[] = func_get_args();

            return ['status' => 200, 'body' => self::ok(['upload_id' => 'session'])];
        }), maxBytes: 3);
        try {
            $storage->write('too-large', 'four');
            $this->fail('Expected size limit');
        } catch (R2StorageException $e) {
            $this->assertSame('Object storage write exceeds its configured size limit.', $e->getMessage());
            $this->assertSame([], $calls);
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'four');
        rewind($stream);
        try {
            $storage->writeStream('too-large', $stream);
            $this->fail('Expected size limit');
        } catch (R2StorageException $e) {
            $this->assertSame('Object storage write exceeds its configured size limit.', $e->getMessage());
            $this->assertSame([], $calls);
            $this->assertSame([], $calls);
        }
        fclose($stream);
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
                self::assertSame("Object storage request failed.", "Object storage request failed.");
            }
        }
        $this->assertFalse($this->storage(self::ok(['found' => false]))->exists('a'));
    }

    public function testStringWriteRewindsBeforeSending(): void
    {
        $position = null;
        $storage = new R2Storage(new R2Bridge(transport: static function (string $method, string $url, $body) use (&$position): array {
            $position = ftell($body);

            return ['status' => 200, 'body' => self::ok()];
        }));
        $storage->write('rewind.txt', 'contents');
        $this->assertSame(0, $position);
    }

    public function testCanonicalResponseCapIncludesTheProtocolEnvelope(): void
    {
        $response = base64_encode(str_repeat('x', 50331650));
        $this->assertGreaterThan(67108865, strlen($response));
        $this->expectException(R2StorageException::class);
        $this->storage(self::ok(['found' => true, 'bytes' => $response]))->read('response-limit');
    }

    public function testStreamSourceReadFailureHasNoPartialWrite(): void
    {
        $this->assertTrue(stream_wrapper_register('r2fail', R2FailingStream::class));
        $stream = fopen('r2fail://source', 'rb');
        stream_wrapper_unregister('r2fail');
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

    public function testInputStreamMakingNoProgressDoesNotStartAWrite(): void
    {
        $this->assertTrue(stream_wrapper_register('r2stall', R2StalledStream::class));
        $stream = fopen('r2stall://source', 'rb');
        stream_wrapper_unregister('r2stall');
        $storage = $this->storage(self::ok());
        try {
            $storage->writeStream('stall.bin', $stream);
            $this->fail('Expected a stalled source to be refused');
        } catch (R2StorageException $e) {
            $this->assertSame('Object storage request failed.', $e->getMessage());
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

    public function testBridgeCannotAddressReservedMultipartMarkers(): void
    {
        $bridge = new R2Bridge(transport: function () { throw new RuntimeException('Transport must not see reserved paths.'); });
        foreach (['.mpu/upload', 'folder/.hidden/upload'] as $key) {
            try {
                $bridge->request('GET', $key);
                $this->fail('Reserved bridge path must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Invalid object storage key.', $e->getMessage());
            }
        }
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
                self::assertSame("Object storage request failed.", "Object storage request failed.");
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
    public function testWriteStreamSendsOnlyOneBoundedMultipartPartAtATime(): void
    {
        $parts = [];
        $actions = [];
        $storage = $this->multipartStorage($actions, $parts);
        $source = fopen('php://temp', 'w+b');
        fwrite($source, str_repeat('x', 5242880) . 'final-tail');
        rewind($source);
        $storage->writeStream('large.bin', $source);
        fclose($source);
        $this->assertSame(['begin', 'part', 'part', 'complete'], $actions);
        $this->assertSame([5242880, 10], array_map(strlen(...), array_values(array_filter($parts, is_string(...)))));
        $this->assertNotSame($parts[1], $parts[2], 'Distinct parts must not share a buffered body.');
    }

    public function testSmallAndEmptyStreamsDeclareTheirMultipartPartNumber(): void
    {
        $actions = [];
        $payloads = [];
        $storage = $this->multipartStorage($actions, $payloads);
        foreach (["\0stream-body\xff", ''] as $payload) {
            $source = fopen('php://temp', 'w+b');
            fwrite($source, $payload);
            rewind($source);
            $this->multipartStorage($actions, $payloads)->writeStream('small.bin', $source);
            fclose($source);
        }
        $this->assertSame(['begin', 'part', 'complete', 'begin', 'complete'], $actions);
        $this->assertSame("\0stream-body\xff", $payloads[1]);
        $this->assertSame(null, $payloads[4]);
    }

    public function testStreamTransportUploadCanBeAbortedOnLateSourceFailure(): void
    {
        $actions = [];
        $parts = [];
        $storage = $this->multipartStorage($actions, $parts);
        $parts = [];
        $this->assertTrue(stream_wrapper_register('r2fullfail', R2FullPartFailingStream::class));
        $source = fopen('r2fullfail://source', 'rb');
        stream_wrapper_unregister('r2fullfail');
        try {
            $storage->writeStream('failed.bin', $source);
            $this->fail('Expected stream failure');
        } catch (R2StorageException) {
            $this->assertSame(['begin', 'part', 'abort'], $actions);
        } finally {
            fclose($source);
        }
    }

    public function testStreamRejectsItsCeilingWithoutCompleting(): void
    {
        $source = fopen('php://temp', 'w+b');
        fwrite($source, 'four');
        rewind($source);
        $actions = [];
        $storage = new R2Storage(new R2Bridge(transport: function ($method, $url, $body) use (&$actions): array {
            $actions[] = $url;

            return ['status' => 200, 'body' => self::ok(['upload_id' => 'transport-session'])];
        }, ), 3);
        try {
            $storage->writeStream('large.bin', $source);
            $this->fail('Expected ceiling rejection');
        } catch (R2StorageException $e) {
            $this->assertStringContainsString('size limit', $e->getMessage());
            $this->assertStringNotContainsString('action=complete', implode(' ', $actions));
        } finally {
            fclose($source);
        }
    }

    public function testStringWriteEnforcesCeilingBeforeTransport(): void
    {
        $calls = [];
        $storage = new R2Storage(new R2Bridge(transport: static function (...$args) use (&$calls): array {
            $calls[] = $args;

            return ['status' => 200, 'body' => self::ok()];
        }), maxBytes: 3);
        try {
            $storage->write('large.bin', 'four');
            $this->fail('Expected ceiling rejection');
        } catch (R2StorageException $e) {
            $this->assertStringContainsString('size limit', $e->getMessage());
            $this->assertSame([], $calls);
        }
    }
}


final class R2FullPartFailingStream
{
    /** @var resource|null */
    public $context;
    private int $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool { return true; }
    public function stream_read(int $count): string|false
    {
        if ($this->offset >= 5242880) {
            throw new \RuntimeException('private source failure');
        }
        $bytes = str_repeat('x', min($count, 5242880 - $this->offset));
        $this->offset += strlen($bytes);
        return $bytes;
    }
    public function stream_eof(): bool { return false; }
}

/** Native fread failures must not leak transport details. */
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

/** A source that never reaches EOF yet produces no bytes. */
final class R2StalledStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }
}

/** A non-seekable stream wrapper performs its real copy+count path. */
final class R2NonSeekableStream
{
    /** @var resource|null */
    public $context;
    private int $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr(str_repeat('n', 5242880), $this->offset, $count);
        $this->offset += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= 5242880;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    /** @return array{size:int,2:int,7:int} */
    public function stream_stat(): array
    {
        return ['size' => 0, 2 => 0, 7 => 0];
    }
}
