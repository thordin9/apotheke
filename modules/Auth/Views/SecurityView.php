<?php

declare(strict_types=1);

namespace Apotheke\Auth\Views;

use Apotheke\Auth\Permissions;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Control Panel → Users & sign-in (/settings/security): legacy
 * view_settings_section('security', …) with settings_security_html(),
 * settings_roles_html(), role_summary() and setting_field_html() for the
 * section's own settings (src/settings_views.php), kept line for line so the
 * output is byte-identical; every value is escaped with Page::e().
 */
final class SecurityView
{
    public const LABEL = 'Users & sign-in';

    public const DESCRIPTION = 'Accounts, roles, passwords; sign-in on or off';

    /**
     * @param array{
     *   on:bool, guestBrowsing:bool, me:array{id:?int,username:string,role:string,guest:bool},
     *   admins:int, users:list<array{id:int,username:string,role:string,status:string,last_login_at:?string}>,
     *   roles:array<string,array{key:string,name:string,builtin:bool,users:int,perms:list<string>}>,
     *   fields:list<array{key:string,label:string,type:string,default:mixed,help:string,options:array<string,mixed>,value:mixed}>,
     *   usernameMax:int, passwordMin:int, timeFormat:string, timezone:string
     * } $v
     * @param array<string,string> $errors
     */
    public static function page(Page $page, array $v, array $errors, ?string $toast): string
    {
        $head = $page->start(self::LABEL . ' · Settings', 'page-settings') . $page->simpleHeader(self::LABEL, '/settings');
        $tok = Page::e($page->csrf());
        $fields = $v['fields'];
        ob_start();
        ?>
<main class="wrap narrow set-security">
  <p class="hint"><?= Page::e(self::DESCRIPTION) ?></p>
  <?php if ($errors): ?><div class="err" role="alert" id="set-errors">Please check the highlighted <?= count($errors) === 1 ? 'field' : 'fields' ?>.<?= isset($errors['_']) ? ' ' . Page::e($errors['_']) : '' ?></div><?php endif; ?>
  <?php /* notes: none on this section */ ?>

  <?php /* appearance extra: not this section */ ?>
  <?= self::security($v, $tok, $errors) ?>
  <?php /* data: not this section */ ?>
  <?php /* system: not this section */ ?>

  <?php if ($fields): ?>
  <form method="post" action="/settings/security" class="setform" id="setform" novalidate>
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <?php foreach ($fields as $f): ?>
      <?= self::field($f, $v['roles'], $errors[$f['key']] ?? null) ?>
      <?php /* accent preview: not this section */ ?>
    <?php endforeach; ?>
    <?php /* item defaults: not this section */ ?>
    <div class="set-actions"><button class="btn btn-primary btn-wide" id="set-save"><?= Icons::svg('check') ?> Save</button></div>
    <?php /* autofill test: not this section */ ?>
  </form>
  <?php /* autofill token: not this section */ ?>
  <form method="post" action="/settings/security/reset" class="set-reset" data-confirm="Reset “<?= Page::e(self::LABEL) ?>” to the defaults from config.php?">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <button class="btn btn-wide" id="set-reset"><?= Icons::svg('undo') ?> Reset this section to defaults</button>
  </form>
  <?php endif; ?>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= Page::e($toast) ?></div><?php endif; ?>
<?php
        return $head . ob_get_clean() . $page->end();
    }

    /**
     * Legacy role_summary(): "view, add items, edit …".
     *
     * @param array<string,array{perms:list<string>}> $roles
     */
    public static function roleSummary(string $key, array $roles): string
    {
        if ($key === 'admin') {
            return 'everything';
        }
        $p = $roles[$key]['perms'] ?? [];

        return $p ? implode(', ', array_map(static fn (string $x): string => mb_strtolower(Permissions::ALL[$x][0]), $p)) : 'nothing';
    }

