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

* An **Overview** panel at the top of the list shows totals (items, total quantity, locations, categories, added this week/month), a chart of items added (30 days by day, or 12 months by month), and the top 5 locations and top 5 categories as bars. Tap a bar to filter the list. It is collapsible, and your choice is remembered. While collapsed, the charts are neither computed nor downloaded; they load the first time you open the panel.

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

### Options (environment variables)

| Variable       | Default         | Meaning |
|----------------|-----------------|---------|
| `APP_PASSWORD` | *(empty)*       | If set, a single password is needed to open the app. Empty means no login (fine on a trusted LAN). |
| `TZ`           | `Europe/Athens` | Time zone used to show created/updated times. |
| `DATA_DIR`     | `/data`         | Where the database and images live inside the container. |

Put these in a `.env` file next to `docker-compose.yml`, for example:

```
APP_PASSWORD=change-me
TZ=Europe/Athens
```

then run `docker compose up -d` again. To use a different port, change `"8080:80"` in `docker-compose.yml`.

### Update

Replace the app files (keep `data/`), then `docker compose up -d --build`.

## iPhone camera notes

* The **Take photo** button uses `<input type="file" accept="image/*" capture="environment">`, which opens the rear camera directly. **Library** lets you pick an existing photo.
* This **works over plain `http://`** on your LAN. HTTPS is not needed for file-input camera capture, unlike the live-camera `getUserMedia` API, which this app does not use.
* HEIC photos: iOS hands Safari a JPEG when uploading through a file input. If any image still arrives in a format the browser can decode, it is converted to JPEG on the device before upload anyway.

## Tests

```bash
tests/run.sh   # lint (php -l, node --check, shellcheck) + integration tests (no Composer)
```

Optional headless mobile checks (filter sheet, theme switch, overflow at 375 and 390 px wide) need Node and Chrome:

```bash
npm ci --prefix tests/e2e
php -S 127.0.0.1:8080 -t public public/index.php &   # with DATA_DIR pointing at a scratch dir
php tests/e2e/seed.php http://127.0.0.1:8080
node tests/e2e/mobile.mjs http://127.0.0.1:8080
```

## Run without Docker (development)

Needs PHP 8.1+ with `pdo_sqlite`, `gd` (and ideally `exif`, `mbstring`):

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
Dockerfile              php:8.3-apache + gd, exif, opcache (pdo_sqlite built in)
docker-compose.yml      port 8080, ./data volume
docker/apache.conf      vhost: docroot public/, FallbackResource router, caching
docker/php.ini          upload limits (20M/22M), opcache
docker/entrypoint.sh    creates data dirs and fixes ownership for www-data
public/index.php        front controller / routes
public/assets/          app.css, app.js, icons
public/manifest.webmanifest
src/bootstrap.php       config, SQLite (auto schema + migrations), sessions, auth, CSRF, helpers
src/items.php           item, location and category queries, search, statistics, image processing (GD)
src/views.php           HTML templates
tests/run.sh            lint + integration tests
tests/integration.php   end-to-end tests (fresh install, items, locations, categories, auth, migrations)
tests/lib.php           tiny test harness (built-in server, HTTP client, assertions)
```

## Development

Run `tests/run.sh` (lint + integration tests, no Composer needed). See [CONTRIBUTING.md](CONTRIBUTING.md) for the commit message format and CI details.

## License

[MIT](LICENSE) © 2026 Stathis Papandreou
