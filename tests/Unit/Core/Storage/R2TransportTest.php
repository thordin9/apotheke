<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage {

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('curl')]
final class R2TransportTest extends TestCase
{
    private static string $base = '';
    /** @var resource|null */
    private static $node;
    /** @var resource|null the fixture's stderr: a pipe, never the runner's own output (a shared fd would be rewound) */
    private static $nodeErr;
    private static string $nodeLog = '';
    private static string $scratch = '';
    /** @var array<int,mixed> */
    public static array $curlOptions = [];
    public static ?string $fixtureCa = null;

    public static function setUpBeforeClass(): void
    {
        self::$scratch = sys_get_temp_dir() . '/coder2-r2-transport-' . getmypid();
        mkdir(self::$scratch, 0700, true);
        file_put_contents(self::$scratch . '/server.mjs', <<<'JS'
import http from "node:http";
const server = http.createServer((request, response) => {
  const url = new URL(request.url, "http://loopback");
  if (url.pathname === "/redirect") return response.writeHead(302, { Location: "/secret" }).end();
  if (url.pathname === "/secret") return response.end(JSON.stringify({ ok: true, found: true, bytes: "c2VjcmV0" }));
  if (url.pathname.startsWith("/meta/")) {
    // As the Worker once did: metadata as JSON for HEAD too. node:http, like any HTTP server, sends no HEAD body.
    const meta = JSON.stringify({ ok: true, found: url.pathname === "/meta/a.jpg", ...(url.pathname === "/meta/a.jpg" ? { etag: "e1", modified: 1740000000 } : {}) });
    if (request.method === "HEAD" || url.searchParams.get("action") === "head") return response.writeHead(200, { "Content-Type": "application/json", "Content-Length": String(Buffer.byteLength(meta)) }).end(meta);
  }
  if (url.searchParams.get("action") === "begin") return response.end(JSON.stringify({ ok: true, upload_id: "local_session-1" }));
  if (url.searchParams.get("action") === "part") {
    const buffers = [];
    request.on("data", (data) => buffers.push(data));
    request.on("end", () => response.end(JSON.stringify({ ok: true, bytes: Buffer.concat(buffers).length, part_number: Number(url.searchParams.get("part")), etag: "local_etag" })));
    return;
  }
  if (url.searchParams.get("action") === "complete" || url.searchParams.get("action") === "abort") return response.end(JSON.stringify({ ok: true }));
  response.writeHead(200, { "Content-Length": "2" }).end("ok");
});
server.listen(0, "127.0.0.1", () => console.log(server.address().port));
JS);
        $pipe = [];
        self::$node = proc_open(['node', self::$scratch . '/server.mjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        self::assertIsResource(self::$node);
        self::$nodeErr = $pipe[2];
        stream_set_blocking(self::$nodeErr, false);
        $timeout = microtime(true) + 5;
        while (($port = fgets($pipe[1])) === false && microtime(true) < $timeout) {
            usleep(10000);
        }
        self::$nodeLog .= self::drain(self::$nodeErr);
        self::assertIsString($port, 'The local fixture must start. Its stderr: ' . self::$nodeLog);
        self::$base = 'http://127.0.0.1:' . (int)$port;
        fclose($pipe[1]);
    }

    /** Read what a fixture wrote to stderr so far without waiting (its pipe is non-blocking). */
    private static function drain(mixed $pipe): string
    {
        $out = '';
        while (is_resource($pipe) && ($chunk = fread($pipe, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    protected function tearDown(): void
    {
        self::$nodeLog .= self::drain(self::$nodeErr);   // keep the long-lived fixture's pipe from filling
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$nodeErr)) fclose(self::$nodeErr);
        if (is_resource(self::$node)) proc_terminate(self::$node);
        if (is_resource(self::$node)) proc_close(self::$node);
        @unlink(self::$scratch . '/server.mjs');
        @rmdir(self::$scratch);
    }

    private function request(string $url): array
    {
        $method = new \ReflectionMethod(R2Bridge::class, 'http');
        return $method->invoke(new R2Bridge(self::$base, timeout: 3), 'GET', self::$base . $url, null);
    }

    /** exists() and modifiedAt() over real HTTP: a HEAD answer has no body, so they must not wait for one. */
    public function testMetadataLookupsReadAJsonBodyOverRealHttp(): void
    {
        $storage = new R2Storage(new R2Bridge(self::$base, timeout: 2));
        $started = microtime(true);
        $this->assertTrue($storage->exists('meta/a.jpg'));
        $this->assertSame(1740000000, $storage->modifiedAt('meta/a.jpg'));
        $this->assertFalse($storage->exists('meta/none.jpg'));
        $this->assertNull($storage->modifiedAt('meta/none.jpg'));
        $this->assertLessThan(2.0, microtime(true) - $started, 'no lookup waits for the bridge timeout');
        try {
            (new R2Bridge(self::$base, timeout: 2))->request('HEAD', 'meta/a.jpg');
            $this->fail('HEAD has no body to read');
        } catch (\LogicException $e) {
            $this->assertSame('A HEAD response has no body; use metadata().', $e->getMessage());
        }
    }

    public function testNativeCurlDoesNotFollowRedirectResponses(): void
    {
        $this->assertSame(['status' => 302, 'body' => ''], $this->request('/redirect'));
    }

    public function testNativeCurlCapturesTheResponseBodyCallback(): void
    {
        $this->assertSame('ok', $this->request('/body')['body']);
    }

    public function testNativeCurlVerifiesTlsPeerAndHost(): void
    {
        self::$curlOptions = [];
        $prefix = self::$scratch . '/tls';
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=not-r2.example -keyout ' . escapeshellarg($prefix . '.key') . ' -out ' . escapeshellarg($prefix . '.crt') . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode('\n', $output));
        $server = <<<'JS'
import https from "node:https";
import fs from "node:fs";
const server = https.createServer({ key: fs.readFileSync(process.argv[2] + ".key"), cert: fs.readFileSync(process.argv[2] + ".crt") }, (request, response) => response.end("ok"));
server.listen(0, "127.0.0.1", () => console.log(server.address().port));
JS;
        file_put_contents($prefix . '.mjs', $server);
        $pipes = [];
        $node = proc_open(['node', $prefix . '.mjs', $prefix], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($node);
        stream_set_blocking($pipes[2], false);
        $err = '';
        try {
            $port = fgets($pipes[1]);
            $err .= self::drain($pipes[2]);
            self::assertIsString($port, 'The TLS fixture must start. Its stderr: ' . $err);
            try {
                $method = new \ReflectionMethod(R2Bridge::class, 'http');
                $method->invoke(new R2Bridge('https://localhost:' . (int)$port), 'GET', 'https://localhost:' . (int)$port . '/', null);
                $this->fail('A self-signed TLS peer must be refused.');
            } catch (\RuntimeException $e) {
                $this->assertSame('HTTP client failed', $e->getMessage());
            }
            try {
                self::$curlOptions = [];
                self::$fixtureCa = $prefix . '.crt';
                $method->invoke(new R2Bridge('https://localhost:' . (int)$port), 'GET', 'https://localhost:' . (int)$port . '/', null);
            } catch (\RuntimeException $e) {
                $this->assertSame('HTTP client failed', $e->getMessage());
            }
            $this->assertSame($prefix . '.crt', self::$curlOptions[CURLOPT_CAINFO] ?? null, 'TLS fixture stderr: ' . ($err .= self::drain($pipes[2])));
        } finally {
            if (is_resource($pipes[1])) fclose($pipes[1]);
            if (is_resource($pipes[2])) fclose($pipes[2]);
            proc_terminate($node);
            proc_close($node);
            foreach (['.key', '.crt', '.mjs'] as $suffix) @unlink($prefix . $suffix);
        }
    }

    public function testNativeCurlTlsOptionsRejectPeerAndHostMutations(): void
    {
        self::$fixtureCa = null;
        self::$curlOptions = [];
        $method = new \ReflectionMethod(R2Bridge::class, 'http');
        try {
            $method->invoke(new R2Bridge('https://r2.internal', timeout: 1), 'GET', 'https://r2.internal/unreachable', null);
        } catch (\RuntimeException) {
            // Only the exact options handed to the native call are under test.
        }
        $this->assertSame(true, self::$curlOptions[CURLOPT_SSL_VERIFYPEER] ?? null);
        $this->assertSame(2, self::$curlOptions[CURLOPT_SSL_VERIFYHOST] ?? null);
    }

    public function testNativeCurlSupportsANonSeekableAppendOnlyStream(): void
    {
        self::$fixtureCa = null;
        $method = new \ReflectionMethod(R2Bridge::class, 'http');
        $pipes = [];
        $process = proc_open(['php', '-r', 'fwrite(STDOUT, "native-stream");'], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fread($pipes[1], 5);
        $response = (new R2Bridge(self::$base))->uploadPart('a', 'local_session-1', 1, $pipes[1]);
        proc_close($process);
        $this->assertSame(['partNumber' => 1, 'etag' => 'local_etag'], $response);
    }

    public function testNativeCurlStreamsBoundedMultipart(): void
    {
        $method = new \ReflectionMethod(R2Bridge::class, "http");
        $source = fopen("php://temp", "w+b");
        fwrite($source, "native-tail");
        rewind($source);
        $response = $method->invoke(new R2Bridge(self::$base), "PUT", self::$base . "/buffer?action=part&upload_id=local_session-1&part=1&size=11", $source);
        fclose($source);
        $this->assertSame('{"ok":true,"bytes":11,"part_number":1,"etag":"local_etag"}', $response["body"]);
    }
}

}

namespace Apotheke\Core\Storage {
    /** @param array<int,mixed> $options */
    function curl_setopt_array(\CurlHandle $handle, array $options): bool
    {
        if (R2TransportTest::$fixtureCa !== null) {
            $options[CURLOPT_CAINFO] = R2TransportTest::$fixtureCa;
        }
        R2TransportTest::$curlOptions = $options;
        return \curl_setopt_array($handle, $options);
    }
}
