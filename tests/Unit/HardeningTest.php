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
            try { image_original_copy($name, $to); $this->fail('copied nothing'); } catch (RuntimeException) { $this->assertNull(image_original_path($to)); }
            @unlink((string)image_original_map_path($name));
            image_original_copy($name, $to);   // a photo from before originals were kept: nothing to copy
            $this->assertNull(image_original_path($to));
        } finally {
            image_delete($name);
        }
    }
}
