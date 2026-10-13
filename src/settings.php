<?php
declare(strict_types=1);

/*
 * Apotheke – Control Panel settings.
 *
 * Precedence:
 *   bootstrap essentials (data dir, database driver/credentials, config file) come only from env > config.php > built-in;
 *   everything else lives in the `settings` table. When the table is created (migration 0013) every setting is seeded
 *   from the then-current config.php / environment value (or the built-in default), so existing installs keep their
 *   configuration. Afterwards the database wins; a missing or invalid row falls back to that same default.
 * Values are stored JSON-encoded, validated on write AND on read, and cached for the rest of the request.
 */

const SETTINGS_SECTIONS = [
    'general'    => ['General', 'Name, tagline and the address printed on QR labels', 'box'],
    'appearance' => ['Appearance', 'Accent colour, logo and the default theme', 'sun'],
    'locale'     => ['Currency, dates & time', 'Currency, number and date formats, time zone', 'clock'],
    'list'       => ['List', 'Items per page, default sort and view', 'filter'],
    'items'      => ['New item defaults', 'Values pre-filled when you add an item', 'plus'],
    'limits'     => ['Thresholds & limits', 'Expiry window, loan period, saved filters, undo, stocktake', 'alert'],
    'labels'     => ['QR labels', 'Label size, grid and what is printed', 'qr'],
    'uploads'    => ['Photos & uploads', 'Upload size, photo and thumbnail sizes, quality, attachments', 'camera'],
    'security'   => ['Users & sign-in', 'Accounts, roles, passwords; sign-in on or off', 'lock'],
    'data'       => ['Backup & data', 'Backup, restore, CSV and settings export', 'archive'],
    'system'     => ['System', 'Database and storage (read-only)', 'sliders'],
];

const ACCENT_DEFAULT = '#4f46e5';
const ACCENT_DEFAULT_DARK = '#8b93ff';
const ACCENT_PRESETS = [
    '#4f46e5' => 'Indigo (default)', '#C8502D' => 'Terracotta', '#6B3A1F' => 'Brown', '#0f766e' => 'Teal',
    '#15803d' => 'Green', '#2563eb' => 'Blue', '#be123c' => 'Rose', '#7c3aed' => 'Violet', '#475569' => 'Slate',
];
/** Backgrounds the accent is checked against (light: page + cards, dark: page + cards). */
const CONTRAST_BG_LIGHT = ['#f5f5f7', '#ffffff'];
const CONTRAST_BG_DARK = ['#111113', '#1c1c20'];
const MONEY_FORMATS = ['en' => '1,234.56', 'de' => '1.234,56', 'fr' => '1 234,56', 'ch' => '1’234.56', 'plain' => '1234.56'];
const DATE_FORMATS = ['Y-m-d' => '2026-06-15', 'd.m.Y' => '15.06.2026', 'd/m/Y' => '15/06/2026', 'm/d/Y' => '06/15/2026', 'j M Y' => '15 Jun 2026'];
const TIME_FORMATS = ['H:i' => '14:30', 'g:i A' => '2:30 PM'];

/**
 * Every DB-backed setting. type: string|int|bool|enum|color|timezone|url|tags|secret.
 * default: literal, or a config path ("cfg:uploads.max_mb") whose config.php/env value is the initial default.
 */
