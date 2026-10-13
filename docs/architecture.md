# Architecture

Apotheke is deliberately small: one front controller, a handful of PHP files with plain functions, server-rendered
HTML, one stylesheet and one script. There is no framework, no autoloader, no template engine and no build step.

## Directory layout

```
public/                   web root (the only directory a web server should expose)
  index.php               front controller + router (also the router script for `php -S`; HEAD is treated as GET for reads)
  assets/                 app.css, app.js, theme.js, icons (served as static files)
src/
  bootstrap.php           constants, data directories, sessions, auth, CSRF, small helpers, asset()
  config.php              configuration loader (defaults < config.php < environment)
  settings.php            Control Panel settings: schema, validation, typed per-request cache, accent contrast maths,
                          logo upload/SVG sanitizer/icon generation, manifest, password
  settings_views.php      Control Panel pages (/settings/…)
  db.php                  PDO connection, dialect helpers, migration runner
  legacy.php              one-time conversion of pre-migration SQLite installs (schema v1–v3)
  items.php               domain logic: items, locations/categories, tags, search, statistics, photos (GD),
                          activity log, low stock, bulk actions, lending, duplicating, the location tree
  markdown.php            safe Markdown subset for notes (escape first, then a few constructs; http/https/mailto links)
  attachments.php         item attachments: type sniffing, limits, storage in data/files/, download headers
  favourites.php          favourite flag, recently viewed list (device cookie or shared scope)
  merge.php               merging duplicates: preview (confirm screen), item_merge() in one transaction, candidates
  undo.php                undo tokens: item snapshots, soft delete (files kept until expiry), apply, prune, the bar
  backup.php              portable backup zip (dump + photos + attachments) and restore
  qr.php                  pure-PHP QR code encoder (SVG)
  seed.php                starter locations/categories and demo data
  views.php               all HTML: page shell, partials (cards, chips, sheets), one view_* function per screen
migrations/NNNN_*.php     versioned schema steps
bin/                      CLI: migrate.php, seed.php, backup.php, restore.php, settings.php
docker/                   nginx.conf (template), fastcgi.conf, php-fpm.conf, php.ini, entrypoint.sh
tests/                    run.sh, integration.php + lib.php, Unit/, Integration/, e2e/ (puppeteer), visual/ (Playwright)
scripts/                  lint.sh, check-links.mjs, smoke.sh, docker-checks.sh, check-commits.sh, update-snapshots.sh, e2e-container-setup.sh
docs/                     this documentation and the README screenshots
```

## Request flow

```mermaid
sequenceDiagram
  participant B as Browser
  participant W as nginx / php -S
  participant R as public/index.php
  participant D as src/items.php + db.php
  participant V as src/views.php
  B->>W: GET /item/3
  W->>R: not a static file → front controller
  R->>R: security headers (CSP, nosniff, frame options); HEAD → GET for routing
  R->>R: /healthz (no session needed)
  R->>R: init_session() → CSRF token, release session lock
  R->>R: /login, /logout, then sign-in gate + role check (users)
  R->>D: item_get(3), photos_for(3), activity_list(3)
  D->>D: db() connects and runs pending migrations on first use
  R->>V: view_item(...)
  V-->>B: HTML (escaped with h(), external CSS/JS only)
```

`public/index.php` is a linear list of `if ($path === … && $method === …)` / `preg_match` blocks inside one
`try`. Each block validates input, calls domain functions and either renders a view, returns JSON (`json_out()`,
when the request has `X-Requested-With: fetch`) or redirects (`redirect()`, 303 after a POST). A `UserError` thrown
anywhere becomes an error page (or JSON) with its status code (400 by default; 404, 419 for an expired CSRF token,
422 for validation); any other exception is logged and shown as a generic 500 page.

Important routes:

| Route | Purpose |
|---|---|
| `GET /` | list; JSON variant for instant search (`html`, `count`, `chips`, `more`, …) |
| `GET /new`, `GET /item/N/edit`, `POST /save` | add / edit form and save |
| `GET /item/N` | item page |
| `POST /item/N/qty` | quantity +/- (JSON, returns the new quantity and the low-stock state) |
| `POST /item/N/photos…` | add, reorder, set cover, delete photos |
| `POST /item/N/duplicate` | duplicate (optionally with photos), redirects to the copy's edit form |
| `POST /item/N/lend`, `POST /item/N/return`, `GET /lent` | lending |
| `POST /bulk` | bulk actions on `ids[]` (delete shows a confirmation page first) |
| `POST /item/N/delete` | delete |
| `/locations…`, `/categories…` | manage lists (add, rename, default, delete with move); `POST /locations/N/parent` moves a location in the tree |
| `GET /labels`, `GET /item/N/label` | printable QR labels |
| `GET /barcode`, `GET /barcode/{code}` | barcode lookup page; redirect to the item, the matches (`/?q=`), or `/new?barcode=` |
| `GET /filters`, `POST /filters`, `POST /filters/N/rename`, `POST /filters/N/delete` | saved filters (manage page, save the current list view, rename, delete) |
| `GET /activity` | history (all items, or `?item=N`) |
| `GET /backup`, `GET /backup.zip`, `POST /backup/restore`, `POST /backup/restore/confirm` | backup and restore |
| `GET /export.csv` | CSV export |
| `GET /stats` | lazily loaded overview fragment |
| `GET /media/(full|thumb)/<hex>.jpg` | photos (strict file-name pattern, served by PHP from `DATA_DIR`) |
| `POST /theme` | Auto / Light / Dark |
| `GET /healthz` | health check (connects to the database) |

