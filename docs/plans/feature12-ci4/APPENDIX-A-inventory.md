# Appendix A — Feature inventory of `thordin9/apotheke` **v2.5.0** (`87850e3`; code = `5633472`, PR #28)

Source: read-only clone at `/workspace/apotheke-ci4-prep`. Inventoried at `56334727bf8f76eeee2bb8e860ab245cd883183a`, the
**merge commit** (two parents: `56baf17`, `56f5b08`) of PR #28 "feat(autofill): keep originals, send resized photo to
Worker". **v2.5.0** = `87850e379d85d6690e232ba107373208f9b7ffbd` (tag object `85a1a3f`) is `chore(release): v2.5.0` on top
of it and differs only in `VERSION` (2.4.1 → 2.5.0), `CHANGELOG.md` and the README coverage tables. Verified (diff checked
here; QA tag check passed; PM re-scan clean), so every reference below holds for v2.5.0.
All paths are relative to the repo root. Line counts are from that commit. Items new or changed in #28 are marked **[#28]**.

## A.1 Code size (PHP, front end)

| File | Lines |
|---|---:|
| `src/views.php` | 1698 |
| `src/items.php` | 1526 |
| `public/index.php` | 1216 |
| `src/autofill.php` | 1008 |
| `src/settings.php` | 793 |
| `src/settings_views.php` | 451 |
| `src/bootstrap.php` | 382 |
| `src/backup.php` | 376 |
| `src/search.php` | 374 |
| `src/qr.php` | 329 |
| `src/import.php` | 326 |
| `src/users.php` | 279 |
| `src/db.php` | 211 |
| `src/roles.php` | 205 |
| `src/stocktake.php` | 177 |
| `src/stocktake_views.php` | 176 |
| `src/undo.php` | 173 |
| `src/attachments.php` | 164 |
| `bin/settings.php` | 145 |
| `src/config.php` | 132 |
| `src/markdown.php` | 117 |
| `src/merge.php` | 114 |
| `src/photo_edit.php` | 102 |
| `src/seed.php` | 94 |
| `src/import_views.php` | 92 |
| `src/legacy.php` | 88 |
| `src/favourites.php` | 69 |
| `src/photo_views.php` | 54 |
| `bin/restore.php` | 46 |
| `bin/migrate.php` | 31 |
| `src/dev_server.php` | 21 |
| `bin/seed.php` | 21 |
| `bin/backup.php` | 21 |
| `public/assets/app.js` | 1152 |
| `public/assets/app.css` | 1018 |

## A.2 HTTP routes (`public/index.php`, permission from `route_perm()` in `src/roles.php`)

HEAD is treated as GET for every read route (`public/index.php:32`). Every POST needs the CSRF token (`csrf` field or
`X-CSRF-Token` header → 419 on failure). JSON is returned when `X-Requested-With: fetch` or `Accept: application/json`
(`wants_json()`), otherwise HTML or a 303 redirect.