function settings_schema(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $s = [
        // general
        'app_name'      => ['general', 'App name', 'string', 'Apotheke', 'Shown in the header, the window title and on the home screen.', ['max' => 40, 'required' => true]],
        'app_tagline'   => ['general', 'Tagline', 'string', 'the storehouse for your stuff', 'Shown on the sign-in page and in the page description.', ['max' => 80]],
        'public_url'    => ['general', 'Public address', 'url', 'cfg:public_url', 'Base URL printed in QR labels, e.g. http://192.168.1.10:8080. Empty = the address you are using right now.', []],
        // appearance
        'accent'        => ['appearance', 'Accent colour', 'color', ACCENT_DEFAULT, 'Buttons, links and highlights. Lighter/darker variants are derived so text stays readable (WCAG AA) in light and dark mode.', []],
        'theme_default' => ['appearance', 'Default theme', 'enum', 'auto', 'Used until someone picks Auto / Light / Dark in the menu on their device.', ['options' => ['auto' => 'System (auto)', 'light' => 'Light', 'dark' => 'Dark']]],
        'icon_background' => ['appearance', 'Icon background', 'color', '#FBF1E3', 'Behind an uploaded PNG/JPEG/WebP logo in the home-screen and maskable app icons.', []],
        'logo'          => ['appearance', 'Logo', 'internal', '', '', []],
        // locale
        'currency'      => ['locale', 'Currency', 'string', 'cfg:currency', 'ISO code or symbol shown with prices (EUR, USD, £, CHF …).', ['max' => 8, 'required' => true]],
        'money_format'  => ['locale', 'Number format', 'enum', 'en', 'How prices are written.', ['options' => MONEY_FORMATS]],
        'currency_position' => ['locale', 'Currency position', 'enum', 'after', '', ['options' => ['after' => 'After the amount (12.50 EUR)', 'before' => 'Before the amount (EUR 12.50)']]],
        'date_format'   => ['locale', 'Date format', 'enum', 'Y-m-d', 'For purchase, expiry and lending dates.', ['options' => DATE_FORMATS]],
        'time_format'   => ['locale', 'Time format', 'enum', 'H:i', 'For times in the history.', ['options' => TIME_FORMATS]],
        'timezone'      => ['locale', 'Time zone', 'timezone', 'cfg:timezone', 'Decides what “today” is (overdue loans, expiry, statistics).', []],
        // list
        'page_size'     => ['list', 'Items per page', 'int', 'cfg:page_size', 'More items load with “Show more”.', ['min' => 1, 'max' => 500]],
        'default_sort'  => ['list', 'Default sort', 'enum', 'recent', 'Order of the list when no sort is chosen.', ['options' => SORTS]],
        'list_view'     => ['list', 'View', 'enum', 'grid', 'Photo cards, or compact rows that fit more items on the screen.', ['options' => ['grid' => 'Cards with photos', 'compact' => 'Compact rows']]],
        'recent_limit'  => ['list', 'Recently viewed', 'int', 8, 'How many recently opened items the list shows above the results. 0 = hide.', ['min' => 0, 'max' => 50]],
        'recent_scope'  => ['list', 'Recently viewed is kept', 'enum', 'device', '', ['options' => ['device' => 'Per device (a cookie)', 'install' => 'Shared by everyone']]],
        'search_typos'  => ['list', 'Typo tolerance in search', 'bool', true, 'A word that matches nothing is also looked up with one or two letters different (“screwdrivr” finds “Screwdriver set”).', []],
        'overview_open' => ['list', 'Show the overview open', 'bool', true, 'Whether the statistics overview starts expanded (each device remembers its own choice afterwards).', []],
        // items
        'default_quantity' => ['items', 'Quantity', 'int', 1, '', ['min' => 0, 'max' => 1000000]],
        'default_min_qty'  => ['items', 'Running low at', 'int', null, 'Empty = no low-stock warning for new items.', ['min' => 0, 'max' => 1000000, 'nullable' => true]],
        'default_tags'     => ['items', 'Tags', 'tags', '', 'Comma-separated, e.g. “home, new”.', ['max' => 500]],
        'default_notes'    => ['items', 'Notes', 'string', '', 'Text pre-filled in the notes field.', ['max' => 500]],
        'default_purchase_today' => ['items', 'Purchase date = today', 'bool', false, 'Pre-fill the purchase date with today.', []],
        'default_expiry_months'  => ['items', 'Expires after (months)', 'int', null, 'Pre-fill the expiry/warranty date this many months from today. Empty = no date.', ['min' => 1, 'max' => 600, 'nullable' => true]],
        // labels
        'label_width_mm'   => ['labels', 'Label width (mm)', 'int', 60, 'Printed width of one label.', ['min' => 15, 'max' => 200]],
        'label_height_mm'  => ['labels', 'Label height (mm)', 'int', 70, 'Printed height of one label (the QR code shrinks to fit).', ['min' => 15, 'max' => 280]],
        'label_columns'    => ['labels', 'Labels per row', 'int', 3, 'Columns on a printed page (A4 fits 3 × 60 mm).', ['min' => 1, 'max' => 10]],
        'label_gap_mm'     => ['labels', 'Gap between labels (mm)', 'int', 4, '0 for sticker sheets without a gap.', ['min' => 0, 'max' => 30]],
        'label_show_sub'   => ['labels', 'Print the location / item count', 'bool', true, 'The second line under the name.', []],
        'label_show_url'   => ['labels', 'Print the link', 'bool', true, 'The address the QR code points to, in small print.', []],
        'label_border'     => ['labels', 'Print cut lines', 'bool', true, 'A dashed border around each label.', []],
        // limits
        'expiry_soon_days' => ['limits', '“Expiring soon” window (days)', 'int', 'cfg:expiry_soon_days', 'An item is expiring soon when its date is within this many days.', ['min' => 1, 'max' => 3650]],
        'lend_due_days'    => ['limits', 'Default loan period (days)', 'int', null, 'Pre-fill “due back” when lending an item. Empty = no due date.', ['min' => 1, 'max' => 3650, 'nullable' => true]],
        'saved_filters_max' => ['limits', 'Saved filters limit', 'int', 50, 'Maximum number of saved filters.', ['min' => 1, 'max' => 500]],
        'undo_seconds'     => ['limits', 'Undo window (seconds)', 'int', 30, 'How long “Undo” is offered after editing, moving or deleting. Deleted photos and attachments stay on disk this long. 0 = no undo.', ['min' => 0, 'max' => 3600]],
        'stocktake_apply_moves' => ['limits', 'Stocktake: move misplaced items', 'bool', true, 'When finishing a stocktake, items found in the checked location but recorded elsewhere are moved there (you can untick it each time).', []],
        'max_photos'       => ['limits', 'Photos per item', 'int', 12, '', ['min' => 1, 'max' => 50]],
        // uploads
        'max_upload_mb'    => ['uploads', 'Maximum upload (MB)', 'int', 'cfg:uploads.max_mb', 'Per photo. PHP’s upload_max_filesize / post_max_size must be at least this large.', ['min' => 1, 'max' => 200]],
        'image_full_max'   => ['uploads', 'Photo size (px)', 'int', 'cfg:uploads.full_max', 'Longest side of stored photos. Applies to new uploads.', ['min' => 320, 'max' => 8000]],
        'image_thumb_max'  => ['uploads', 'Thumbnail size (px)', 'int', 'cfg:uploads.thumb_max', 'Longest side of list thumbnails. Applies to new uploads.', ['min' => 64, 'max' => 2000]],
        'jpeg_quality'     => ['uploads', 'JPEG quality', 'int', 82, '40–95; thumbnails use 6 less.', ['min' => 40, 'max' => 95]],
        'attach_types'     => ['uploads', 'Attachment types', 'enum', 'pdf_images', 'Receipts, manuals and warranty cards attached to an item. The real file type is checked, not the name.', ['options' => ATTACH_TYPE_SETS]],
        'attach_max_mb'    => ['uploads', 'Attachment size limit (MB)', 'int', 10, 'Per file. PHP’s upload_max_filesize / post_max_size must be at least this large.', ['min' => 1, 'max' => 200]],
        'attach_max_per_item' => ['uploads', 'Attachments per item', 'int', 10, '', ['min' => 1, 'max' => 100]],
        // security (own form)
        'auth_enabled'     => ['security', 'Require sign-in', 'internal', 'cfg_auth', '', []],
        'password_hash'    => ['security', 'Password (before accounts; moved to the user “admin” by migration 0018)', 'secret', 'cfg_hash', '', []],
    ];
    return $s;
}