### Sessions, auth and CSRF

`init_session()` opens the PHP session (files in `DATA_DIR/sessions`, cookie `apothekesid`, `HttpOnly`,
`SameSite=Lax`, `Secure` on HTTPS), makes sure a CSRF token exists, and **closes the session right away** so parallel
requests (photos, instant search) never wait for the session lock. Every state-changing route calls `check_csrf()`
(form field `csrf` or header `X-CSRF-Token`). When sign-in is on (Settings → Users & sign-in; `APP_PASSWORD` creates the account `admin`), everything except `/login`, `/healthz` and
the branding routes (`/theme.css`, `/manifest.webmanifest`, `/favicon.ico`, `/brand/…`) requires a signed-in session (`uid`) whose
fingerprint matches that user's current password hash (a new password signs the user's other devices out); unauthenticated JSON requests get a 401, media requests a bare
401, pages a redirect to `/login`. Then `route_role()` (src/users.php) names the role the route needs — viewer for pages,
editor for every POST and the edit forms, admin for `/settings*` and `/backup*` — and `user_can()` answers 403 otherwise.

## Database layer

* `db()` returns the request's PDO connection. On first use it connects (`db_connect()`) and runs `migrate()`, so a
  fresh install or an upgrade needs no manual step.
* SQL is written once for both dialects. The portability rules (see the header of `src/db.php`):
  * case/accent-insensitive uniqueness and search use **PHP-folded columns** (`name_key`, `items.search`, computed
    with `fold()`), so both databases use plain `UNIQUE` and `LIKE … ESCAPE '!'`;
  * natural sorting uses a PHP-computed `sort_key` (`natkey()`), so "box 2" sorts before "box 10";
  * timestamps are UTC `Y-m-d H:i:s` text; lending dates are local `Y-m-d` text;
  * dialect-specific bits live in small helpers: `db_insert()` (insert ids), `db_local_bucket()` (date bucketing
    for the statistics), and the migration lock.
* `db_tx(fn)` runs a closure in a transaction, or joins the caller's transaction if one is open. That is how bulk
  actions and duplicating reuse `item_save()` and still commit or roll back as one unit.
* `activity_log()` is always called inside the same transaction as the change it records.

More in [Database](database.md).

## Views and front end

`src/views.php` renders everything with plain PHP templates. `page_start()` emits the `<head>` (theme from the cookie,
`theme-color`, manifest, CSRF meta tag, versioned CSS/JS); `page_end()` closes the document. Every dynamic value
goes through `h()`.

The Content-Security-Policy forbids inline scripts and styles, so:

* `public/assets/theme.js` (loaded synchronously in `<head>`) only restores a `localStorage` theme before first paint;
* `public/assets/app.js` (deferred) progressively enhances server-rendered pages: instant search, the filter sheet,
  chips, quantity buttons (coalescing quick taps), the photo manager and client-side shrinking, tag input, confirm
  dialogs (`data-confirm`), the overview panel, selection mode and the bulk bar;
* there are no `style="…"` attributes; all styling is in `public/assets/app.css`, with light/dark variables under
  `[data-theme]` and `prefers-color-scheme`.

### Settings

Two layers: **bootstrap** values (`cfg()`: data dir, database, seeding) come from `config.php` / the environment;
**everything else** comes from `setting($key)` (`src/settings.php`), which loads the `settings` table once per request
and falls back to the defaults (`config.php` values or literals) before migration 0013. The custom accent is delivered
as a generated stylesheet (`/theme.css?v=<hash>`, CSS variables for light, dark and `prefers-color-scheme`), so no inline
styles are needed; the manifest and `favicon.ico` are generated routes, so an uploaded logo can replace the icons. See
[control-panel.md](control-panel.md).

Forms are real `<form method="post">` elements, so the core flows work without JavaScript; `app.js` only makes them
faster (fetch + JSON) where it matters.

## Location tree

Locations have an optional `parent_id`. `tax_list('location')` reads all locations with their direct item counts in
one query and `location_tree_order()` turns them into tree order (each parent followed by its children, siblings
in natural order), adding `depth`, `path` ("Garage › Shelf 2") and `total` (items including sub-locations). Views use
that list everywhere: `tax_option_label()` indents dropdown options, `parent_options()` builds the parent picker
(without the location's own branch), `location_trail_html()` renders the path on the item page. The list filter
`loc=N` expands to `location_id IN (…)` with `location_subtree_ids()`. A search word that matches a location name expands the same way
(`location_ids_matching()`), so items below a matching location are found too. The overview's *By location* bars
come from `stats_location_rollup()`, which adds every item to the top-level location of its branch. `stats_value()` uses the same rollup for the purchase-price totals of the *Value* card.

## Docker image

```mermaid
flowchart LR
  tini --> ep["entrypoint.sh"]
  ep --> fpm["php-fpm<br/>/run/php-fpm.sock"]
  ep --> nginx["nginx :PORT"]
  nginx -->|"/assets/*, manifest"| static["static files<br/>(immutable caching, gzip)"]
  nginx -->|"everything else"| fpm
  nginx -->|"src/, migrations/, dotfiles, other *.php"| deny["404"]
```

nginx serves `public/assets/` directly, sends every other request to `public/index.php` through php-fpm, and returns
404 for `src/`, `data/`, `bin/`, `migrations/`, `docker/`, `tests/`, `vendor/`, `config.php`, dotfiles and every `.php`
path except `/index.php`, the front controller itself. `scripts/docker-checks.sh` verifies this behaviour in CI.