| Method | Path | Handler / domain | Perm |
|---|---|---|---|
| any | any path containing an `originals` segment (`(?:^\|/)originals(?:/\|$)`, case-insensitive) | **[#28]** 404 `Not found` (text/plain) before anything else runs (`public/index.php:12`) | – |
| GET | `/healthz` | text `ok` + `X-Apotheke-Version`, touches DB (runs migrations) | public |
| GET | `/health` | JSON `{status, version}` | public |
| GET | `/theme.css`, `/labels.css` | `accent_css()`, `labels_css()` (settings.php) | public |
| GET | `/manifest.webmanifest`, `/favicon.ico`, `/brand/{file}` | branding (settings.php `manifest_data`, `brand_serve`) | public |
| any POST | (body > post_max_size) | 413 page/JSON | – |
| GET/POST | `/login` | `login()` + `rate_limit('login')` | public |
| GET/POST | `/register` | `user_register()` (only if `registration_open()`) | public |
| POST | `/logout` | `logout()` | self |
| GET/POST | `/account` | own password change | self |
| POST | `/theme` | theme cookie | view |
| GET | `/media/(full\|thumb)/{name}` | `serve_image()` (items.php) | view |
| GET | `/stats` | overview fragment | view |
| GET | `/` | list + search + filters + sort + pagination + toasts; JSON variant | view |
| GET | `/new` | `view_form()` (defaults from settings, `?cat=`) | create |
| GET | `/item/{id}`, `/item/{id}/edit` | `view_item()` / `view_form()`, `recent_track()` | view / edit |
| POST | `/save` | `item_save()` (+ new location/category inline, tags, photos, undo) | create/edit (+move) |
| GET/POST | `/item/{id}/photos/{pid}/edit` | `photo_edit()` crop/rotate (photo_edit.php) | edit |
| POST | `/item/{id}/photos/order` | `photo_reorder()` | edit |
| POST | `/item/{id}/photos[/{pid}/(cover\|left\|right\|delete)]` | `photo_add()`, `photo_action()` | edit |
| POST | `/item/{id}/attachments[/{aid}/delete]` | `attachments_add()`, `attachment_delete()` | edit |
| GET | `/attachment/{id}` | `attachment_serve()` (download, sandbox CSP) | view |
| POST | `/item/{id}/qty` | `item_adjust_qty()` | edit |
| POST | `/item/{id}/duplicate` | `item_duplicate()` | create |
| POST | `/item/{id}/(lend\|return)` | `item_lend()`, `item_return()` | lend |
| GET | `/lent` | `view_lent()` | view |
| POST | `/bulk` | `items_bulk()` (move, category, tag_add, tag_remove, delete w/ confirm, labels), max 500 | bulk + `bulk_perm()` |
| GET/POST | `/item/{id}/merge` | `merge_preview()`, `item_merge()` | delete |
| POST | `/item/{id}/fav` | `fav_set()` | edit |
| POST | `/recent/clear` | `recent_clear()` | view |
| POST | `/undo` | `undo_apply()` | edit |
| POST | `/item/{id}/delete` | `item_delete()` (soft-delete files for undo window) | delete |
| GET | `/settings` | Control Panel home | settings |
| GET | `/settings/export.json` | `settings_export()` | settings |
| POST | `/settings/import` | `settings_import()` (≤256 KiB; clears autofill token if URL moves) | settings |
| POST | `/settings/logo`, `/settings/logo/reset` | `brand_store()` (SVG sanitizer, PNG→icons/ICO), `brand_reset()` | settings |
| POST | `/settings/password` | sign-in on/off (`auth_turn_on/off`) | users |
| POST | `/settings/users[/{id}[/(role\|password\|rename\|delete\|approve)]]` | users.php | users |
| POST | `/settings/roles[/{key}[/delete]]` | roles.php | users |
| POST | `/autofill/suggest` | **[#28]** `autofill_suggest_path()`: upload `image` (tmp file) or stored `photo_id` (analyses the kept original when there is one, else the served JPEG; never rewrites either) | create |
| POST | `/settings/autofill/token` | write-only token save/clear | settings |
| POST | `/settings/autofill/test`, `/settings/autofill/test-photo` | `autofill_test()`, `autofill_test_photo()` | settings |
| GET/POST | `/settings/{section}[/reset]` | 12 sections (`SETTINGS_SECTIONS`), `settings_save()` | settings / users (security) |
| GET | `/barcode`, `/barcode/{code…}` | `items_by_barcode()`, `barcode_target()` (code may contain `/`) | view |
| GET/POST | `/filters`, `/filters/{id}/(rename\|delete)` | saved filters | view / edit |
| GET/POST | `/(locations\|categories)[/{id}[/(rename\|default\|delete\|parent)]]` | taxonomy (items.php `tax_*`, `location_set_parent`) | view / edit |
| GET/POST | `/stocktake`, `/stocktake/{id}[/(check\|report\|finish\|cancel)]` | stocktake.php | view / stocktake (+move) |
| GET | `/labels`, `/item/{id}/label` | QR labels (qr.php `qr_svg`) | labels |
| GET | `/activity[?item=]` | `activity_list()` | view |
| GET | `/backup`, `/backup.zip` | `backup_create()` | backup |
| POST | `/backup/restore`, `/backup/restore/confirm` | `backup_open()`, `backup_restore()` (token in `DATA_DIR/tmp`) | backup |
| GET/POST | `/import`, `/import/{id}[/(preview\|commit)]` | import.php (≤5 MiB, ≤5000 rows) | import |
| GET | `/export.csv` | CSV export (formula-injection protection `csv_protect`) | export |
| – | anything else | 404 page/JSON; `UserError` → 4xx; other `Throwable` → 500 "Server error" | – |

Global response headers on every app response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Referrer-Policy: same-origin`, strict CSP (`default-src 'self'`, no inline script/style, `img-src 'self' blob: data:`,
`connect-src 'self' blob:`, `form-action 'self'`, `frame-ancestors 'self'`).

## A.3 Pages / views (`src/*views.php`, all server-rendered strings, `h()` escaping)

`views.php`: list (`view_list`, cards/compact, chips, filter sheet, saved filters, stats overview with SVG bars,
recent, favourites, bulk bar, undo bar), item page (`view_item`, lend section, attachments, badges), item form
(`view_form`, picker, autofill Analyze buttons `autofill_analyze_html`), manage (`view_manage`, tabs, parent options,
`view_tax_delete`), barcode (`view_barcode`, scan dialog), lent list, bulk delete confirm, merge pick/confirm, login,
register, account, error, activity, backup, labels. `settings_views.php`: Control Panel home + 12 sections (general,
appearance, locale, list, items, limits, labels, uploads, autofill, security, data, system) incl. users/roles editor,
accent preview + WCAG warnings, autofill test result + token form. `stocktake_views.php`, `import_views.php`
(home/map/preview), `photo_views.php` (photo editor). Visual baselines: `tests/visual/__screenshots__/` (light, dark).

## A.4 Forms (POST fields that matter for parity)

* Item form → `/save`: `id`, `name`, `quantity`, `min_qty`, `location_id`/`new_location`, `category_id`/`new_category`
  (`__new` sentinel), `tags` (comma list), `notes` (Markdown subset, ≤20000), `value` (money, `79,90`/`79.90`),
  `purchase_date`, `expires_on`, `serial`, `barcode`, `photos[]` (multiple files), `after` (`another`), `csrf`.
* **[#28]** Photo-manager form (`/item/{id}/photos`) carries `MAX_FILE_SIZE` = `max_upload()` (`src/views.php:783`); the
  browser reads it to decide whether to shrink (see A.8).
* Login/register/account: `username`, `password`, `confirm`, `current`, `new`.
* Settings sections: one field per key in `settings_schema()`; autofill token via its own form (write-only).
* Bulk: `ids[]`, `action`, `target`, `tag`, `confirm`, `back`. Lend: `to`, `due`. Stocktake: `location`, `code`, `moves`.
* Import: CSV file, column `mapping[]`, `create`, `skip_errors`. Restore: zip file, `token`, `confirm`.

## A.5 Database (25 migrations, `migrations/0001…0025`; runner `src/db.php::migrate()`)

Tables (SQLite DDL dumped from a fresh `php bin/migrate.php` in Appendix B): `locations`, `categories`, `items`,
`item_photos`, `tags`, `item_tags`, `activity`, `saved_filters`, `settings`, `app_settings` (seed flag),
`item_attachments`, `recent_views`, `undo_tokens`, `stocktakes`, `stocktake_lines`, `users`, `roles`,
`auth_attempts`, `search_docs`, `search_meta`, `imports`, `schema_migrations`; SQLite only: `search_fts` (FTS5) +
`search_fts_v` (fts5vocab); PostgreSQL only: `search_docs.tsv` generated tsvector + GIN, optional `pg_trgm` index,
`locations.parent_id` FK `DEFERRABLE INITIALLY DEFERRED`. SQLite also keeps `PRAGMA user_version = 3` for old
app versions. Legacy pre-migration SQLite v1–v3 installs are adopted (`src/legacy.php`) after a `VACUUM INTO` backup.

**[#28] No schema change and no new migration** (still 0001–0025; a fresh migrate at 5633472 dumps a schema
byte-identical to 56baf17, see Appendix B). Settings: 62 keys in `settings` (JSON-encoded values, `settings_schema()` in `src/settings.php:47`), including the
write-only `autofill_token` (type `secret`) and the legacy `password_hash` (emptied by 0018).

## A.6 Configuration (`src/config.php`, `config.example.php`)

Precedence: built-in defaults < `config.php` (or `$APOTHEKE_CONFIG`) < environment (empty env ignored).
Env map: `DATA_DIR, TZ, APP_PASSWORD, DB_DRIVER, DB_DSN, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SCHEMA,
SQLITE_PATH, MAX_UPLOAD_MB, IMAGE_FULL_MAX, IMAGE_THUMB_MAX, PAGE_SIZE, PUBLIC_URL, CURRENCY, EXPIRY_SOON_DAYS,
SEED_DEFAULTS`, **[#28] `ORIGINALS_DIR`** (`originals_dir`; empty → `<data_dir>/originals`; relative → under the app root;
absolute as given). Read directly with `getenv()` (not in the config map) **[#28]**: `AUTOFILL_UPLOAD_MAX_SIDE` (default
1600, clamped 64–4096) and `AUTOFILL_UPLOAD_JPEG_QUALITY` (default 80, clamped 40–95). Test-only: `APOTHEKE_CLOCK`. Bootstrap values seed the DB settings once (0013); from then
the DB is the source of truth (Control Panel). Docker env: `DATA_DIR=/data`, `PORT=9091`, `TZ`, `APP_PASSWORD`.

## A.7 Data directory (`DATA_DIR`)

`catalog.sqlite` (+ WAL), `images/` and `thumbs/` (`[a-f0-9]{32}.jpg`), `files/` (attachments, random names),
`brand/` (logo + icons), `sessions/` (PHP file sessions, cookie `apothekesid`), `tmp/` (restore/import staging),
`autofill-info.json` (cached `/v1/info` per URL), `catalog-v<N>-backup-*.sqlite` (legacy adopt backups).

**[#28] `ORIGINALS_DIR`** (default `DATA_DIR/originals`, `src/bootstrap.php:20–113`, `src/items.php` `image_original_*`):
`<64 hex>.<jpg|png|webp|gif|heic>` = the upload byte for byte; `map/<32 hex>` = one line, the original's basename, keyed
by the served name (the only link; the served URL never reveals the original's name); `.htaccess` deny file
(`Require all denied` / `Deny from all`). Dirs 0750, files 0640 (`originals_chmod_*`, failures ignored). **Startup
refusal**: if `ORIGINALS_DIR` resolves (lexically or via `realpath`) to `public/` or inside it, every request gets a 500
and CLI exits 1 (`originals_dir_in_web_root`). Photos uploaded before #28 have no original and stay as they are (no data
migration). Docker entrypoint creates `originals/map`. Temp analysis files live in `DATA_DIR/tmp` (`autofill-src-*`,
analysis JPEGs) and are removed in `finally`.

## A.8 Uploads

* Photos, **[#28] option (b)** — `image_store()` (`src/items.php`): accepts JPEG/PNG/WebP/GIF (`getimagesize`, ≤60 MP)
  **and HEIC/HEIF** (ftyp brand sniff). Two outputs per upload:
  1. **original**: `image_original_save()` copies the upload byte-identical into `ORIGINALS_DIR` (0640) + map entry;
  2. **served copy**: EXIF orientation applied, JPEG ≤ `image_full_max` (1600) at `jpeg_quality` (82), metadata stripped,
     + thumbnail ≤ 480 px at q−6 (`image_write_pair`). HEIC → Imagick with libheif when available
     (`image_store_heic`), else a 64×64 grey placeholder (`image_store_placeholder`) — never the HEIC bytes.
  The served copy (`images/`, `thumbs/`) is what views, thumbnails, labels, CSV export, `/media` and the JSON list use;
  the original is never a URL. `image_delete()` also removes the original + map (`image_original_forget`), so delete
  and replace remove all three files. For an undoable delete they stay until the next undoable action prunes the
  token (N-4). `image_copy()` (duplicate) and
  `photo_edit()` (crop/rotate, new version) give the new served name its **own** copy of the original
  (`image_original_copy`). Max per photo `max_upload_mb`; max photos/item `max_photos` (12).
* **[#28] Browser side** (`public/assets/app.js`): the picked file is uploaded **unchanged** when it is ≤ `MAX_FILE_SIZE`
  (default 20 MB). Only a larger file is shrunk on the device (`shrinkImage`, 1600 px, JPEG 0.8) and the form shows
  *"This photo is over the upload limit, so a smaller copy will be saved. The original file is not kept."*
  (`#photo-limit` warnbox / status line). Previews and Analyze use separate shrunk copies.
* Attachments: `src/attachments.php`, magic-byte sniffing, `attach_types`/`attach_max_mb`/`attach_max_per_item`,
  served as download with `default-src 'none'; sandbox`.
* Logo: `brand_store()` PNG/JPEG/WebP/SVG (sanitized), ≤2 MiB, generates icons + ICO.
* CSV import ≤5 MiB/5000 rows; backup restore up to 520 MB through nginx (zip-bomb guards 4 GB/100k entries).
* **[#28] Web-server blocks for originals**: nginx `location ~* ^/originals(/|$) { return 404; }`
  (`docker/nginx.conf`), Apache `public/.htaccess` `RedirectMatch 404 (?i)/originals`, the PHP guard in
  `public/index.php`, and the deny file inside `ORIGINALS_DIR`. `scripts/docker-checks.sh` probes `/originals`,
  `/Originals`, `/ORIGINALS` → 404.
* PHP/nginx limits: `docker/php.ini` (upload 20M, post 22M, `max_file_uploads=4`, memory 256M), `client_max_body_size 22m`.

## A.9 Auth / session (`src/bootstrap.php`, `src/users.php`, `src/roles.php`)

Sign-in optional (`auth_enabled` setting; `APP_PASSWORD` bootstraps account `admin`). Accounts with
`password_hash()`/rehash, constant-time unknown-user verify, session epoch (`user_epoch`, invalidates sessions on
password change), statuses active/pending, self-registration (off by default, optional approval), guest browsing
(role `guest`), configurable roles with 14 permissions, rate limits (`auth_attempts`: login 10/15 min, register 5/h per
client), session cookie `apothekesid` (HttpOnly, SameSite=Lax, Secure when HTTPS / trusted proxy), `session_days`,
session closed early (`init_session()`) so parallel image requests never queue. CSRF token in `$_SESSION['csrf']`.

## A.10 Autofill client (`src/autofill.php` 1,008 lines after #28, routes `public/index.php:690–760`, JS `app.js`)

API v1: `GET /v1/info` (capabilities, `max_image_bytes`, `models`, `image_sizes`, `avg_latency_ms`), `POST /v1/suggest`
(multipart `image` + JSON `context` {categories, tags, locale, model}), `Authorization: Bearer`. The service address is
the Worker URL; docs and UI examples use the placeholder **`https://autofill.example`** [#28]. curl, 3 s connect timeout,
`autofill_timeout` total (3–60, default 50), no redirects, http/https only, response cap 256 KiB. Token write-only, only
sent to the saved URL, cleared when the URL changes; log lines carry at most an 80-char redacted snippet
(`autofill_log_snippet`) [#28]. `/v1/info` cached in `DATA_DIR/autofill-info.json`.

**[#28] Temporary analysis copy** (`autofill_suggest_path`, `autofill_open_analysis`, `autofill_analysis_jpeg`):
* built from the kept original (or the uploaded tmp file); engines: GD then Imagick; HEIC only via Imagick+libheif, else
  `unsupported` and nothing is sent; the original is **never** sent and never rewritten;
* EXIF orientation applied, all metadata stripped, long edge ≤ min(model image size, `AUTOFILL_UPLOAD_MAX_SIDE`=1600),
  never enlarged, JPEG at q80 → q70 → q60 until the **whole multipart body** (`autofill_multipart_body`) is ≤ the limit
  (`max_image_bytes` from `/v1/info`, else `AUTOFILL_SERVICE_IMAGE_BYTES` = **983040** = 1 MiB − 64 KiB); still too big →
  `too_large`, no request;
* written to a temp file and removed in `finally` (`autofill_unlink_analysis`), also on exceptions.

**[#28] Answer handling** (`autofill_suggest_at`, one request, never retries server-side):
* the five failure cases — **HTML 5xx / Cloudflare 1102**, **200 non-JSON**, **200 truncated JSON**, **JSON 5xx that is not
  the API's 503 error shape**, **timeout/unreachable** — all map to code `unavailable`, message *"No suggestions:
  autofill is unavailable right now. You can save the item and fill it in yourself."*; no retry_after, item saving
  unaffected (save never waits for autofill);
* API JSON 503 `backend_unavailable` + `Retry-After` ≤ **60 s** (`AUTOFILL_RETRY_AFTER_MAX`) → `busy` + `retry_after` (warming
  / busy); > 60 s (e.g. the daily cap until 00:00 UTC) → plain `busy`, no retry; other API 503 code → `backend_error`;
* 401/403 → unauthorized, 413 → too_large, 415 → unsupported, other 4xx → bad_response.
* **Browser retry** (`app.js` `autofill()`): at most **once**, only for `code === 'busy'` with `0 < retry_after ≤ 60` and ≤ the
  timeout; the countdown never disables the form or Save; the retry re-posts the same picked/stored photo to Apotheke,
  which builds a fresh analysis copy — only that copy reaches the Worker.
* Control Panel photo test uses the same pipeline and the same single retry.
Test stub modes added in #28: `html` (502 1102 page), `htmlfail` (503 1102 page), `text`, `truncated`, request counter
(`/_stub/count`) and per-request sha256/size recording (`tests/stubs/autofill-stub.php`).

**Pending patch on main after #28 (QA findings, not merged at 5633472) — must be in main before the CI4 parity freeze:**
* **L-4**: server-undecodable photos (HEIC from desktop Safari / iOS "keep originals") now get a grey placeholder on a
  GD-only install, which is the shipped Docker image → add **Imagick with libheif (HEIC)** to the Docker image (or a
  browser-made JPEG as the served copy).
* **N-6**: the startup-refusal 500 page shows the absolute web-root path → generic page, path only in the log.
* **N-7**: the refusal uses `fwrite(STDERR, …)`, undefined under php-fpm → `error_log()` / `defined('STDERR')`.
* **N-8**: README still says the browser converts decodable images to JPEG; `autofill.md` HEIC paragraph to say only the
  analysis copy is converted.
Also open but not in the patch: L-2 (pre-existing: undecodable JPEG / >60 MP → 422), N-2, N-4, I-1
(`docs/qa/apotheke-pr28-resize-qa.md` §4).

## A.11 CLI scripts (`bin/`)

`migrate.php [--status]`, `seed.php [--demo]`, `backup.php [file]`, `restore.php file [--dry-run|--yes]` (**[#28]** both
carry originals, see A.15),
`settings.php list|get|set|reset|users|user add|approve|password|role|…`. Docker docs call
`php /var/www/app/bin/backup.php` from cron, so these paths are a public interface.
Dev/CI scripts: `scripts/lint.sh, smoke.sh, docker-checks.sh, coverage.sh, check-commits.sh, check-links.mjs,
release.php, update-snapshots.sh, e2e-container-setup.sh, coverage-readme.php`.

## A.12 CI workflows (`.github/workflows/`)

* `ci.yml` (on every `pull_request` — any base — and push to `main`): Lint (PHP 8.3, node 20, shellcheck);
  Tests matrix sqlite/pgsql (postgres:17, CLI migrate idempotency + seed, PHPUnit unit + HTTP suite, coverage on
  sqlite); Headless mobile checks (puppeteer); Visual E2E Playwright 1.64 in 3 shards; Docker build + smoke (SQLite
  and PostgreSQL, docker-checks, graceful stop, Playwright @smoke against the image); Conventional Commits; Secret scan
  (pinned gitleaks 8.30.1, full history).
* **[#28]** Secret scan now runs `gitleaks git --log-opts="--all"` (every ref) with `actions/checkout` pinned to the
  v4.4.0 SHA.
* `release.yml` (after CI success on push to `main`): SemVer from commits, VERSION/CHANGELOG, tag, GitHub Release.
* `snapshots.yml` (label `update-snapshots`): re-render Playwright baselines.

## A.13 Tests

`tests/integration.php` (~1,004 assertion calls after #28; QA reported 1,413 checks passing at the #28 head,
49+ sections, black-box over HTTP via `php -S`, `tests/lib.php` Server/Client),
`tests/integration_autofill.php` (~189 assertions against the stub), `tests/Unit/*` (PHPUnit classes, **[#28]** new `OriginalsTest`, extended `AutofillTest`, `BackupTest`, ~6.7k lines incl.
integration files), `tests/visual/*.spec.ts` (~148 Playwright tests, **[#28]** new `originals.spec.ts` + light/dark baselines), `tests/e2e/mobile.mjs`.

## A.14 Hosting constraints found in the repo

* PHP **≥ 8.2** (`composer.json` `require.php` and `config.platform.php = 8.2.0`); CI and Docker use **8.3**
  (`php:8.3-fpm-alpine`). Required ext: gd, mbstring, pdo + pdo_sqlite or pdo_pgsql; optional exif, dom, zip, curl.
* **No runtime Composer packages, no framework, no CDN, no JS build** (`AGENTS.md` "Hard constraints", README
  "Requirements"). Must work fully offline on a LAN.
* **Deployment is Docker-only (Stathis, 2026-10-20).** Apache/`.htaccess` files stay in the repo for parity but are not a
  supported target.
* Docker: nginx + php-fpm + tini, port 9091, volume `/data`, healthcheck `/healthz`; code copied root-owned
  (`public src migrations bin config.example.php VERSION`), no `vendor/` in the image today.
* Web-server contract: docroot `public/`, everything else → `public/index.php`, only that PHP file runs; `src/ data/
  bin/ migrations/ docker/ tests/ vendor/ config.php` blocked (nginx already blocks `vendor`). Apache:
  `FallbackResource /index.php`. Dev: `php -S … public/index.php`.
* Databases: SQLite (default) and PostgreSQL 17; every query must work on both.

## A.15 Backup / restore with originals **[#28]** (`src/backup.php`)

Zip entries: `backup.json` (unchanged format `apotheke-backup` v1), `images/<32hex>.jpg`, `thumbs/<32hex>.jpg` (served
copies), **`originals/index.json`** (served name → original basename, only for photos that have one) and
**`originals/<64hex>.<jpg|png|webp|gif|heic>`** (byte-identical, stored uncompressed), `files/…`. `backup_open()`
whitelists exactly these names. Restore (`backup_restore_originals`) writes missing originals (0640) and rewrites the map
entries; a broken index is a warning, a non-writable originals dir is a warning; older zips without `originals/` restore
unchanged. Web restore and `bin/restore.php` share the code. CSV export names only the served photo.