/** Bootstrap settings shown read-only: [label, value, source]. Never includes the database password. */
function settings_bootstrap_info(): array
{
    $c = cfg();
    $db = $c['db'];
    $src = fn(string $env) => (getenv($env) !== false && getenv($env) !== '') ? "env $env" : 'config.php / default';
    $rows = [
        ['Data directory', $c['data_dir'], $src('DATA_DIR')],
        ['Database driver', $db['driver'], $src('DB_DRIVER')],
    ];
    if ($db['driver'] === 'sqlite') {
        $rows[] = ['SQLite file', $db['sqlite_path'], $src('SQLITE_PATH')];
    } else {
        $rows[] = ['Database', $db['dsn'] !== '' ? preg_replace('/password=[^;]*/i', 'password=•••', $db['dsn']) : $db['host'] . ':' . $db['port'] . ' / ' . $db['name'], $src('DB_HOST')];
        $rows[] = ['Database user', $db['user'], $src('DB_USER')];
        $rows[] = ['Schema', $db['schema'] !== '' ? $db['schema'] : '(default)', $src('DB_SCHEMA')];
        $rows[] = ['Database password', $db['password'] !== '' ? '•••••• (set)' : '(empty)', $src('DB_PASSWORD')];
    }
    $rows[] = ['Starter data on a new database', $c['seed_defaults'] ? 'yes' : 'no', $src('SEED_DEFAULTS')];
    $file = getenv('APOTHEKE_CONFIG') ?: dirname(__DIR__) . '/config.php';
    $rows[] = ['Config file', is_file($file) ? $file : $file . ' (not present)', getenv('APOTHEKE_CONFIG') ? 'env APOTHEKE_CONFIG' : 'default'];
    $rows[] = ['PHP', PHP_VERSION, 'server'];
    return $rows;
}

function setting_default(string $key): mixed
{
    $d = settings_schema()[$key][3] ?? null;
    if (is_string($d) && str_starts_with($d, 'cfg:')) {
        $v = cfg();
        foreach (explode('.', substr($d, 4)) as $k) $v = $v[$k] ?? null;
        try { return setting_validate($key, $v); } catch (UserError) { return null; }
    }
    if ($d === 'cfg_auth') return cfg()['app_password'] !== '';
    if ($d === 'cfg_hash') return cfg()['app_password'] !== '' ? password_hash(cfg()['app_password'], PASSWORD_DEFAULT) : '';
    return $d;
}

/**
 * Validate and type a raw value (from a form, JSON import or the database). Throws UserError with a readable message.
 */
