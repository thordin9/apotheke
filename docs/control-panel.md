# Control Panel

The Control Panel (**☰ → Settings**, `/settings`) is where everything that is not needed to *open* the database is
configured: name, colours, logo, formats, list behaviour, defaults for new items, limits, photo sizes, users and sign-in.
Values are stored in the database (`settings` table), take effect on the next request and travel with backups.

![Control Panel](screenshots/settings-light.png)

## Where a setting comes from

| Kind | Examples | Lives in | Changed with |
|---|---|---|---|
| **Bootstrap** | `DATA_DIR`, `DB_DRIVER`, `DB_HOST` … `DB_PASSWORD`, `DB_SCHEMA`, `SQLITE_PATH`, `SEED_DEFAULTS`, `APOTHEKE_CONFIG` | `config.php` / environment | editing the file or the environment, then restarting. Shown read-only under **Settings → System** (the DB password only as "set"). A database setting that cannot be used stops the app. The page says the database settings could not be used. The error log lists the driver, host, port and database name, and does not include the user or the password. |
| **Everything else** | name, accent, currency, page size, time zone, upload limits, password … | database (`settings`) | the Control Panel, `bin/settings.php`, or importing a settings JSON |

The first time the `0013_settings` migration runs (a new install or an upgrade) it **copies the current `config.php` /
environment values into the database** — so an upgrade keeps what you had configured. From then on the database
wins: changing `CURRENCY`, `PAGE_SIZE`, `TZ`, `APP_PASSWORD` … in the environment has no effect on an existing
database. Use the panel (or `bin/settings.php`) instead.

## Sections

