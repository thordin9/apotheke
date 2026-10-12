# Apotheke

[![CI](https://github.com/thordin9/apotheke/actions/workflows/ci.yml/badge.svg)](https://github.com/thordin9/apotheke/actions/workflows/ci.yml)

*The storehouse for your stuff.*

> **Apotheke** comes from the ancient Greek **ἀποθήκη** (*apothḗkē*), "storehouse": a place where things are put away for safekeeping. (It is also where the word for pharmacy comes from in many languages.)

A small, fast, self-hosted web app for cataloging your belongings: snap a photo on your
iPhone, name the item, pick where it lives ("box 1023") and what it is ("Tools"), set a quantity, done. Then find
anything again with instant search.

* Plain PHP 8 + SQLite + GD. No framework, no Composer, no build step, no CDNs: works fully offline on your LAN.
* Mobile-first UI: every function works one-handed on a phone. 44 px+ tap targets, a bottom sheet for filters and sorting with removable filter chips, sticky Save/Edit bars within thumb reach, large +/- quantity controls, safe-area support and "Add to Home Screen".
* **Dark mode** with an explicit **Auto / Light / Dark** switch in the ☰ menu. Your choice is saved (cookie + localStorage) and rendered by the server, so pages never flash the wrong theme. Auto follows your phone's setting.
* Photos are resized in the browser before upload (max 1600 px, JPEG ~80%), so a 4 MB iPhone photo becomes about 150–300 KB and uploads instantly. The server checks and re-encodes every image with GD (which also strips EXIF/GPS data) and makes a 480 px thumbnail for the grid.
* Instant search across name, location, category and notes (ignores case and accents, including Greek). Filter by location and/or category (including "No location" / "No category"), sort by recent, name, location, category or quantity, use quick +/- quantity buttons, add/edit/replace photo/delete, and export to CSV.
* **Locations** and **categories** are managed lists that work the same way. You pick from a dropdown (sorted naturally, so box 2 comes before box 10), can add a new entry right inside the item form, and can mark one entry of each as the **default** for new items.

* **Multiple photos per item** (up to 12): add from camera or library, reorder, pick a cover, browse with arrows.
* **QR labels** for locations and items: print, stick on the box, scan to open the list.
* An **Overview** panel at the top of the list shows totals (items, total quantity, locations, categories, added this week/month), a chart of items added (30 days by day, or 12 months by month), and the top 5 locations and top 5 categories as bars. Tap a bar to filter the list. It is collapsible, and your choice is remembered. While collapsed, the charts are neither computed nor downloaded; they load the first time you open the panel.

## QR labels

Stick a QR label on every box. **☰ → Print QR labels** (or *Manage → Locations*) prints a sheet with one label per location.
Each label opens that location's item list when you scan it with the iPhone camera. A location can also print *one label per item*,
and every item page has a **QR label** button that links straight to the item. Codes are generated on the server in pure PHP
(`src/qr.php`, no network, no libraries) as crisp SVG, and the page has a print stylesheet (3 labels per row on A4/Letter).
Set `PUBLIC_URL` if the address printed on the labels should differ from the one you're using right now.

## Photos

Every item can have up to 12 photos. The first one is the **cover**, which the list shows. On the item page, use the ‹ › arrows,
the thumbnail strip or the ← → keys to page through the gallery. All of these are plain links, so the gallery also works without JavaScript.
Open **Photos** below the details to add more pictures (camera or library; they are shrunk on the phone before upload),
move photos left or right, make one the cover (★) or delete it. If you upgrade from an older version, the single photo
each item had becomes its cover (migration `0003`).

## Screenshots

| List & overview | Filter sheet | Menu & theme | Dark mode |
|---|---|---|---|
| ![List](docs/screenshots/list-light.png) | ![Filter sheet](docs/screenshots/filters-light.png) | ![Menu](docs/screenshots/menu-light.png) | ![Dark list](docs/screenshots/list-dark.png) |

| Add item | Item (dark) | Manage (dark) | Desktop |
|---|---|---|---|
| ![New item](docs/screenshots/new-light.png) | ![Item](docs/screenshots/item-dark.png) | ![Manage](docs/screenshots/manage-dark.png) | ![Desktop](docs/screenshots/desktop-light.png) |

## Locations & categories

Locations and categories behave identically:


* **In the item form:** pick a location and a category from the two dropdowns. To add one without leaving the form, tap the **＋** next to it (or choose "＋ New location…" / "＋ New category…"), type the name and tap **Add**. If you type a name and just tap Save, it is created as well. Names are unique regardless of case or accents ("Box 5" = "box 5").
* **Save & new** keeps both the location and the category you chose, which is handy when you're emptying one box.
* **Manage page** (the sliders icon in the header, a "Manage" link in the form, or the footer links): it has two tabs, **Locations** and **Categories**. Each shows every entry with its item count, and you can add, rename (tap *Edit*), and delete. Tap the ★ to make an entry the default, and tap it again to clear it. There is at most one default per tab, and it is preselected on new items.
* **Deleting an entry that still has items** opens a confirmation page where you choose to **move the items to another location/category** or **leave them unassigned**. Items are never deleted along with a location or category.

## Run it (Docker)

On the LAN server:

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
docker compose up -d --build
```

Then on your iPhone (same Wi-Fi) open **http://SERVER-IP:8080**. To find the server IP, run `hostname -I` on the server.

**Add to Home Screen:** in Safari tap Share, then **Add to Home Screen**. It opens full-screen like an app.

Everything is stored in `./data` next to `docker-compose.yml`:

```
data/
  catalog.sqlite      # the database (created automatically on first run)
  images/             # full-size photos (≤1600 px JPEG, random file names)
  thumbs/             # 480 px thumbnails
  sessions/           # login sessions
```

**Upgrading from an older version:** on first start the app upgrades the database automatically.

* From the very first version (free-text locations), the distinct location texts become managed locations (trimmed and de-duplicated ignoring case and accents), and every item is linked to its location.
* From the locations version, a `categories` table is added. Existing items start without a category.

Before upgrading, the app saves a snapshot `data/catalog-v<old version>-backup-<date>.sqlite`. The upgrade runs as a single transaction, so if anything fails, nothing changes. Once everything looks right, you can delete the snapshot.

**Backup:** copy the `data/` folder. For a perfectly consistent copy while the app is running, use
`sqlite3 data/catalog.sqlite ".backup backup.sqlite"` or stop the container first.

### Configuration

Settings come from three layers (later wins): built-in defaults → `config.php` → environment variables.
For Docker, environment variables (or a `.env` file next to `docker-compose.yml`) are the easy way. Without Docker, copy
[`config.example.php`](config.example.php) to `config.php` (git-ignored) and edit it. Empty environment variables are ignored,
so they never wipe a value set in `config.php`.

| Variable | Default | Meaning |
|---|---|---|
| `APP_PASSWORD` | *(empty)* | If set, a single password is needed to open the app. Empty means no login (fine on a trusted LAN). |
| `TZ` | `UTC` (`Europe/Athens` in compose) | Time zone for dates and statistics. |
| `DATA_DIR` | `./data` (`/data` in the container) | Photos, thumbnails, sessions and the SQLite file. |
| `DB_DRIVER` | `sqlite` | `sqlite` or `pgsql`. |
| `SQLITE_PATH` | `<DATA_DIR>/catalog.sqlite` | SQLite database file. |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | `127.0.0.1` / `5432` / `apotheke` / `apotheke` / *(empty)* | PostgreSQL connection. |
| `DB_SCHEMA` | *(empty)* | Optional PostgreSQL schema (created if missing). |
| `DB_DSN` | *(empty)* | Full PDO DSN; overrides host/port/name. |
| `MAX_UPLOAD_MB`, `IMAGE_FULL_MAX`, `IMAGE_THUMB_MAX` | `20`, `1600`, `480` | Upload limit (MB) and stored photo/thumbnail size (px). |
| `PAGE_SIZE` | `60` | Items per page. |
| `PUBLIC_URL` | *(from the request)* | Base URL encoded in QR labels, e.g. `http://192.168.1.10:8080`. Set it if you open the app under a different name than your phone will. |
| `SEED_DEFAULTS` | `true` | Create starter locations and categories on a brand-new database. |
| `APOTHEKE_CONFIG` | `./config.php` | Path to an alternative config file. |

Example `.env`:

```
APP_PASSWORD=change-me
TZ=Europe/Athens
```

then run `docker compose up -d` again. To use a different port, change `"8080:80"` in `docker-compose.yml`.

### PostgreSQL (optional)

SQLite is the default and needs nothing extra. To use PostgreSQL with the bundled container, put this in `.env`:

```
DB_DRIVER=pgsql
DB_PASSWORD=a-strong-password
```

and start with the `postgres` profile:

```bash
docker compose --profile postgres up -d --build
```

Its data lives in `./data/postgres`. To use an existing server instead, set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD`, and leave the profile off.
Photos always stay in `DATA_DIR`, whichever database you use.

### Database migrations & seed data

The schema is versioned: numbered files in [`migrations/`](migrations), recorded in a `schema_migrations` table.
Pending migrations run **automatically on the first request** (in one transaction, under a lock), or explicitly:

```bash
php bin/migrate.php            # apply pending migrations
php bin/migrate.php --status   # show applied / pending
docker compose exec apotheke php /var/www/app/bin/migrate.php --status
```

**Upgrading from an older version:** an existing SQLite database (schema v1, v2 or v3) is first copied to
`data/catalog-vN-backup-<date>.sqlite`, then converted and adopted into the migration system. No data is lost, and if any step
fails, the whole upgrade is rolled back.

On a **brand-new** database, Apotheke adds a starter set of locations (Box 1 ★, Living room, Kitchen, Garage, Basement, Attic) and
categories (General ★, Tools, Electronics, Kitchen, Documents, Hobby). Turn this off with `SEED_DEFAULTS=false`. Rename or delete
any of them freely, since seeding only ever runs once. Seeding is idempotent and can be run by hand:

```bash
php bin/seed.php          # starter locations/categories (skips names that already exist)
php bin/seed.php --demo   # plus a few demo items with generated photos
```

### Update

Replace the app files (keep `data/`), then `docker compose up -d --build`.

## iPhone camera notes

* The **Take photo** button uses `<input type="file" accept="image/*" capture="environment">`, which opens the rear camera directly. **Library** lets you pick an existing photo.
* This **works over plain `http://`** on your LAN. HTTPS is not needed for file-input camera capture, unlike the live-camera `getUserMedia` API, which this app does not use.
* HEIC photos: iOS hands Safari a JPEG when uploading through a file input. If any image still arrives in a format the browser can decode, it is converted to JPEG on the device before upload anyway.

## Tests

Two layers, both runnable without Docker:

```bash
tests/run.sh                    # lint (php -l, node --check, shellcheck) + HTTP integration suite on SQLite. No Composer needed
composer install                # dev only: PHPUnit
vendor/bin/phpunit              # unit tests (config, migration runner, repositories, search normalisation) + HTTP suite
DB_DRIVER=pgsql TEST_PG_PASSWORD=… vendor/bin/phpunit   # same against PostgreSQL (TEST_PG_HOST/PORT/DB/USER, defaults 127.0.0.1/5432/apotheke/apotheke)
```

On PostgreSQL, every test uses its own throwaway schema. CI runs the whole matrix (SQLite + PostgreSQL service container), uploads
a coverage report artifact, builds the Docker image and smoke-tests it against both databases.

Optional headless mobile checks (filter sheet, theme switch, overflow at 375 and 390 px wide) need Node and Chrome:

```bash
npm ci --prefix tests/e2e
php -S 127.0.0.1:8080 -t public public/index.php &   # with DATA_DIR pointing at a scratch dir
php tests/e2e/seed.php http://127.0.0.1:8080
node tests/e2e/mobile.mjs http://127.0.0.1:8080
```

## Run without Docker (development)

Needs PHP 8.2+ with `gd`, `mbstring` and `pdo_sqlite` or `pdo_pgsql` (ideally also `exif`). Optionally `cp config.example.php config.php` and edit it.

```bash
php -d upload_max_filesize=20M -d post_max_size=22M -S 0.0.0.0:8080 -t public public/index.php
```

Data goes to `./data` (or set `DATA_DIR=/some/path`).

## Security notes

* All SQL uses prepared statements. All output is HTML-escaped. A strict Content-Security-Policy is set.
* Every change (save, quantity, delete, login/logout) requires a CSRF token.
* Uploads: up to 20 MB, validated with `getimagesize` and decoded and re-encoded by GD, then stored under random hex names **outside the web root**. They are served only through a strict `[a-f0-9]{32}.jpg` route, so nothing uploaded can ever be executed.
* Apache only runs `index.php`. `src/` and `data/` are not reachable over HTTP.
* Meant for a trusted LAN. If you expose it to the internet, put it behind HTTPS and set `APP_PASSWORD`.

## Files

```
Dockerfile                 php:8.3-apache + gd, exif, opcache, pdo_pgsql (pdo_sqlite built in)
docker-compose.yml         port 8080, ./data volume, optional "postgres" profile
config.example.php         all settings (copy to config.php; env vars override)
bin/migrate.php            run / inspect migrations
bin/seed.php               starter data (+ --demo items)
migrations/NNNN_*.php      versioned schema steps (SQL per dialect, or a PHP closure)
docker/                    apache vhost, php.ini, entrypoint
public/index.php           front controller / routes
public/assets/             app.css, app.js, theme.js, icons
src/config.php             config loader (defaults < config.php < env)
src/db.php                 PDO connection (SQLite/PostgreSQL), dialect helpers, migration runner
src/legacy.php             one-time upgrade of pre-migration SQLite installs (v1–v3)
src/qr.php                 pure-PHP QR code encoder (SVG) for labels
src/seed.php               idempotent starter/demo seeding
src/bootstrap.php          sessions, auth, CSRF, helpers
src/items.php              item, location and category queries, search, statistics, image processing (GD)
src/views.php              HTML templates
tests/run.sh               lint + HTTP integration suite (no Composer)
tests/integration.php      end-to-end HTTP tests (SQLite or PostgreSQL via TEST_DB_DRIVER)
tests/Unit/, Integration/  PHPUnit suites
tests/e2e/                 headless mobile checks (puppeteer-core, dev only)
```

## Development

Run `tests/run.sh` (lint + integration tests, no Composer needed). See [CONTRIBUTING.md](CONTRIBUTING.md) for the commit message format and CI details.

## License

[MIT](LICENSE) © 2026 Stathis Papandreou
