<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/apotheke-logo-dark.svg">
    <img src="docs/assets/apotheke-logo.svg" alt="Apotheke" width="330">
  </picture>
</p>

# Apotheke

[![CI](https://github.com/thordin9/apotheke/actions/workflows/ci.yml/badge.svg)](https://github.com/thordin9/apotheke/actions/workflows/ci.yml)

*The storehouse for your stuff.*

> **Apotheke** comes from the ancient Greek **ἀποθήκη** (*apothḗkē*), "storehouse": a place where things are put away
> for safekeeping. (It is also where the word for pharmacy comes from in many languages.)

A small, fast, self-hosted web app for cataloguing your belongings: snap a photo on your phone, name the item, pick
where it lives ("box 1023") and what it is ("Tools"), set a quantity, done. Then find anything again with instant search.

Plain PHP + PDO (SQLite or PostgreSQL) + GD, and Imagick in the Docker image so HEIC photos can be read. No framework, no runtime Composer packages, no JS build step, no CDNs:
it works fully offline on your LAN.

**Documentation:** the full manual lives in [`docs/`](docs/index.md): [getting started](docs/getting-started.md) ·
[configuration](docs/configuration.md) · [user guide](docs/user-guide.md) · [architecture](docs/architecture.md) ·
[database](docs/database.md) · [testing](docs/testing.md) · [CI](docs/ci.md) ·
[deployment and operations](docs/deployment-and-operations.md) · [AI autofill](docs/autofill.md) · [contributing](docs/contributing.md).
Agents: see [`AGENTS.md`](AGENTS.md).

## Contents

[Features](#features) · [Screenshots](#screenshots) · [Requirements](#requirements) · [Install with Docker](#install-with-docker) ·
[Install without Docker](#install-without-docker) · [Configuration](#configuration) · [Command-line tools](#command-line-tools) ·
[Upgrading](#upgrading) · [Testing](#testing) · [CI](#ci) · [Security notes](#security-notes) · [Project layout](#project-layout) ·
[Contributing](#contributing) · [License](#license) · [AGENTS.md](AGENTS.md) · [Full documentation](docs/index.md)

## Features

* **Mobile-first UI.** Every function works one-handed on a phone: 44 px+ tap targets, sticky Save/Edit bars within thumb
  reach, large +/- quantity controls, safe-area support and "Add to Home Screen" (opens full-screen like an app).
* **Locations and categories** are managed lists that behave identically. Pick from a dropdown (natural sort, so box 2
  comes before box 10), add a new entry right inside the item form (＋), and mark one of each as the **default** (★) for new
  items. Names are unique regardless of case or accents ("Box 5" = "box 5"). **Save & new** keeps the location and
  category you just used. The *Manage* page (Locations / Categories tabs) shows item counts and lets you add, rename,
  set/clear the default and delete; deleting an entry that still has items asks whether to **move them** elsewhere or
  **leave them unassigned**. Items are never deleted with a location or category.
* **Nested locations**: a location can sit inside another one (Garage › Shelf 2 › Box 1024), up to 6 levels below
  a top-level location. Create a location *inside* another on the Locations page, or move it with *Edit → Move*
  (cycles are refused). Lists and pickers show the tree indented, the item page shows the full path with a link
  per level, and filtering by a location (and its item count) includes everything in its sub-locations. Searching
  for a location name also finds the items in its sub-locations ("garage" finds the box on the garage shelf).
  Deleting a location moves its sub-locations up one level. Categories stay flat.
* **Overview stats** at the top of the list: items, total quantity, locations, categories, added this week/month, a chart of
  items added (30 days by day or 12 months by month) and the top 5 locations and categories as bars (locations are rolled up to the top-level
  location, counting everything inside it). Tap a bar to filter.
  The panel is collapsible and remembers its state; while collapsed the charts are neither computed nor downloaded.
* **Favourites and recently viewed**: star items on the card or item page, a ★ chip and filter show only favourites;
  the last opened items appear as a thumbnail strip above the list (per device or shared, length set in the Control Panel).
* **Undo**: after an edit, a delete or a bulk action a bar offers *Undo* for a configurable window (30 s by default).
  Deleting is a soft delete until then: undo restores the item with its id, photos, attachments and tags.
* **Notes and attachments**: notes support a safe Markdown subset (bold, italic, lists, headings, code, links — rendered
  on the server, everything else escaped). Attach receipts, manuals and warranty cards (PDF/JPEG/PNG/WebP; types, size and
  count limits in the Control Panel); they are downloaded, never rendered inline, and included in backups.
* **Control Panel** (☰ → *Settings*): name, accent colour with presets and an automatic WCAG AA contrast check for light
  and dark mode, your own logo (SVG cleaned on upload; PNG/JPEG/WebP also become the favicon and home-screen icons),
  currency and number/date/time formats, time zone, page size, default sort, a compact list view, defaults for new items,
  thresholds and limits, photo sizes, users and sign-in — stored in the database, exportable as JSON, resettable per
  section. Database and paths stay in `config.php` / env and are shown read-only. See [docs/control-panel.md](docs/control-panel.md).
* **AI autofill (optional)**: an [autofill service](https://github.com/thordin9/apotheke-autofill-service) suggests
  name, category, tags and notes from a photo. The service address is your Worker URL, for example
  `https://autofill.example` ([how to deploy the Worker](https://github.com/thordin9/apotheke-autofill-service/blob/main/docs/cloudflare-workers-deploy.md)).
  A service you run yourself uses the same API. Apotheke sends a small
  stripped JPEG, not the camera file. **On demand**: each photo on the item form has an *Analyze* button and
  nothing is sent until you tap it (auto-analyze is an admin option, off by default). Only empty fields are filled,
  never what you typed; one analysis at a time with a progress estimate. The admin picks the model and image size from
  what the service offers, and can cap the JPEG that is sent (`auto` follows the service; an explicit size is still capped to the service’s enforced limit, otherwise the limit it advertises). The camera file stays as
  uploaded. See the [admin guide](docs/autofill-admin-guide.md) and [docs/autofill.md](docs/autofill.md).
* **Dark mode** with an explicit **Auto / Light / Dark** switch in the ☰ menu. The choice is saved (cookie + localStorage)
  and rendered by the server, so pages never flash the wrong theme. Auto follows the phone's setting.
* **Full-text search**, instant, across name, notes, tags, serial number, barcode, borrower, category and the whole
  location path: ranked (name before tags before the rest), matches highlighted with a snippet of the field that
  matched, typo-tolerant ("screwdrivr"), ignoring case and accents (Greek included). SQLite FTS5 or PostgreSQL
  tsvector + pg_trgm, with a plain substring fallback when an extension is missing.
* **Filter sheet**: a bottom sheet to filter by location, category (including "No location" / "No category") and tag,
  and to sort by recent, name, location, category, quantity or value. Active filters show as removable chips with *Clear all*.
* **Multiple photos** per item (up to 12): take one with the camera or pick from the library, drag to reorder, choose the cover,
  crop and rotate in the browser (canvas, no libraries; saved as a new version with a fresh thumbnail), delete; browse with ‹ › arrows, thumbnails or the ← → keys (plain links, so it works without JavaScript too). The file you pick is
  uploaded unchanged when it fits the upload limit (20 MB by default). A larger file is reduced in the browser, and the page says the original is not kept. The server stores whatever it received byte for byte and serves a
  separate JPEG (EXIF/GPS stripped, longest side at most 1600 px) plus a 480 px thumbnail. Deleting or replacing a photo removes the camera file, the
  JPEG and the thumbnail.
* **Tags and custom fields**: any number of tags per item (Enter or comma to add, autocomplete, tag chips link to a filtered
  list), plus purchase price (in `CURRENCY`), purchase date and serial number. Unused tags are removed automatically.
* **QR labels** for locations and items: ☰ → *Print QR labels* prints one label per location (or one per item in a location;
  every item page has a *QR label* button). Scanning opens that list or item. Codes are generated on the server in pure PHP
  (`src/qr.php`, no network, no libraries) as SVG, with a print stylesheet. **Bulk printing**: pick items in selection
  mode → *Print QR labels*, or print a location together with all its sub-locations. Label width/height, labels per
  row, gap and printed lines are set in *Settings → QR labels* (delivered as a generated stylesheet, CSP-safe). Set the
  public URL if the address on the labels should differ from the one you are using right now.
* **Backup & restore**: ☰ → *Backup & restore* downloads one zip with a portable JSON copy of the database (`backup.json`:
  items, locations, categories, tags, photo list, history, settings) plus every served photo, thumbnail and camera file.
  Restoring puts the camera file and the stripped JPEG back. CSV export only names the served photo. Restoring shows a
  preview of the file next to your current data; nothing changes until you tick the box and press *Restore*, and it runs in
  one transaction. The format doesn't depend on the database, so SQLite ↔ PostgreSQL works both ways. Older zips without
  camera files still restore.
* **Activity history**: every change is logged in the same transaction as the change itself: created, edited (old → new
  for name, category, notes, value, purchase date, serial, tags, low-stock level), moved (including moves caused by deleting a location),
  quantity, photos added/reordered/deleted/new cover, lent/returned, deleted. The item page shows the latest ten entries with a link to
  the full history; ☰ → *Recent activity* lists all items, newest first. Entries of deleted items are kept.
* **Low-stock alerts**: give an item an optional *Running low at* level. When its quantity drops to that level or below,
  the card and the item page show a **Running low** badge (it updates live as you tap −/+), the overview has a *Running
  low* tile with the count (tap it to filter), and the filter sheet has a *Stock → Running low* filter (`/?stock=low`).
  Changes to the level are logged in the history; CSV and backups include it.
* **Bulk actions**: tap *Select* next to *Filter* to switch the list into selection mode. Tap photos (or the round
  checkbox) to pick items, *All on page* picks every loaded card, and the selection survives searching and filtering.
  *Actions* opens a sheet to **move** them to a location, **set the category**, **add tags** or **remove a tag**, or
  **delete** them after a confirmation page. Each action runs in one transaction, writes a history entry per item and is
  CSRF-protected (`POST /bulk`, at most 500 items at once). Works without JavaScript too (`/?select=1`, `&all=1`).
* **Purchase price and value totals**: an optional purchase price (decimal, `79,90` or `79.90`, in `CURRENCY`,
  default EUR) and purchase date per item. The overview's *Value* card shows the total purchase price and the
  totals per location (rolled up to top-level locations) and per category; *Sort by → Value* lists the most valuable
  items first (items without a price last). Prices are in the CSV export (`value`, `currency`) and backups.
* **Barcodes**: an optional *Barcode* per item (EAN/UPC or any scanned text; indexed, not strictly unique). Search
  finds items by (part of) their barcode, and ☰ → *Find by barcode* (`/barcode`) looks a code up: `/barcode/{code}`
  opens the item with that code, lists the matches if several share it, or starts a new item with the code filled in.
  Where the browser has the `BarcodeDetector` API (e.g. Chrome on Android) a **Scan** button uses the camera; elsewhere
  (iPhone Safari, desktop Firefox) you type the code. No external libraries. The barcode is in the CSV, backups and history.
* **Saved filters**: save the current search + filters + sort under a name (★ *Save filter*); saved filters appear as
  chips above the list to re-apply in one tap, and `/filters` renames or deletes them. Included in backups.
* **Expiry and warranty dates**: an optional *Expires / warranty until* date per item (food, medicine, batteries,
  warranties). Cards and the item page show **Expires in N days** (within `EXPIRY_SOON_DAYS`, default 30) or a red
  **Expired** badge, the overview has *Expiring soon* and *Expired* tiles, and the filter sheet has *Expiry / warranty →
  Expiring soon / Expired* (`/?exp=soon`, `/?exp=expired`). The date is in the CSV export, backups and the history.
* **Lending tracker**: *Lend this item* on the item page records who has it, since when (defaults to today) and an
  optional due date; *Mark as returned* brings it back. Cards show a **Lent · name** or red **Overdue · name** badge
  (overdue = the due date is before today in the `TZ` time zone), the filter sheet has *Lending → Lent out / Overdue*
  (`/?lend=out`, `/?lend=overdue`), and ☰ → *Lent out* (`/lent`) lists everything that is out, overdue first, with a
  one-tap *Returned* button. Lending and returns are logged in the history; CSV and backup/restore include it.
* **Duplicate item**: *Duplicate* on the item page creates "Name (copy)" with the same location, category, tags,
  quantity, low-stock level, notes and custom fields (value, purchase date, serial) and opens its edit form. Tick *with
  photos* to copy the photos as well (the files are duplicated, so deleting either item never affects the other). Lending
  is not copied. The copy's history starts with "Created … copied from “Name”".
* **Merge duplicates**: *Merge…* on the item page suggests likely duplicates, shows a confirm screen (combined quantity,
  photos, attachments, tags, filled-in fields, loan) and merges everything — history included — in one transaction.
* **Stocktake**: ☰ → *Stocktake* checks one location and everything inside it. Tick items in the list or scan their
  QR label / barcode (camera where `BarcodeDetector` exists, or a USB scanner / typing; `#42` means item 42). Items that
  turn up but belong elsewhere are listed as *misplaced*. *Finish* shows a report (found / missing / misplaced) and can
  move the misplaced items into the checked location (logged in their history, default in Control Panel → *Thresholds &
  limits*). Finished and cancelled stocktakes stay in the history at `/stocktake`; they are part of backups.
* **CSV export** of the whole catalogue (including `tags`, `value`, `currency`, `purchase_date`, `serial`, `min_qty`, `expires_on`, `barcode`,
  `lent_to`, `lent_at` and `lent_due` columns).
* **CSV import**: upload a spreadsheet, map its columns (guessed from the headers), preview a dry run with every row's
  errors, then import in one transaction — optionally creating missing locations (paths like `Garage › Shelf 2` become
  nested), categories and tags. The CSV export imports unchanged (its `id` column updates those items).
* **Users & roles** (optional): turn sign-in on and give everyone their own account — *admin* (everything), *editor*
  (change the catalog), *viewer* (look only) or your own **custom roles** built from 14 granular permissions (view, add,
  edit, delete, move, bulk, lend, import, export, stocktake, labels, settings, users, backup), enforced by the server on
  every route. Optional **guest browsing** (look-only, configurable Guest role) and **self-registration** at `/register`
  with a default role, admin approval and rate limiting — both off by default. Only an admin account can grant the
  admin role, change an admin account, turn sign-in off, or apply sign-in settings from an import or restore. Sign-in attempts are counted per client and account (10 in 15 minutes). The history shows who changed
  what; no-login mode stays available for a trusted LAN. `APP_PASSWORD` creates the first account `admin`.
  Empty means no login, fine on a trusted LAN.

### iPhone camera notes

* **Take photo** uses `<input type="file" accept="image/*" capture="environment">`, which opens the rear camera directly;
  **Library** picks an existing photo.
* This works over plain `http://` on your LAN. HTTPS is only needed for the live-camera `getUserMedia` API, which
  Apotheke does not use.
* The photo you pick is uploaded untouched when it fits the upload limit. The browser shrinks it only when it is over that limit, and the page says the original is not kept. The server stores the file it received byte for byte and serves a separate JPEG with EXIF and GPS removed. HEIC is decoded on the server when Imagick lists HEIC (the Docker image does, via libheif). Without Imagick the HEIC file is still kept and the page shows a grey placeholder.

## Screenshots

| List & overview | Filter sheet | Menu & theme | Dark mode |
|---|---|---|---|
| ![List](docs/screenshots/list-light.png) | ![Filter sheet](docs/screenshots/filters-light.png) | ![Menu](docs/screenshots/menu-light.png) | ![Dark list](docs/screenshots/list-dark.png) |

| Add item | Item (dark) | Manage (dark) | Desktop |
|---|---|---|---|
| ![New item](docs/screenshots/new-light.png) | ![Item](docs/screenshots/item-dark.png) | ![Manage](docs/screenshots/manage-dark.png) | ![Desktop](docs/screenshots/desktop-light.png) |

| Photo gallery | Tags & custom fields (dark) | QR labels | Restore preview | History (dark) |
|---|---|---|---|---|
| ![Gallery](docs/screenshots/gallery-light.png) | ![Tags](docs/screenshots/tags-dark.png) | ![Labels](docs/screenshots/labels-light.png) | ![Restore](docs/screenshots/restore-light.png) | ![Activity](docs/screenshots/activity-dark.png) |

| Running low | Bulk actions (dark) | Lent out | Lent item (dark) |
|---|---|---|---|
| ![Low stock](docs/screenshots/low-stock-light.png) | ![Bulk actions](docs/screenshots/bulk-dark.png) | ![Lent page](docs/screenshots/lent-light.png) | ![Lent item](docs/screenshots/item-lent-dark.png) |

| Duplicate | Nested locations | Location path (dark) |
|---|---|---|
| ![Duplicate](docs/screenshots/duplicate-light.png) | ![Location tree](docs/screenshots/locations-tree-light.png) | ![Item in a nested location](docs/screenshots/item-nested-dark.png) |

| Stocktake | CSV import preview | Photo editor (dark) | Roles & permissions | Registration |
|---|---|---|---|---|
| ![Stocktake](docs/screenshots/stocktake-light.png) | ![CSV import](docs/screenshots/import-light.png) | ![Photo editor](docs/screenshots/photo-editor-dark.png) | ![Roles](docs/screenshots/roles-light.png) | ![Register](docs/screenshots/register-light.png) |

| Expiring soon + saved filters | Value (dark) | Saved filters | Barcode lookup (dark) | Item barcode |
|---|---|---|---|---|
| ![Expiring soon with saved-filter chips](docs/screenshots/expiring-light.png) | ![Value totals](docs/screenshots/value-dark.png) | ![Saved filters](docs/screenshots/saved-filters-light.png) | ![Barcode lookup](docs/screenshots/barcode-dark.png) | ![Item with barcode](docs/screenshots/item-barcode-light.png) |

| Notes + attachments | Bulk labels (dark) | Favourites + recently viewed | Merge (dark) | Undo |
|---|---|---|---|---|
| ![Markdown notes and attachments](docs/screenshots/item-notes-attachments-light.png) | ![Label sheet for a selection](docs/screenshots/labels-selection-dark.png) | ![Favourites chip and recently viewed](docs/screenshots/list-favourites-recent-light.png) | ![Merge confirm screen](docs/screenshots/merge-confirm-dark.png) | ![Undo bar](docs/screenshots/undo-bar-light.png) |

| Sign in (password mode) | Settings | Appearance (dark) | Custom accent + logo |
|---|---|---|---|
| ![Sign in](docs/screenshots/login-light.png) | ![Settings](docs/screenshots/settings-light.png) | ![Appearance](docs/screenshots/settings-appearance-dark.png) | ![Custom accent and logo](docs/screenshots/custom-brand-light.png) |

## Requirements

**Runtime** (the Docker image has all of this):

* PHP **8.2+** (the Docker image and CI use 8.3) with `pdo_sqlite` **or** `pdo_pgsql`, `gd` (JPEG + WebP), `mbstring` and
  `zip` (backups); `exif` is recommended (photo orientation), `dom` for SVG logo uploads, `opcache` for speed.
  Function JIT can miscompile QR labels on PHP 8.2, 8.3 and 8.4, so `src/qr.php` turns it off for the whole worker on the first request
  (tracing JIT stays on; the Docker image never enables JIT). `opcache_jit_blacklist()` does not help.
  The Docker image also includes Imagick built with HEIC/HEIF (`libheif`). Imagick flattens transparent areas onto
  white and converts CMYK to sRGB, matching GD. For a grayscale photo, Imagick writes a 1-channel JPEG, so that
  match is approximate. Other installs stay on GD: a HEIC upload is kept byte for byte and
  shown as a placeholder until Imagick can decode it.
* SQLite (built in) or PostgreSQL (tested with 17).
* No Composer packages at runtime: `composer.json` only lists PHPUnit under `require-dev`.

**Development only:** Composer + PHPUnit 11 (`composer install`), Node 20+ for the JS syntax check, the headless mobile
checks and Playwright (`npm ci`), `shellcheck` for the shell lint, optionally `zbar-tools` (QR round-trip tests).

## Install with Docker

On the LAN server:

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
docker compose up -d --build
```

Then open **http://SERVER-IP:9091** on your phone (same Wi-Fi; `hostname -I` shows the server IP). In Safari tap Share →
**Add to Home Screen**. To use another host port, change the left side of `"9091:9091"` in `docker-compose.yml`
(e.g. `"8080:9091"`).

> **Upgrading from 1.x (port 8080):** the image now listens on **9091** (was 80 inside the container) and the compose
> file maps host port **9091** (was 8080). Update bookmarks, home-screen icons, reverse proxies and `PUBLIC_URL`;
> QR labels printed with `:8080` in them need reprinting (or keep the old address with `ports: ["8080:9091"]`).
> `docker run -p 8080:80 …` becomes `-p 9091:9091` (or `-p 8080:9091`). `PORT` still overrides the container port.

The image (about 100 MB) is `php:8.3.36-fpm-alpine` with **nginx** in the same container and `gd`, `exif`, `opcache`,
`pdo_pgsql` and `zip` added (`pdo_sqlite` is built in). `tini` is PID 1; a small entrypoint runs php-fpm and nginx,
stops both gracefully on `docker stop` and exits if either dies (so `restart: unless-stopped` brings it back). nginx
listens on port 9091 inside the container; set `PORT` to change that (e.g. with `--network host`). The entrypoint creates `images/`, `thumbs/` and `sessions/` in the data volume and makes them writable by `www-data`; a
`HEALTHCHECK` polls `/healthz`. Everything is stored in `./data` next to `docker-compose.yml`:

```
data/
  catalog.sqlite      # the SQLite database (created on first run)
  images/             # full-size photos (≤1600 px JPEG, random file names)
  thumbs/             # 480 px thumbnails
  sessions/           # login sessions
  postgres/           # only with the postgres profile
```

Settings go into a `.env` file next to `docker-compose.yml` (see [Configuration](#configuration)), e.g.

```
APP_PASSWORD=change-me
TZ=Europe/Athens
```

then `docker compose up -d` again.

### PostgreSQL (optional)

SQLite is the default and needs nothing extra. To use the bundled PostgreSQL container, put this in `.env`:

```
DB_DRIVER=pgsql
DB_PASSWORD=a-strong-password
```

and start with the `postgres` profile:

```bash
docker compose --profile postgres up -d --build
```

Its data lives in `./data/postgres`. For an existing server set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and
`DB_PASSWORD` instead and leave the profile off. Photos always stay in `DATA_DIR`, whichever database you use.

### Update

Pull the new version (keep `data/`), then `docker compose up -d --build`. After a PHP security release, use
`docker compose build --pull` and `docker compose up -d` so the new base image is fetched. Migrations run automatically; see
[Upgrading](#upgrading).

## Install without Docker

With PHP and the extensions from [Requirements](#requirements):

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
cp config.example.php config.php        # optional; edit, or use environment variables
php -d upload_max_filesize=20M -d post_max_size=22M -S 0.0.0.0:9091 -t public public/index.php
```

Data goes to `./data` (or `DATA_DIR`). The built-in server is fine for a home LAN. Behind Apache or nginx, make `public/`
the document root, route every request that is not an existing file in `public/` to `public/index.php`, never execute
any other PHP file, and keep `src/`, `data/` and `config.php` outside the web root (see `docker/nginx.conf` and
`docker/fastcgi.conf` for a complete example, including the larger request limit for `/backup/restore`). For web restores of big backups, raise
`upload_max_filesize` / `post_max_size` (the Docker image allows 512 MB on that route only) or use `bin/restore.php`.

## Configuration

Most settings are edited in the **Control Panel** (☰ → *Settings*) and stored in the database. `config.php` and the
environment provide the **bootstrap** values (data directory, database connection, seeding) and the **initial defaults**
for everything else: on a new install or upgrade they are copied into the database once; after that the panel (or
`php bin/settings.php`) is the place to change them. Details: [docs/control-panel.md](docs/control-panel.md) and
[docs/configuration.md](docs/configuration.md).

`config.php` / environment layers, later wins: built-in defaults → `config.php` (or the file named by `APOTHEKE_CONFIG`)
→ environment variables. [`config.example.php`](config.example.php) lists every setting with its variable name; copy it
to `config.php` (git-ignored). Empty environment variables are ignored, so `APP_PASSWORD: ${APP_PASSWORD:-}` in a
compose file never wipes a value from `config.php`.

| Variable | Default | Meaning |
|---|---|---|
| `APP_PASSWORD` | *(empty)* | Password of the first account `admin` (created on the first start, sign-in on); afterwards Settings → Users & sign-in. Empty means no login (fine on a trusted LAN). |
| `TZ` | `UTC` (`Europe/Athens` in compose) | Initial time zone for dates and statistics (then Settings → Currency, dates & time). |
| `DATA_DIR` | `./data` (`/data` in the container) | Photos, thumbnails, sessions and the SQLite file. |
| `DB_DRIVER` | `sqlite` | `sqlite` or `pgsql`. |
| `SQLITE_PATH` | `<DATA_DIR>/catalog.sqlite` | SQLite database file. |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | `127.0.0.1` / `5432` / `apotheke` / `apotheke` / *(empty)* | PostgreSQL connection. |
| `DB_SCHEMA` | *(empty)* | Optional PostgreSQL schema. Created only when it is missing, so a role without CREATE can use one that already exists. |
| `DB_DSN` | *(empty)* | Full PDO DSN; overrides host/port/name. |
| `MAX_UPLOAD_MB`, `IMAGE_FULL_MAX`, `IMAGE_THUMB_MAX` | `20`, `1600`, `480` | Upload limit (MB) and stored photo/thumbnail size (px). |
| `PAGE_SIZE` | `60` | Items per page. |
| `CURRENCY` | `EUR` | Currency shown next to item values (ISO code or symbol). Only a label; no conversion. |
| `EXPIRY_SOON_DAYS` | `30` | An item is *Expiring soon* when its expiry/warranty date is within this many days (1–3650). |
| `PUBLIC_URL` | *(from the request)* | Base URL encoded in QR labels, e.g. `http://192.168.1.10:9091`. Set it if you open the app under a different name than your phone will. |
| `MAX_UPLOAD_MB` … `PUBLIC_URL` above | | Initial values only; edited in the Control Panel afterwards. |
| `ALLOWED_HOSTS` | *(empty)* | Extra host names the app answers to, comma-separated (e.g. `apotheke.lan,nas.lan`). IP addresses, `localhost`, the machine's host name and `PUBLIC_URL`'s host always work; other names get 421, health checks (`/healthz`, `/health`) included. See [Host names](docs/deployment-and-operations.md#host-names). |
| `SEED_DEFAULTS` | `true` | Create starter locations and categories on a brand-new database. |
| `TRUSTED_PROXIES` | *(none)* | Your reverse proxies (addresses or CIDR, comma-separated). `X-Forwarded-For` is read only when `trust_proxy` is on and the connecting peer is listed here. The client is the rightmost hop that isn't itself listed. With `trust_proxy` on and an empty list, the connecting address is used. |
| `APOTHEKE_CONFIG` | `./config.php` | Path to an alternative config file. |

## Command-line tools

All of them read the same configuration as the web app. In Docker, prefix them with
`docker compose exec apotheke php /var/www/app/bin/…`.

```bash
php bin/migrate.php                      # apply pending migrations (also adopts legacy SQLite installs)
php bin/migrate.php --status             # list applied / pending migrations
php bin/seed.php                         # starter locations/categories (idempotent, skips existing names)
php bin/seed.php --demo                  # … plus a few demo items with generated photos
php bin/backup.php                       # apotheke-backup-<date>.zip in the current directory
php bin/backup.php /backups/home.zip     # … or to that file
php bin/restore.php home.zip --dry-run   # validate and show what would be restored
php bin/restore.php home.zip             # asks for confirmation, then REPLACES all data
php bin/restore.php home.zip --yes       # no prompt (scripts)
php bin/settings.php list                # Control Panel settings (get KEY / set KEY VALUE / reset SECTION / export / import FILE)
php bin/settings.php users               # accounts · user add|password|role|approve|rename|delete NAME … · roles [reset] · auth on|off
php bin/settings.php user password admin # locked out? new password for admin
```

The schema is versioned: numbered files in [`migrations/`](migrations), recorded in a `schema_migrations` table. Pending
migrations also run **automatically on the first request** (one transaction, under a lock), so `bin/migrate.php` is
optional. On a **brand-new** database Apotheke adds starter locations (Box 1 ★, Living room, Kitchen, Garage, Basement,
Attic) and categories (General ★, Tools, Electronics, Kitchen, Documents, Hobby, Clothing, Shoes, Books, Lighting) unless `SEED_DEFAULTS=false`; rename or
delete them freely.

Backups from the CLI are handy for cron jobs and archives bigger than the web upload limit. The zip includes
`originals/index.json` and the camera files; restore writes both the camera file and the stripped JPEG. Photo files
that restored data doesn't reference stay in `data/images` and `originals/` and are never deleted automatically.

## Upgrading

On first start after an update the database is upgraded automatically (or run `php bin/migrate.php`).

After a PHP security release, rebuild the image so the new base is fetched, then restart: `docker compose build --pull` and `docker compose up -d`.

* **Pre-migration SQLite installs (schema v1–v3)** are first copied to `data/catalog-v<N>-backup-<date>.sqlite`, then
  converted and adopted into the migration system in one transaction: if anything fails, nothing changes.
  * From the very first version (free-text locations), the distinct location texts become managed locations (trimmed,
    de-duplicated ignoring case and accents) and every item is linked to its location.
  * From the locations-only version, categories are added; existing items start without a category.
* **Single photo → multiple photos** (migration `0003`): each item's photo becomes its cover.
* Migrations `0004` (tags, custom fields), `0005` (activity log), `0006` (low-stock minimum), `0007` (lending),
  `0008` (parent location) and `0009` (expiry date) only add tables/columns;
  `0010` only adds an index on the purchase price `0011` adds the `saved_filters` table and `0012` the barcode column.

Once everything looks right you can delete the `catalog-v*-backup-*.sqlite` snapshot. Before any upgrade, take a backup
(☰ → *Backup & restore* or `bin/backup.php`); a plain copy of `data/` also works if the app is stopped, or use
`sqlite3 data/catalog.sqlite ".backup backup.sqlite"` while it runs.

## Testing

No Docker needed:

```bash
tests/run.sh                 # lint (php -l, node --check, shellcheck) + HTTP integration suite on SQLite; no Composer
composer install             # dev only: PHPUnit
vendor/bin/phpunit           # unit tests (config, migrations, repositories, search) + the HTTP suite, on SQLite
DB_DRIVER=pgsql TEST_PG_PASSWORD=secret vendor/bin/phpunit   # against PostgreSQL
```

On PostgreSQL every test uses its own throwaway schema; `TEST_PG_HOST`, `TEST_PG_PORT`, `TEST_PG_DB`, `TEST_PG_USER` and
`TEST_PG_PASSWORD` default to `127.0.0.1`, `5432`, `apotheke`, `apotheke`, `apotheke`. A quick server:
`docker run --rm -p 5432:5432 -e POSTGRES_USER=apotheke -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=apotheke postgres:17`.

Headless mobile checks (filter sheet, theme switch, no overflow at 375 and 390 px) need Node and Chrome:

```bash
npm ci --prefix tests/e2e
DATA_DIR=/tmp/apotheke-e2e php -S 127.0.0.1:9091 -t public public/index.php &
php tests/e2e/seed.php http://127.0.0.1:9091
CHROME="$(command -v google-chrome)" node tests/e2e/mobile.mjs http://127.0.0.1:9091
```

`scripts/smoke.sh http://127.0.0.1:9091` smoke-tests any running instance (e.g. the Docker container);
`scripts/docker-checks.sh` checks the image's web server behaviour, and
`E2E_DOCKER_IMAGE=apotheke:latest npx playwright test --project=docker-smoke` runs the `@smoke` E2E tests against it.

### Coverage

<!-- coverage:start -->
| Area | PHPUnit (unit) | HTTP integration | Combined |
|---|---:|---:|---:|
| Front controller & routes | – | 97.0 % | 97.0 % |
| Items, search, bulk, lending | 89.1 % | 91.0 % | 95.9 % |
| Favourites, merge, undo | 80.9 % | 99.5 % | 99.5 % |
| Stocktake | 44.7 % | 98.1 % | 99.5 % |
| Users & roles | 94.1 % | 94.9 % | 97.0 % |
| Full-text search | 89.0 % | 87.7 % | 90.8 % |
| CSV import | 60.1 % | 97.8 % | 98.2 % |
| Photos | 59.6 % | 88.8 % | 88.8 % |
| Notes & attachments | 88.2 % | 90.7 % | 96.3 % |
| Views (HTML) | 11.8 % | 99.4 % | 99.3 % |
| Control Panel settings | 75.1 % | 94.6 % | 97.3 % |
| Backup & restore | 87.4 % | 89.6 % | 96.9 % |
| Database, migrations, legacy | 79.7 % | 81.0 % | 91.0 % |
| Bootstrap, config, QR, seed | 73.7 % | 74.6 % | 90.8 % |
| **All PHP** (7153 lines) | **65.4 %** | **90.2 %** | **95.8 %** |

| Playwright project | Functional tests | Visual tests |
|---|---:|---:|
| `chromium-pixel` | 139 | 22 |
| `chromium-pixel-dark` | 2 | 22 |
| `desktop-chromium` | 139 | 22 |
| `desktop-chromium-dark` | 2 | 22 |
| `pgsql-smoke` | 33 | 0 |
| `webkit-iphone-se` | 139 | 22 |
| `webkit-iphone-se-dark` | 2 | 22 |
| `webkit-iphone13` | 139 | 22 |
| `webkit-iphone13-dark` | 2 | 22 |
| **Total** | **597** | **176** |

<sub>Line coverage from pcov: PHPUnit unit suite, the HTTP integration suite (every `php -S` request of `tests/integration.php`, collected by `tests/coverage/prepend.php`) and both merged with phpcov, SQLite driver. Regenerate with `npm run coverage:readme`; the release workflow refreshes it from the CI artifact in every release commit.</sub>
<!-- coverage:end -->

`npm run coverage:readme` (`scripts/coverage.sh`, needs pcov) runs the unit suite with coverage, the HTTP suite with a
per-request collector, merges both with phpcov (HTML report in `coverage/html/`) and rewrites the table above. CI uploads
the same reports as the `coverage-sqlite` artifact; see [docs/ci.md](docs/ci.md#why-the-coverage-table-is-refreshed-by-the-release-commit).

### Visual end-to-end tests (Playwright)

`tests/visual/` drives the real app with [Playwright](https://playwright.dev) on WebKit (iPhone 13, iPhone SE), Chromium
(Pixel 7) and desktop Chromium, in light and dark mode: every feature end to end (add/edit/delete, photos, tags and
custom fields, stats, search, filter sheet, manage lists, QR labels, CSV, backup/restore, history, login, error pages),
plus checks for console errors, horizontal overflow and 44 px tap targets, and `toHaveScreenshot` baselines of the key
screens. Each test gets a fresh, deterministic catalogue (`tests/visual/seed.php`) on `php -S` with the server clock
frozen via `APOTHEKE_CLOCK`; a `@smoke` subset also runs against PostgreSQL. Needs Node 20+ and PHP:

```bash
npm ci
npx playwright install --with-deps chromium webkit
npm run test:e2e                      # whole suite
TEST_PG_HOST=127.0.0.1 npm run test:e2e:smoke-pg
npm run test:e2e:report               # open the HTML report
```

Screenshot baselines (`tests/visual/__screenshots__/`) are only rendered in the official
`mcr.microsoft.com/playwright:v<version>-noble` image, the same one CI uses, so screenshot assertions fail on other
machines by design. To update them run `npm run test:e2e:update` (Docker), or add the `update-snapshots` label to a PR
and fetch the result with `scripts/update-snapshots.sh --from-ci <run id>`. Details in [CONTRIBUTING.md](CONTRIBUTING.md).

## CI

GitHub Actions ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs on every pull request and on pushes to `main`:

| Job | What it does |
|---|---|
| Lint | `scripts/lint.sh` (PHP, JS, shell) |
| Tests | PHP 8.3 × SQLite / PostgreSQL 17 service: migrations + seeding via the CLI, PHPUnit with a coverage artifact |
| Headless mobile checks | `tests/e2e/mobile.mjs` at 375 and 390 px |
| Visual E2E | Playwright suite in 3 shards inside the Playwright image, `retries=1`; report, traces and screenshot diffs uploaded on failure |
| Docker build + smoke test | builds the image, checks Imagick lists HEIC (`scripts/docker-heic.sh`), checks the ImageMagick policy, runs `scripts/smoke.sh` on SQLite and PostgreSQL, `scripts/docker-checks.sh` (nginx routing, blocked paths, headers, gzip, body limits, Host check), `scripts/docker-auth-checks.sh` (sign-in mode: sessions, restore limit), a graceful-stop check and the Playwright `@smoke` subset against the image |
| Conventional Commits | PRs only: `scripts/check-commits.sh` on every commit |
| Secret scan | pinned gitleaks CLI (`gitleaks git --log-opts=--all`) over every ref in the full git history; the job fails if it finds a secret |

The secret scan downloads the [gitleaks](https://github.com/gitleaks/gitleaks) CLI (version and checksum pinned in
[`.github/workflows/ci.yml`](.github/workflows/ci.yml)) and runs `gitleaks git --log-opts="--all"` on a full checkout
(`fetch-depth: 0`, `actions/checkout` pinned to v4.4.0). It does not use the gitleaks GitHub Action and needs no token
or license. See [CI](docs/ci.md#secret-scan).

A separate *Update visual snapshots* workflow renders new baselines when a PR gets the `update-snapshots` label.
After CI passes on `main`, the *Release* workflow bumps the [SemVer](https://semver.org/) version from the Conventional
Commits, updates [`VERSION`](VERSION), [`CHANGELOG.md`](CHANGELOG.md) and the coverage table, tags and publishes a GitHub
Release ([docs/releasing.md](docs/releasing.md)). The running version is shown in the footer, under Control Panel →
*System*, in `GET /health` (`{"status":"ok","version":"…"}`) and in the `X-Apotheke-Version` header of `/healthz`.

## Security notes

* All SQL uses prepared statements; all output is HTML-escaped; a strict Content-Security-Policy is sent.
* Every change (save, quantity, delete, theme, login/logout, restore) requires a CSRF token; an expired token shows a
  friendly page and changes nothing.
* Uploads that fit the limit are stored byte-identical under `originals/` (outside the web root) and served only as a
  stripped JPEG through a strict `[a-f0-9]{32}.jpg` route, so nothing uploaded can be executed. The Docker image uses
  Imagick for HEIC; other installs use GD and show a placeholder when they cannot decode HEIC.
* Attachments are checked by their magic bytes, stored under random names in `data/files/` and served only as downloads
  with `Content-Disposition: attachment`, `nosniff` and a `sandbox` CSP.
* The web server only runs `public/index.php`; `src/` and `data/` are not reachable over HTTP.
* Meant for a trusted LAN. If you expose it to the internet, put it behind HTTPS and turn sign-in on (Settings → Users & sign-in).
* Uploaded SVG logos are sanitized (no scripts, handlers, external references or entities) and served with a sandbox CSP;
  the custom accent is a generated stylesheet, so the CSP keeps `style-src 'self'`.

## Project layout

```
Dockerfile                    php:8.3.36-fpm-alpine + nginx + tini; gd, imagick (HEIC), exif, opcache, pdo_pgsql, zip
docker-compose.yml            port 9091, ./data volume, optional "postgres" profile
docker/                       nginx.conf, fastcgi.conf, php-fpm.conf, php.ini, entrypoint
config.example.php            every setting (copy to config.php; env vars override)
bin/                          migrate.php, seed.php, backup.php, restore.php
migrations/NNNN_*.php         versioned schema steps (SQL per dialect, or a PHP closure)
public/index.php              front controller and routes
public/assets/                app.css, app.js, theme.js, icons
src/config.php                config loader (defaults < config.php < env)
src/db.php                    PDO connection (SQLite/PostgreSQL), dialect helpers, migration runner
src/legacy.php                one-time upgrade of pre-migration SQLite installs (v1–v3)
src/items.php                 items, locations, categories, search, statistics, images (GD)
src/backup.php                portable backup zip and restore
src/qr.php                    loads the QR encoder after the function-JIT guard
src/qr-encode.php             pure-PHP QR encoder (SVG)
src/seed.php                  idempotent starter/demo seeding
src/bootstrap.php             sessions, auth, CSRF, helpers
src/hosts.php                 Host allow-list, same-origin rule (no-login mode)
src/views.php                 HTML templates
tests/run.sh, integration.php lint + HTTP integration suite (no Composer)
tests/Unit/, Integration/     PHPUnit suites
tests/e2e/                    headless mobile checks (puppeteer-core, dev only)
tests/visual/                 Playwright visual E2E suite, fixtures, seed and screenshot baselines
playwright.config.ts          Playwright projects (devices × colour schemes, pgsql-smoke)
scripts/                      lint.sh, check-links.mjs, smoke.sh, docker-checks.sh, check-commits.sh, update-snapshots.sh,
                              e2e-container-setup.sh
docs/                         detailed documentation (Markdown, mermaid diagrams) and README screenshots
```

## Contributing

Pull requests are welcome. Commits must follow [Conventional Commits](https://www.conventionalcommits.org/) (subject ≤ 72
characters, checked in CI); see [CONTRIBUTING.md](CONTRIBUTING.md) for the details, test commands and how to write a
migration, and [docs/contributing.md](docs/contributing.md) for a step-by-step guide to adding a feature.

## License

[MIT](LICENSE) © 2026 Stathis Papandreou