| Section | Settings |
|---|---|
| **General** | App name (title, header, manifest, backups' file name stays `apotheke-…`), tagline, public URL for QR labels |
| **Appearance** | Logo upload / reset, accent colour (picker, 9 presets incl. Terracotta, live light/dark preview with contrast), default theme (Auto/Light/Dark), icon background |
| **Currency, dates & time** | Currency label, number format (`1,234.56`, `1.234,56`, `1 234,56`, `1’234.56`, `1234.56`), currency before/after, date format, 24 h / 12 h time, time zone |
| **List** | Items per page (1–500), default sort, view (photo cards or compact rows), *Recently viewed* length (0–50, 0 = hide) and whether it is kept per device (cookie) or shared, typo tolerance in search, whether the Overview starts open |
| **New item defaults** | Quantity, running-low threshold, tags, notes, purchase date = today, expiry *N* months ahead, default location and category (the same ★ as on the Locations/Categories pages) |
| **QR labels** | Label width and height (mm), labels per row, gap (mm), print the subtitle / link line, dashed cut lines |
| **Thresholds & limits** | *Expiring soon* window (days), default loan period (pre-fills *Due back*), max saved filters, **undo window** (seconds, 0–3600, 0 = no undo), max photos per item |
| **Photos & uploads** | Upload limit per photo (MB), stored photo size, thumbnail size, JPEG quality (thumbnails use quality − 6); attachments: allowed types (PDF + images / PDF only / images only), max size per file (MB), max files per item |
| **AI autofill** | Suggestions from a photo by your own autofill service: on/off, *Analyze photos automatically* (off by default), service address, timeout, model and image size (shown when the service offers them), maximum image size in bytes (default `auto`; an explicit number is capped to `enforced_image_bytes`, otherwise `max_image_bytes`, and used as stored only when neither is known), write-only access token, Test connection / Test with a photo. See the [admin guide](autofill-admin-guide.md) |
| **Users & sign-in** | Turn sign-in on/off (the first time creates an admin account), change your password, add users with a role (admin / editor / viewer), change roles, rename, set passwords, delete accounts |
| **Backup & data** | Backup / restore, CSV export, CSV import (and whether it pre-ticks *create missing locations, categories and tags*), settings export / import (JSON) |
| **System** | Read-only bootstrap values, PHP version, schema version |

Every field shows its default; **Reset this section to defaults** puts a section back to the `config.php` / built-in
values (the password is never reset this way). Invalid input is rejected with a message next to the field and nothing in
that section is saved (all or nothing).

## Accent colour and contrast (WCAG AA)

The accent is used for buttons, links, chips and highlights. Apotheke checks it against the light backgrounds
(`#f5f5f7`, `#ffffff`) and the dark ones (`#111113`, `#1c1c20`) and **enforces 4.5 : 1 (WCAG AA)**: if a colour is too
light for light mode it is darkened just enough, if it is too dark for dark mode it is lightened, and the panel tells you
which shade is used (e.g. Terracotta `#C8502D` is 4.16 : 1 on the light page, so light mode uses `#bc4b2a`). Hover/pressed
shades, the text colour on accent buttons (white or near-black, whichever contrasts more), the soft tint and the shadow
are derived automatically.

The CSS is served by `/theme.css` (cache-busted with `?v=`), so the Content-Security-Policy stays `style-src 'self'`
without `unsafe-inline`. The default indigo needs no extra stylesheet.

## Logo

* **SVG** (max. 512 KB, needs a `viewBox` or width/height; requires the PHP `dom` extension, included in the Docker image): cleaned on upload — scripts, event handlers, `foreignObject`,
  external references (`href`/`url()` to anything but `#id`), entities and DOCTYPEs are removed or rejected. It is used for
  the header, sign-in page and `favicon.svg`; served with `Content-Security-Policy: default-src 'none'; sandbox`. GD
  cannot rasterise SVG, so the PNG home-screen icons and `favicon.ico` stay the Apotheke defaults — upload a PNG if you
  want those to change too.
* **PNG, JPEG, WebP** (64–4096 px, max. 2 MB): squared on the icon background and stored as `logo.png` (512 px),
  `icon-32/180/192/512.png`, a maskable `icon-maskable-512.png` (with safe-zone padding) and `favicon.ico`. The
  manifest (`/manifest.webmanifest`, now generated) and the apple-touch icon point at them.
* Files live in `DATA_DIR/brand/`. **Use the default logo** deletes them.

## Users & sign-in

Accounts live in the `users` table (migration 0018); passwords are `password_hash()` hashes. The setting
`auth_enabled` is the switch: off means no sign-in and every visitor is an admin; on means everybody signs in with a
user name and password and the **role** decides what they may do. Roles live in `roles` (migration 0022): a key, a
name and a JSON list of permissions from `PERMISSIONS` in `src/roles.php` (view, create, edit, delete, move, bulk, lend,
import, export, stocktake, labels, settings, users, backup). Built-in: admin (always everything; cannot be changed or
deleted), editor, viewer and guest (anonymous visitors while guest browsing is on; never settings/users/backup).
`route_perm()` names the permission every route needs, handlers check finer cases (`require_perm('move')` when the
location changes, `bulk_perm()` per bulk action); a forbidden page answers 403, a forbidden JSON request
`{"ok":false,"error":…}` with 403, an anonymous visitor is sent to `/login` (JSON: 401). `<body>` carries
`no-<permission>` classes so CSS hides what the role can't use.

| Setting | Default | |
|---|---|---|
| `auth_guest_disabled` | on | off: anonymous visitors browse with the Guest role |
| `auth_register_disabled` | on | off: public `/register` (CSRF + 5 per hour per address) |
| `auth_register_role` | viewer | role of self-registered accounts (any but admin) |
| `auth_register_approval` | off | new accounts are `pending` until an admin approves them |

Lockout prevention: the last active admin can't be demoted or deleted, a role can't lose *Users* if nobody else could
manage users, and pending admins don't count. `auth_attempts` rate-limits sign-in (10 / 15 min) and registration.

Only admins see this section. Rules: user names are 1–40 letters, digits and `. _ @ -` (case-insensitive, unique);
passwords at least 8 characters; the last admin can't be demoted or deleted; you can't delete yourself. A new password
signs that user out on every device (sessions carry a fingerprint of the hash); changing your own keeps this device
signed in. Turning sign-in off keeps the accounts. Accounts are **not** part of backups or the settings export, so a
restore never locks you out.

Locked out? On the server:

```
php bin/settings.php users                          # accounts, roles, last sign-in
php bin/settings.php roles                          # roles and their permissions · roles reset (restore built-ins)
php bin/settings.php user role NAME admin           # make someone an admin · user approve NAME
php bin/settings.php user password admin            # new password (asks twice; or pass it as the next argument)
php bin/settings.php user add anna editor           # roles: admin, editor, viewer
php bin/settings.php user role anna admin           # · user rename OLD NEW · user delete NAME
php bin/settings.php auth off                       # no sign-in (auth on: back on, needs an admin)
php bin/settings.php password                       # shortcut: password of "admin" (created if missing) + sign-in on
```

(In Docker: `docker compose exec apotheke php bin/settings.php users`.)

With `APOTHEKE_APP=ci4`, `bin/settings.php` passes `list`, `get`, `set`, `reset`, `export` and `import` to
`php ci4/spark apotheke:settings` (same output and exit codes: 1 with the message on stderr for a refused value, 2 for
an unknown command). The account and sign-in commands above still run on the legacy code until the CI4 Auth module lands.
Intentional differences from legacy, otherwise output and exit codes are byte-identical:

1. **Secrets are write-only.** `get autofill_token` and `get password_hash` print `“KEY” is write-only.` on stderr and exit 1; legacy printed the stored value.
2. **`get` of an unknown key** prints `Unknown setting “KEY”.` and exits 1; legacy stopped with an uncaught exception (PHP fatal, exit 255).
3. **`import` of a missing file** prints the same message and exits 1 like legacy, without PHP's `file_get_contents(…)` warning line.
4. **`list` is read-only.** It does not create the account `admin` from `APP_PASSWORD` (sign-in on, no accounts yet), so its `users` line can show 0 where legacy shows 1 until the Auth PR ports that bootstrap. The `sign-in` line is the same.

   For example, with sign-in on, no accounts and `APP_PASSWORD` set, legacy `list` ends with `users 1` (it has just
   created `admin`), CI4 ends with `users 0`. The first web request on legacy creates that account, and the Auth PR
   brings the same bootstrap to CI4.

To rotate the AI autofill token, set the new token on the service and enter it under **AI autofill → Access token**
(**Save token**, or **Clear token**). The CI4 CLI never reads or writes the token.

## Import / export

**Settings → Backup & data → Export settings** downloads `apotheke-settings-YYYY-MM-DD.json`:

```json
{ "format": "apotheke-settings", "version": 1, "exported_at": "…", "settings": { "app_name": "Apotheke", "accent": "#C8502D", "…": "…" } }
```

Import validates every value: valid ones are applied, invalid ones are listed and skipped, unknown keys and the
password are ignored. Full backups (`backup.zip`) also contain the settings (never the password) and a restore applies
them; backups made before this version restore without touching the settings.

## Command line

```
php bin/settings.php list | get KEY | set KEY VALUE | reset SECTION | export | import FILE | password [--off]
```

`set` validates exactly like the form.

## For developers

* Schema: `settings_schema()` in `src/settings.php` — key ⇒ section, label, type (`string`, `int`, `bool`, `enum`,
  `color`, `url`, `timezone`, `tags`, …), default (a literal or `cfg:<path>` = the config value), help text and options.
  Adding a setting = one line there plus using `setting('key')` where it matters; the form, validation, export/import,
  reset and seeding follow automatically. Add it to the integration test's `$secKeys`.
* `setting()` reads all rows once per request (cached); writes clear the cache. Without the table (before the migration)
  the defaults are used.
* Views: `src/settings_views.php`; routes: `/settings…`, `/theme.css`, `/manifest.webmanifest`, `/favicon.ico`,
  `/brand/{file}` in `public/index.php` (the last four are public, the sign-in page needs them).