function setting_validate(string $key, mixed $raw): mixed
{
    $def = settings_schema()[$key] ?? null;
    if ($def === null) throw new UserError("Unknown setting “{$key}”.");
    [, $label, $type, , , $o] = $def;
    $str = is_scalar($raw) || $raw === null ? trim((string)$raw) : null;
    switch ($type) {
        case 'bool':
        case 'internal':
            if ($type === 'internal' && $key === 'logo') {
                if (!in_array($raw, ['', 'svg', 'png'], true)) throw new UserError('Invalid logo.');
                return $raw;
            }
            if (is_bool($raw)) return $raw;
            $b = filter_var($str, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($b === null) throw new UserError("$label: yes or no.");
            return $b;
        case 'secret':
            if (!is_string($raw)) throw new UserError("$label: invalid.");
            return $raw;
        case 'int':
            if ($str === '' || $str === null) {
                if (!empty($o['nullable'])) return null;
                throw new UserError("$label: enter a number.");
            }
            if (!preg_match('/^-?\d{1,9}$/D', $str)) throw new UserError("$label: enter a whole number.");
            $n = (int)$str;
            if ($n < $o['min'] || $n > $o['max']) throw new UserError("$label: between {$o['min']} and {$o['max']}.");
            return $n;
        case 'enum':
            if ($str === null || !array_key_exists($str, $o['options'])) throw new UserError("$label: choose one of the options.");
            return $str;
        case 'color':
            if ($str === null || !preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/iD', $str, $m)) throw new UserError("$label: a colour like #C8502D.");
            $h = $m[1];
            if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            return '#' . strtolower($h) === strtolower(ACCENT_DEFAULT) ? ACCENT_DEFAULT : '#' . strtoupper($h);
        case 'timezone':
            if ($str === null || !in_array($str, DateTimeZone::listIdentifiers(), true) && $str !== 'UTC') throw new UserError("$label: unknown time zone.");
            return $str;
        case 'url':
            if ($str === null) throw new UserError("$label: invalid.");
            $str = rtrim($str, '/');
            if ($str !== '' && (!preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+(/[^\s]*)?$#D', $str) || strlen($str) > 200)) {
                throw new UserError("$label: a full address starting with http:// or https://.");
            }
            return $str;
        case 'tags':
            if ($str === null) throw new UserError("$label: invalid.");
            if (mb_strlen($str) > $o['max']) throw new UserError("$label: at most {$o['max']} characters.");
            return implode(', ', tags_parse($str));
        case 'string':
        default:
            if ($str === null) throw new UserError("$label: invalid.");
            $str = clean_line($str, 1000);
            if (mb_strlen($str) > $o['max']) throw new UserError("$label: at most {$o['max']} characters.");
            if (!empty($o['required']) && $str === '') throw new UserError("$label cannot be empty.");
            return $str;
    }
}

/** Cache holder: settings_cache() reads, settings_cache(null, true) clears. */
function settings_cache(?array $set = null, bool $clear = false): ?array
{
    static $c = null;
    if ($clear) $c = null;
    if ($set !== null) $c = $set;
    return $c;
}

/** All settings, typed. One query per request. */
function settings_all(): array
{
    if (($c = settings_cache()) !== null) return $c;
    $rows = [];
    try {
        foreach (db()->query('SELECT name, value FROM settings') as $r) $rows[(string)$r['name']] = (string)$r['value'];
    } catch (PDOException) { /* table missing (mid-migration): defaults */ }
    $out = [];
    foreach (settings_schema() as $k => $_) {
        if (array_key_exists($k, $rows)) {
            try { $out[$k] = setting_validate($k, json_decode($rows[$k], true)); continue; } catch (UserError) { /* invalid row: default */ }
        }
        $out[$k] = setting_default($k);
    }
    return settings_cache($out);
}

function setting(string $key): mixed
{
    $all = settings_all();
    if (!array_key_exists($key, $all)) throw new LogicException("Unknown setting $key");
    return $all[$key];
}

/** Keys of a section that the generic form edits. */
function settings_section_keys(string $section): array
{
    return array_keys(array_filter(settings_schema(), fn($d) => $d[0] === $section && !in_array($d[2], ['internal', 'secret'], true)));
}

/** Store already-validated values (one transaction). */
function settings_store(array $values): void
{
    if (!$values) return;
    db_tx(function (PDO $pdo) use ($values): void {
        $del = $pdo->prepare('DELETE FROM settings WHERE name = ?');
        $ins = $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)');
        foreach ($values as $k => $v) {
            $del->execute([$k]);
            $ins->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc()]);
        }
    });
    settings_cache(null, true);
}

/**
 * Validate and save a set of raw values. Nothing is saved when any value is invalid.
 * @return array<string,string> errors by key (empty = saved)
 */
function settings_save(array $raw): array
{
    $ok = $err = [];
    foreach ($raw as $k => $v) {
        try { $ok[$k] = setting_validate((string)$k, $v); } catch (UserError $e) { $err[$k] = $e->getMessage(); }
    }
    if (!$err) settings_store($ok);
    return $err;
}

/** Back to the config.php/env (or built-in) defaults for one section. The password is never touched. */
function settings_reset_section(string $section): void
{
    if (!isset(SETTINGS_SECTIONS[$section])) throw new UserError('Unknown section.', 404);
    $keys = settings_section_keys($section);
    if ($section === 'appearance') {
        $keys[] = 'logo';
        brand_reset();
    }
    $vals = [];
    foreach ($keys as $k) $vals[$k] = setting_default($k);
    settings_store($vals);
}

/** Initial rows (migration 0013): every setting from config.php / env / built-in. */
function settings_seed(PDO $pdo): void
{
    $have = $pdo->query('SELECT name FROM settings')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)');
    foreach (settings_schema() as $k => $_) {
        if (in_array($k, $have, true)) continue;
        $ins->execute([$k, json_encode(setting_default($k), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now_utc()]);
    }
    settings_cache(null, true);
}

