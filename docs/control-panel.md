# Control Panel

The Control Panel (**☰ → Settings**, `/settings`) is where everything that is not needed to *open* the database is
configured: name, colours, logo, formats, list behaviour, defaults for new items, limits, photo sizes, users and sign-in.
Values are stored in the database (`settings` table), take effect on the next request and travel with backups.

![Control Panel](screenshots/settings-light.png)

## Where a setting comes from

| Kind | Examples | Lives in | Changed with |
|---|---|---|---|
| **Bootstrap** | `DATA_DIR`, `DB_DRIVER`, `DB_HOST` … `DB_PASSWORD`, `DB_SCHEMA`, `SQLITE_PATH`, `SEED_DEFAULTS`, `APOTHEKE_CONFIG`, `TRUSTED_PROXIES` | `config.php` / environment | editing the file or the environment, then restarting. Shown read-only under **Settings → System** (the DB password only as "set"; a DSN is shown as host, port and database name). |
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
| **AI autofill** | Suggestions from a photo by your own autofill service: on/off, *Analyze photos automatically* (off by default), service address, timeout, model and image size (shown when the service offers them), maximum image size in bytes (default `auto`), write-only access token, Test connection / Test with a photo. See [AI autofill](autofill.md) |
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
| `session_days` | 30 | longest a sign-in lasts, counted from sign-in. Later visits do not extend it. The server ends it when this time has passed. 1–365. A session from before the upgrade is stamped on its next request |
| `session_idle_hours` | 168 | end a sign-in after this many hours with no request. 1–8760 (365 days). Lowering it applies on the next request. The clock moves at most once a minute. The favicon, the manifest, the theme and label styles, and the health checks do not count. The stay-signed-in limit still applies |

Lockout prevention: the last active admin can't be demoted or deleted, a role can't lose *Users* if nobody else could
manage users, and pending admins don't count. `auth_attempts` rate-limits sign-in (10 tries / 15 min for one account
from one client, and 30 / 15 min for all accounts from one address; an IPv6 client counts by its /64) and
registration (5 / hour per address). A successful sign-in clears its account's count, not the address's. A
current-password check (Your account, Users & sign-in), and the admin password that turns sign-in back on, count as
a sign-in try for that account and toward the address cap. Everyone behind one address shares the address cap, so list a reverse proxy in
`TRUSTED_PROXIES`. One client's
attempts against one account do not use up the limit for anyone else. How the client address is found behind a proxy (`trust_proxy`, `TRUSTED_PROXIES`):
[Deployment](deployment-and-operations.md#behind-a-web-server-or-reverse-proxy).

Accounts with the *Users* permission see this section. Rules: user names are 1–40 letters, digits and `. _ @ -`
(case-insensitive, unique); passwords 8–200 characters without a null character (only the first 72 bytes count: bcrypt ignores the rest); the last admin can't be demoted or deleted; you can't
delete yourself. A new password signs that user out on every device (each account has a session epoch; a session
is valid only while it holds the current one). Changing your own password keeps this device signed in.
**Sign out everywhere** on Your account (☰ → your name) does the same for the other sessions and keeps this
device. The command line ends every session of that account, including this one. **Only an admin account can grant the admin role, turn sign-in
off, or change an existing admin account** (its role, name, password, approval, or deletion). The *Users* permission
on its own is not enough for any of those. Setting a password signs that account out everywhere, so a non-admin
cannot do it to an admin. An account with Users can still change other accounts, roles, and sign-in options. Turning sign-in off keeps the accounts.
Accounts, roles and permissions are **not** part of backups or the settings export. A zip that names the `users` or
`roles` table is refused, so a restore never replaces them.

Locked out? On the server:

```
php bin/settings.php users                          # accounts, roles, last sign-in
php bin/settings.php roles                          # roles and their permissions · roles reset (restore built-ins)
php bin/settings.php user role NAME admin           # make someone an admin · user approve NAME
php bin/settings.php user password admin            # new password (asks twice; or pass it as the next argument)
php bin/settings.php user signout NAME              # end every session of that account
php bin/settings.php user add anna editor           # roles: admin, editor, viewer
php bin/settings.php user role anna admin           # · user rename OLD NEW · user delete NAME
php bin/settings.php auth off                       # no sign-in (auth on: back on, needs an admin)
php bin/settings.php password                       # shortcut: password of "admin" (created if missing) + sign-in on
```

(In Docker: `docker compose exec apotheke php bin/settings.php users`.)

## Import / export

**Settings → Backup & data → Export settings** downloads `apotheke-settings-YYYY-MM-DD.json`:

```json
{ "format": "apotheke-settings", "version": 1, "exported_at": "…", "settings": { "app_name": "Apotheke", "accent": "#C8502D", "…": "…" } }
```

Import validates every value: valid ones are applied, invalid ones are listed and skipped, unknown keys and the
password are ignored. Sign-in settings (`auth_guest_disabled`, `auth_register_disabled`, `auth_register_role`,
`auth_register_approval`, `session_days`, `session_idle_hours`, `trust_proxy`) are applied only for an admin account. Without that they
are skipped and named with the other protected keys; the rest of the file is still applied. `auth_enabled` is not
in the export, so a file cannot turn sign-in off. `php bin/settings.php import` applies the sign-in settings too
(the command runs on the server).

Full backups (`backup.zip`) also contain the settings (never the password) and a restore applies them with the same
rule: only an admin restore changes those sign-in settings. A Backup or Users account does not. The zip has no
accounts or roles; a file that adds a `users` or `roles` table is refused. Backups made before
this version restore without touching the settings.

## Command line

```
php bin/settings.php list | get KEY | set KEY VALUE | reset SECTION | export | import FILE | password [--off]
php bin/settings.php key generate | key rotate
```

`key generate` prints a new `APP_KEY` and stores nothing. `key rotate` re-encrypts stored secrets; the new key
comes from `APP_KEY_NEW` or stdin, never from the command arguments. See [Stored secrets](configuration.md#stored-secrets).

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
