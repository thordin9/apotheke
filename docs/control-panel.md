# Control Panel

The Control Panel (**☰ → Settings**, `/settings`) is where everything that is not needed to *open* the database is
configured: name, colours, logo, formats, list behaviour, defaults for new items, limits, photo sizes and the password.
Values are stored in the database (`settings` table), take effect on the next request and travel with backups.

![Control Panel](screenshots/settings-light.png)

## Where a setting comes from

| Kind | Examples | Lives in | Changed with |
|---|---|---|---|
| **Bootstrap** | `DATA_DIR`, `DB_DRIVER`, `DB_HOST` … `DB_PASSWORD`, `DB_SCHEMA`, `SQLITE_PATH`, `SEED_DEFAULTS`, `APOTHEKE_CONFIG` | `config.php` / environment | editing the file or the environment, then restarting. Shown read-only under **Settings → System** (the DB password only as "set"). |
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
| **List** | Items per page (1–500), default sort, view (photo cards or compact rows), whether the Overview starts open |
| **New item defaults** | Quantity, running-low threshold, tags, notes, purchase date = today, expiry *N* months ahead, default location and category (the same ★ as on the Locations/Categories pages) |
| **Thresholds & limits** | *Expiring soon* window (days), default loan period (pre-fills *Due back*), max saved filters, max photos per item |
| **Photos & uploads** | Upload limit per photo (MB), stored photo size, thumbnail size, JPEG quality (thumbnails use quality − 6) |
| **Password** | Turn the shared password on/off, change it (min. 8 characters; the current password is required) |
| **Backup & data** | Backup / restore, CSV export, settings export / import (JSON) |
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

* **SVG** (max. 2 MB, needs a `viewBox` or width/height): cleaned on upload — scripts, event handlers, `foreignObject`,
  external references (`href`/`url()` to anything but `#id`), entities and DOCTYPEs are removed or rejected. It is used for
  the header, sign-in page and `favicon.svg`; served with `Content-Security-Policy: default-src 'none'; sandbox`. GD
  cannot rasterise SVG, so the PNG home-screen icons and `favicon.ico` stay the Apotheke defaults — upload a PNG if you
  want those to change too.
* **PNG, JPEG, WebP** (64–4096 px, max. 2 MB): squared on the icon background and stored as `logo.png` (512 px),
  `icon-32/180/192/512.png`, a maskable `icon-maskable-512.png` (with safe-zone padding) and `favicon.ico`. The
  manifest (`/manifest.webmanifest`, now generated) and the apple-touch icon point at them.
* Files live in `DATA_DIR/brand/`. **Use the default logo** deletes them.

## Password

The password is stored as a `password_hash()` hash in the database. Changing it signs out every other device (sessions
carry a fingerprint of the hash); the device you changed it on stays signed in. Locked out? On the server:

```
php bin/settings.php password            # asks for a new password
php bin/settings.php password --off      # no password
```

(In Docker: `docker compose exec apotheke php bin/settings.php password`.)

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