/** Exportable settings (no password, no logo file reference). */
function settings_export(): array
{
    $out = [];
    foreach (settings_all() as $k => $v) {
        if (in_array(settings_schema()[$k][2], ['secret', 'internal'], true)) continue;
        $out[$k] = $v;
    }
    return ['format' => 'apotheke-settings', 'version' => 1, 'exported_at' => now_utc(), 'settings' => $out];
}

/**
 * Import an export. Valid known keys are applied together; invalid ones are reported and skipped, unknown ones ignored.
 * @return array{applied:int,errors:array<string,string>,ignored:string[]}
 */
function settings_import(string $json): array
{
    $d = json_decode($json, true);
    if (!is_array($d) || ($d['format'] ?? '') !== 'apotheke-settings' || !is_array($d['settings'] ?? null)) {
        throw new UserError('That is not an Apotheke settings file.');
    }
    $ok = $err = $ignored = [];
    foreach ($d['settings'] as $k => $v) {
        $k = (string)$k;
        $def = settings_schema()[$k] ?? null;
        if ($def === null || in_array($def[2], ['secret', 'internal'], true)) { $ignored[] = $k; continue; }
        try { $ok[$k] = setting_validate($k, is_bool($v) ? $v : (is_scalar($v) || $v === null ? (string)$v : $v)); }
        catch (UserError $e) { $err[$k] = $e->getMessage(); }
    }
    settings_store($ok);
    return ['applied' => count($ok), 'errors' => $err, 'ignored' => $ignored];
}

/* ---------- typed accessors used across the app ---------- */

function app_name(): string { return (string)setting('app_name'); }
function app_tagline(): string { return (string)setting('app_tagline'); }
function page_size(): int { return (int)setting('page_size'); }
function max_upload(): int { return (int)setting('max_upload_mb') * 1024 * 1024; }
function image_full_max(): int { return (int)setting('image_full_max'); }
function image_thumb_max(): int { return (int)setting('image_thumb_max'); }
function default_sort(): string { return (string)setting('default_sort'); }
function saved_filters_max(): int { return (int)setting('saved_filters_max'); }
function max_photos(): int { return (int)setting('max_photos'); }

/** Pre-filled values of the Add item form (quantity, low-stock level, tags, notes, dates). */
function new_item_defaults(): array
{
    $today = local_today();
    $months = setting('default_expiry_months');
    return [
        'quantity' => (int)setting('default_quantity'),
        'min_qty' => setting('default_min_qty') === null ? '' : (string)setting('default_min_qty'),
        'tags' => (string)setting('default_tags'),
        'notes' => (string)setting('default_notes'),
        'value' => '',
        'purchase_date' => setting('default_purchase_today') ? $today : '',
        'expires_on' => $months === null ? '' : (new DateTimeImmutable($today))->modify('+' . (int)$months . ' months')->format('Y-m-d'),
    ];
}

/** Pre-filled "due back" date for a new loan ('' = none). */
function default_lend_due(): string
{
    $d = setting('lend_due_days');
    return $d === null ? '' : (new DateTimeImmutable(local_today()))->modify('+' . (int)$d . ' days')->format('Y-m-d');
}

/** Apply settings that change PHP state for this request (time zone). */
function settings_apply_runtime(): void
{
    try { date_default_timezone_set((string)setting('timezone')); } catch (Throwable) { /* keep bootstrap zone */ }
}

/** Format a calendar date (Y-m-d) with the configured date format. */
function fmt_date(?string $ymd): string
{
    $ymd = (string)$ymd;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ymd)) return $ymd;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d ? $d->format((string)setting('date_format')) : $ymd;
}

/** Number with the configured separators. */
function fmt_number(float $n, int $dec = 2): string
{
    [$d, $t] = match ((string)setting('money_format')) {
        'de' => [',', '.'], 'fr' => [',', "\u{202F}"], 'ch' => ['.', '’'], 'plain' => ['.', ''], default => ['.', ','],
    };
    return number_format($n, $dec, $d, $t);
}

/* ---------- accent colour: WCAG contrast and derived variants ---------- */

