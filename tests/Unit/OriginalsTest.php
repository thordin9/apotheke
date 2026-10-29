<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/** Camera uploads stay byte-identical and off the web path; delete removes every copy. */
final class OriginalsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    public function testUploadKeepsTheOriginalAndServesAStrippedCopy(): void
    {
        $bytes = $this->markedJpeg(120, 80);
        $name = $this->store($bytes);
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $orig));
        $this->assertSame(1, preg_match('/^[a-f0-9]{64}\.jpg$/', basename($orig)));
        $this->assertNotSame(substr($name, 0, 32), substr(basename($orig), 0, 32));
        $served = (string)file_get_contents(IMG_DIR . '/' . $name);
        $this->assertStringStartsWith("\xFF\xD8", $served);
        $this->assertStringNotContainsString('GPSLatitude', $served);
        $this->assertStringNotContainsString('SerialNumber', $served);
        $this->assertStringNotContainsString("\xFF\xE1", $served);
        $this->assertNotSame(hash('sha256', $bytes), hash('sha256', $served));
        $this->assertFileExists(THUMB_DIR . '/' . $name);
        $this->assertFileExists(ORIGINALS_DIR . '/.htaccess');
        $ht = (string)file_get_contents(ORIGINALS_DIR . '/.htaccess');
        $this->assertSame(originals_htaccess(), $ht);
        $this->assertStringContainsString("<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>", $ht);
        $this->assertStringContainsString("<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>", $ht);
        $this->assertFalse(valid_image_name(basename($orig)));
    }

    public function testOriginalsArePrivateOnDisk(): void
    {
        $name = $this->store($this->plainJpeg(40, 30));
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $map = ORIGINALS_DIR . '/map/' . substr($name, 0, 32);
        $this->assertSame(0750, fileperms(ORIGINALS_DIR) & 0777);
        $this->assertSame(0750, fileperms(ORIGINALS_DIR . '/map') & 0777);
        $this->assertSame(0640, fileperms((string)$orig) & 0777);
        $this->assertSame(0640, fileperms($map) & 0777);
        $this->assertSame(0640, fileperms(ORIGINALS_DIR . '/.htaccess') & 0777);
    }

    public function testOriginalsDirInsideTheWebRootIsRefused(): void
    {
        $root = dirname(__DIR__, 2);
        $web = $root . '/public';
        $this->assertTrue(originals_dir_in_web_root($web . '/camera-private', $web));
        $this->assertTrue(originals_dir_in_web_root($web, $web));
        $this->assertFalse(originals_dir_in_web_root($root . '/public-files/x', $web));
        $this->assertFalse(originals_dir_in_web_root($web . '/../data/originals', $web));
        $this->assertTrue(image_originals_prepare(), 'the test originals directory is outside public/');

        $link = sys_get_temp_dir() . '/apotheke-orig-via-' . bin2hex(random_bytes(4));
        mkdir($web . '/camera-private-probe', 0750, true);
        try {
            symlink($web . '/camera-private-probe', $link);
            $this->assertTrue(originals_dir_in_web_root($link, $web), 'a path outside public/ that points inside it');
        } finally {
            @unlink($link);
            @rmdir($web . '/camera-private-probe');
        }

        $bad = $web . '/camera-private';
        if (is_dir($bad)) @rmdir($bad);
        $this->assertDirectoryDoesNotExist($bad);
        $data = sys_get_temp_dir() . '/apotheke-webroot-' . bin2hex(random_bytes(4));
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $data,
            'ORIGINALS_DIR' => $bad,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
        ];
        $proc = proc_open(
            [PHP_BINARY, '-r', 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'],
            $spec,
            $pipes,
            $root,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(1, $code, $out . $err);
        $this->assertStringContainsString('web root', $err);
        $this->assertStringContainsString($web, $err);
        $this->assertStringNotContainsString($web, $out);
        $this->assertDirectoryDoesNotExist($bad);
        $this->assertDirectoryDoesNotExist($data);
    }

    public function testBrowserRefusalHidesThePathAndLogsIt(): void
    {
        $root = dirname(__DIR__, 2);
        $web = $root . '/public';
        $bad = $web . '/camera-private-http';
        if (is_dir($bad)) @rmdir($bad);
        $data = sys_get_temp_dir() . '/apotheke-refuse-http-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $log = $data . '.log';
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($sock);
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/public/index.php'];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'DATA_DIR' => $data,
            'ORIGINALS_DIR' => $bad,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
        ];
        $proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        try {
            $up = false;
            for ($i = 0; $i < 50; $i++) {
                $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($c) { fclose($c); $up = true; break; }
                usleep(50000);
            }
            $this->assertTrue($up, 'php -S did not listen');
            $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
            $body = (string)@file_get_contents("http://127.0.0.1:$port/", false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $status = (int)$m[1];
            }
            $logged = (string)@file_get_contents($log);
            $this->assertSame(500, $status, $body . $logged);
            $this->assertStringContainsString('public web directory', $body);
            $this->assertStringNotContainsString($web, $body);
            $this->assertStringNotContainsString($bad, $body);
            $this->assertStringContainsString($web, $logged);
            $this->assertStringContainsString($bad, $logged);
            $this->assertStringNotContainsString('Undefined constant', $logged);
            $this->assertDirectoryDoesNotExist($bad);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($log);
            if (is_dir($data)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($data, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
                @rmdir($data);
            }
        }
    }

    public function testPhpCgiRefusalHidesThePathAndDoesNotNeedStderr(): void
    {
        $cgi = $this->phpCgi();
        if ($cgi === null) $this->markTestSkipped('php-cgi is not installed');
        $root = dirname(__DIR__, 2);
        $web = $root . '/public';
        $bad = $web . '/camera-private-cgi';
        if (is_dir($bad)) @rmdir($bad);
        $data = sys_get_temp_dir() . '/apotheke-refuse-cgi-' . bin2hex(random_bytes(4));
        mkdir($data, 0775, true);
        $script = $data . '/refuse.php';
        file_put_contents($script, "<?php\nrequire " . var_export($root . '/src/bootstrap.php', true) . ";\necho \"booted\\n\";\n");
        $errFile = $data . '/error.log';
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'SCRIPT_FILENAME' => $script,
            'SCRIPT_NAME' => '/refuse.php',
            'REQUEST_METHOD' => 'GET',
            'REDIRECT_STATUS' => '200',
            'DATA_DIR' => $data,
            'ORIGINALS_DIR' => $bad,
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
        ];
        $proc = proc_open([$cgi, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $errFile, $script], $spec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $logged = (string)@file_get_contents($errFile);
        $this->assertStringContainsString('Status: 500', $out, $out . $err . $logged);
        $this->assertStringContainsString('public web directory', $out);
        $this->assertStringNotContainsString($web, $out);
        $this->assertStringNotContainsString($bad, $out);
        $this->assertStringNotContainsString('Undefined constant', $out . $err . $logged);
        $this->assertStringContainsString($web, $logged);
        $this->assertStringContainsString($bad, $logged);
        $this->assertStringNotContainsString('booted', $out);
        $this->assertDirectoryDoesNotExist($bad);
        @unlink($script);
        @unlink($errFile);
        @rmdir($data);
    }

    public function testStartupLogWritesThePathToTheErrorLog(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'apotheke-log');
        $prev = ini_set('error_log', $file);
        try {
            startup_log(originals_web_root_log_message('/tmp/secret-originals', '/tmp/secret-public'));
            $logged = (string)file_get_contents($file);
            $this->assertStringContainsString('/tmp/secret-originals', $logged);
            $this->assertStringContainsString('/tmp/secret-public', $logged);
            $page = originals_web_root_public_message();
            $this->assertStringNotContainsString('/tmp/secret-originals', $page);
            $this->assertStringNotContainsString('/tmp/secret-public', $page);
        } finally {
            if ($prev !== false) ini_set('error_log', (string)$prev);
            @unlink($file);
        }
    }

    public function testBootstrapDoesNotReferenceTheStderrConstant(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/src/bootstrap.php');
        $this->assertDoesNotMatchRegularExpression('/\bSTDERR\b/', $src);
    }

    private function phpCgi(): ?string
    {
        $cands = [dirname(PHP_BINARY) . '/php-cgi', PHP_BINDIR . '/php-cgi'];
        $which = trim((string)shell_exec('command -v php-cgi 2>/dev/null'));
        if ($which !== '') $cands[] = $which;
        foreach ($cands as $bin) {
            if (is_file($bin) && is_executable($bin)) return $bin;
        }
        return null;
    }

    public function testLegacyPhotoWithoutAnOriginalIsLeftAlone(): void
    {
        $name = bin2hex(random_bytes(16)) . '.jpg';
        file_put_contents(IMG_DIR . '/' . $name, $this->plainJpeg(20, 20));
        $this->assertNull(image_original_path($name));
        $this->assertFileExists(IMG_DIR . '/' . $name);
    }

    public function testDeleteRemovesOriginalDerivativeAndThumbnail(): void
    {
        $name = $this->store($this->plainJpeg(60, 40));
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $map = ORIGINALS_DIR . '/map/' . substr($name, 0, 32);
        image_delete($name);
        $this->assertFileDoesNotExist(IMG_DIR . '/' . $name);
        $this->assertFileDoesNotExist(THUMB_DIR . '/' . $name);
        $this->assertFileDoesNotExist($orig);
        $this->assertFileDoesNotExist($map);
        $this->assertNull(image_original_path($name));
    }

    public function testReplacingAPhotoRemovesThePreviousFiles(): void
    {
        $first = $this->store($this->plainJpeg(50, 40));
        $firstOrig = image_original_path($first);
        $id = item_save(null, ['name' => 'Mug', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $first, false);
        $secondBytes = $this->plainJpeg(70, 50);
        $second = $this->store($secondBytes);
        item_save(item_get($id), item_input(item_get($id)), $second, false);
        $this->assertFileDoesNotExist(IMG_DIR . '/' . $first);
        $this->assertFileDoesNotExist(THUMB_DIR . '/' . $first);
        $this->assertFileDoesNotExist((string)$firstOrig);
        $this->assertFileDoesNotExist(ORIGINALS_DIR . '/map/' . substr($first, 0, 32));
        $kept = image_original_path($second);
        $this->assertNotNull($kept);
        $this->assertSame(hash('sha256', $secondBytes), hash_file('sha256', $kept));
        $this->assertFileExists(IMG_DIR . '/' . $second);
        $this->assertFileExists(THUMB_DIR . '/' . $second);
    }

    public function testUnreadableHeicKeepsTheOriginalAndServesAPlaceholder(): void
    {
        $bytes = "\x00\x00\x00\x18ftypheic" . str_repeat('x', 200);
        $name = $this->store($bytes, 'shot.heic');
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $orig));
        $this->assertStringEndsWith('.heic', $orig);
        $served = (string)file_get_contents(IMG_DIR . '/' . $name);
        $this->assertStringStartsWith("\xFF\xD8", $served);
        $this->assertNotSame($bytes, $served);
        $this->assertFileExists(THUMB_DIR . '/' . $name);
    }

    private function store(string $bytes, string $filename = 'a.jpg'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);
        try {
            return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => $filename]);
        } finally {
            @unlink($tmp);
        }
    }

    private function plainJpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 80, 120));
        ob_start();
        imagejpeg($im);
        return (string)ob_get_clean();
    }

    private function markedJpeg(int $w, int $h): string
    {
        $jpeg = $this->plainJpeg($w, $h);
        $payload = "Exif\x00\x00GPSLatitude Make Model SerialNumber";
        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }
}
