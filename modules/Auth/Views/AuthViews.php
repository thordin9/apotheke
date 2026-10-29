<?php

declare(strict_types=1);

namespace Apotheke\Auth\Views;

use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;

/**
 * The sign-in, registration and account pages: legacy view_login(),
 * view_register() and view_account() (2.x views.php), kept line for line so
 * the output is byte-identical; every value is escaped with Page::e().
 */
final class AuthViews
{
    /**
     * @param array{appName:string,tagline:string,registrationOpen:bool,guestBrowsing:bool,usernameMax:int} $v
     */
    public static function login(Page $page, array $v, ?string $error, string $username = '', ?string $note = null): string
    {
        $head = $page->start('Sign in', 'page-login');
        ['appName' => $appName, 'tagline' => $tagline, 'registrationOpen' => $registrationOpen, 'guestBrowsing' => $guestBrowsing, 'usernameMax' => $usernameMax] = $v;
        ob_start();
        ?>
<main class="wrap narrow login">
  <div class="brand brand-lg"><?= $page->brandLogo(56) ?><span><?= Page::e($appName) ?></span></div>
  <p class="tagline"><?= Page::e(ucfirst($tagline)) ?></p>
  <form method="post" action="/login" class="itemform" id="login-form">
    <input type="hidden" name="csrf" value="<?= Page::e($page->csrf()) ?>">
    <?php if ($error): ?><div class="err" role="alert"><?= Page::e($error) ?></div><?php endif; ?>
    <label class="field"><span>User name</span><input type="text" name="username" id="login-user" value="<?= Page::e($username) ?>" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" maxlength="<?= $usernameMax * 2 ?>" required<?= $username === '' ? ' autofocus' : '' ?>></label>
    <label class="field"><span>Password</span><input type="password" name="password" id="login-pw" autocomplete="current-password" required enterkeyhint="go"<?= $username !== '' ? ' autofocus' : '' ?>></label>
    <button class="btn btn-primary btn-wide" id="login-go">Sign in</button>
  </form>
  <?php if ($note === 'pending'): ?><p class="okbox" role="status" id="login-pending">Account created. You can sign in once an admin has approved it.</p><?php endif; ?>
  <?php if ($registrationOpen): ?><p class="login-alt"><a class="btn btn-wide" href="/register" id="login-register">Create an account</a></p><?php endif; ?>
  <?php if ($guestBrowsing): ?><p class="login-alt"><a class="btn btn-wide" href="/" id="login-guest">Look around as a guest</a></p><?php endif; ?>
</main>
<?php
        return $head . ob_get_clean() . $page->end();
    }

    /**
     * @param array{appName:string,approval:bool,registerRole:string,roleName:string,usernameMax:int,passwordMin:int} $v
     */
    public static function register(Page $page, array $v, ?string $error, string $username = ''): string
    {
        $head = $page->start('Create an account', 'page-login page-register');
        ['appName' => $appName, 'approval' => $approval, 'usernameMax' => $usernameMax, 'passwordMin' => $passwordMin] = $v;
        $roleName = static fn (string $role): string => $v['roleName'];
        $registerRole = $v['registerRole'];
        ob_start();
        ?>
<main class="wrap narrow login">
  <div class="brand brand-lg"><?= $page->brandLogo(56) ?><span><?= Page::e($appName) ?></span></div>
  <h1 class="tagline">Create an account</h1>
  <form method="post" action="/register" class="itemform" id="reg-form">
    <input type="hidden" name="csrf" value="<?= Page::e($page->csrf()) ?>">
    <?php if ($error): ?><div class="err" role="alert" id="reg-err"><?= Page::e($error) ?></div><?php endif; ?>
    <label class="field"><span>User name <em>letters, digits, . _ @ -</em></span><input type="text" name="username" id="reg-user" value="<?= Page::e($username) ?>" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" maxlength="<?= $usernameMax ?>" required></label>
    <label class="field"><span>Password <em>at least <?= $passwordMin ?> characters</em></span><input type="password" name="new" id="reg-pw" autocomplete="new-password" minlength="<?= $passwordMin ?>" required></label>
    <label class="field"><span>Repeat password</span><input type="password" name="confirm" id="reg-confirm" autocomplete="new-password" minlength="<?= $passwordMin ?>" required></label>
    <p class="hint" id="reg-hint">New accounts get the role <b><?= Page::e($roleName($registerRole)) ?></b><?= $approval ? ' and can sign in once an admin has approved them' : '' ?>.</p>
    <button class="btn btn-primary btn-wide" id="reg-go">Create account</button>
  </form>
  <p class="login-alt"><a class="btn btn-wide" href="/login" id="reg-login">I have an account – sign in</a></p>
</main>
<?php
        return $head . ob_get_clean() . $page->end();
    }

    /**
     * @param array{id:?int,username:string,role:string} $me
     * @param array{roleName:string,perms:list<string>,permNames:array<string,string>,passwordMin:int} $v
     */
    public static function account(Page $page, array $me, array $v, ?string $error, ?string $toast): string
    {
        $head = $page->start('Your account', 'page-account') . $page->simpleHeader('Your account', '/');
        $tok = Page::e($page->csrf());
        ['perms' => $perms, 'permNames' => $permNames, 'passwordMin' => $passwordMin] = $v;
        $roleName = static fn (string $role): string => $v['roleName'];
        ob_start();
        ?>
<main class="wrap narrow">
  <section class="set-card" id="acct-card">
    <p class="pw-state"><?= Icons::svg('lock') ?> Signed in as <b id="acct-name"><?= Page::e($me['username']) ?></b> · <span class="role role-<?= Page::e($me['role']) ?>" id="acct-role"><?= Page::e($roleName($me['role'])) ?></span></p>
    <p class="hint" id="acct-perms">You may: <?= Page::e(implode(', ', array_map(fn($p) => mb_strtolower($permNames[$p]), $perms))) ?>.</p>
    <?php if ($error): ?><div class="err" role="alert" id="acct-err"><?= Page::e($error) ?></div><?php endif; ?>
    <form method="post" action="/account" class="setform" id="acct-pw">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <h2 class="set-h">Change your password</h2>
      <label class="field"><span>Current password</span><input type="password" name="current" id="acct-current" autocomplete="current-password" required></label>
      <label class="field"><span>New password <em>at least <?= $passwordMin ?> characters</em></span><input type="password" name="new" id="acct-new" autocomplete="new-password" minlength="<?= $passwordMin ?>" required></label>
      <label class="field"><span>Repeat new password</span><input type="password" name="confirm" id="acct-confirm" autocomplete="new-password" minlength="<?= $passwordMin ?>" required></label>
      <button class="btn btn-primary btn-wide" id="acct-save">Change password</button>
    </form>
    <form method="post" action="/account/signout" id="acct-signout">
      <input type="hidden" name="csrf" value="<?= $tok ?>">
      <h2 class="set-h">Sign out everywhere</h2>
      <p class="hint" id="acct-signout-hint">Ends this account's other sessions. This device stays signed in.</p>
      <button class="btn btn-wide" type="submit" id="acct-signout-go">Sign out everywhere</button>
    </form>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= $tok ?>"><button class="btn btn-wide" id="acct-logout"><?= Icons::svg('logout') ?> Sign out</button></form>
  </section>
</main>
<?php if ($toast): ?><div class="toast" role="status"><?= Page::e($toast) ?></div><?php endif; ?>
<?php
        return $head . ob_get_clean() . $page->end();
    }
}
