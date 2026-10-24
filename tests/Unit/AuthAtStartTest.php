<?php
declare(strict_types=1);

/** auth_on_at_start(): the Docker entrypoint's read-only sign-in check (hardening, SEC-M3). */
final class AuthAtStartTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
    }

    public function testNoDatabaseOrNoTablesFollowsAppPassword(): void
    {
        $this->assertFalse(auth_on_at_start(null, ''));
        $this->assertTrue(auth_on_at_start(null, 'change-me'));
        $this->assertFalse(auth_on_at_start($this->pdo, ''), 'empty database');
        $this->assertTrue(auth_on_at_start($this->pdo, 'change-me'), 'empty database');
        $this->assertFalse($this->tableExists('settings'), 'the check creates nothing');
    }

    public function testStoredSettingWins(): void
    {
        $this->migrateFresh(['seed' => false]);
        settings_store(['auth_enabled' => false]);
        $this->assertFalse(auth_on_at_start($this->pdo, ''));
        $this->assertFalse(auth_on_at_start($this->pdo, 'change-me'), 'sign-in turned off in the database stays off');

        settings_store(['auth_enabled' => true]);
        $this->assertFalse(auth_on_at_start($this->pdo, ''), 'on, but no account and no APP_PASSWORD');
        $this->assertTrue(auth_on_at_start($this->pdo, 'change-me'), 'APP_PASSWORD creates admin');
        user_create('root', 'long-enough', 'admin');
        $this->assertTrue(auth_on_at_start($this->pdo, ''), 'existing install with an account');
    }

    public function testMissingRowFollowsAppPassword(): void
    {
        $this->migrateFresh(['seed' => false]);
        $this->pdo->exec("DELETE FROM settings WHERE name = 'auth_enabled'");
        $this->assertFalse(auth_on_at_start($this->pdo, ''));
        $this->assertTrue(auth_on_at_start($this->pdo, 'change-me'));
    }
}