/** @return array{0:float,1:float,2:float} 0–255 */
function hex_rgb(string $hex): array
{
    $h = ltrim($hex, '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function rgb_hex(array $c): string
{
    return sprintf('#%02x%02x%02x', ...array_map(fn($v) => (int)round(max(0, min(255, $v))), $c));
}

function rel_luminance(string $hex): float
{
    $l = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, hex_rgb($hex));
    return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
}

function contrast_ratio(string $a, string $b): float
{
    [$x, $y] = [rel_luminance($a), rel_luminance($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** Mix $hex towards $to (#000/#fff) by $t (0–1). */
function mix_hex(string $hex, string $to, float $t): string
{
    $a = hex_rgb($hex);
    $b = hex_rgb($to);
    return rgb_hex([$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t]);
}

/** Smallest mix towards $to that reaches $ratio against every background. */
function contrast_fit(string $hex, array $bgs, string $to, float $ratio = 4.5): string
{
    for ($i = 0; $i <= 50; $i++) {
        $c = mix_hex($hex, $to, $i / 50);
        if (min(array_map(fn($bg) => contrast_ratio($c, $bg), $bgs)) >= $ratio) return $c;
    }
    return $to;
}

/**
 * Light and dark variants of an accent colour that pass WCAG AA (4.5:1) as text on the app's backgrounds, with a
 * readable ink colour for text on accent buttons, a hover shade and a soft tint.
 */
function accent_variants(string $hex): array
{
    $hex = strtolower($hex);
    $isDefault = $hex === strtolower(ACCENT_DEFAULT);
    $light = contrast_fit($hex, CONTRAST_BG_LIGHT, '#000000');
    // the built-in indigo keeps its hand-picked dark shade (app.css)
    $dark = $isDefault ? ACCENT_DEFAULT_DARK : contrast_fit($hex, CONTRAST_BG_DARK, '#ffffff');
    $ink = fn(string $c) => contrast_ratio($c, '#ffffff') >= contrast_ratio($c, '#0b0b10') ? '#ffffff' : '#0b0b10';
    $rgba = fn(string $c, float $a) => 'rgba(' . implode(',', array_map('intval', hex_rgb($c))) . ',' . $a . ')';
    $out = [];
    foreach (['light' => [$light, CONTRAST_BG_LIGHT, '#000000', 0.10], 'dark' => [$dark, CONTRAST_BG_DARK, '#ffffff', 0.16]] as $mode => [$c, $bgs, $to, $soft]) {
        $out[$mode] = [
            'accent' => $c, 'hover' => mix_hex($c, $to, 0.15), 'ink' => $ink($c), 'soft' => $rgba($c, $soft), 'shadow' => $rgba($c, 0.4),
            'contrast' => round(min(array_map(fn($bg) => contrast_ratio($c, $bg), $bgs)), 2),
            'ink_contrast' => round(contrast_ratio($c, $ink($c)), 2),
        ];
    }
    $out['raw'] = [
        'light' => round(min(array_map(fn($bg) => contrast_ratio($hex, $bg), CONTRAST_BG_LIGHT)), 2),
        'dark' => round(min(array_map(fn($bg) => contrast_ratio($hex, $bg), CONTRAST_BG_DARK)), 2),
    ];
    $out['adjusted'] = ['light' => $light !== $hex, 'dark' => !$isDefault && $dark !== $hex];
    return $out;
}

/** Human warnings for the panel ("Terracotta is 4.16:1 on the light background – darkened to #b4441f"). */
function accent_warnings(string $hex): array
{
    $v = accent_variants($hex);
    $w = [];
    foreach (['light' => 'light', 'dark' => 'dark'] as $m => $name) {
        if ($v['adjusted'][$m]) {
            $w[] = sprintf('%s is %.2f:1 on the %s background (WCAG AA needs 4.5:1) – %s mode uses %s instead.', strtoupper($hex), $v['raw'][$m], $name, ucfirst($name), $v[$m]['accent']);
        }
    }
    return $w;
}

/** Generated print stylesheet for the QR label grid (served at /labels.css, keeps the CSP at style-src 'self'). */
function labels_css(): string
{
    $w = (int)setting('label_width_mm'); $hgt = (int)setting('label_height_mm');
    $c = (int)setting('label_columns'); $g = (int)setting('label_gap_mm');
    $qr = max(8, min($w - 6, $hgt - (setting('label_show_sub') ? 18 : 12) - (setting('label_show_url') ? 4 : 0)));
    return "/* QR label grid from Settings > QR labels */\n@media print{\n"
        . ".labels{grid-template-columns:repeat($c,{$w}mm)!important;gap:{$g}mm!important;justify-content:start}\n"
        . ".label{width:{$w}mm;height:{$hgt}mm;overflow:hidden;padding:2mm!important;box-sizing:border-box;justify-content:center"
        . (setting('label_border') ? '' : ';border:0!important') . "}\n"
        . ".label .qr{width:{$qr}mm!important;max-width:{$qr}mm!important;height:{$qr}mm!important}\n}\n";
}

/** The generated stylesheet for a custom accent (served at /theme.css). '' for the default accent. */
function accent_css(string $hex): string
{
    if (strcasecmp($hex, ACCENT_DEFAULT) === 0) return '';
    $v = accent_variants($hex);
    $vars = fn(array $x) => "--accent:{$x['accent']};--accent-2:{$x['hover']};--accent-ink:{$x['ink']};--accent-soft:{$x['soft']};--accent-shadow:{$x['shadow']}";
    return "/* Apotheke accent {$hex} (generated) */\n"
        . ':root{' . $vars($v['light']) . "}\n"
        . ':root[data-theme=dark]{' . $vars($v['dark']) . "}\n"
        . '@media (prefers-color-scheme:dark){:root:not([data-theme=light]):not([data-theme=dark]){' . $vars($v['dark']) . "}}\n";
}

/* ---------- logo / icons (stored in DATA_DIR/brand) ---------- */

const LOGO_MAX_BYTES = 2 * 1024 * 1024;
const BRAND_FILES = ['logo.svg' => 'image/svg+xml', 'logo.png' => 'image/png', 'icon-32.png' => 'image/png', 'icon-180.png' => 'image/png',
    'icon-192.png' => 'image/png', 'icon-512.png' => 'image/png', 'icon-maskable-512.png' => 'image/png', 'favicon.ico' => 'image/vnd.microsoft.icon'];

function brand_dir(): string { return DATA_DIR . '/brand'; }

function brand_version(): string
{
    $f = brand_dir() . '/logo.' . (setting('logo') ?: 'none');
    return is_file($f) ? substr(md5((string)filemtime($f) . filesize($f) . setting('icon_background')), 0, 8) : '0';
}

/** URL of the header logo (custom or the bundled one). */
function brand_logo_url(): string
{
    $l = (string)setting('logo');
    return $l !== '' && is_file(brand_dir() . "/logo.$l") ? "/brand/logo.$l?v=" . brand_version() : asset('icon.svg');
}

/** URL of a PNG icon (32, 180, 192, 512, maskable-512): generated from a raster logo, else the bundled icon. */
function brand_icon_url(string $size): string
{
    $map = ['32' => 'icon-32.png', '180' => 'apple-touch-icon.png', '192' => 'icon-192.png', '512' => 'icon-512.png', 'maskable-512' => 'icon-maskable-512.png'];
    $own = $size === '180' ? 'icon-180.png' : "icon-$size.png";
    if (setting('logo') === 'png' && is_file(brand_dir() . "/$own")) return "/brand/$own?v=" . brand_version();
    return asset($map[$size]);
}

function brand_favicon_svg_url(): string
{
    return setting('logo') === 'svg' && is_file(brand_dir() . '/logo.svg') ? '/brand/logo.svg?v=' . brand_version() : asset('favicon.svg');
}

function brand_reset(): void
{
    foreach (array_keys(BRAND_FILES) as $f) @unlink(brand_dir() . "/$f");
    settings_store(['logo' => '']);
}

/**
 * Strip an SVG down to drawing elements and presentation attributes: no scripts, event handlers, external
 * references, foreignObject, entities or styles that load anything. Returns the cleaned document.
 */
function svg_sanitize(string $svg): string
{
    if (!class_exists(DOMDocument::class)) throw new UserError('SVG logos need the PHP dom extension; upload a PNG, JPEG or WebP instead.');
    if (strlen($svg) > 512 * 1024) throw new UserError('That SVG is too large (max 512 KB).');
    if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) throw new UserError('SVG files with a DOCTYPE or entities are not accepted.');
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || !$doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') throw new UserError('That is not a valid SVG image.');

    $elements = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'lineargradient', 'radialgradient',
        'stop', 'title', 'desc', 'clippath', 'mask', 'symbol', 'use', 'text', 'tspan'];
    $attrs = ['id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-miterlimit', 'opacity', 'transform', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'spreadmethod', 'clip-path', 'clip-rule', 'mask', 'maskunits', 'clippathunits', 'preserveaspectratio',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'version', 'href'];
    $walk = function (DOMNode $n) use (&$walk, $elements, $attrs): void {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMElement) {
                if (!in_array(strtolower($c->localName), $elements, true) || ($c->namespaceURI !== null && $c->namespaceURI !== 'http://www.w3.org/2000/svg')) {
                    $n->removeChild($c);
                    continue;
                }
                $walk($c);
            } elseif ($c instanceof DOMProcessingInstruction || $c instanceof DOMComment || $c instanceof DOMEntityReference) {
                $n->removeChild($c);
            } elseif ($c instanceof DOMCdataSection) {
                $n->replaceChild($n->ownerDocument->createTextNode($c->data), $c);
            }
        }
        if ($n instanceof DOMElement) {
            foreach (iterator_to_array($n->attributes) as $a) {
                $name = strtolower($a->localName);
                $val = (string)$a->value;
                $bad = !in_array($name, $attrs, true)
                    || ($a->namespaceURI !== null && $a->namespaceURI !== 'http://www.w3.org/1999/xlink')
                    || preg_match('/javascript:|data:|expression\s*\(|url\s*\(\s*[\'"]?\s*(?!#)/i', $val)
                    || ($name === 'href' && !str_starts_with($val, '#'));
                if ($bad) $n->removeAttributeNode($a);
            }
        }
    };
    $root = $doc->documentElement;
    $walk($root);
    if (!$root->hasAttribute('viewBox') && !($root->hasAttribute('width') && $root->hasAttribute('height'))) {
        throw new UserError('The SVG needs a viewBox (or width and height).');
    }
    $out = $doc->saveXML($root);
    if ($out === false || !str_contains($out, '<')) throw new UserError('That is not a valid SVG image.');
    if (!$root->hasAttribute('xmlns')) $out = preg_replace('/^<svg\b/', '<svg xmlns="http://www.w3.org/2000/svg"', $out, 1);
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n";
}

/** Square-fit $src into $size×$size: transparent, or on $bg (hex) with $inset padding (0–0.5). */
function icon_square(GdImage $src, int $size, ?string $bg = null, float $inset = 0.0): GdImage
{
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    if ($bg === null) {
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    } else {
        [$r, $g, $b] = hex_rgb($bg);
        imagefill($im, 0, 0, imagecolorallocate($im, (int)$r, (int)$g, (int)$b));
    }
    imagealphablending($im, true);
    $w = imagesx($src);
    $h = imagesy($src);
    $box = $size * (1 - 2 * $inset);
    $s = $box / max($w, $h);
    $nw = max(1, (int)round($w * $s));
    $nh = max(1, (int)round($h * $s));
    imagecopyresampled($im, $src, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    return $im;
}

function gd_png(GdImage $im): string
{
    ob_start();
    imagepng($im, null, 9);
    return (string)ob_get_clean();
}

/** ICO file with PNG-encoded entries. @param array<int,string> $pngs size => PNG bytes */
function ico_from_pngs(array $pngs): string
{
    $head = pack('vvv', 0, 1, count($pngs));
    $dir = $data = '';
    $offset = 6 + 16 * count($pngs);
    foreach ($pngs as $size => $png) {
        $dir .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
        $data .= $png;
        $offset += strlen($png);
    }
    return $head . $dir . $data;
}

/**
 * Install an uploaded logo: SVG (sanitised; used as is) or PNG/JPEG/WebP (square 512 PNG plus generated favicon,
 * apple-touch and manifest icons on the icon background colour).
 */
function brand_store(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new UserError(upload_error_text((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
    if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') throw new UserError('Invalid upload.');
    if ((int)$file['size'] > LOGO_MAX_BYTES) throw new UserError('That logo is too large (max 2 MB).');
    $bytes = (string)file_get_contents($file['tmp_name']);
    $dir = brand_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new UserError('Server could not save the logo.');

    $head = ltrim(substr($bytes, 0, 512));
    if (str_starts_with($head, '<?xml') || str_starts_with($head, '<svg') || preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg\b/s', $bytes)) {
        $clean = svg_sanitize($bytes);
        foreach (array_keys(BRAND_FILES) as $f) @unlink("$dir/$f");
        file_put_contents("$dir/logo.svg", $clean);
        settings_store(['logo' => 'svg']);
        return 'svg';
    }

    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        throw new UserError('Unsupported logo. Use SVG, PNG, JPEG or WebP.');
    }
    if ($info[0] < 64 || $info[1] < 64) throw new UserError('The logo must be at least 64×64 pixels.');
    if ($info[0] > 4096 || $info[1] > 4096) throw new UserError('The logo can be at most 4096×4096 pixels.');
    $src = @imagecreatefromstring($bytes);
    if (!$src) throw new UserError('Could not read that image.');
    imagesavealpha($src, true);
    $bg = (string)setting('icon_background');
    $files = [
        'logo.png' => gd_png(icon_square($src, 512)),
        'icon-32.png' => gd_png(icon_square($src, 32)),
        'icon-180.png' => gd_png(icon_square($src, 180, $bg, 0.08)),
        'icon-192.png' => gd_png(icon_square($src, 192)),
        'icon-512.png' => gd_png(icon_square($src, 512)),
        'icon-maskable-512.png' => gd_png(icon_square($src, 512, $bg, 0.2)),
        'favicon.ico' => ico_from_pngs([16 => gd_png(icon_square($src, 16)), 32 => gd_png(icon_square($src, 32)), 48 => gd_png(icon_square($src, 48))]),
    ];
    foreach (array_keys(BRAND_FILES) as $f) @unlink("$dir/$f");
    foreach ($files as $f => $b) {
        if (file_put_contents("$dir/$f", $b) === false) throw new UserError('Server could not save the logo.');
    }
    settings_store(['logo' => 'png']);
    return 'png';
}

/** Serve a file from DATA_DIR/brand (strict names, own CSP so an SVG opened directly can never run anything). */
function brand_serve(string $name): never
{
    $path = brand_dir() . '/' . $name;
    if (!isset(BRAND_FILES[$name]) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . BRAND_FILES[$name]);
    header('Content-Length: ' . filesize($path));
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($path);
    exit;
}

/** The web app manifest (name, colours and icons follow the settings). */
function manifest_data(): array
{
    $accent = (string)setting('accent');
    $custom = setting('logo') !== '';
    $icons = [];
    if (setting('logo') === 'svg') $icons[] = ['src' => brand_logo_url(), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
    elseif (!$custom) $icons[] = ['src' => '/assets/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
    $icons[] = ['src' => brand_icon_url('192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
    $icons[] = ['src' => brand_icon_url('512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
    $icons[] = ['src' => brand_icon_url('maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
    return [
        'name' => app_name(), 'short_name' => mb_substr(app_name(), 0, 12),
        'description' => app_name() . ', ' . app_tagline(),
        'start_url' => '/', 'scope' => '/', 'display' => 'standalone',
        'background_color' => $custom ? (string)setting('icon_background') : '#FBF1E3',
        'theme_color' => strcasecmp($accent, ACCENT_DEFAULT) === 0 ? '#C8502D' : accent_variants($accent)['light']['accent'],
        'icons' => $icons,
    ];
}
