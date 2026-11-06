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
        self::$node = proc_open(['node', self::$scratch . '/server.mjs'], [1 => ['pipe', 'w'], 2 => STDOUT], $pipe);
        self::assertIsResource(self::$node);
        $timeout = microtime(true) + 5;
        while (($port = fgets($pipe[1])) === false && microtime(true) < $timeout) {
            usleep(10000);
        }
        self::assertIsString($port, 'The local fixture must start.');
        self::$base = 'http://127.0.0.1:' . (int)$port;
        fclose($pipe[1]);
    }

    public static function tearDownAfterClass(): void
    {
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
        $node = proc_open(['node', $prefix . '.mjs', $prefix], [1 => ['pipe', 'w'], 2 => STDOUT], $pipes);
        self::assertIsResource($node);
        try {
            $port = fgets($pipes[1]);
            self::assertIsString($port);
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
            $this->assertSame($prefix . '.crt', self::$curlOptions[CURLOPT_CAINFO] ?? null);
        } finally {
            if (is_resource($pipes[1])) fclose($pipes[1]);
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
