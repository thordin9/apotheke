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
