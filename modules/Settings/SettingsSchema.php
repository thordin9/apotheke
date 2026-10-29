<?php

declare(strict_types=1);

namespace Apotheke\Settings;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Text;
use Closure;
use DateTimeZone;

/**
 * Every database-backed setting: the single source of truth for the keys,
 * their sections, labels, types, defaults and validation rules. Ported from
 * the legacy settings_schema(), setting_default() and setting_validate();
 * golden tests compare every key with legacy settings.php.
 *
 * Immutable and stateless, so one instance is safe across requests.
 *
 * A definition is [section, label, type, default, help, options]. type is
 * string|int|bool|enum|color|timezone|url|tags|secret|internal|role|af_model|
 * af_size|af_bytes. default is a literal, a config path ("cfg:uploads.max_mb"),
 * "cfg_auth" or "cfg_hash".
 */
final class SettingsSchema
{
    public const SECTIONS = [
        'general'    => ['General', 'Name, tagline and the address printed on QR labels', 'box'],
        'appearance' => ['Appearance', 'Accent colour, logo and the default theme', 'sun'],
        'locale'     => ['Currency, dates & time', 'Currency, number and date formats, time zone', 'clock'],
        'list'       => ['List', 'Items per page, default sort and view', 'filter'],
        'items'      => ['New item defaults', 'Values pre-filled when you add an item', 'plus'],
        'limits'     => ['Thresholds & limits', 'Expiry window, loan period, saved filters, undo, stocktake', 'alert'],
        'labels'     => ['QR labels', 'Label size, grid and what is printed', 'qr'],
        'uploads'    => ['Photos & uploads', 'Upload size, photo and thumbnail sizes, quality, attachments', 'camera'],
        'autofill'   => ['AI autofill', 'Suggestions from a photo, by your own autofill service', 'sparkle'],
        'security'   => ['Users & sign-in', 'Accounts, roles, passwords; sign-in on or off', 'lock'],
        'data'       => ['Backup & data', 'Backup, restore, CSV and settings export', 'archive'],
        'system'     => ['System', 'Database and storage (read-only)', 'sliders'],
    ];

    public const ACCENT_DEFAULT = '#4f46e5';
    public const MONEY_FORMATS = ['en' => '1,234.56', 'de' => '1.234,56', 'fr' => '1 234,56', 'ch' => '1’234.56', 'plain' => '1234.56'];
    public const DATE_FORMATS = ['Y-m-d' => '2026-06-15', 'd.m.Y' => '15.06.2026', 'd/m/Y' => '15/06/2026', 'm/d/Y' => '06/15/2026', 'j M Y' => '15 Jun 2026'];
    public const TIME_FORMATS = ['H:i' => '14:30', 'g:i A' => '2:30 PM'];
    public const SORTS = ['recent' => 'Recently added', 'name' => 'Name', 'location' => 'Location', 'category' => 'Category', 'qty' => 'Quantity', 'value' => 'Value (highest first)'];
    public const ATTACH_TYPE_SETS = ['pdf_images' => 'PDF and images', 'pdf' => 'PDF only', 'images' => 'Images only'];
    /** Smallest long edge an admin can pick for autofill_image_size (px). */
    public const AUTOFILL_SIDE_MIN = 64;
    /** Bounds of an explicit autofill_max_image_bytes (v2.6.0, migration 0026). */
    public const AUTOFILL_IMAGE_BYTES_MIN = 65536;
    public const AUTOFILL_IMAGE_BYTES_MAX = 10485760;
    /** Account roles before migration 0022 adds the roles table (legacy account_roles() without guest). */
    public const BUILTIN_ACCOUNT_ROLES = ['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'];

    /** Types the generic forms, exports and imports skip. */
    private const PROTECTED_TYPES = ['secret', 'internal'];

