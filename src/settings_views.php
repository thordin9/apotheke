<?php
declare(strict_types=1);

/* Apotheke – Control Panel views (/settings). */

function settings_header(string $title, string $back): string
{
    return '<header class="top top-simple"><div class="bar">'
        . '<a class="btn btn-ghost btn-round" href="' . h($back) . '" aria-label="Back">' . icon('back') . '</a>'
        . '<h1 class="title-sm">' . h($title) . '</h1>' . menu_html() . '</div></header>';
}

function view_settings_home(?string $toast): void
{
    page_start('Settings', 'page-settings');
    echo settings_header('Settings', '/');
    ?>
<main class="wrap narrow">
  <p class="hint">Everything here is stored in the database and applies right away. Database and storage paths come from
    <code>config.php</code> or environment variables and are shown read-only under <a href="/settings/system">System</a>.</p>
  <nav class="setlist" id="setlist" aria-label="Settings sections">
    <?php foreach (SETTINGS_SECTIONS as $k => [$label, $desc, $ic]): ?>
    <a class="setlink" href="/settings/<?= $k ?>" id="sl-<?= $k ?>"><?= icon($ic) ?><span><b><?= h($label) ?></b><small><?= h($desc) ?></small></span></a>
    <?php endforeach; ?>
  </nav>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

/** One form control for a schema setting. */
function setting_field_html(string $key, mixed $value, ?string $error): string
{
    [, $label, $type, , $help, $o] = settings_schema()[$key];
    $id = 's-' . $key;
    $err = $error !== null ? '<p class="field-err" id="' . $id . '-err">' . h($error) . '</p>' : '';
    $desc = ($help !== '' ? '<small class="field-help">' . h($help) . '</small>' : '');
    $def = setting_default($key);
    $defText = match (true) {
        $type === 'bool' => $def ? 'on' : 'off',
        $type === 'enum' => (string)($o['options'][$def] ?? $def),
        $def === null || $def === '' => 'empty',
        default => (string)$def,
    };
    $desc .= '<small class="field-def">Default: ' . h($defText) . '</small>';
    $aria = ' aria-describedby="' . $id . '-d"' . ($error !== null ? ' aria-invalid="true"' : '');
    $wrapD = '<div class="field-desc" id="' . $id . '-d">' . $desc . '</div>';
    $cls = 'field set-field' . ($error !== null ? ' has-err' : '');
    switch ($type) {
        case 'bool':
            return '<div class="' . $cls . ' set-bool"><label class="switch" for="' . $id . '"><input type="hidden" name="' . $key . '" value="0">'
                . '<input type="checkbox" role="switch" name="' . $key . '" id="' . $id . '" value="1"' . ($value ? ' checked' : '') . $aria . '>'
                . '<span class="switch-ui" aria-hidden="true"></span><span class="switch-label">' . h($label) . '</span></label>' . $wrapD . $err . '</div>';
        case 'enum':
        case 'timezone':
            $opts = $type === 'enum' ? $o['options'] : array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
            $sel = '';
            foreach ($opts as $k => $l) $sel .= '<option value="' . h((string)$k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . h((string)$l) . '</option>';
            return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . h($label) . '</label><select name="' . $key . '" id="' . $id . '"' . $aria . '>' . $sel . '</select>' . $wrapD . $err . '</div>';
        case 'int':
            return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . h($label) . '</label><input type="number" name="' . $key . '" id="' . $id . '" value="' . h($value === null ? '' : (string)$value)
                . '" min="' . $o['min'] . '" max="' . $o['max'] . '" step="1" inputmode="numeric"' . (!empty($o['nullable']) ? ' placeholder="empty"' : ' required') . $aria . '>' . $wrapD . $err . '</div>';
        case 'color':
            return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . h($label) . '</label><div class="colorrow"><input type="color" id="' . $id . '-pick" value="' . h(strtolower((string)$value)) . '" aria-label="' . h($label) . ' picker" data-color-for="#' . $id . '">'
                . '<input type="text" name="' . $key . '" id="' . $id . '" value="' . h((string)$value) . '" maxlength="7" pattern="#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?" autocomplete="off" spellcheck="false"' . $aria . '></div>' . $wrapD . $err . '</div>';
        case 'url':
            return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . h($label) . '</label><input type="url" name="' . $key . '" id="' . $id . '" value="' . h((string)$value) . '" maxlength="200" placeholder="' . h(public_url('')) . '" autocomplete="off"' . $aria . '>' . $wrapD . $err . '</div>';
        default:
            return '<div class="' . $cls . '"><label class="field-l" for="' . $id . '">' . h($label) . '</label><input type="text" name="' . $key . '" id="' . $id . '" value="' . h((string)$value) . '" maxlength="' . (int)($o['max'] ?? 200) . '"' . (!empty($o['required']) ? ' required' : '') . ' autocomplete="off"' . $aria . '>' . $wrapD . $err . '</div>';
    }
}

/**
 * A section page. $values: current (or re-submitted) values, $errors by key, $notes: extra messages (warnings).
 */
function view_settings_section(string $section, array $values, array $errors, ?string $toast, array $notes = []): void
{
    [$label, $desc] = SETTINGS_SECTIONS[$section];
    page_start($label . ' · Settings', 'page-settings');
    echo settings_header($label, '/settings');
    $tok = h(csrf_token());
    $keys = settings_section_keys($section);
    ?>
<main class="wrap narrow set-<?= $section ?>">
  <p class="hint"><?= h($desc) ?></p>
  <?php if ($errors): ?><div class="err" role="alert" id="set-errors">Please check the highlighted <?= count($errors) === 1 ? 'field' : 'fields' ?>.<?= isset($errors['_']) ? ' ' . h($errors['_']) : '' ?></div><?php endif; ?>
  <?php foreach ($notes as $n): ?><p class="warnbox" role="status"><?= icon('alert') ?> <span><?= h($n) ?></span></p><?php endforeach; ?>

  <?php if ($section === 'appearance') echo settings_appearance_extra($values, $tok); ?>
  <?php if ($section === 'security') echo settings_security_html($tok, $errors); ?>
  <?php if ($section === 'data') echo settings_data_html($tok); ?>
  <?php if ($section === 'system') echo settings_system_html(); ?>

  <?php if ($keys): ?>
  <form method="post" action="/settings/<?= $section ?>" class="setform" id="setform" novalidate>
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <?php foreach ($keys as $k): ?>
      <?= setting_field_html($k, $values[$k] ?? setting($k), $errors[$k] ?? null) ?>
      <?php if ($k === 'accent') echo accent_preview_html((string)($values[$k] ?? setting($k))); ?>
    <?php endforeach; ?>
    <?php if ($section === 'items') echo settings_items_defaults_html(); ?>
    <div class="set-actions"><button class="btn btn-primary btn-wide" id="set-save"><?= icon('check') ?> Save</button></div>
  </form>
  <form method="post" action="/settings/<?= $section ?>/reset" class="set-reset" data-confirm="Reset “<?= h($label) ?>” to the defaults from config.php?">
    <input type="hidden" name="csrf" value="<?= $tok ?>">
    <button class="btn btn-wide" id="set-reset"><?= icon('undo') ?> Reset this section to defaults</button>
  </form>
  <?php endif; ?>
</main>
<?php if ($toast !== null): ?><div class="toast" role="status"><?= h($toast) ?></div><?php endif; ?>
<?php
    page_end();
}

/** Default location / category (stored on the lists themselves, ★ on the Locations page). */
function settings_items_defaults_html(): string
{
    $o = '';
    foreach (KINDS as $kind) {
        $t = tax($kind);
        $cur = tax_default_id($kind);
        $opts = '<option value="">None</option>';
        foreach ($kind === 'location' ? locations() : categories() as $r) {
            $opts .= '<option value="' . (int)$r['id'] . '"' . ((int)$r['id'] === $cur ? ' selected' : '') . '>' . h($kind === 'location' ? location_path((int)$r['id']) : (string)$r['name']) . '</option>';
        }
        $o .= '<div class="field set-field"><label class="field-l" for="s-default_' . $kind . '">' . h($t['One']) . '</label><select name="default_' . $kind . '" id="s-default_' . $kind . '">' . $opts . '</select>'
            . '<div class="field-desc"><small class="field-help">Pre-selected for new items (same as ★ on the ' . h($t['Many']) . ' page).</small></div></div>';
    }
    return $o;
}

function accent_preview_html(string $hex): string
{
    $v = accent_variants($hex);
    $presets = '';
    $i = 0;
    foreach (ACCENT_PRESETS as $c => $name) {
        $presets .= '<button type="button" class="preset sw-' . $i++ . (strcasecmp($c, $hex) === 0 ? ' on' : '') . '" data-accent="' . h($c) . '" aria-label="' . h($name) . '" title="' . h($name) . '"></button>';
    }
    $pane = fn(string $m, string $title) => '<div class="pv pv-' . $m . '" id="pv-' . $m . '"><b class="pv-title">' . $title . '</b>'
        . '<span class="pv-btn">Save</span> <span class="pv-link">Link text</span> <span class="pv-chip">Chip</span>'
        . '<span class="pv-ratio" data-mode="' . $m . '">' . number_format($v[$m]['contrast'], 2) . ':1 ' . ($v[$m]['contrast'] >= 4.5 ? '✓ AA' : '✗') . '</span></div>';
    $w = '';
    foreach (accent_warnings($hex) as $x) $w .= '<li>' . h($x) . '</li>';
    return '<div class="presets" role="group" aria-label="Preset colours">' . $presets . '</div>'
        . '<div class="accent-preview" id="accent-preview" aria-live="polite">' . $pane('light', 'Light') . $pane('dark', 'Dark') . '</div>'
        . '<ul class="accent-warn" id="accent-warn"' . ($w === '' ? ' hidden' : '') . '>' . $w . '</ul>';
}

function settings_appearance_extra(array $values, string $tok): string
{
    $custom = (string)setting('logo');
    $o = '<section class="set-card" id="logo-card"><h2 class="set-h">Logo</h2>'
        . '<div class="logo-now"><img src="' . h(brand_logo_url()) . '" width="64" height="64" alt="Current logo" id="logo-current"><span>'
        . ($custom === '' ? 'The default Apotheke logo.' : ($custom === 'svg' ? 'Your SVG logo (also used as the favicon).' : 'Your logo; favicon and app icons were generated from it.')) . '</span></div>'
        . '<form method="post" action="/settings/logo" enctype="multipart/form-data" class="logo-form" id="logo-form">'
        . '<input type="hidden" name="csrf" value="' . $tok . '">'
        . '<label class="field"><span>Upload a logo <em>SVG, PNG, JPEG or WebP · max 2 MB · square works best</em></span>'
        . '<input type="file" name="logo" id="logo-file" accept=".svg,image/svg+xml,image/png,image/jpeg,image/webp" required></label>'
        . '<button class="btn btn-primary btn-wide" id="logo-upload">' . icon('image') . ' Upload logo</button></form>';
    if ($custom !== '') {
        $o .= '<form method="post" action="/settings/logo/reset" data-confirm="Go back to the default Apotheke logo?"><input type="hidden" name="csrf" value="' . $tok . '">'
            . '<button class="btn btn-wide" id="logo-reset">' . icon('undo') . ' Use the default Apotheke logo</button></form>';
    }
    return $o . '<p class="hint">SVG files are cleaned (scripts, links and external references are removed). For PNG, JPEG and WebP the favicon, the iPhone home-screen icon and the app icons are generated automatically; an SVG logo is used for the header and the favicon while the PNG app icons stay the default ones.</p></section>';
}

function settings_security_html(string $tok, array $errors): string
{
    $on = auth_enabled();
    $me = current_user();
    $err = fn(string $k, string $id) => isset($errors[$k]) ? '<div class="err" role="alert" id="' . $id . '">' . h($errors[$k]) . '</div>' : '';
    $pw = fn(string $id, string $label = 'Password') => '<label class="field"><span>' . $label . ' <em>at least ' . PASSWORD_MIN . ' characters</em></span><input type="password" name="new" id="' . $id . '" autocomplete="new-password" minlength="' . PASSWORD_MIN . '" required></label>'
        . '<label class="field"><span>Repeat ' . strtolower($label) . '</span><input type="password" name="confirm" id="' . $id . '-confirm" autocomplete="new-password" minlength="' . PASSWORD_MIN . '" required></label>';
    $cur = '<label class="field"><span>Your current password</span><input type="password" name="current" id="pw-current" autocomplete="current-password" required></label>';
    $roleSel = function (string $name, string $id, string $cur) {
        $o = '<select name="' . $name . '" id="' . $id . '">';
        foreach (USER_ROLES as $k => $l) $o .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . $l . ' – ' . USER_ROLE_HINTS[$k] . '</option>';
        return $o . '</select>';
    };
    $o = '<section class="set-card" id="pw-card"><p class="pw-state" id="pw-state">' . icon('lock') . ' '
        . ($on ? 'Sign-in is on: everybody signs in with their own user name and password.' : 'Sign-in is off: anyone who can reach this address can use Apotheke with full rights (fine on a private home network).') . '</p>' . $err('password', 'pw-err');
    $admins = users_count('admin');
    if ($on) {
        $o .= '<form method="post" action="/settings/password" class="setform" id="pw-change"><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="mode" value="change">'
            . '<h2 class="set-h">Change your password</h2><p class="hint">Signed in as <b id="me-name">' . h($me['username']) . '</b> (' . h(USER_ROLES[$me['role']]) . ').</p>'
            . $cur . str_replace(['name="new" id="pw-new"', 'id="pw-new-confirm"'], ['name="new" id="pw-new"', 'id="pw-confirm"'], $pw('pw-new', 'New password')) . '<button class="btn btn-primary btn-wide" id="pw-save">Change password</button></form>'
            . '<form method="post" action="/settings/password" class="setform" id="pw-disable" data-confirm="Turn sign-in off? Anyone on the network can then use Apotheke with full rights. The accounts are kept."><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="mode" value="disable">'
            . '<h2 class="set-h">Turn sign-in off</h2>' . str_replace('id="pw-current"', 'id="pw-current-off"', $cur) . '<button class="btn btn-danger btn-wide" id="pw-off">Turn sign-in off</button></form>';
    } else {
        $first = $admins === 0;
        $o .= '<form method="post" action="/settings/password" class="setform" id="pw-enable"><input type="hidden" name="csrf" value="' . $tok . '"><input type="hidden" name="mode" value="change">'
            . '<h2 class="set-h">Turn sign-in on</h2><p class="hint">' . ($first ? 'Create the first account – an admin. You are signed in with it right away.' : 'Accounts exist already: enter an admin’s user name and password to turn sign-in on again.') . '</p>'
            . '<label class="field"><span>User name</span><input type="text" name="username" id="pw-user" value="admin" autocomplete="username" autocapitalize="off" spellcheck="false" maxlength="' . USERNAME_MAX . '" required></label>'
            . ($first ? str_replace('id="pw-new-confirm"', 'id="pw-confirm"', $pw('pw-new')) : '<label class="field"><span>Password</span><input type="password" name="new" id="pw-new" autocomplete="current-password" required></label>')
            . '<button class="btn btn-primary btn-wide" id="pw-save">Turn sign-in on</button></form>';
    }
    $o .= '</section>';
    // accounts
    $users = users_list();
    $o .= '<section class="set-card" id="users"><h2 class="set-h">Users <span class="muted-n">' . count($users) . '</span></h2>' . $err('users', 'users-err');
    if (!$users) $o .= '<p class="hint">No accounts yet.</p>';
    $o .= '<ul class="userlist" id="userlist">';
    foreach ($users as $u) {
        $self = !$me['guest'] && $me['id'] === $u['id'];
        $id = $u['id'];
        $o .= '<li class="user" id="user-' . $id . '"><details><summary><span class="user-ic">' . icon('lock') . '</span><span class="user-t"><b>' . h($u['username']) . '</b>' . ($self ? ' <em>(you)</em>' : '')
            . '<span class="role role-' . $u['role'] . '">' . h(USER_ROLES[$u['role']]) . '</span><small>' . ($u['last_login_at'] ? 'Last sign-in ' . h(fmt_time($u['last_login_at'])) : 'Never signed in') . '</small></span></summary>'
            . '<div class="user-forms">'
            . '<form method="post" action="/settings/users/' . $id . '/role" class="user-f"><input type="hidden" name="csrf" value="' . $tok . '"><label class="field"><span>Role</span>' . $roleSel('role', 'role-' . $id, $u['role']) . '</label><button class="btn" id="role-save-' . $id . '">Change role</button></form>'
            . '<form method="post" action="/settings/users/' . $id . '/rename" class="user-f"><input type="hidden" name="csrf" value="' . $tok . '"><label class="field"><span>User name</span><input type="text" name="username" id="uname-' . $id . '" value="' . h($u['username']) . '" maxlength="' . USERNAME_MAX . '" autocapitalize="off" spellcheck="false" required></label><button class="btn">Rename</button></form>'
            . '<form method="post" action="/settings/users/' . $id . '/password" class="user-f"><input type="hidden" name="csrf" value="' . $tok . '">' . $pw('upw-' . $id, 'New password') . '<button class="btn" id="upw-save-' . $id . '">Set password</button></form>'
            . (!$self ? '<form method="post" action="/settings/users/' . $id . '/delete" data-confirm="Delete the account “' . h($u['username']) . '”? Their history entries keep the name."><input type="hidden" name="csrf" value="' . $tok . '"><button class="btn btn-danger btn-wide" id="udel-' . $id . '">' . icon('trash') . ' Delete account</button></form>' : '')
            . '</div></details></li>';
    }
    $o .= '</ul><form method="post" action="/settings/users" class="setform" id="user-add"><input type="hidden" name="csrf" value="' . $tok . '"><h2 class="set-h">Add a user</h2>'
        . '<label class="field"><span>User name</span><input type="text" name="username" id="nu-name" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="' . USERNAME_MAX . '" required></label>'
        . '<label class="field"><span>Role</span>' . $roleSel('role', 'nu-role', 'editor') . '</label>' . $pw('nu-pw')
        . '<button class="btn btn-primary btn-wide" id="nu-save">' . icon('plus') . ' Add user</button></form>'
        . ($on ? '' : '<p class="hint">Accounts only count while sign-in is on.</p>') . '</section>';
    return $o . '<p class="hint">Locked out? On the server run <code>php bin/settings.php user password admin</code> to set a new password, <code>php bin/settings.php users</code> to list the accounts, or <code>php bin/settings.php auth off</code> to turn sign-in off.</p>';
}

function settings_data_html(string $tok): string
{
    return '<section class="set-card"><h2 class="set-h">Your catalogue</h2><div class="set-links">'
        . '<a class="btn btn-wide" href="/backup" id="d-backup">' . icon('archive') . ' Backup &amp; restore</a>'
        . '<a class="btn btn-wide" href="/export.csv" id="d-csv">' . icon('download') . ' Export CSV</a></div></section>'
        . '<section class="set-card"><h2 class="set-h">Settings file</h2>'
        . '<p class="hint">Download every setting as JSON (the password and the logo are not included), or load such a file. Invalid values in the file are skipped and listed.</p>'
        . '<a class="btn btn-wide" href="/settings/export.json" id="d-export" download>' . icon('download') . ' Export settings (JSON)</a>'
        . '<form method="post" action="/settings/import" enctype="multipart/form-data" class="setform" id="import-form"><input type="hidden" name="csrf" value="' . $tok . '">'
        . '<label class="field"><span>Import settings</span><input type="file" name="file" id="import-file" accept="application/json,.json" required></label>'
        . '<button class="btn btn-primary btn-wide" id="import-go">' . icon('undo') . ' Import settings</button></form></section>';
}

function settings_system_html(): string
{
    $rows = '';
    foreach (settings_bootstrap_info() as [$l, $v, $src]) {
        $rows .= '<tr><th scope="row">' . h($l) . '</th><td><code>' . h((string)$v) . '</code><small>' . h($src) . '</small></td></tr>';
    }
    $applied = migrations_applied(db(), db_driver());
    return '<section class="set-card"><p class="hint">These values are needed before the database can be opened, so they cannot be stored in it. '
        . 'Change them in <code>config.php</code> or with environment variables (environment wins) and restart the app. '
        . 'See <code>docs/configuration.md</code>.</p>'
        . '<table class="systable" id="systable"><tbody>'
        . '<tr><th scope="row">Version</th><td><code id="app-version">' . h(app_version()) . '</code><small>VERSION · <a href="https://github.com/thordin9/apotheke/releases">release notes</a></small></td></tr>' . $rows
        . '<tr><th scope="row">Search engine</th><td><code id="search-engine">' . h(search_engine()) . '</code><small>' . h(search_engine_label()) . '</small></td></tr>'
        . '<tr><th scope="row">Database schema</th><td><code>' . h((string)array_key_last($applied)) . '</code><small>' . count($applied) . ' migrations applied</small></td></tr>'
        . '</tbody></table>'
        . '<p class="hint">All other options (everything in the other sections) were copied from <code>config.php</code> / the environment when this database was created or upgraded, and are edited here from then on.</p></section>';
}
