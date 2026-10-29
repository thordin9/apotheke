<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

require_once dirname(__DIR__, 2) . '/src/views.php';
require_once dirname(__DIR__, 2) . '/src/settings_views.php';

/** Recoverable secrets are sealed with enc:v1: when a key is set. Password hashes are not. */
final class SecretAtRestTest extends DatabaseTestCase
{
    private array $savedEnv = [];
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_KEY', 'APP_KEY_FILE', 'APP_KEY_NEW', 'APOTHEKE_SODIUM'] as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
        }
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === false) putenv($k);
            else putenv($k . '=' . $v);
        }
        foreach ($this->cleanup as $p) if (is_file($p) || is_link($p)) @unlink($p);
        settings_cache(null, true);
        parent::tearDown();
    }

    public function testSodiumIsLoaded(): void
    {
        $this->assertTrue(extension_loaded('sodium'));
        $this->assertTrue(function_exists('sodium_crypto_secretbox'));
    }

    public function testOnlyRecoverableSecretsAreListed(): void
    {
        $this->assertSame(['autofill_token'], secret_at_rest_names());
        $this->assertSame('secret', settings_schema()['password_hash'][2]);
        $this->assertNotContains('password_hash', secret_at_rest_names());
    }

    public function testNoKeyKeepsPlaintextAndShowsTheWarning(): void
    {
        $plain = 'legacy-plain-' . bin2hex(random_bytes(4));
        settings_store(['autofill_token' => $plain]);
        $this->assertSame($plain, $this->decoded('autofill_token'));
        $this->assertStringNotContainsString(SECRET_ENVELOPE, $this->raw('autofill_token'));
        settings_cache(null, true);
        $this->assertSame($plain, setting('autofill_token'));
        $html = settings_autofill_token_html('csrf', null);
        $this->assertStringContainsString('id="secret-key-warn"', $html);
        $this->assertStringContainsString('Set APP_KEY to encrypt stored secrets.', $html);
        $this->assertStringNotContainsString($plain, $html);
    }

    public function testMissingKeyIsLoggedOnce(): void
    {
        $log = $this->temp('log');
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/src/bootstrap.php', true) . '; secret_key(); secret_key();';
        [$codeExit, $out, $err] = $this->php($code, ['APP_KEY' => '', 'APP_KEY_FILE' => '', 'APP_KEY_NEW' => ''], '', $log);
        $this->assertSame(0, $codeExit, $out . $err);
        $text = (string)file_get_contents($log);
        $this->assertSame(1, substr_count($text, 'apotheke: set APP_KEY to encrypt stored secrets'), $text);
    }

    public function testEverySecretRoundTripsAndIsNotStoredPlain(): void
    {
        $key = random_bytes(32);
        putenv('APP_KEY=base64:' . base64_encode($key));
        foreach (secret_at_rest_names() as $name) {
            $plain = 'secret-for-' . $name . '-' . bin2hex(random_bytes(6));
            settings_store([$name => $plain]);
            $first = $this->raw($name);
            settings_store([$name => $plain]);
            $second = $this->raw($name);
            $this->assertNotSame($first, $second);
            $this->assertStringContainsString(SECRET_ENVELOPE, $second);
            $this->assertStringNotContainsString($plain, $second);
            settings_cache(null, true);
            $this->assertSame($plain, setting($name));
            $this->assertStringNotContainsString($plain, (string)json_encode(settings_export()));
        }
    }

    public function testLegacyPlaintextIsReadAndUpgraded(): void
    {
        $plain = 'legacy-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        $this->pdo->prepare('UPDATE settings SET value = ? WHERE name = ?')->execute([json_encode($plain), 'autofill_token']);
        settings_cache(null, true);
        $this->assertSame($plain, setting('autofill_token'));
        $raw = $this->raw('autofill_token');
        $this->assertStringContainsString(SECRET_ENVELOPE, $raw);
        $this->assertStringNotContainsString($plain, $raw);
        settings_cache(null, true);
        $this->assertSame($plain, setting('autofill_token'));
    }

    public function testWrongKeyReadsAsUnsetAndDoesNotThrow(): void
    {
        $plain = 'wrong-key-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        settings_store(['autofill_token' => $plain]);
        $kept = $this->raw('autofill_token');
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        settings_cache(null, true);
        $log = $this->temp('log');
        $prev = ini_set('error_log', $log);
        $prevOn = ini_set('log_errors', '1');
        try {
            $this->assertSame('', setting('autofill_token'));
            $this->assertSame('', autofill_token());
        } finally {
            if ($prev !== false) ini_set('error_log', (string)$prev);
            if ($prevOn !== false) ini_set('log_errors', (string)$prevOn);
        }
        $text = (string)file_get_contents($log);
        $this->assertStringContainsString('setting autofill_token could not be decrypted and is treated as unset.', $text);
        $this->assertStringNotContainsString($plain, $text);
        $this->assertSame($kept, $this->raw('autofill_token'));
    }

    public function testAppKeyWinsOverTheKeyFile(): void
    {
        $fileKey = random_bytes(32);
        $envKey = random_bytes(32);
        $path = $this->temp('key');
        file_put_contents($path, base64_encode($fileKey) . "\n");
        putenv('APP_KEY_FILE=' . $path);
        putenv('APP_KEY=' . base64_encode($envKey));
        $plain = 'winner-' . bin2hex(random_bytes(4));
        settings_store(['autofill_token' => $plain]);
        $stored = (string)$this->decoded('autofill_token');
        $this->assertStringStartsWith(SECRET_ENVELOPE, $stored);
        $this->assertSame($plain, secret_open_with('autofill_token', $stored, $envKey, false));
        $this->assertNull(secret_open_with('autofill_token', $stored, $fileKey, false));
        settings_cache(null, true);
        $this->assertSame($plain, setting('autofill_token'));
    }

    public function testKeyFileOutsideTheDataDirEncrypts(): void
    {
        $key = random_bytes(32);
        $path = $this->temp('key');
        file_put_contents($path, base64_encode($key));
        $this->assertFalse(secret_key_file_refused($path));
        putenv('APP_KEY_FILE=' . $path);
        $plain = 'from-file-' . bin2hex(random_bytes(4));
        settings_store(['autofill_token' => $plain]);
        $this->assertStringNotContainsString($plain, $this->raw('autofill_token'));
        settings_cache(null, true);
        $this->assertSame($plain, setting('autofill_token'));
        $this->assertStringNotContainsString('id="secret-key-warn"', settings_autofill_token_html('csrf', null));
    }

    public function testKeyFileInsideTheDataDirIsRefused(): void
    {
        $path = DATA_DIR . '/app.key';
        $this->cleanup[] = $path;
        $fileKey = base64_encode(random_bytes(32));
        file_put_contents($path, $fileKey . "\n");
        $this->assertTrue(secret_key_file_refused($path));
        $this->assertTrue(secret_key_file_refused(ORIGINALS_DIR . '/k.key'));
        $this->assertUnusableKeyRefusesSave(
            ['APP_KEY' => '', 'APP_KEY_FILE' => $path],
            'APP_KEY_FILE is inside the data directory or a backup path',
            [$fileKey]
        );
    }

    public function testMalformedAppKeyRefusesTheSave(): void
    {
        $bad = 'malformed-key-' . bin2hex(random_bytes(6));
        $this->assertUnusableKeyRefusesSave(
            ['APP_KEY' => $bad, 'APP_KEY_FILE' => ''],
            'APP_KEY is not a 32-byte key',
            [$bad]
        );
    }

    public function testMalformedKeyFileRefusesTheSave(): void
    {
        $path = $this->temp('key');
        $bad = 'not-a-key-file-' . bin2hex(random_bytes(4));
        file_put_contents($path, $bad . "\n");
        $this->assertFalse(secret_key_file_refused($path));
        $this->assertUnusableKeyRefusesSave(
            ['APP_KEY' => '', 'APP_KEY_FILE' => $path],
            'APP_KEY_FILE is not a 32-byte key',
            [$bad]
        );
    }

    public function testUnreadableKeyFileRefusesTheSave(): void
    {
        $path = $this->temp('key');
        $fileKey = base64_encode(random_bytes(32));
        file_put_contents($path, $fileKey . "\n");
        chmod($path, 0000);
        $this->assertFalse(secret_key_file_refused($path));
        $this->assertUnusableKeyRefusesSave(
            ['APP_KEY' => '', 'APP_KEY_FILE' => $path],
            'APP_KEY_FILE could not be read',
            [$fileKey]
        );
    }

    public function testSodiumMissingWithAKeyRefusesTheSave(): void
    {
        $key = base64_encode(random_bytes(32));
        $this->assertUnusableKeyRefusesSave(
            ['APP_KEY' => $key, 'APP_KEY_FILE' => '', 'APOTHEKE_SODIUM' => '0'],
            'sodium extension is missing',
            [$key]
        );
    }

    public function testNoKeyStillStoresPlaintext(): void
    {
        putenv('APOTHEKE_SODIUM=0');
        $plain = 'plain-' . bin2hex(random_bytes(4));
        settings_store(['autofill_token' => $plain]);
        $this->assertSame($plain, $this->decoded('autofill_token'));
        $this->assertStringNotContainsString(SECRET_ENVELOPE, $this->raw('autofill_token'));
        $html = settings_autofill_token_html('csrf', null);
        $this->assertStringContainsString('id="secret-key-warn"', $html);
        $this->assertStringContainsString('Set APP_KEY to encrypt stored secrets.', $html);
        $this->assertStringNotContainsString('sodium', strtolower($html));
        $this->assertStringNotContainsString('cannot be saved', $html);
    }

    public function testAddressChangeClearsTokenWhenTheKeyIsWrong(): void
    {
        $this->assertAddressChangeClearsUndecryptableToken(false);
    }

    public function testAddressChangeClearsTokenWhenTheKeyIsMissing(): void
    {
        $this->assertAddressChangeClearsUndecryptableToken(true);
    }

    public function testPasswordHashStaysAHash(): void
    {
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        $hash = password_hash('a-long-password', PASSWORD_DEFAULT);
        settings_store(['password_hash' => $hash]);
        $raw = $this->raw('password_hash');
        $this->assertStringContainsString($hash, $raw);
        $this->assertStringNotContainsString(SECRET_ENVELOPE, $raw);
        $id = user_create('ada', 'a-long-password', 'viewer');
        $col = (string)$this->pdo->query('SELECT password_hash FROM users WHERE id = ' . (int)$id)->fetchColumn();
        $this->assertStringNotContainsString(SECRET_ENVELOPE, $col);
        $this->assertTrue(password_verify('a-long-password', $col));
    }

    public function testBackupAndExportOmitTheSecretAndTheKey(): void
    {
        if (!class_exists('ZipArchive')) $this->markTestSkipped('zip extension missing');
        $key = random_bytes(32);
        $keyText = base64_encode($key);
        putenv('APP_KEY=' . $keyText);
        $plain = 'backup-secret-' . bin2hex(random_bytes(6));
        settings_store(['autofill_token' => $plain]);
        $export = (string)json_encode(settings_export());
        $this->assertStringNotContainsString($plain, $export);
        $this->assertStringNotContainsString(SECRET_ENVELOPE, $export);
        $this->assertStringNotContainsString('autofill_token', $export);
        $this->assertStringNotContainsString($keyText, $export);

        $dir = $this->temp('dir');
        mkdir($dir);
        $sentinel = 'KEYFILE-SENTINEL-' . bin2hex(random_bytes(8));
        file_put_contents($dir . '/app.key', $sentinel . "\n" . $keyText);
        $zipPath = $dir . '/backup.zip';
        backup_create($this->pdo, $this->driver, $dir, $zipPath);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $names = [];
        $blob = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string)$zip->getNameIndex($i);
            $blob .= (string)$zip->getFromIndex($i);
        }
        $zip->close();
        $this->assertNotContains('app.key', $names);
        $this->assertStringNotContainsString($plain, $blob);
        $this->assertStringNotContainsString($sentinel, $blob);
        $this->assertStringNotContainsString($keyText, $blob);
        $this->assertStringNotContainsString('autofill_token', $blob);
    }

    public function testRotateRoundTripPrintsCountsOnly(): void
    {
        $old = random_bytes(32);
        $new = random_bytes(32);
        $plain = 'rotate-me-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode($old));
        settings_store(['autofill_token' => $plain]);
        [$exit, $out, $err] = $this->settings(['APP_KEY' => base64_encode($old), 'APP_KEY_NEW' => base64_encode($new), 'APP_KEY_FILE' => ''], ['key', 'rotate']);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertSame("rotated 1, encrypted 0\n", $out);
        $this->assertStringNotContainsString($plain, $out . $err);
        $this->assertStringNotContainsString(base64_encode($new), $out . $err);
        $raw = $this->raw('autofill_token');
        $this->assertStringNotContainsString($plain, $raw);
        $this->assertNull(secret_open_with('autofill_token', (string)$this->decoded('autofill_token'), $old, false));
        putenv('APP_KEY=' . base64_encode($new));
        settings_cache(null, true);
        $this->assertSame($plain, setting('autofill_token'));
    }

    public function testRotateEncryptsLegacyPlaintextFromStdin(): void
    {
        $plain = 'stdin-legacy-' . bin2hex(random_bytes(4));
        $this->pdo->prepare('UPDATE settings SET value = ? WHERE name = ?')->execute([json_encode($plain), 'autofill_token']);
        $new = random_bytes(32);
        [$exit, $out, $err] = $this->settings(['APP_KEY' => '', 'APP_KEY_NEW' => '', 'APP_KEY_FILE' => ''], ['key', 'rotate'], base64_encode($new) . "\n");
        $this->assertSame(0, $exit, $out . $err);
        $this->assertSame("rotated 0, encrypted 1\n", $out);
        $this->assertStringNotContainsString($plain, $out . $err);
        putenv('APP_KEY=' . base64_encode($new));
        settings_cache(null, true);
        $this->assertSame($plain, setting('autofill_token'));
    }

    public function testRotateWithTheWrongOldKeyChangesNothingAndPrintsNoSecret(): void
    {
        $plain = 'do-not-print-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        settings_store(['autofill_token' => $plain]);
        $before = $this->raw('autofill_token');
        [$exit, $out, $err] = $this->settings([
            'APP_KEY' => base64_encode(random_bytes(32)),
            'APP_KEY_NEW' => base64_encode(random_bytes(32)),
            'APP_KEY_FILE' => '',
        ], ['key', 'rotate']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('could not be decrypted. Nothing was changed.', $err);
        $this->assertStringNotContainsString($plain, $out . $err);
        $this->assertSame($before, $this->raw('autofill_token'));
        settings_cache(null, true);
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        $this->assertSame('', setting('autofill_token'));
    }

    public function testRotateRefusesAKeyOnArgv(): void
    {
        $plain = 'argv-secret-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        settings_store(['autofill_token' => $plain]);
        $before = $this->raw('autofill_token');
        [$exit, $out, $err] = $this->settings(['APP_KEY_NEW' => ''], ['key', 'rotate', $plain]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not read from the command line', $err);
        $this->assertStringNotContainsString($plain, $out . $err);
        $this->assertSame($before, $this->raw('autofill_token'));
    }

    public function testKeyGeneratePrintsAKeyAndNoStoredSecret(): void
    {
        $plain = 'generate-secret-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        settings_store(['autofill_token' => $plain]);
        [$exit, $out, $err] = $this->settings(['APP_KEY' => (string)getenv('APP_KEY'), 'APP_KEY_NEW' => ''], ['key', 'generate']);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertSame(32, strlen((string)base64_decode(trim($out), true)));
        $this->assertStringNotContainsString($plain, $out . $err);
        [$exit2, $out2] = $this->settings([], ['key', 'generate']);
        $this->assertSame(0, $exit2);
        $this->assertNotSame(trim($out), trim($out2));
    }

    /** @param array<string,string> $env @param list<string> $forbidden */
    private function assertUnusableKeyRefusesSave(array $env, string $phrase, array $forbidden): void
    {
        $oldPlain = 'old-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        putenv('APP_KEY_FILE');
        putenv('APOTHEKE_SODIUM');
        settings_cache(null, true);
        settings_store(['autofill_token' => $oldPlain]);
        $before = $this->raw('autofill_token');
        $this->assertStringContainsString(SECRET_ENVELOPE, $before);
        $this->assertStringNotContainsString($oldPlain, $before);

        foreach (['APP_KEY', 'APP_KEY_FILE', 'APOTHEKE_SODIUM'] as $k) putenv($k);
        foreach ($env as $k => $v) {
            if ($v === '') putenv($k);
            else putenv($k . '=' . $v);
        }
        settings_cache(null, true);

        $newPlain = 'new-' . bin2hex(random_bytes(6));
        $log = $this->temp('log');
        $prev = ini_set('error_log', $log);
        $prevOn = ini_set('log_errors', '1');
        try {
            $html = settings_autofill_token_html('csrf', null);
            $this->assertStringContainsString('id="secret-key-warn"', $html);
            $this->assertStringContainsString($phrase, $html);
            $this->assertStringContainsString('Stored secrets cannot be saved.', $html);
            $this->assertStringNotContainsString('Set APP_KEY', $html);
            foreach ($forbidden as $secret) $this->assertStringNotContainsString($secret, $html);

            $thrown = null;
            try {
                settings_store(['autofill_token' => $newPlain]);
            } catch (UserError $e) {
                $thrown = $e;
            }
            $this->assertInstanceOf(UserError::class, $thrown);
            $this->assertStringContainsString($phrase, $thrown->getMessage());
            $this->assertStringContainsString('The secret was not saved.', $thrown->getMessage());
            foreach ($forbidden as $secret) $this->assertStringNotContainsString($secret, $thrown->getMessage());
            $this->assertStringNotContainsString($newPlain, $thrown->getMessage());
            $this->assertStringNotContainsString($oldPlain, $thrown->getMessage());

            $err = settings_save(['autofill_token' => $newPlain]);
            $this->assertArrayHasKey('autofill_token', $err);
            $this->assertStringContainsString($phrase, $err['autofill_token']);
            $this->assertStringContainsString('The secret was not saved.', $err['autofill_token']);
            $form = settings_autofill_token_html('csrf', $err['autofill_token']);
            $this->assertStringContainsString('id="af-token-err"', $form);
            $this->assertStringContainsString($phrase, $form);
            $this->assertStringContainsString('The secret was not saved.', $form);
        } finally {
            if ($prev !== false) ini_set('error_log', (string)$prev);
            if ($prevOn !== false) ini_set('log_errors', (string)$prevOn);
        }

        $this->assertSame($before, $this->raw('autofill_token'));
        $this->assertStringNotContainsString($newPlain, $this->raw('autofill_token'));
        $text = (string)file_get_contents($log);
        $this->assertStringContainsString('apotheke: ' . $phrase . '; stored secrets will not be saved.', $text);
        $this->assertStringNotContainsString('set APP_KEY to encrypt stored secrets', $text);
        $this->assertStringNotContainsString('stay unencrypted', $text);
        $this->assertStringNotContainsString($newPlain, $text);
        $this->assertStringNotContainsString($oldPlain, $text);
        foreach ($forbidden as $secret) $this->assertStringNotContainsString($secret, $text);
    }

    private function assertAddressChangeClearsUndecryptableToken(bool $dropKey): void
    {
        $key = random_bytes(32);
        $token = 'tok-' . bin2hex(random_bytes(6));
        putenv('APP_KEY=' . base64_encode($key));
        settings_cache(null, true);
        settings_store([
            'autofill_url' => 'http://autofill.example',
            'autofill_token' => $token,
        ]);
        $sealed = $this->raw('autofill_token');
        $this->assertStringContainsString(SECRET_ENVELOPE, $sealed);
        if ($dropKey) putenv('APP_KEY');
        else putenv('APP_KEY=' . base64_encode(random_bytes(32)));
        settings_cache(null, true);
        $this->assertSame('', setting('autofill_token'));
        $this->assertSame('', autofill_token());
        $cleared = settings_store(['autofill_url' => 'http://other.example']);
        $this->assertTrue($cleared);
        $this->assertSame('', $this->decoded('autofill_token'));
        $this->assertStringNotContainsString($token, $this->raw('autofill_token'));
        $this->assertStringNotContainsString(SECRET_ENVELOPE, $this->raw('autofill_token'));
        putenv('APP_KEY=' . base64_encode($key));
        settings_cache(null, true);
        $this->assertSame('', setting('autofill_token'));
        $this->assertStringNotContainsString($token, $this->raw('autofill_token'));
    }

    private function raw(string $name): string
    {
        $st = $this->pdo->prepare('SELECT value FROM settings WHERE name = ?');
        $st->execute([$name]);
        return (string)$st->fetchColumn();
    }

    private function decoded(string $name): mixed
    {
        return json_decode($this->raw($name), true);
    }

    private function temp(string $suffix): string
    {
        $p = sys_get_temp_dir() . '/apotheke-secret-' . bin2hex(random_bytes(4)) . '-' . $suffix;
        $this->cleanup[] = $p;
        return $p;
    }

    /** @param array<string,string> $env @param list<string> $args @return array{0:int,1:string,2:string} */
    private function settings(array $env, array $args, string $stdin = ''): array
    {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/settings.php'], $args);
        return $this->proc($cmd, $this->childEnv($env), $stdin);
    }

    /** @param array<string,string> $env @return array{0:int,1:string,2:string} */
    private function php(string $code, array $env, string $stdin, string $log): array
    {
        $cmd = [PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $log, '-r', $code];
        return $this->proc($cmd, array_merge($this->childEnv($env), ['DATA_DIR' => DATA_DIR]), $stdin);
    }

    /** @param list<string> $cmd @param array<string,string> $env @return array{0:int,1:string,2:string} */
    private function proc(array $cmd, array $env, string $stdin): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    /** @param array<string,string> $extra */
    private function childEnv(array $extra): array
    {
        $env = array_merge(getenv(), [
            'APOTHEKE_CONFIG' => '/nonexistent',
            'SEED_DEFAULTS' => '0',
            'DB_DRIVER' => $this->driver,
            'DATA_DIR' => DATA_DIR,
            'APP_KEY_FILE' => '',
            'APP_KEY_NEW' => '',
        ], $extra);
        if ($this->driver === 'sqlite') {
            $env['SQLITE_PATH'] = (string)$this->pdo->query('PRAGMA database_list')->fetch()['file'];
        } else {
            $db = cfg()['db'];
            $env['DB_SCHEMA'] = (string)$this->pdo->query('SELECT current_schema()')->fetchColumn();
            $env['DB_HOST'] = (string)$db['host'];
            $env['DB_PORT'] = (string)$db['port'];
            $env['DB_NAME'] = (string)$db['name'];
            $env['DB_USER'] = (string)$db['user'];
            $env['DB_PASSWORD'] = (string)$db['password'];
        }
        return $env;
    }
}