    private const DEFINITIONS = [
        // general
        'app_name'      => ['general', 'App name', 'string', 'Apotheke', 'Shown in the header, the window title and on the home screen.', ['max' => 40, 'required' => true]],
        'app_tagline'   => ['general', 'Tagline', 'string', 'the storehouse for your stuff', 'Shown on the sign-in page and in the page description.', ['max' => 80]],
        'public_url'    => ['general', 'Public address', 'url', 'cfg:public_url', 'Base URL printed in QR labels, e.g. http://192.168.1.10:9091. Empty = the address you are using right now.', []],
        // appearance
        'accent'        => ['appearance', 'Accent colour', 'color', self::ACCENT_DEFAULT, 'Buttons, links and highlights. Lighter/darker variants are derived so text stays readable (WCAG AA) in light and dark mode.', []],
        'theme_default' => ['appearance', 'Default theme', 'enum', 'auto', 'Used until someone picks Auto / Light / Dark in the menu on their device.', ['options' => ['auto' => 'System (auto)', 'light' => 'Light', 'dark' => 'Dark']]],
        'icon_background' => ['appearance', 'Icon background', 'color', '#FBF1E3', 'Behind an uploaded PNG/JPEG/WebP logo in the home-screen and maskable app icons.', []],
        'logo'          => ['appearance', 'Logo', 'internal', '', '', []],
        // locale
        'currency'      => ['locale', 'Currency', 'string', 'cfg:currency', 'ISO code or symbol shown with prices (EUR, USD, £, CHF …).', ['max' => 8, 'required' => true]],
        'money_format'  => ['locale', 'Number format', 'enum', 'en', 'How prices are written.', ['options' => self::MONEY_FORMATS]],
        'currency_position' => ['locale', 'Currency position', 'enum', 'after', '', ['options' => ['after' => 'After the amount (12.50 EUR)', 'before' => 'Before the amount (EUR 12.50)']]],
        'date_format'   => ['locale', 'Date format', 'enum', 'Y-m-d', 'For purchase, expiry and lending dates.', ['options' => self::DATE_FORMATS]],
        'time_format'   => ['locale', 'Time format', 'enum', 'H:i', 'For times in the history.', ['options' => self::TIME_FORMATS]],
        'timezone'      => ['locale', 'Time zone', 'timezone', 'cfg:timezone', 'Decides what “today” is (overdue loans, expiry, statistics).', []],
        // list
        'page_size'     => ['list', 'Items per page', 'int', 'cfg:page_size', 'More items load with “Show more”.', ['min' => 1, 'max' => 500]],
        'default_sort'  => ['list', 'Default sort', 'enum', 'recent', 'Order of the list when no sort is chosen.', ['options' => self::SORTS]],
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
        'attach_types'     => ['uploads', 'Attachment types', 'enum', 'pdf_images', 'Receipts, manuals and warranty cards attached to an item. The real file type is checked, not the name.', ['options' => self::ATTACH_TYPE_SETS]],
        'attach_max_mb'    => ['uploads', 'Attachment size limit (MB)', 'int', 10, 'Per file. PHP’s upload_max_filesize / post_max_size must be at least this large.', ['min' => 1, 'max' => 200]],
        'attach_max_per_item' => ['uploads', 'Attachments per item', 'int', 10, '', ['min' => 1, 'max' => 100]],
        // AI autofill (token: own form, write-only)
        'autofill_enabled' => ['autofill', 'Suggest details from the photo', 'bool', false, 'Off: Apotheke never contacts the service. On: each photo on the item form gets an Analyze button; tap it and a progress line shows while the service looks at the photo, then empty fields are pre-filled with suggestions you can keep or clear.', []],
        'autofill_auto'    => ['autofill', 'Analyze photos automatically', 'bool', false, 'Off (recommended): nothing is sent until someone taps Analyze on a photo. On: every photo picked on the item form is sent to the service right away, as in Apotheke 2.3.', []],
        'autofill_url'     => ['autofill', 'Service address', 'url', '', 'Where your autofill service runs, e.g. http://192.168.1.10:8080 (without /v1). The photo is sent there, resized to about 1024 px.', ['placeholder' => 'http://192.168.1.10:8080']],
        'autofill_timeout' => ['autofill', 'Timeout (seconds)', 'int', 50, 'How long to wait for suggestions. Saving an item never waits for them. Set it about 5 s above the service’s own timeout: 50 for the default Jetson setup (the service gives up after 45 s). When the service says its model is still loading, Apotheke waits as long as it asks (up to this timeout) and tries once more.', ['min' => 3, 'max' => 60]],
        'autofill_model'   => ['autofill', 'Model', 'af_model', '', 'Which of the service’s models looks at the photos. Switching models makes the next photo slower while the service loads it.', ['max' => 120]],
        'autofill_image_size' => ['autofill', 'Image size', 'af_size', 0, 'Long edge of the photo sent to the service. Smaller is faster; larger can read small print. Photos are never enlarged.', ['min' => 0, 'max' => 4096]],
        'autofill_max_image_bytes' => ['autofill', 'Maximum image size (bytes)', 'af_bytes', 'auto', 'Largest JPEG sent to the service. auto uses the service’s limit, or 983040 when the service has not said. A number from 65536 to 10485760 is capped to the service’s enforced limit, otherwise to the limit it advertises, and used as entered only when the service has not said. The stored photo is not changed.', []],
        'autofill_token'   => ['autofill', 'Access token', 'secret', '', '', []],
        // security (own form)
        'auth_enabled'     => ['security', 'Require sign-in', 'internal', 'cfg_auth', '', []],
        'auth_guest_disabled' => ['security', 'Disable guest browsing', 'bool', true, 'On: visitors must sign in before they see anything. Off: they can look around with the permissions of the Guest role.', []],
        'auth_register_disabled' => ['security', 'Disable registrations', 'bool', true, 'Off: anyone who can reach this address can create an account at /register (limited to 5 per hour per address).', []],
        'auth_register_role' => ['security', 'Role for new registrations', 'role', 'viewer', 'Admin cannot be chosen.', []],
        'auth_register_approval' => ['security', 'Registrations need an admin’s approval', 'bool', false, 'New accounts can only sign in once an admin approves them under Users.', []],
        'session_days' => ['security', 'Stay signed in for (days)', 'int', 30, 'The longest a sign-in lasts, counted from sign-in. Later visits do not extend it. The server ends it when this time has passed.', ['min' => 1, 'max' => 365]],
        'session_idle_hours' => ['security', 'Sign out after idle (hours)', 'int', 168, 'End a sign-in after this many hours with no request. 1–8760 (365 days). The stay-signed-in limit above still applies.', ['min' => 1, 'max' => 8760]],
        'trust_proxy' => ['security', 'Trust the reverse proxy', 'bool', false, 'Turn on only when the app sits behind your own HTTPS proxy (nginx, Caddy, Traefik) that sets X-Forwarded-Proto. Then sign-in cookies are marked Secure. Leave off when the app is reachable directly.', []],
        'import_create_missing' => ['data', 'CSV import creates missing entries', 'bool', true, 'Pre-ticks “Create missing locations, categories and tags” on the import screen.', []],
        'password_hash'    => ['security', 'Password (before accounts; moved to the user “admin” by migration 0018)', 'secret', 'cfg_hash', '', []],
    ];