    /**
     * Legacy settings_security_html().
     *
     * @param array<string,mixed> $v see page()
     * @param array<string,string> $errors
     */
    private static function security(array $v, string $tok, array $errors): string
    {
        $h = static fn (?string $s): string => Page::e($s);
        $on = $v['on'];
        $me = $v['me'];
        $roles = $v['roles'];
        $min = $v['passwordMin'];
        $max = $v['usernameMax'];
        $roleName = static fn (string $key): string => $roles[$key]['name'] ?? ucfirst($key);
        $accountRoles = array_filter($roles, static fn (array $r): bool => $r['key'] !== 'guest');
        $err = static fn (string $k, string $id): string => isset($errors[$k]) ? '<div class="err" role="alert" id="' . $id . '">' . $h($errors[$k]) . '</div>' : '';
        $pw = static fn (string $id, string $label = 'Password'): string => '<label class="field"><span>' . $label . ' <em>at least ' . $min . ' characters</em></span><input type="password" name="new" id="' . $id . '" autocomplete="new-password" minlength="' . $min . '" required></label>'
            . '<label class="field"><span>Repeat ' . strtolower($label) . '</span><input type="password" name="confirm" id="' . $id . '-confirm" autocomplete="new-password" minlength="' . $min . '" required></label>';
        $cur = '<label class="field"><span>Your current password</span><input type="password" name="current" id="pw-current" autocomplete="current-password" required></label>';
        $roleSel = static function (string $name, string $id, string $cur) use ($accountRoles, $roles, $h): string {
            $o = '<select name="' . $name . '" id="' . $id . '">';
            foreach ($accountRoles as $k => $r) {
                $o .= '<option value="' . $h($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . $h($r['name']) . ' – ' . $h(self::roleSummary($k, $roles)) . '</option>';
            }

            return $o . '</select>';
        };
        $o = '<section class="set-card" id="pw-card"><p class="pw-state" id="pw-state">' . Icons::svg('lock') . ' '
            . ($on ? 'Accounts are on: everybody signs in with their own user name and password' . ($v['guestBrowsing'] ? '; visitors who are not signed in can look around as guests.' : '.') : 'Accounts are off: everyone is an anonymous guest with admin rights (fine on a private home network).') . '</p>' . $err('password', 'pw-err');
        if ($on) {
            $o .= '<form method="post" action="/settings/password" class="setform" id="pw-change"><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="mode" value="change">'
                . '<h2 class="set-h">Change your password</h2><p class="hint">Signed in as <b id="me-name">' . $h($me['username']) . '</b> (' . $h($roleName($me['role'])) . ').</p>'
                . $cur . str_replace(['name="new" id="pw-new"', 'id="pw-new-confirm"'], ['name="new" id="pw-new"', 'id="pw-confirm"'], $pw('pw-new', 'New password')) . '<button class="btn btn-primary btn-wide" id="pw-save">Change password</button></form>'
                . '<form method="post" action="/settings/password" class="setform" id="pw-disable" data-confirm="Turn sign-in off? Anyone on the network can then use Apotheke with full rights. The accounts are kept."><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="mode" value="disable">'
                . '<h2 class="set-h">Turn sign-in off</h2>' . str_replace('id="pw-current"', 'id="pw-current-off"', $cur) . '<button class="btn btn-danger btn-wide" id="pw-off">Turn sign-in off</button></form>';
        } else {
            $first = $v['admins'] === 0;
            $o .= '<form method="post" action="/settings/password" class="setform" id="pw-enable"><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="mode" value="change">'
                . '<h2 class="set-h">Turn sign-in on</h2><p class="hint">' . ($first ? 'Create the first account – an admin. You are signed in with it right away.' : 'Accounts exist already: enter an admin’s user name and password to turn sign-in on again.') . '</p>'
                . '<label class="field"><span>User name</span><input type="text" name="username" id="pw-user" value="admin" autocomplete="username" autocapitalize="off" spellcheck="false" maxlength="' . $max . '" required></label>'
                . ($first ? str_replace('id="pw-new-confirm"', 'id="pw-confirm"', $pw('pw-new')) : '<label class="field"><span>Password</span><input type="password" name="new" id="pw-new" autocomplete="current-password" required></label>')
                . '<button class="btn btn-primary btn-wide" id="pw-save">Turn sign-in on</button></form>';
        }
        $o .= '</section>';
        $users = $v['users'];
        $o .= '<section class="set-card" id="users"><h2 class="set-h">Users <span class="muted-n">' . count($users) . '</span></h2>' . $err('users', 'users-err');
        if (!$users) {
            $o .= '<p class="hint">No accounts yet.</p>';
        }
        $o .= '<ul class="userlist" id="userlist">';
        foreach ($users as $u) {
            $self = !$me['guest'] && $me['id'] === $u['id'];
            $id = $u['id'];
            $o .= '<li class="user" id="user-' . $id . '"><details><summary><span class="user-ic">' . Icons::svg('lock') . '</span><span class="user-t"><b>' . $h($u['username']) . '</b>' . ($self ? ' <em>(you)</em>' : '')
                . '<span class="role role-' . $h($u['role']) . '">' . $h($roleName($u['role'])) . '</span>' . ($u['status'] === 'pending' ? '<span class="role role-pending" id="pending-' . $id . '">Waiting for approval</span>' : '') . '<small>' . ($u['last_login_at'] ? 'Last sign-in ' . $h(self::time($u['last_login_at'], $v['timeFormat'], $v['timezone'])) : 'Never signed in') . '</small></span></summary>'
                . '<div class="user-forms">'
                . ($u['status'] === 'pending' ? '<form method="post" action="/settings/users/' . $id . '/approve" class="user-f"><input type="hidden" name="csrf" value="' . $tok . '"><button class="btn btn-primary btn-wide" id="approve-' . $id . '">' . Icons::svg('check') . ' Approve account</button></form>' : '')
                . '<form method="post" action="/settings/users/' . $id . '/role" class="user-f"><input type="hidden" name="csrf" value="' . $tok . '"><label class="field"><span>Role</span>' . $roleSel('role', 'role-' . $id, $u['role']) . '</label><button class="btn" id="role-save-' . $id . '">Change role</button></form>'
                . '<form method="post" action="/settings/users/' . $id . '/rename" class="user-f"><input type="hidden" name="csrf" value="' . $tok . '"><label class="field"><span>User name</span><input type="text" name="username" id="uname-' . $id . '" value="' . $h($u['username']) . '" maxlength="' . $max . '" autocapitalize="off" spellcheck="false" required></label><button class="btn">Rename</button></form>'
                . '<form method="post" action="/settings/users/' . $id . '/password" class="user-f"><input type="hidden" name="csrf" value="' . $tok . '">' . $pw('upw-' . $id, 'New password') . '<button class="btn" id="upw-save-' . $id . '">Set password</button></form>'
                . (!$self ? '<form method="post" action="/settings/users/' . $id . '/delete" data-confirm="Delete the account “' . $h($u['username']) . '”? Their history entries keep the name."><input type="hidden" name="csrf" value="' . $tok . '"><button class="btn btn-danger btn-wide" id="udel-' . $id . '">' . Icons::svg('trash') . ' Delete account</button></form>' : '')
                . '</div></details></li>';
        }
        $o .= '</ul><form method="post" action="/settings/users" class="setform" id="user-add"><input type="hidden" name="csrf" value="' . $tok . '"><h2 class="set-h">Add a user</h2>'
            . '<label class="field"><span>User name</span><input type="text" name="username" id="nu-name" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="' . $max . '" required></label>'
            . '<label class="field"><span>Role</span>' . $roleSel('role', 'nu-role', 'editor') . '</label>' . $pw('nu-pw')
            . '<button class="btn btn-primary btn-wide" id="nu-save">' . Icons::svg('plus') . ' Add user</button></form>'
            . ($on ? '' : '<p class="hint">Accounts only count while sign-in is on.</p>') . '</section>';
        $o .= self::roles($roles, $tok, $errors);

        return $o . '<p class="hint">Locked out? On the server run <code>php bin/settings.php user password admin</code> to set a new password, <code>php bin/settings.php user role NAME admin</code> to make someone an admin, <code>php bin/settings.php roles reset</code> to restore the built-in roles, or <code>php bin/settings.php auth off</code> to turn accounts off.</p>';
    }

    /**
     * Legacy settings_roles_html(): one card per role with a permission
     * checklist; admin read-only.
     *
     * @param array<string,array{key:string,name:string,builtin:bool,users:int,perms:list<string>}> $roles
     * @param array<string,string> $errors
     */
    private static function roles(array $roles, string $tok, array $errors): string
    {
        $h = static fn (?string $s): string => Page::e($s);
        $o = '<section class="set-card" id="roles"><h2 class="set-h">Roles &amp; permissions <span class="muted-n">' . count($roles) . '</span></h2>'
            . (isset($errors['roles']) ? '<div class="err" role="alert" id="roles-err">' . $h($errors['roles']) . '</div>' : '')
            . '<p class="hint">A role is a set of permissions. Admin always has every permission. Guest is what visitors who are not signed in may do when guest browsing is on.</p><ul class="rolelist" id="rolelist">';
        $checks = static function (string $key, array $have, bool $locked) use ($h): string {
            $c = '<fieldset class="perm-grid"><legend class="vh">Permissions</legend>';
            foreach (Permissions::ALL as $p => [$label, $hint]) {
                $dis = $locked || ($key === 'guest' && in_array($p, ['users', 'settings', 'backup'], true));
                $c .= '<label class="perm"><input type="checkbox" name="perms[]" value="' . $p . '" id="perm-' . $h($key) . '-' . $p . '"' . (in_array($p, $have, true) ? ' checked' : '') . ($dis ? ' disabled' : '') . '>'
                    . '<span><b>' . $h($label) . '</b><small>' . $h($hint) . '</small></span></label>';
            }

            return $c . '</fieldset>';
        };
        foreach ($roles as $k => $r) {
            $ek = $h($k);
            $o .= '<li class="rolecard" id="rc-' . $ek . '"><details><summary><span class="user-t"><b>' . $h($r['name']) . '</b>' . ($r['builtin'] ? ' <em>built-in</em>' : '')
                . '<small>' . ($k === 'guest' ? 'visitors' : $r['users'] . ' ' . ($r['users'] === 1 ? 'account' : 'accounts')) . ' · ' . $h(self::roleSummary($k, $roles)) . '</small></span></summary>';
            if ($k === 'admin') {
                $o .= '<div class="user-forms">' . $checks($k, $r['perms'], true) . '<p class="hint">The admin role cannot be changed or deleted.</p></div></details></li>';
                continue;
            }
            $o .= '<div class="user-forms"><form method="post" action="/settings/roles/' . $ek . '" class="user-f" id="role-form-' . $ek . '"><input type="hidden" name="csrf" value="' . $tok . '">'
                . (!$r['builtin'] ? '<label class="field"><span>Name</span><input type="text" name="name" id="role-name-' . $ek . '" value="' . $h($r['name']) . '" maxlength="60" required></label>' : '')
                . $checks($k, $r['perms'], false) . '<button class="btn btn-primary btn-wide" id="role-save-perm-' . $ek . '">Save ' . $h($r['name']) . '</button></form>';
            if (!$r['builtin']) {
                $sel = '';
                foreach ($roles as $mk => $mr) {
                    if ($mk !== 'guest' && $mk !== $k) {
                        $sel .= '<option value="' . $h($mk) . '"' . ($mk === 'viewer' ? ' selected' : '') . '>' . $h($mr['name']) . '</option>';
                    }
                }
                $o .= '<form method="post" action="/settings/roles/' . $ek . '/delete" class="user-f" data-confirm="Delete the role “' . $h($r['name']) . '”?"><input type="hidden" name="csrf" value="' . $tok . '">'
                    . '<label class="field"><span>Move its accounts to</span><select name="move_to" id="role-move-' . $ek . '">' . $sel . '</select></label>'
                    . '<button class="btn btn-danger btn-wide" id="role-del-' . $ek . '">' . Icons::svg('trash') . ' Delete role</button></form>';
            }
            $o .= '</div></details></li>';
        }

        return $o . '</ul><form method="post" action="/settings/roles" class="setform" id="role-add"><input type="hidden" name="csrf" value="' . $tok . '"><h2 class="set-h">Add a role</h2>'
            . '<label class="field"><span>Name</span><input type="text" name="name" id="nr-name" maxlength="60" required></label>'
            . $checks('new', ['view'], false) . '<button class="btn btn-primary btn-wide" id="nr-save">' . Icons::svg('plus') . ' Add role</button></form></section>';
    }

    /**
     * Legacy setting_field_html() for the types this section uses (bool, role, int).
     *
     * @param array{key:string,label:string,type:string,default:mixed,help:string,options:array<string,mixed>,value:mixed} $f
     * @param array<string,array{key:string,name:string,builtin:bool,users:int,perms:list<string>}> $roles
     */
    private static function field(array $f, array $roles, ?string $error): string
    {
        $h = static fn (?string $s): string => Page::e($s);
        ['key' => $key, 'label' => $label, 'type' => $type, 'default' => $def, 'help' => $help, 'options' => $o, 'value' => $value] = $f;
        $id = 's-' . $key;
        $err = $error !== null ? '<p class="field-err" id="' . $id . '-err">' . $h($error) . '</p>' : '';
        $desc = ($help !== '' ? '<small class="field-help">' . $h($help) . '</small>' : '');
        $defText = match (true) {
            $type === 'bool' => $def ? 'on' : 'off',
            $def === null || $def === '' => 'empty',
            default => is_scalar($def) ? (string)$def : '',
        };
        $desc .= '<small class="field-def">Default: ' . $h($defText) . '</small>';
        $aria = ' aria-describedby="' . $id . '-d"' . ($error !== null ? ' aria-invalid="true"' : '');
        $wrapD = '<div class="field-desc" id="' . $id . '-d">' . $desc . '</div>';
        $cls = 'field set-field' . ($error !== null ? ' has-err' : '');
        $str = static fn (mixed $x): string => is_array($x) ? 'Array' : (is_scalar($x) ? (string)$x : '');   // legacy (string) of a re-submitted value
        switch ($type) {
            case 'bool':
                return '<div class="' . $cls . ' set-bool"><label class="switch" for="' . $id . '"><input type="hidden" name="' . $key . '" value="0">'
                    . '<input type="checkbox" role="switch" name="' . $key . '" id="' . $id . '" value="1"' . ($value ? ' checked' : '') . $aria . '>'
                    . '<span class="switch-ui" aria-hidden="true"></span><span class="switch-label">' . $h($label) . '</span></label>' . $wrapD . $err . '</div>';
            case 'role':
                $sel = '';
                foreach ($roles as $k => $r) {
                    if ($k !== 'admin' && $k !== 'guest') {
                        $sel .= '<option value="' . $h($k) . '"' . ($k === $value ? ' selected' : '') . '>' . $h($r['name']) . '</option>';
                    }
                }

                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><select name="' . $key . '" id="' . $id . '"' . $aria . '>' . $sel . '</select>' . $wrapD . $err . '</div>';
            case 'int':
                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><input type="number" name="' . $key . '" id="' . $id . '" value="' . $h($value === null ? '' : $str($value))
                    . '" min="' . $str($o['min'] ?? '') . '" max="' . $str($o['max'] ?? '') . '" step="1" inputmode="numeric"' . (!empty($o['nullable']) ? ' placeholder="empty"' : ' required') . $aria . '>' . $wrapD . $err . '</div>';
            default:
                return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . $h($label) . '</label><input type="text" name="' . $key . '" id="' . $id . '" value="' . $h($str($value)) . '" maxlength="' . (int)($o['max'] ?? 200) . '"' . (!empty($o['required']) ? ' required' : '') . ' autocomplete="off"' . $aria . '>' . $wrapD . $err . '</div>';
        }
    }

    /** Legacy fmt_time(): a stored UTC time in the app's zone. */
    private static function time(string $utc, string $timeFormat, string $timezone): string
    {
        try {
            try {
                $tz = new DateTimeZone($timezone);
            } catch (Throwable) {
                $tz = new DateTimeZone(date_default_timezone_get());
            }

            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('j M Y, ' . $timeFormat);
        } catch (Throwable) {
            return $utc;
        }
    }
}
