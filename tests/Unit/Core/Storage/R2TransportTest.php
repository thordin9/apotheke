<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('curl')]
final class R2TransportTest extends TestCase
{
    private static string $base = '';
    /** @var resource|null */
    private static $node;
    private static string $scratch = '';

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
    request.on("end", () => response.end(JSON.stringify({ ok: true, bytes: Buffer.concat(buffers).length })));
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

    public function testNativeCurlSupportsANonSeekableAppendOnlyStream(): void
    {
        $method = new \ReflectionMethod(R2Bridge::class, 'http');
        $pipes = [];
        $process = proc_open(['php', '-r', 'fwrite(STDOUT, "native-stream");'], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fread($pipes[1], 5);
        $response = $method->invoke(new R2Bridge(self::$base), 'PUT', self::$base . '/a?action=part&upload_id=local_session-1&part=1&size=8', $pipes[1]);
        proc_close($process);
        $this->assertSame(['status' => 200, 'body' => '{"ok":true,"bytes":8}'], $response);
    }

    public function testNativeCurlStreamsBoundedMultipart(): void
    {
        $method = new \ReflectionMethod(R2Bridge::class, "http");
        $source = fopen("php://temp", "w+b");
        fwrite($source, "native-tail");
        rewind($source);
        $response = $method->invoke(new R2Bridge(self::$base), "PUT", self::$base . "/buffer?action=part&upload_id=local_session-1&part=1&size=11", $source);
        fclose($source);
        $this->assertSame("{\"ok\":true,\"bytes\":11}", $response["body"]);
    }
}
