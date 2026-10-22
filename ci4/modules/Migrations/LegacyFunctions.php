<?php

declare(strict_types=1);

/**
 * Globals the 25 migration files and the runner call. Kept after cutover.
 * Settings still lives here until the Settings module is the single source.
 * Each function is defined only when the legacy app has not already defined it.
 */

if (!class_exists('UserError', false)) {
    class UserError extends RuntimeException
    {
    }
}

if (!defined('LEGACY_SCHEMA_VERSION')) {
    define('LEGACY_SCHEMA_VERSION', 3);
}
if (!defined('ACCENT_DEFAULT')) {
    define('ACCENT_DEFAULT', '#4f46e5');
}
if (!defined('AUTOFILL_SIDE_MIN')) {
    define('AUTOFILL_SIDE_MIN', 64);
}
if (!defined('SORTS')) {
    define('SORTS', ['recent' => 'Recently added', 'name' => 'Name', 'location' => 'Location', 'category' => 'Category', 'qty' => 'Quantity', 'value' => 'Value (highest first)']);
}
if (!defined('ATTACH_TYPE_SETS')) {
    define('ATTACH_TYPE_SETS', ['pdf_images' => 'PDF and images', 'pdf' => 'PDF only', 'images' => 'Images only']);
}
if (!defined('MONEY_FORMATS')) {
    define('MONEY_FORMATS', ['en' => '1,234.56', 'de' => '1.234,56', 'fr' => '1 234,56', 'ch' => '1’234.56', 'plain' => '1234.56']);
}
if (!defined('DATE_FORMATS')) {
    define('DATE_FORMATS', ['Y-m-d' => '2026-06-15', 'd.m.Y' => '15.06.2026', 'd/m/Y' => '15/06/2026', 'm/d/Y' => '06/15/2026', 'j M Y' => '15 Jun 2026']);
}
if (!defined('TIME_FORMATS')) {
    define('TIME_FORMATS', ['H:i' => '14:30', 'g:i A' => '2:30 PM']);
}
if (!defined('ROLE_DEFAULTS')) {
    define('ROLE_DEFAULTS', [
        'admin' => ['Admin', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels', 'settings', 'users', 'backup']],
        'editor' => ['Editor', ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels']],
        'viewer' => ['Viewer', ['view', 'export', 'labels']],
        'guest' => ['Guest', ['view']],
    ]);
}
if (!defined('SEED_LOCATIONS')) {
    define('SEED_LOCATIONS', ['Box 1', 'Living room', 'Kitchen', 'Garage', 'Basement', 'Attic']);
}
if (!defined('SEED_CATEGORIES')) {
    define('SEED_CATEGORIES', ['General', 'Tools', 'Electronics', 'Kitchen', 'Documents', 'Hobby', 'Clothing', 'Shoes', 'Books', 'Lighting']);
}
if (!defined('SEED_DEFAULT_LOCATION')) {
    define('SEED_DEFAULT_LOCATION', 'Box 1');
}
if (!defined('SEED_DEFAULT_CATEGORY')) {
    define('SEED_DEFAULT_CATEGORY', 'General');
}

function apotheke_migration_cfg(array $cfg): void
{
    $GLOBALS['apotheke_migration_cfg'] = $cfg;
}

if (!function_exists('cfg')) {
    function cfg(): array
    {
        $cfg = $GLOBALS['apotheke_migration_cfg'] ?? null;
        if (!is_array($cfg)) {
            throw new RuntimeException('Migration config is not set.');
        }

        return $cfg;
    }
}
if (!function_exists('app_time')) {
    function app_time(): int
    {
        static $fixed = null;
        if ($fixed === null) {
            $v = getenv('APOTHEKE_CLOCK');
            $fixed = is_string($v) && $v !== '' && ($t = strtotime($v . ' UTC')) !== false ? $t : 0;
        }

        return $fixed ?: time();
    }
}
if (!function_exists('now_utc')) {
    function now_utc(): string
    {
        return gmdate('Y-m-d H:i:s', app_time());
    }
}
if (!function_exists('fold')) {
    function fold(string $s): string
    {
        static $map = [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ς' => 'σ',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ];

        return strtr(mb_strtolower($s), $map);
    }
}
if (!function_exists('clean_line')) {
    function clean_line(string $s, int $max): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));

        return mb_substr($s, 0, $max);
    }
}
if (!function_exists('natkey')) {
    function natkey(string $s): string
    {
        return mb_substr((string)preg_replace_callback('/\d+/', static fn($m) => str_pad(ltrim($m[0], '0') ?: '0', 12, '0', STR_PAD_LEFT), fold(trim($s))), 0, 400);
    }
}
if (!function_exists('tags_parse')) {
    function tags_parse(string|array $in): array
    {
        $parts = is_array($in) ? $in : preg_split('/[,;\n]+/u', $in);
        $out = [];
        foreach ($parts ?: [] as $p) {
            $p = ltrim(clean_line((string)$p, 40), '#');
            if ($p === '') {
                continue;
            }
            $out[fold($p)] ??= $p;
        }
        if (count($out) > 20) {
            throw new UserError('An item can have at most 20 tags.');
        }

        return array_values($out);
    }
}
if (!function_exists('account_roles')) {
    function account_roles(): array
    {
        return ['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer', 'guest' => 'Guest'];
    }
}
if (!function_exists('db_insert')) {
    function db_insert(PDO $pdo, string $sql, array $args): int
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $st = $pdo->prepare($sql . ' RETURNING id');
            $st->execute($args);

            return (int)$st->fetchColumn();
        }
        $pdo->prepare($sql)->execute($args);

        return (int)$pdo->lastInsertId();
    }
}
if (!function_exists('settings_schema')) {
    function settings_schema(): array
    {
        static $s = null;
        if ($s !== null) {
            return $s;
        }
        $s = [
            'app_name' => ['general', 'App name', 'string', 'Apotheke', 'Shown in the header, the window title and on the home screen.', ['max' => 40, 'required' => true]],
            'app_tagline' => ['general', 'Tagline', 'string', 'the storehouse for your stuff', 'Shown on the sign-in page and in the page description.', ['max' => 80]],
            'public_url' => ['general', 'Public address', 'url', 'cfg:public_url', 'Base URL printed in QR labels, e.g. http://192.168.1.10:9091. Empty = the address you are using right now.', []],
            'accent' => ['appearance', 'Accent colour', 'color', ACCENT_DEFAULT, 'Buttons, links and highlights. Lighter/darker variants are derived so text stays readable (WCAG AA) in light and dark mode.', []],
            'theme_default' => ['appearance', 'Default theme', 'enum', 'auto', 'Used until someone picks Auto / Light / Dark in the menu on their device.', ['options' => ['auto' => 'System (auto)', 'light' => 'Light', 'dark' => 'Dark']]],
            'icon_background' => ['appearance', 'Icon background', 'color', '#FBF1E3', 'Behind an uploaded PNG/JPEG/WebP logo in the home-screen and maskable app icons.', []],
            'logo' => ['appearance', 'Logo', 'internal', '', '', []],
            'currency' => ['locale', 'Currency', 'string', 'cfg:currency', 'ISO code or symbol shown with prices (EUR, USD, £, CHF …).', ['max' => 8, 'required' => true]],
            'money_format' => ['locale', 'Number format', 'enum', 'en', 'How prices are written.', ['options' => MONEY_FORMATS]],
            'currency_position' => ['locale', 'Currency position', 'enum', 'after', '', ['options' => ['after' => 'After the amount (12.50 EUR)', 'before' => 'Before the amount (EUR 12.50)']]],
            'date_format' => ['locale', 'Date format', 'enum', 'Y-m-d', 'For purchase, expiry and lending dates.', ['options' => DATE_FORMATS]],
            'time_format' => ['locale', 'Time format', 'enum', 'H:i', 'For times in the history.', ['options' => TIME_FORMATS]],
            'timezone' => ['locale', 'Time zone', 'timezone', 'cfg:timezone', 'Decides what “today” is (overdue loans, expiry, statistics).', []],
            'page_size' => ['list', 'Items per page', 'int', 'cfg:page_size', 'More items load with “Show more”.', ['min' => 1, 'max' => 500]],
            'default_sort' => ['list', 'Default sort', 'enum', 'recent', 'Order of the list when no sort is chosen.', ['options' => SORTS]],
            'list_view' => ['list', 'View', 'enum', 'grid', 'Photo cards, or compact rows that fit more items on the screen.', ['options' => ['grid' => 'Cards with photos', 'compact' => 'Compact rows']]],
            'recent_limit' => ['list', 'Recently viewed', 'int', 8, 'How many recently opened items the list shows above the results. 0 = hide.', ['min' => 0, 'max' => 50]],
            'recent_scope' => ['list', 'Recently viewed is kept', 'enum', 'device', '', ['options' => ['device' => 'Per device (a cookie)', 'install' => 'Shared by everyone']]],
            'search_typos' => ['list', 'Typo tolerance in search', 'bool', true, 'A word that matches nothing is also looked up with one or two letters different (“screwdrivr” finds “Screwdriver set”).', []],
            'overview_open' => ['list', 'Show the overview open', 'bool', true, 'Whether the statistics overview starts expanded (each device remembers its own choice afterwards).', []],
            'default_quantity' => ['items', 'Quantity', 'int', 1, '', ['min' => 0, 'max' => 1000000]],
            'default_min_qty' => ['items', 'Running low at', 'int', null, 'Empty = no low-stock warning for new items.', ['min' => 0, 'max' => 1000000, 'nullable' => true]],
            'default_tags' => ['items', 'Tags', 'tags', '', 'Comma-separated, e.g. “home, new”.', ['max' => 500]],
            'default_notes' => ['items', 'Notes', 'string', '', 'Text pre-filled in the notes field.', ['max' => 500]],
            'default_purchase_today' => ['items', 'Purchase date = today', 'bool', false, 'Pre-fill the purchase date with today.', []],
            'default_expiry_months' => ['items', 'Expires after (months)', 'int', null, 'Pre-fill the expiry/warranty date this many months from today. Empty = no date.', ['min' => 1, 'max' => 600, 'nullable' => true]],
            'label_width_mm' => ['labels', 'Label width (mm)', 'int', 60, 'Printed width of one label.', ['min' => 15, 'max' => 200]],
            'label_height_mm' => ['labels', 'Label height (mm)', 'int', 70, 'Printed height of one label (the QR code shrinks to fit).', ['min' => 15, 'max' => 280]],
            'label_columns' => ['labels', 'Labels per row', 'int', 3, 'Columns on a printed page (A4 fits 3 × 60 mm).', ['min' => 1, 'max' => 10]],
            'label_gap_mm' => ['labels', 'Gap between labels (mm)', 'int', 4, '0 for sticker sheets without a gap.', ['min' => 0, 'max' => 30]],
            'label_show_sub' => ['labels', 'Print the location / item count', 'bool', true, 'The second line under the name.', []],
            'label_show_url' => ['labels', 'Print the link', 'bool', true, 'The address the QR code points to, in small print.', []],
            'label_border' => ['labels', 'Print cut lines', 'bool', true, 'A dashed border around each label.', []],
            'expiry_soon_days' => ['limits', '“Expiring soon” window (days)', 'int', 'cfg:expiry_soon_days', 'An item is expiring soon when its date is within this many days.', ['min' => 1, 'max' => 3650]],
            'lend_due_days' => ['limits', 'Default loan period (days)', 'int', null, 'Pre-fill “due back” when lending an item. Empty = no due date.', ['min' => 1, 'max' => 3650, 'nullable' => true]],
            'saved_filters_max' => ['limits', 'Saved filters limit', 'int', 50, 'Maximum number of saved filters.', ['min' => 1, 'max' => 500]],
            'undo_seconds' => ['limits', 'Undo window (seconds)', 'int', 30, 'How long “Undo” is offered after editing, moving or deleting. Deleted photos and attachments stay on disk this long. 0 = no undo.', ['min' => 0, 'max' => 3600]],
            'stocktake_apply_moves' => ['limits', 'Stocktake: move misplaced items', 'bool', true, 'When finishing a stocktake, items found in the checked location but recorded elsewhere are moved there (you can untick it each time).', []],
            'max_photos' => ['limits', 'Photos per item', 'int', 12, '', ['min' => 1, 'max' => 50]],
            'max_upload_mb' => ['uploads', 'Maximum upload (MB)', 'int', 'cfg:uploads.max_mb', 'Per photo. PHP’s upload_max_filesize / post_max_size must be at least this large.', ['min' => 1, 'max' => 200]],
            'image_full_max' => ['uploads', 'Photo size (px)', 'int', 'cfg:uploads.full_max', 'Longest side of stored photos. Applies to new uploads.', ['min' => 320, 'max' => 8000]],
            'image_thumb_max' => ['uploads', 'Thumbnail size (px)', 'int', 'cfg:uploads.thumb_max', 'Longest side of list thumbnails. Applies to new uploads.', ['min' => 64, 'max' => 2000]],
            'jpeg_quality' => ['uploads', 'JPEG quality', 'int', 82, '40–95; thumbnails use 6 less.', ['min' => 40, 'max' => 95]],
            'attach_types' => ['uploads', 'Attachment types', 'enum', 'pdf_images', 'Receipts, manuals and warranty cards attached to an item. The real file type is checked, not the name.', ['options' => ATTACH_TYPE_SETS]],
            'attach_max_mb' => ['uploads', 'Attachment size limit (MB)', 'int', 10, 'Per file. PHP’s upload_max_filesize / post_max_size must be at least this large.', ['min' => 1, 'max' => 200]],
            'attach_max_per_item' => ['uploads', 'Attachments per item', 'int', 10, '', ['min' => 1, 'max' => 100]],
            'autofill_enabled' => ['autofill', 'Suggest details from the photo', 'bool', false, 'Off: Apotheke never contacts the service. On: each photo on the item form gets an Analyze button; tap it and a progress line shows while the service looks at the photo, then empty fields are pre-filled with suggestions you can keep or clear.', []],
            'autofill_auto' => ['autofill', 'Analyze photos automatically', 'bool', false, 'Off (recommended): nothing is sent until someone taps Analyze on a photo. On: every photo picked on the item form is sent to the service right away, as in Apotheke 2.3.', []],
            'autofill_url' => ['autofill', 'Service address', 'url', '', 'Where your autofill service runs, e.g. http://192.168.1.10:8080 (without /v1). The photo is sent there, resized to about 1024 px.', ['placeholder' => 'http://192.168.1.10:8080']],
            'autofill_timeout' => ['autofill', 'Timeout (seconds)', 'int', 50, 'How long to wait for suggestions. Saving an item never waits for them. Set it about 5 s above the service’s own timeout: 50 for the default Jetson setup (the service gives up after 45 s). When the service says its model is still loading, Apotheke waits as long as it asks (up to this timeout) and tries once more.', ['min' => 3, 'max' => 60]],
            'autofill_model' => ['autofill', 'Model', 'af_model', '', 'Which of the service’s models looks at the photos. Switching models makes the next photo slower while the service loads it.', ['max' => 120]],
            'autofill_image_size' => ['autofill', 'Image size', 'af_size', 0, 'Long edge of the photo sent to the service. Smaller is faster; larger can read small print. Photos are never enlarged.', ['min' => 0, 'max' => 4096]],
            'autofill_token' => ['autofill', 'Access token', 'secret', '', '', []],
            'auth_enabled' => ['security', 'Require sign-in', 'internal', 'cfg_auth', '', []],
            'auth_guest_disabled' => ['security', 'Disable guest browsing', 'bool', true, 'On: visitors must sign in before they see anything. Off: they can look around with the permissions of the Guest role.', []],
            'auth_register_disabled' => ['security', 'Disable registrations', 'bool', true, 'Off: anyone who can reach this address can create an account at /register (limited to 5 per hour per address).', []],
            'auth_register_role' => ['security', 'Role for new registrations', 'role', 'viewer', 'Admin cannot be chosen.', []],
            'auth_register_approval' => ['security', 'Registrations need an admin’s approval', 'bool', false, 'New accounts can only sign in once an admin approves them under Users.', []],
            'session_days' => ['security', 'Stay signed in for (days)', 'int', 30, 'How long a sign-in lasts without being used. Shorter is safer on shared devices; applies from the next sign-in.', ['min' => 1, 'max' => 365]],
            'trust_proxy' => ['security', 'Trust the reverse proxy', 'bool', false, 'Turn on only when the app sits behind your own HTTPS proxy (nginx, Caddy, Traefik) that sets X-Forwarded-Proto. Then sign-in cookies are marked Secure. Leave off when the app is reachable directly.', []],
            'import_create_missing' => ['data', 'CSV import creates missing entries', 'bool', true, 'Pre-ticks “Create missing locations, categories and tags” on the import screen.', []],
            'password_hash' => ['security', 'Password (before accounts; moved to the user “admin” by migration 0018)', 'secret', 'cfg_hash', '', []],
        ];

        return $s;
    }
}
if (!function_exists('setting_default')) {
    function setting_default(string $key): mixed
    {
        $d = settings_schema()[$key][3] ?? null;
        if (is_string($d) && str_starts_with($d, 'cfg:')) {
            $v = cfg();
            foreach (explode('.', substr($d, 4)) as $k) {
                $v = $v[$k] ?? null;
            }
            try {
                return setting_validate($key, $v);
            } catch (UserError) {
                return null;
            }
        }
        if ($d === 'cfg_auth') {
            return cfg()['app_password'] !== '';
        }
        if ($d === 'cfg_hash') {
            return cfg()['app_password'] !== '' ? password_hash(cfg()['app_password'], PASSWORD_DEFAULT) : '';
        }

        return $d;
    }
}
if (!function_exists('setting_validate')) {
    function setting_validate(string $key, mixed $raw): mixed
    {
        $def = settings_schema()[$key] ?? null;
        if ($def === null) {
            throw new UserError("Unknown setting “{$key}”.");
        }
        [, $label, $type, , , $o] = $def;
        $str = is_scalar($raw) || $raw === null ? trim((string)$raw) : null;
        switch ($type) {
            case 'bool':
            case 'internal':
                if ($type === 'internal' && $key === 'logo') {
                    if (!in_array($raw, ['', 'svg', 'png'], true)) {
                        throw new UserError('Invalid logo.');
                    }

                    return $raw;
                }
                if (is_bool($raw)) {
                    return $raw;
                }
                $b = filter_var($str, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    throw new UserError("$label: yes or no.");
                }

                return $b;
            case 'secret':
                if (!is_string($raw)) {
                    throw new UserError("$label: invalid.");
                }

                return $raw;
            case 'int':
                if ($str === '' || $str === null) {
                    if (!empty($o['nullable'])) {
                        return null;
                    }
                    throw new UserError("$label: enter a number.");
                }
                if (!preg_match('/^-?\d{1,9}$/D', $str)) {
                    throw new UserError("$label: enter a whole number.");
                }
                $n = (int)$str;
                if ($n < $o['min'] || $n > $o['max']) {
                    throw new UserError("$label: between {$o['min']} and {$o['max']}.");
                }

                return $n;
            case 'af_model':
                if ($str === null) {
                    throw new UserError("$label: invalid.");
                }
                $str = clean_line($str, 1000);
                if (mb_strlen($str) > $o['max']) {
                    throw new UserError("$label: at most {$o['max']} characters.");
                }

                return $str;
            case 'af_size':
                if ($str === '' || $str === null || $str === '0') {
                    return 0;
                }
                if (!preg_match('/^\d{1,5}$/D', $str) || (int)$str < AUTOFILL_SIDE_MIN || (int)$str > $o['max']) {
                    throw new UserError("$label: between " . AUTOFILL_SIDE_MIN . " and {$o['max']} px.");
                }

                return (int)$str;
            case 'role':
                if ($str === null || !isset(account_roles()[$str]) || $str === 'admin') {
                    throw new UserError("$label: choose a role other than admin.");
                }

                return $str;
            case 'enum':
                if ($str === null || !array_key_exists($str, $o['options'])) {
                    throw new UserError("$label: choose one of the options.");
                }

                return $str;
            case 'color':
                if ($str === null || !preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/iD', $str, $m)) {
                    throw new UserError("$label: a colour like #C8502D.");
                }
                $h = $m[1];
                if (strlen($h) === 3) {
                    $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
                }

                return '#' . strtolower($h) === strtolower(ACCENT_DEFAULT) ? ACCENT_DEFAULT : '#' . strtoupper($h);
            case 'timezone':
                if ($str === null || !in_array($str, DateTimeZone::listIdentifiers(), true) && $str !== 'UTC') {
                    throw new UserError("$label: unknown time zone.");
                }

                return $str;
            case 'url':
                if ($str === null) {
                    throw new UserError("$label: invalid.");
                }
                $str = rtrim($str, '/');
                $str = (string)preg_replace_callback('#^(https?)://#i', fn($m) => strtolower($m[1]) . '://', $str);
                if ($str !== '' && (!preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+(/[^\s]*)?$#D', $str) || strlen($str) > 200)) {
                    throw new UserError("$label: a full address starting with http:// or https://.");
                }

                return $str;
            case 'tags':
                if ($str === null) {
                    throw new UserError("$label: invalid.");
                }
                if (mb_strlen($str) > $o['max']) {
                    throw new UserError("$label: at most {$o['max']} characters.");
                }

                return implode(', ', tags_parse($str));
            case 'string':
            default:
                if ($str === null) {
                    throw new UserError("$label: invalid.");
                }
                $str = clean_line($str, 1000);
                if (mb_strlen($str) > $o['max']) {
                    throw new UserError("$label: at most {$o['max']} characters.");
                }
                if (!empty($o['required']) && $str === '') {
                    throw new UserError("$label cannot be empty.");
                }

                return $str;
        }
    }
}
if (!function_exists('settings_cache')) {
    function settings_cache(?array $set = null, bool $clear = false): ?array
    {
        static $c = null;
        if ($clear) {
            $c = null;
        }
        if ($set !== null) {
            $c = $set;
        }

        return $c;
    }
}
if (!function_exists('settings_seed')) {
    function settings_seed(PDO $pdo): void
    {
        $have = $pdo->query('SELECT name FROM settings')->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)');
        foreach (settings_schema() as $k => $_) {
            if (in_array($k, $have, true)) {
                continue;
            }
            $ins->execute([$k, json_encode(setting_default($k), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc()]);
        }
        settings_cache(null, true);
    }
}
if (!function_exists('setting_get')) {
    function setting_get(PDO $pdo, string $name): ?string
    {
        $st = $pdo->prepare('SELECT value FROM app_settings WHERE name = ?');
        $st->execute([$name]);
        $v = $st->fetchColumn();

        return $v === false ? null : (string)$v;
    }
}
if (!function_exists('setting_set')) {
    function setting_set(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM app_settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO app_settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
    }
}
if (!function_exists('seed_defaults')) {
    function seed_defaults(PDO $pdo, bool $force = false): int
    {
        if (!$force && setting_get($pdo, 'seeded_defaults') !== null) {
            return 0;
        }
        $n = 0;
        foreach (['locations' => [SEED_LOCATIONS, SEED_DEFAULT_LOCATION], 'categories' => [SEED_CATEGORIES, SEED_DEFAULT_CATEGORY]] as $table => [$names, $default]) {
            $find = $pdo->prepare("SELECT id FROM $table WHERE name_key = ?");
            foreach ($names as $name) {
                $find->execute([fold($name)]);
                if ($find->fetchColumn() !== false) {
                    continue;
                }
                db_insert($pdo, "INSERT INTO $table (name, name_key, sort_key, created_at) VALUES (?,?,?,?)", [$name, fold($name), natkey($name), now_utc()]);
                $n++;
            }
            if (!(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE is_default = 1")->fetchColumn()) {
                $pdo->prepare("UPDATE $table SET is_default = 1 WHERE name_key = ?")->execute([fold($default)]);
            }
        }
        setting_set($pdo, 'seeded_defaults', now_utc());

        return $n;
    }
}
if (!function_exists('legacy_migrate_to_v2')) {
    function legacy_migrate_to_v2(PDO $pdo, int $v): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS locations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL,
                name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
                is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
                created_at TEXT    NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_locations_one_default ON locations(is_default) WHERE is_default = 1;
            CREATE TABLE items_v2 (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL,
                quantity    INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
                notes       TEXT    NOT NULL DEFAULT '',
                image       TEXT,
                search      TEXT    NOT NULL DEFAULT '',  -- fold(name + notes)
                created_at  TEXT    NOT NULL,
                updated_at  TEXT    NOT NULL
            );
        SQL);
        if ($v === 1) {
            $ins = $pdo->prepare('INSERT OR IGNORE INTO locations (name, name_key, created_at) VALUES (?,?,?)');
            $ids = [];
            $rows = $pdo->query('SELECT id, name, location, quantity, notes, image, created_at, updated_at FROM items ORDER BY id')->fetchAll();
            foreach ($rows as $r) {
                $name = clean_line((string)$r['location'], 120);
                if ($name === '') {
                    continue;
                }
                $key = fold($name);
                if (!isset($ids[$key])) {
                    $ins->execute([$name, $key, $r['created_at']]);
                    $st = $pdo->prepare('SELECT id FROM locations WHERE name_key = ?');
                    $st->execute([$key]);
                    $ids[$key] = (int)$st->fetchColumn();
                    $st->closeCursor();
                }
            }
            $copy = $pdo->prepare('INSERT INTO items_v2 (id, name, location_id, quantity, notes, image, search, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $name = clean_line((string)$r['location'], 120);
                $copy->execute([$r['id'], $r['name'], $name === '' ? null : $ids[fold($name)], $r['quantity'], $r['notes'], $r['image'],
                    fold($r['name'] . "\n" . $r['notes']), $r['created_at'], $r['updated_at']]);
            }
            $pdo->exec('DROP TABLE items');
        } else {
            $pdo->exec('DROP TABLE IF EXISTS items');
        }
        $pdo->exec(<<<SQL
            ALTER TABLE items_v2 RENAME TO items;
            CREATE INDEX idx_items_location ON items(location_id);
            CREATE INDEX idx_items_name     ON items(name COLLATE NOCASE);
            CREATE INDEX idx_items_updated  ON items(updated_at);
        SQL);
    }
}
if (!function_exists('legacy_migrate_to_v3')) {
    function legacy_migrate_to_v3(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS categories (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL,
                name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
                is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
                created_at TEXT    NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_one_default ON categories(is_default) WHERE is_default = 1;
            ALTER TABLE items ADD COLUMN category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL;
            CREATE INDEX idx_items_category ON items(category_id);
        SQL);
    }
}
