<?php
declare(strict_types=1);

/** Password input checks, the sign-in limit's bookkeeping, and copying a photo's original. */
final class HardeningTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh(['seed' => false]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    public function testPasswordsWithANullCharacterAreRefused(): void
    {
        try { user_password_valid("long-enough\0more"); $this->fail('accepted'); } catch (UserError $e) { $this->assertSame(422, $e->getCode()); }
        $this->assertSame('long-enough', user_password_valid('long-enough'));
        user_create('ada', 'long-enough', 'admin');
        $this->assertNotNull(user_verify('ada', 'long-enough'));
        $this->assertNull(user_verify('ada', "long-enough\0x"));
    }

    public function testANameWithANullCharacterFindsNoAccount(): void
    {
        user_create('ada', 'long-enough', 'admin');
        $this->assertNotNull(user_by_name('ada'));
        foreach (["ada\0x", "\0ada", "ada\0"] as $name) {
            $this->assertNull(user_by_name($name), json_encode($name));
            $this->assertNull(user_verify($name, 'long-enough'), json_encode($name));
        }
    }

    public function testNewNamesWithANullCharacterAreRefused(): void
    {
        $id = user_create('ada', 'long-enough', 'admin');
        foreach (["bea\0", "\0bea", "be\0a", "\0"] as $name) {
            foreach (['add' => fn() => user_create($name, 'long-enough', 'viewer'), 'rename' => fn() => user_rename($id, $name)] as $what => $try) {
                try {
                    $try();
                    $this->fail($what . ' accepted ' . json_encode($name));
                } catch (UserError $e) {
                    $this->assertSame(422, $e->getCode(), json_encode($name));
                    $this->assertStringStartsWith('User names use letters, digits', $e->getMessage(), $what . ' ' . json_encode($name));
                }
            }
        }
        $this->assertSame(['ada'], array_column(users_list(), 'username'), 'nothing added or renamed');
    }

    public function testAppPasswordWithANullCharacterIsRefused(): void
    {
        $this->assertNotNull(app_password_problem("long-enough\0"));
        $this->assertNull(app_password_problem('long-enough'));
    }

    public function testARefusedAttemptIsNotRecorded(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.21';
        for ($i = 0; $i < 5; $i++) rate_limit('register');
        for ($i = 0; $i < 3; $i++) {
            try { rate_limit('register'); $this->fail('no limit'); } catch (UserError $e) { $this->assertSame(429, $e->getCode()); }
        }
        $this->assertSame(5, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'register'")->fetchColumn());
    }

    public function testSignInsFromOneAddressShareACap(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.22';
        for ($i = 0; $i < 30; $i++) rate_limit('login_address');
        try { rate_limit('login_address'); $this->fail('no limit'); } catch (UserError $e) { $this->assertSame(429, $e->getCode()); }
        rate_limit_clear('login_address');
        rate_limit('login_address');
        $this->addToAssertionCount(1);
    }

    public function testPerAddressCapsCountAnIpv6ClientByItsPrefix(): void
    {
        $this->assertSame('192.0.2.7', address_scope('192.0.2.7'));
        $this->assertSame('2001:db8:1:2::/64', address_scope('2001:db8:1:2:aaaa::1'));
        $this->assertSame('::ffff:192.0.2.7', address_scope('::ffff:192.0.2.7'));
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
        for ($i = 0; $i < 30; $i++) rate_limit('login_address');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::ffff';
        try { rate_limit('login_address'); $this->fail('no limit'); } catch (UserError $e) { $this->assertSame(429, $e->getCode()); }
        rate_limit('login', 'ada');   // the per-account limit still counts the full address
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
        rate_limit('login_address');
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login'")->fetchColumn());
    }

    public function testAReplacedPhotoIsRemovedOnlyAfterTheCommit(): void
    {
        $old = $this->storedPhoto();
        $new = $this->storedPhoto();
        $id = item_save(null, ['name' => 'Lamp', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $old, false);
        $fail = new DomainException('outer failure');   // only this is caught; PHPUnit's own failures pass through
        $caught = null;
        try {
            db_tx(function () use ($id, $old, $new, $fail): void {
                item_save(item_get($id), item_input(item_get($id)), $new, false);
                $this->assertFileExists(IMG_DIR . '/' . $old, 'kept until the commit');
                throw $fail;
            });
        } catch (DomainException $e) {
            $caught = $e;
        }
        $this->assertSame($fail, $caught);
        $this->assertFileExists(IMG_DIR . '/' . $old, 'a rollback keeps the photo');
        $this->assertSame($old, item_get($id)['image']);
        db_tx(fn() => item_save(item_get($id), item_input(item_get($id)), $new, false));
        $this->assertFileDoesNotExist(IMG_DIR . '/' . $old, 'removed after the commit');
        $this->assertSame($new, item_get($id)['image']);
        image_delete($new);
    }

    public function testAfterCommitCallbacksRunOnceAfterTheCommitOnly(): void
    {
        $ran = [];
        db_tx(function () use (&$ran): void {
            after_commit(function () use (&$ran): void { $ran[] = db()->inTransaction() ? 'inside' : 'after'; });
            $this->assertSame([], $ran, 'not before the commit');
        });
        $this->assertSame(['after'], $ran);
        try {
            db_tx(function () use (&$ran): void {
                after_commit(function () use (&$ran): void { $ran[] = 'rolled back'; });
                throw new DomainException('roll back');
            });
        } catch (DomainException) {
        }
        db_tx(fn() => null);
        $this->assertSame(['after'], $ran, 'a rolled-back callback never runs later');

        // a transaction opened directly ends the same way
        db()->beginTransaction();
        after_commit(function () use (&$ran): void { $ran[] = 'direct'; });
        db()->rollBack();
        after_commit_end(false);
        db_tx(fn() => null);
        $this->assertSame(['after'], $ran);
    }

    /** Import, restore and migrate end their own transactions: queued cleanups run after the COMMIT, never after a rollback. */
    public function testImportRestoreAndMigrateRunQueuedCleanupsOnlyAfterTheCommit(): void
    {
        $ran = [];
        $queue = function (string $site) use (&$ran): void {
            $q = &after_commit_queue();
            $q[] = function () use (&$ran, $site): void { $ran[] = $site . (db()->inTransaction() ? ' inside' : ' after'); };
        };

        $csv = "name\nSaw\n";
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $csv);
        $imp = import_get(import_upload(['error' => UPLOAD_ERR_OK, 'size' => strlen($csv), 'tmp_name' => $tmp, 'name' => 'x.csv']));
        @unlink($tmp);
        db()->prepare('UPDATE imports SET mapping = ?, options = ? WHERE id = ?')->execute([json_encode(['name']), json_encode(['create' => true, 'skip_errors' => false]), $imp['id']]);
        $imp = import_get($imp['id']);
        $queue('import preview');
        $this->assertFalse(import_run($imp, false)['committed']);
        $this->assertSame([], $ran, 'an import preview rolls back');
        $queue('import');
        $this->assertTrue(import_run($imp, true)['committed']);
        $this->assertSame(['import after'], $ran);

        $dump = backup_dump($this->pdo, $this->driver);
        unset($dump['settings']);   // the settings step runs its own transaction, which would drain the queue too
        $zip = function (array $dump): string {
            $z = new ZipArchive();
            $path = tempnam(sys_get_temp_dir(), 'bak') . '.zip';
            $z->open($path, ZipArchive::CREATE);
            $z->addFromString('backup.json', (string)json_encode($dump));
            $z->close();
            return $path;
        };
        $bad = $dump;
        $i = array_search('location_id', $bad['tables']['items']['columns'], true);
        $bad['tables']['items']['rows'][0][$i] = 999999;   // dangling foreign key: the restore rolls back
        $dataDir = sys_get_temp_dir() . '/apotheke-drain-' . bin2hex(random_bytes(4));
        mkdir($dataDir);
        foreach ([$zip($bad) => 'restore failed', $zip($dump) => 'restore'] as $path => $site) {
            $queue($site);
            try {
                backup_restore($this->pdo, $this->driver, $dataDir, $path);
            } catch (UserError $e) {
                $this->assertSame('restore failed', $site, $e->getMessage());
            }
            @unlink($path);
            $this->assertSame($site === 'restore' ? ['import after', 'restore after'] : ['import after'], $ran, $site);
        }
        foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST)) as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
        @rmdir($dataDir);

        // migrate (on SQLite before PHP 8.4, PDO does not see its BEGIN IMMEDIATE, so the cleanup is queued up front)
        $dir = sys_get_temp_dir() . '/apotheke-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/0901_fail.php", "<?php return ['sqlite' => 'CREATE TABLE drain_fail (', 'pgsql' => 'CREATE TABLE drain_fail ('];");
        $queue('migrate failed');
        try { migrate($this->pdo, $this->driver, ['dir' => $dir]); $this->fail('expected a failure'); } catch (PDOException) {}
        $this->assertSame(['import after', 'restore after'], $ran, 'a failed migration rolls back');
        file_put_contents("$dir/0901_fail.php", "<?php return ['sqlite' => 'CREATE TABLE drain_ok (id INTEGER)', 'pgsql' => 'CREATE TABLE drain_ok (id INTEGER)'];");
        $queue('migrate');
        $this->assertSame(['0901'], migrate($this->pdo, $this->driver, ['dir' => $dir]));
        $this->assertSame(['import after', 'restore after', 'migrate after'], $ran);
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
        db_tx(fn() => null);
        $this->assertSame(['import after', 'restore after', 'migrate after'], $ran, 'rolled-back cleanups never run later');
    }

    /** An import that ends in an error, with row errors or with an exception, drops the queued cleanups. */
    public function testAFailedImportDropsQueuedCleanups(): void
    {
        $ran = [];
        $queue = function () use (&$ran): void {
            $q = &after_commit_queue();
            $q[] = function () use (&$ran): void { $ran[] = 'ran'; };
        };
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, "name,notes\nSaw,\n,empty name\n");
        $imp = import_get(import_upload(['error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), 'tmp_name' => $tmp, 'name' => 'x.csv']));
        @unlink($tmp);
        db()->prepare('UPDATE imports SET mapping = ?, options = ? WHERE id = ?')->execute([json_encode(['name', 'notes']), json_encode(['create' => true, 'skip_errors' => false]), $imp['id']]);
        $imp = import_get($imp['id']);

        $queue();
        $out = import_run($imp, true);
        $this->assertSame([1, false], [$out['errors'], $out['committed']], 'a row error stops the import');
        $this->assertSame([], $ran, 'row errors roll back');

        $this->pdo->exec('ALTER TABLE tags RENAME TO tags_off');   // the import's first query fails
        $queue();
        try {
            import_run($imp, true);
            $this->fail('expected a failure');
        } catch (PDOException) {
        } finally {
            $this->pdo->exec('ALTER TABLE tags_off RENAME TO tags');
        }
        db_tx(fn() => null);
        $this->assertSame([], $ran, 'a failed import rolls back');
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    /**
     * after_commit() inside a migration waits for migrate's COMMIT and is dropped on a rollback, on every driver and
     * PHP version: the callback sees the migration from a second connection (N-312), and right after a failed
     * migration the flag is off and after_commit() runs at once (N-313).
     */
    public function testAfterCommitInsideAMigrationWaitsForTheCommit(): void
    {
        $GLOBALS['mig_ran'] = [];
        $GLOBALS['mig_other'] = $this->connectAgain();
        $dir = sys_get_temp_dir() . '/apotheke-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $step = fn(string $sql): string => "<?php return function (PDO \$pdo): void { after_commit(function (): void { \$GLOBALS['mig_ran'][] = 'after ' . \$GLOBALS['mig_other']->query(\"SELECT COUNT(*) FROM schema_migrations WHERE version = '0902'\")->fetchColumn(); }); \$GLOBALS['mig_ran'][] = 'step'; \$pdo->exec('$sql'); };";
        try {
            file_put_contents("$dir/0902_after.php", $step('CREATE TABLE mig_after ('));
            try { migrate($this->pdo, $this->driver, ['dir' => $dir]); $this->fail('expected a failure'); } catch (PDOException) {}
            $this->assertSame(['step'], $GLOBALS['mig_ran'], 'a failed migration drops it');
            $this->assertFalse(migrate_active(), 'the flag is off after a failed migration');
            after_commit(function (): void { $GLOBALS['mig_ran'][] = 'after a failure'; });
            $this->assertSame(['step', 'after a failure'], $GLOBALS['mig_ran'], 'and after_commit() runs at once');
            file_put_contents("$dir/0902_after.php", $step('CREATE TABLE mig_after (id INTEGER)'));
            $this->assertSame(['0902'], migrate($this->pdo, $this->driver, ['dir' => $dir]));
            $this->assertSame(['step', 'after a failure', 'step', 'after 1'], $GLOBALS['mig_ran'], 'runs after the commit, which another connection sees');
            after_commit(function (): void { $GLOBALS['mig_ran'][] = 'outside'; });
            $this->assertSame('outside', end($GLOBALS['mig_ran']), 'outside a transaction it runs at once again');
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
            unset($GLOBALS['mig_ran'], $GLOBALS['mig_other']);
        }
    }

    /**
     * bin/migrate.php gives its connection to db(), so a migration that calls after_commit() or db() does not open a
     * second connection (N-311). With a second one, SQLite answered "database is locked" and PostgreSQL waited on the
     * migrations lock. Runs a copy of the app with one extra migration.
     */
    public function testTheMigrateCommandSharesItsConnection(): void
    {
        $app = sys_get_temp_dir() . '/apotheke-cli-' . bin2hex(random_bytes(4));
        $root = dirname(__DIR__, 2);
        mkdir($app);
        try {
            foreach (['bin', 'src', 'migrations'] as $d) exec('cp -R ' . escapeshellarg("$root/$d") . ' ' . escapeshellarg("$app/$d"));
            file_put_contents("$app/migrations/9990_after_commit.php", "<?php return function (PDO \$pdo): void { db()->exec('CREATE TABLE cli_after (id INTEGER)'); after_commit(function (): void { db()->exec('INSERT INTO cli_after (id) VALUES (1)'); }); };");
            $env = array_merge(getenv(), $this->driver === 'pgsql' ? ['DB_SCHEMA' => 'c_' . bin2hex(random_bytes(6))] : ['SQLITE_PATH' => "$app/cli.sqlite"]);
            $proc = proc_open([PHP_BINARY, "$app/bin/migrate.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            stream_set_blocking($pipes[1], false);
            $out = '';
            $deadline = microtime(true) + 20;
            while (proc_get_status($proc)['running'] && microtime(true) < $deadline) { $out .= stream_get_contents($pipes[1]); usleep(50000); }
            $running = proc_get_status($proc)['running'];
            if ($running) proc_terminate($proc, 9);
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $code = proc_close($proc);
            $this->assertFalse($running, 'migrate.php did not finish: ' . $out);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('applied 9990 after_commit', $out);
            $pdo = db_connect(['sqlite_path' => "$app/cli.sqlite", 'schema' => $env['DB_SCHEMA'] ?? ''] + cfg()['db']);
            $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM cli_after')->fetchColumn(), 'the queued step ran after the commit');
            if ($this->driver === 'pgsql') $pdo->exec('DROP SCHEMA "' . $env['DB_SCHEMA'] . '" CASCADE');
        } finally {
            exec('rm -rf ' . escapeshellarg($app));
        }
    }

    /** A failed password check waits until sign_in_fail_ms after the request began; once past it, it does not wait. */
    public function testSignInPaceWaitsForTheDeadlineFromTheStart(): void
    {
        $slept = [];
        $sleep = function (int $us) use (&$slept): void { $slept[] = $us; };
        $ms = cfg()['sign_in_fail_ms'];
        $waited = sign_in_pace(microtime(true) - 0.3, $sleep);
        $this->assertSame([$waited], $slept);
        $this->assertEqualsWithDelta(($ms - 300) * 1000, $waited, 20000, 'the rest of the deadline, not a fixed sleep');
        $this->assertSame(0, sign_in_pace(microtime(true) - $ms / 1000 - 0.01, $sleep), 'past the deadline');
        $this->assertCount(1, $slept);
    }

    public function testAFailingAfterCommitCallbackDoesNotStopTheOthers(): void
    {
        $ran = [];
        $log = ini_set('error_log', '/dev/null');
        try {
            db_tx(function () use (&$ran): void {
                after_commit(function (): void { throw new RuntimeException('cleanup failed'); });
                after_commit(function () use (&$ran): void { $ran[] = 'second'; });
            });
        } finally {
            ini_set('error_log', (string)$log);
        }
        $this->assertSame(['second'], $ran);
    }

    public function testTheAccountKeyKeepsTheFullAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
        $a = [rate_bucket('login', 'ada'), rate_bucket('login_address', null)];
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::2';
        $b = [rate_bucket('login', 'ada'), rate_bucket('login_address', null)];
        $this->assertNotSame($a[0], $b[0], 'per account: the full address');
        $this->assertSame($a[1], $b[1], 'per address: the /64');
        $this->assertSame('64:ff9b::c000:207', address_scope('64:ff9b::c000:207'));
        $this->assertSame('::c000:207', address_scope('::c000:207'));
    }

    public function testTheCurrentPasswordCheckHasTheAddressCap(): void
    {
        $id = user_create('ada', 'long-enough', 'admin');
        $me = user_get($id);
        $_SERVER['REMOTE_ADDR'] = '2001:db8:7:8::1';
        for ($i = 0; $i < 5; $i++) rate_limit('login_address');
        user_check_current($me, 'long-enough');
        $this->assertSame(5, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login_address'")->fetchColumn(), 'a success takes back only its own row');
        for ($i = 0; $i < 25; $i++) rate_limit('login_address');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:7:8::99';   // same /64
        try { user_check_current($me, 'long-enough'); $this->fail('no cap'); } catch (UserError $e) { $this->assertSame(429, $e->getCode()); }
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login'")->fetchColumn(), 'stopped before the account count');
    }

    private function storedPhoto(): string
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, (string)ob_get_clean());
        $name = image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'name' => 'a.jpg']);
        @unlink($tmp);
        return $name;
    }

    public function testCopyingAPhotoWhoseOriginalIsGoneFails(): void
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, (string)ob_get_clean());
        $name = image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'name' => 'a.jpg']);
        @unlink($tmp);
        $to = bin2hex(random_bytes(16)) . '.jpg';
        try {
            unlink((string)image_original_path($name));
            $refused = false;
            try { image_original_copy($name, $to); } catch (RuntimeException) { $refused = true; }
            $this->assertTrue($refused, 'a missing original is refused');
            $this->assertNull(image_original_path($to));
            @unlink((string)image_original_map_path($name));
            image_original_copy($name, $to);   // a photo from before originals were kept: nothing to copy
            $this->assertNull(image_original_path($to));
        } finally {
            image_delete($name);
        }
    }
}