    /** @param array<string,string> $accountRoles role key => name; admin is never a valid registration role */
    public function __construct(private readonly array $accountRoles = self::BUILTIN_ACCOUNT_ROLES)
    {
    }

    /**
     * The same schema, validating `role` settings against these account roles.
     *
     * @param array<string,string> $accountRoles
     */
    public function withAccountRoles(array $accountRoles): self
    {
        return new self($accountRoles);
    }

    /** @return array<string,array{0:string,1:string,2:string,3:mixed,4:string,5:array<string,mixed>}> */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public function has(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** The section a key belongs to (legacy settings_schema()[$key][0]). */
    public function section(string $key): ?string
    {
        return self::DEFINITIONS[$key][0] ?? null;
    }

    public function type(string $key): ?string
    {
        return self::DEFINITIONS[$key][2] ?? null;
    }

    /** Secret or internal: not on the generic forms, not exported, not imported, not settable from the CLI. */
    public function isProtected(string $key): bool
    {
        return in_array($this->type($key), self::PROTECTED_TYPES, true);
    }

    public function isSecret(string $key): bool
    {
        return $this->type($key) === 'secret';
    }

    /**
     * Keys of a section that the generic form edits (legacy settings_section_keys()).
     *
     * @return list<string>
     */
    public function sectionKeys(string $section): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $def) {
            if ($def[0] === $section && !in_array($def[2], self::PROTECTED_TYPES, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * The default of a key (legacy setting_default()). A "cfg:" default reads
     * the config path and validates it; an invalid config value gives null.
     *
     * @param array<string,mixed>|Closure():array<string,mixed> $config config values, or a closure
     *        that returns them (only called for config-backed defaults)
     */
    public function default(string $key, array|Closure $config): mixed
    {
        $d = self::DEFINITIONS[$key][3] ?? null;
        if (is_string($d) && str_starts_with($d, 'cfg:')) {
            $v = $config instanceof Closure ? $config() : $config;
            foreach (explode('.', substr($d, 4)) as $k) {
                $v = is_array($v) ? ($v[$k] ?? null) : null;
            }
            try {
                return $this->validate($key, $v);
            } catch (UserError) {
                return null;
            }
        }
        if ($d === 'cfg_auth' || $d === 'cfg_hash') {
            $cfg = $config instanceof Closure ? $config() : $config;
            $password = (string)($cfg['app_password'] ?? '');
            if ($d === 'cfg_auth') {
                return $password !== '';
            }

            return $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : '';
        }

        return $d;
    }

    /**
     * Validate and type a raw value from a form, a JSON import, the CLI or the
     * database (legacy setting_validate()). Throws UserError with the legacy message.
     */
    public function validate(string $key, mixed $raw): mixed
    {
        $def = $this->definitions()[$key] ?? null;
        if ($def === null) {
            throw new UserError("Unknown setting “{$key}”.");
        }
        [, $label, $type, , , $o] = $def;
        $str = is_scalar($raw) || $raw === null ? trim((string)$raw) : null;

        return match ($type) {
            'bool', 'internal' => $this->boolOrInternal($key, $type, $label, $raw, $str),
            'secret' => is_string($raw) ? $raw : throw new UserError("$label: invalid."),
            'int' => $this->int($label, $str, $o),
            'af_model' => $this->maxLine($label, $str, $o),
            'af_size' => $this->afSize($label, $str, $o),
            'af_bytes' => $this->afBytes($label, $str),
            'role' => $str !== null && isset($this->accountRoles[$str]) && $str !== 'admin'
                ? $str : throw new UserError("$label: choose a role other than admin."),
            'enum' => $str !== null && array_key_exists($str, $o['options'])
                ? $str : throw new UserError("$label: choose one of the options."),
            'color' => $this->color($label, $str),
            'timezone' => $str !== null && (in_array($str, DateTimeZone::listIdentifiers(), true) || $str === 'UTC')
                ? $str : throw new UserError("$label: unknown time zone."),
            'url' => $this->url($label, $str),
            'tags' => $this->tags($label, $str, $o),
            default => $this->string($label, $str, $o),
        };
    }

    private function boolOrInternal(string $key, string $type, string $label, mixed $raw, ?string $str): mixed
    {
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
    }

    /** @param array<string,mixed> $o */
    private function int(string $label, ?string $str, array $o): ?int
    {
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
    }

    /** @param array<string,mixed> $o */
    private function maxLine(string $label, ?string $str, array $o): string
    {
        if ($str === null) {
            throw new UserError("$label: invalid.");
        }
        $str = Text::cleanLine($str, 1000);
        if (mb_strlen($str) > $o['max']) {
            throw new UserError("$label: at most {$o['max']} characters.");
        }

        return $str;
    }

    /** @param array<string,mixed> $o */
    private function afSize(string $label, ?string $str, array $o): int
    {
        if ($str === '' || $str === null || $str === '0') {
            return 0;
        }
        if (!preg_match('/^\d{1,5}$/D', $str) || (int)$str < self::AUTOFILL_SIDE_MIN || (int)$str > $o['max']) {
            throw new UserError("$label: between " . self::AUTOFILL_SIDE_MIN . " and {$o['max']} px.");
        }

        return (int)$str;
    }

    /** 'auto', or an explicit byte cap (v2.6.0). No environment override. */
    private function afBytes(string $label, ?string $str): int|string
    {
        $message = "$label: auto, or a whole number of bytes from " . self::AUTOFILL_IMAGE_BYTES_MIN . ' to ' . self::AUTOFILL_IMAGE_BYTES_MAX . '.';
        if ($str === null) {
            throw new UserError($message);
        }
        if ($str === '' || strcasecmp($str, 'auto') === 0) {
            return 'auto';
        }
        if (!preg_match('/^\d{1,8}$/D', $str)) {
            throw new UserError($message);
        }
        $n = (int)$str;
        if ($n < self::AUTOFILL_IMAGE_BYTES_MIN || $n > self::AUTOFILL_IMAGE_BYTES_MAX) {
            throw new UserError($message);
        }

        return $n;
    }

    private function color(string $label, ?string $str): string
    {
        if ($str === null || !preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/iD', $str, $m)) {
            throw new UserError("$label: a colour like #C8502D.");
        }
        $h = $m[1];
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }

        return '#' . strtolower($h) === strtolower(self::ACCENT_DEFAULT) ? self::ACCENT_DEFAULT : '#' . strtoupper($h);
    }

    private function url(string $label, ?string $str): string
    {
        if ($str === null) {
            throw new UserError("$label: invalid.");
        }
        $str = rtrim($str, '/');
        // Schemes are case-insensitive (RFC 3986): HTTP:// is stored as http://.
        $str = (string)preg_replace_callback('#^(https?)://#i', static fn (array $m): string => strtolower($m[1]) . '://', $str);
        if ($str !== '' && (!preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+(/[^\s]*)?$#D', $str) || strlen($str) > 200)) {
            throw new UserError("$label: a full address starting with http:// or https://.");
        }

        return $str;
    }

    /** @param array<string,mixed> $o */
    private function tags(string $label, ?string $str, array $o): string
    {
        if ($str === null) {
            throw new UserError("$label: invalid.");
        }
        if (mb_strlen($str) > $o['max']) {
            throw new UserError("$label: at most {$o['max']} characters.");
        }

        return implode(', ', Text::tags($str));
    }

    /** @param array<string,mixed> $o */
    private function string(string $label, ?string $str, array $o): string
    {
        if ($str === null) {
            throw new UserError("$label: invalid.");
        }
        $str = Text::cleanLine($str, 1000);
        if (mb_strlen($str) > $o['max']) {
            throw new UserError("$label: at most {$o['max']} characters.");
        }
        if (!empty($o['required']) && $str === '') {
            throw new UserError("$label cannot be empty.");
        }

        return $str;
    }
}
