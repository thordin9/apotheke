# Feature 12 — Migrating Apotheke to CodeIgniter 4: plan (rev. 3)

*Status: approved rev. 3 (PM + QA), committed in PR 1.*
*Base: **`v2.5.0`** = **`87850e379d85d6690e232ba107373208f9b7ffbd`** (tag object `85a1a3f`, 2026-10-20 10:11 EEST),
`chore(release): v2.5.0` on top of `5633472` (merge of PR #28; its tree equals PR head `56f5b08`). **The v2.5.0 tag check is verified** (QA PASS at 10:15 EEST, 87850e3, only VERSION, CHANGELOG and README coverage tables changed). The diff
from 56f5b08/5633472 is those files (`VERSION`, `CHANGELOG.md`, `README.md`), as reported by Coder,
re-checked here, together with the **PM's re-scan of 87850e3** (gitleaks + trufflehog clean, production
hostname 0 hits). Every code reference in this plan holds for v2.5.0 unchanged.*
*Rev. 1: 56baf17 (v2.4.1). Rev. 2: 5633472. Rev. 3: v2.5.0 (87850e3). The changelog is at the end of this file.*
*Target: **CodeIgniter 4.7.5** (2026-10-09; Packagist: `php ^8.2`, `ext-intl`, `ext-mbstring`,
`laminas/laminas-escaper ^2.18`, `psr/log ^3.0`) on **PHP 8.4**.*

Appendices: [A — feature inventory with file references](APPENDIX-A-inventory.md),
[B — real SQLite schema dumped from a fresh migrate at 5633472](APPENDIX-B-schema.sql.md).

---

## Decisions log

| When (EEST, 2026-10-20) | Who | Decision |
|---|---|---|
| before 10:00 | Stathis | Drop the "no framework / no runtime Composer" rule (AGENTS.md, README) and adopt CodeIgniter 4 with pinned deps. Existing sessions may be invalidated once (one-time re-login). Docker-only: no non-Docker installs and no vendored zip. |
| 10:08 | PM | No lock on main, and main is merged into `feature/ci4` after each main release. Cutover = **3.0.0**; 2.5.x gets security fixes only, for 90 days. The 25 migrations stay byte-identical and run through a PDO runner in its own module, with tests. **PHP 8.4**, and CI on `feature/ci4` runs 8.4. About 60 MB of image growth is accepted, with before and after sizes in the cutover PR. Coder owns the L-4 patch (Imagick+HEIC, N-6, N-7, N-8), and the freeze starts when it lands. Neurons (as then proposed): stub-only per PR plus one live pass at cutover (≤70). |
| 10:09 | Stathis | **Approved by Stathis (2026-10-20 10:09 EEST):** "yes to all", covering all PM decisions above and QA's rev-2 review items. |
| 10:11 | PM | Rev-2 review "approve with changes" (H-1, H-2, M-1…M-6, L-1…L-3, nits), all folded in below; the PM confirmed all of QA's points. |
| 10:15 | PM | **Neuron plan finalised:** stub-only on every PR plus **two live checks of ≤25 Neurons each**, one on the autofill client PR and one at cutover (**≤50 total**). This **replaces the single ≤70 pass Stathis saw at 10:09** and stays within that approved budget (§4.4). |
| 10:15 | QA, PM | **Base verified:** v2.5.0 tag check passed (QA); re-scan of 87850e3 clean (PM). |

These decisions are reflected in the body. §8 lists what is still open.

---

## 0. Summary

1. **No schema change.** CI4 reads and writes the same 22 tables (plus FTS/tsvector extras), the same
   `schema_migrations`, the same `settings` JSON values and the same storage layout, including #28's `ORIGINALS_DIR`
   (byte-identical originals, `map/` pointers, deny file, 0750/0640). #28 added no migration (the dump is identical to
   v2.4.1). Upgrade happens in place, and rollback to the 2.5.x image needs no data step. No migration script is needed.
   The backup and rollback runbook (§3.6) is still part of the cutover. **No 0026+ on 3.0.x** unless it is also
   backported to 2.5.x during the 90-day window (§3.4).
2. **Strangler port, side by side.** CI4 lives in `ci4/` next to the untouched legacy app during the branch, and the same
   black-box suites run against both. The cutover moves `ci4/` to the root.
3. **Branches.** `feature/ci4` is cut from `v2.5.0` (87850e3, verified). Every CI4 PR targets it. **There is no
   lock on main.** Main is merged into `feature/ci4` (merge commits) **after each main release**. The cutover is the only
   CI4 PR into main and ships **3.0.0**. A **`release/2.5`** branch carries 2.5.x security fixes until 3.0.0 + 90 days
   (§4.5).
4. **Same contracts.** URLs (including `/originals` → 404 in any spelling), form fields (including `MAX_FILE_SIZE`), JSON
   shapes and codes (`unavailable`/`busy`), status codes, headers, cookie names, `trust_proxy` + `X-Forwarded-Proto`
   Secure-cookie behaviour, CLI argv/output/exit codes (`bin/*.php`), env vars (including `DB_DSN`, `ORIGINALS_DIR`,
   `AUTOFILL_UPLOAD_MAX_SIDE`, `AUTOFILL_UPLOAD_JPEG_QUALITY`), the backup zip (including `originals/`), and the Docker
   port, volume and healthcheck all stay the same. The Playwright baselines must pass unchanged.
5. **Stathis's decisions are applied.** The rule change is the first PR. There is no session migration (one-time
   re-login). Deployment is Docker-only. The Apache files stay as tested defence in depth.
6. **PHP 8.4.** The CI4 image and the CI on `feature/ci4` run 8.4. An early PR runs the legacy suite on 8.4 and fixes any
   breakage separately from the port. **Parity compares legacy@8.4 with CI4@8.4**, so 8.4 deprecations are never counted
   as port differences. **Legacy@8.3**, the shipped 2.5.x image, stays the reference for upgrade and rollback.
7. **Parity target = main at v2.5.0 plus the L-4 patch** (Coder; Imagick+libheif in Docker, N-6, N-7, N-8). The freeze
   starts when that patch lands. Known legacy quirks (N-2, QA L-2, N-4, I-3) are ported **as they are** and fixed after the
   cutover (§5.2, R12).
8. **Autofill image size** is what goes out: the long edge is **min(model image size, 1600) px**. Today that is
   **1024 px**, because the Worker's `/v1/info` lists no `image_sizes` (QA I-1). The 1600 cap applies only when a service
   advertises larger sizes.
9. **Neurons:** stub-only per PR; two live checks of ≤25 each (autofill client PR, cutover), ≤50 total (§4.4).

---

## 1. Feature inventory of current main

Full inventory with file references: **Appendix A** (items new in #28 are marked **[#28]**). In short:

| Area | Legacy files | Notes for the port |
|---|---|---|
| Front controller / router | `public/index.php` (~75 route branches) | `/originals` guard first; HEAD = GET; JSON vs HTML; 303 redirects; `UserError` → 4xx; 413 on oversize POST; global security headers |
| Dev router | `src/dev_server.php` + `php -S … public/index.php` | static files under `public/` only, no `..`, never `*.php` → CI4 `public/router.php` equivalent (M-2) |
| Core helpers + bootstrap | `src/bootstrap.php` | helpers; `request_is_https()` (`trust_proxy` + first `X-Forwarded-Proto` value) → Secure cookie; **[#28]** `ORIGINALS_DIR` resolution and startup refusal |
| Config | `src/config.php`, `config.example.php` | defaults < config.php < env (21 mapped vars incl. `DB_DSN`, `ORIGINALS_DIR`) + 2 direct `getenv` autofill knobs; **PDO DSN strings** (`DB_DSN`, `config_dsn()`) need a translator for CI4 drivers (M-2) |
| DB + migrations | `src/db.php`, `src/legacy.php`, `migrations/0001–0025` | migrations call `settings_seed` (0013, 0022, 0023), `settings_cache` (0024, 0025), `natkey` (0002), `app_time` (0018, 0022), `now_utc` (0024, 0025), `ROLE_DEFAULTS` (0022); `settings_seed` itself uses `settings_schema`, `setting_default` (reads config) and `now_utc`; the runner uses `legacy.php` (`fold`, `clean_line`) and `seed.php` → shim (H-1, §3.4) |
| Items domain | `src/items.php` | **[#28]** option (b) storage: byte-identical original + stripped served copy; HEIC via Imagick or placeholder |
| Bulk / merge, favourites / recent | `src/items.php` (bulk), `src/merge.php`, `src/favourites.php` | own CI4 modules (M-5) |
| Notes Markdown | `src/markdown.php` (`md_render`, used in `views.php:757`) | own module (M-2) |
| Activity | `src/items.php` (`activity_*`), route `/activity` | own controller (M-2) |
| Backup | `src/backup.php` | **[#28]** `originals/index.json` + `originals/<64hex>.<ext>`; restore writes them back |
| Autofill client | `src/autofill.php` (**1,008 lines**), `app.js` | **[#28]** temporary analysis copy (EXIF-rotated, stripped, long edge **min(model size, 1600)** = **1024 px today**, q80→70→60, whole multipart ≤ `max_image_bytes` or **983040**, deleted in `finally`); five failure cases → `unavailable`; JSON 503 retry only if Retry-After ≤60 s; one browser retry that never blocks Save |
| Browser upload | `public/assets/app.js` | **[#28]** picked file unchanged when ≤ `MAX_FILE_SIZE`; shrink only above it, with an "original not kept" notice |
| Settings CLI | `bin/settings.php` | **11 top-level commands, 19 invocation forms** (list below) + 4 error paths → golden tests (M-3) |
| Web server | `docker/nginx.conf`, `public/.htaccess`, `docker/entrypoint.sh` | **[#28]** case-insensitive `/originals` blocks |
| CI / release | `ci.yml` (gitleaks `git --log-opts=--all`), `release.yml` (`workflow_run` on `main` only), `snapshots.yml`, `scripts/release.php` (`git describe` last tag) | `release/2.5` support (M-4) |

**`bin/settings.php` forms (counted at v2.5.0):** `list` (also the default with no argument), `get KEY`,
`set KEY VALUE`, `reset SECTION`, `users`, `user add NAME ROLE [PASSWORD]`, `user approve NAME`,
`user password NAME [PASSWORD]`, `user role NAME ROLE`, `user rename NAME NEWNAME`, `user delete NAME`, `roles`,
`roles reset`, `auth on`, `auth off`, `password [PASSWORD]`, `password --off`, `export`, `import [FILE|stdin]` =
**19 forms across 11 top-level commands**. The error paths are: unknown command (stderr, **exit 2**), `UserError` (stderr,
**exit 1**), `user` usage, `auth` usage, and the interactive password prompt with a mismatch.

**Hosting constraints:** **Docker-only.** Today's image is `php:8.3-fpm-alpine` + nginx + tini, port 9091, `/data`
volume, `/healthz` healthcheck. **The CI4 image moves to `php:8.4-fpm-alpine`** (PM decision). SQLite is the default and
PostgreSQL 17 is supported. LAN-offline, no CDN, no JS build, strict CSP. The CI4 `composer.json` declares `php: ^8.4`
with platform `8.4.x`.

**What CI4 adds to the image:** `ext-intl` (ICU), `sqlite3` and `pgsql` (CI4 has no PDO driver; `pdo_sqlite`/`pdo_pgsql`
stay for the migration runner), `vendor/` and `WRITEPATH`. The L-4 patch adds Imagick + libheif. **About 60 MB of growth
is accepted.** The cutover PR records the before (2.5.x + L-4) and after sizes.

---

## 2. CI4 module layout

Shown as during the branch (`ci4/`). After cutover the same tree sits at the root.

```
ci4/
  app/                         thin CI4 shell: only framework glue
    Config/  App.php (baseURL from PUBLIC_URL or the request; proxyIPs EMPTY — see Core/Http/RequestScheme;
             reads trust_proxy once per request and the session filter reuses it, SEC-N11)
             Autoload.php (PSR-4 Apotheke\ => modules/), Database.php (built by Core DatabaseFactory),
             Filters.php, Routing.php (autoRoute off), Session.php (FileHandler, DATA_DIR/sessions, cookie
             apothekesid, Secure flag from RequestScheme, lifetime from session_days through Core/Http/SessionLifetime,
             SEC-N9), Security.php (CI4 CSRF off), Toolbar.php (off outside
             development), Exceptions.php (UserError → 4xx; else 500 "Server error"), Services.php (factories only)
  modules/
    Core/        Config/ApothekeConfig (defaults < config.php < env, same env map incl. DB_DSN, ORIGINALS_DIR)
                 Config/DsnTranslator (PDO DSN → CI4 driver config, M-2: `sqlite:<path>` → SQLite3 `database`;
                   `pgsql:host=…;port=…;dbname=…[;sslmode=…;…]` → Postgre hostname/port/database + a pg_connect
                   string for extra keys; unknown or unsupported DSNs fail at boot with a clear message; table test)
                 Config/DatabaseFactory (SQLite3: WAL, busy_timeout, synchronous NORMAL, foreign_keys;
                   Postgre: UTC, DB_SCHEMA create + search_path), Database/Dialect
                 Http/RequestScheme (port of request_is_https(): HTTPS/port 443, or trust_proxy ON and the first
                   X-Forwarded-Proto value == https; drives Secure cookies and public_url; NOT CI4 proxyIPs, M-2)
                 Filters/ OriginalsGuard (first), SecurityHeaders, HeadAsGet, PostTooLarge, JsonNegotiation,
                   CsrfFilter (global before-filter, SEC-N10: legacy check_csrf() on every non-safe method;
                   session token from the `csrf` field or `X-CSRF-Token`, 419; never starts a session)
                 Boot/StoragePreflight (ORIGINALS_DIR vs FCPATH, generic refusal page (N-6), error_log (N-7))
                 Helpers/apotheke_helper.php, Exceptions/UserError, Libraries/Clock + Responder, Views/layout…
    Migrations/  Apotheke\Migrations — the PDO runner in its own module (PM decision, H-1)
                 PdoMigrationRunner   port of migrate(): schema_migrations, one tx, BEGIN IMMEDIATE /
                                      pg_advisory_xact_lock(7243100), legacy v1–v3 adopt (VACUUM INTO), user_version 3;
                                      opens its own PDO from ApothekeConfig/DSN and **closes it before** CI4 connects
                 LegacyFunctions.php  shim, kept after cutover: byte-identical behaviour of every global the 25 files
                                      and the runner call — settings_seed, settings_cache, settings_schema,
                                      setting_default, now_utc, app_time, natkey, fold, clean_line, ROLE_DEFAULTS
                                      (+ legacy_migrate_to_v2/v3, seed_defaults); settings_schema/setting_default
                                      delegate to Settings\SettingsSchema (single source), golden-tested against legacy
                 Commands/ apotheke:migrate [--status]; bin/migrate.php wrapper
    Auth/        Login/Register/Account/Logout controllers; UserModel, RoleModel, AuthAttemptModel; AuthService,
                 Permissions, RateLimiter; filters Permission (`perm:<name>`), SessionRelease (Csrf is Core's
                 CsrfFilter)
    Settings/    SettingsSchema (62 keys), SettingsService, SettingsRepository, Branding; Control Panel;
                 Commands/SettingsCommand (all 19 forms) + bin/settings.php wrapper
    Taxonomy/    LocationModel, CategoryModel, TagModel, ItemTagRepository, LocationTree; controllers
    Notes/       Apotheke\Notes — MarkdownRenderer (port of markdown.php: safe subset, MD_MAX_LEN 20000), used by
                 Catalog views and the activity diff; golden corpus vs md_render (M-2)
    Catalog/     ItemModel + Item, ItemService, ListQuery, Stats, SavedFilterModel, Barcode; controllers, views
    BulkMerge/   Apotheke\BulkMerge — BulkService (move/category/tag_add/tag_remove/delete/labels, BULK_MAX 500),
                 MergeService (preview, merge, candidates), Duplicate, QuantityAdjust; controllers (M-5)
    Favourites/  Apotheke\Favourites — FavouriteService, RecentViewsRepository (scope device cookie apo_dev or
                 install), controllers /item/{id}/fav, /recent/clear (M-5)
    Search/      SqliteFtsEngine, PostgresTsEngine, LikeEngine; SearchDocsRepository
    Media/       Storage/OriginalStore, Storage/ServedImageWriter (+HeicConverter, Placeholder), ImageStore,
                 PhotoModel, PhotoEditor, PhotoOrder, MediaController, AttachmentModel, AttachmentStore
    Activity/    ActivityModel, ActivityLogger (same tx); **ActivityController** for `/activity[?item=]` with paging
                 (ACTIVITY_PAGE 50) and the deleted-item fallback (M-2)
    Undo/        UndoTokenRepository, UndoService (held files incl. originals; released at the next prune = N-4)
    Lending/  Stocktake/  Labels/  ImportExport/  Backup/ (with originals)  Seed/
    Autofill/    AutofillClient (raw curl), AnalysisCopyBuilder, TempFile, ResponseClassifier, InfoCache,
                 TokenPolicy, LogRedactor; controllers Suggest, SettingsAutofill
    DevServer/   Apotheke\DevServer — `ci4/public/router.php` for `php -S 127.0.0.1:9091 -t ci4/public
                 ci4/public/router.php`: port of dev_server_static_file() (real files under public/ only, no `..`,
                 never *.php) + the OriginalsGuard, else hand to CI4's index.php; used by tests/lib.php Server (M-2)
  public/index.php, public/router.php, public/.htaccess   spark   writable/ (cache/logs only)
bin/*.php                      thin wrappers calling spark (argv, output and exit codes identical — golden tests)
```

**Modularity rules (the user's explicit requirement):**

* **Dependency graph, enforced in CI** with deptrac (dev-only; a PHPStan rule is the fallback) (L-1). Allowed edges
  (→ = may depend on): every module → Core; Migrations → Settings (schema only); Auth → Settings; Media → Settings;
  Catalog → Taxonomy, Media, Search, Activity, Undo, Notes, Settings; BulkMerge → Catalog, Taxonomy, Media, Activity,
  Undo; Favourites → Catalog; Lending/Stocktake/Labels → Catalog, Taxonomy; ImportExport → Catalog, Taxonomy;
  Backup → all repositories + Media + Settings; Autofill → Media (read-only `originalPathOrServed`), Settings,
  Taxonomy (context names). No cycles; Core depends on nothing. A violation fails the Lint job.
* **Constructor injection everywhere.** `service()` / `Services::` calls are allowed **only in controllers** and
  filters, which CI4 builds without constructor arguments, inside `before()`/`after()` only (and
  `Config/Services.php` factories; conditions in ADR 001). Services, models and commands get their collaborators through constructors. A
  PHPStan rule enforces this (L-1).
* Thin controllers. Services hold the rules and are shared by CLI and web. CI4 `Model` for single-id tables, Query
  Builder repositories for composite/text keys. Filters for cross-cutting concerns. Views ported verbatim. No inline
  script/style. `.env` optional. `strict_types`, PHPStan 6, PHP-CS-Fixer, soft size limits.
* **Tests per PR (L-3):** every new service has unit tests in the same PR, and per-area line coverage on CI4 must be
  **≥ the legacy coverage of the corresponding `src/` files** (pcov, as `scripts/coverage.sh`). The PR shows both
  numbers.

---

## 3. Database compatibility analysis

### 3.1 Method

The schema comes from the migration files and a fresh migrate at 5633472 (Appendix B), which is byte-identical to
v2.4.1. Compatibility is enforced by schema-dump equality (legacy vs CI4 runner), cross-version start tests and the
upgrade-from-every-version test (§3.4).

### 3.2 Table-by-table mapping

| Table (migration) | Key | CI4 type | Entity casts / notes | Care points |
|---|---|---|---|---|
| `locations` (0001, 0002, 0008) | id | `LocationModel` | is_default, parent_id ?int | partial unique "one default"; PG parent FK DEFERRABLE; depth/cycles |
| `categories` (0001, 0002) | id | `CategoryModel` | is_default | "one default" |
| `items` (0001…0015) | id | `ItemModel` + `Item` | ints, ?ints, cents, dates as text | `search`/`sort_key` from PHP fold/natkey (byte-identical); `image` = served cover |
| `item_photos` (0003, 0021) | id | `PhotoModel` | position, version | served name only; original via `map/` |
| `tags` / `item_tags` (0004) | id / composite | model / repository | – | composite PK |
| `activity` (0005, 0018) | id | `ActivityModel` | details JSON | same transaction |
| `saved_filters` (0011) | id | `SavedFilterModel` | – | – |
| `app_settings` (0002), `settings` (0013) | name | repositories | JSON | 62 keys; write-only token |
| `item_attachments` (0014) | id | `AttachmentModel` | size BIGINT | – |
| `recent_views` (0015) | composite | Favourites repository | seq BIGINT | – |
| `undo_tokens` (0016) | token | repository | JSON | held files incl. originals |
| `stocktakes`, `stocktake_lines` (0017) | id | models | JSON | unique pair |
| `users`, `roles`, `auth_attempts` (0018, 0022) | id | models | hash hidden | rehash |
| `search_docs`, `search_meta`, `search_fts*` (0019) | item_id / k | repositories + raw SQL | – | PG `tsv` generated; FTS5 present |
| `imports` (0020) | id | `ImportModel` | JSON | `DATA_DIR/tmp` |
| `schema_migrations` | version | `PdoMigrationRunner` | – | kept; CI4 `migrations` table unused |

### 3.3 Driver-level differences

CI4's SQLite3/Postgre drivers replace PDO for the app. This affects type casts (pgsql returns strings), `insertID`,
LIKE `!` escaping, pragmas (in `DatabaseFactory`), raw `BEGIN IMMEDIATE` / advisory locks and the `db_local_bucket`
SQL. All are covered by the driver matrix and typed-JSON parity. `DB_DSN` goes through `DsnTranslator` for the app and
is used **unchanged** by the PDO runner.

### 3.4 Migration strategy (decided)

* **The 25 migration files stay byte-identical** and run through `Apotheke\Migrations\PdoMigrationRunner` on a
  short-lived PDO connection. Every global they need comes from **`Migrations/LegacyFunctions.php`**, a shim with
  byte-identical behaviour that is **kept after the cutover**. Exact call sites at v2.5.0:

  | Migration | Calls |
  |---|---|
  | 0002 | `natkey` (→ `fold`) |
  | 0013, 0023 | `settings_seed` (→ `settings_schema`, `setting_default` incl. `cfg:*`/`cfg_auth`/`cfg_hash` defaults from config, `now_utc`, `settings_cache`) |
  | 0018 | `app_time` (+ reads `settings`) |
  | 0022 | `ROLE_DEFAULTS`, `app_time`, `settings_seed` |
  | 0024 | `now_utc`, `settings_cache` |
  | 0025 | `now_utc`, `settings_cache` (guarded by `function_exists`; `settings_schema` only named in a comment) |
  | runner | `legacy_migrate_to_v2/v3` (`fold`, `clean_line`), `seed_defaults`, `now_utc` |

  (`fold` in 0001 is only inside an SQL comment.)
* **Tests in the Migrations PR (H-1 + QA), all under PHP 8.4 on SQLite *and* PostgreSQL:**
  1. **fresh migrate with `src/` removed** from the checkout, so there is no hidden dependency on legacy code; the
     schema dump and the 62 seeded settings equal legacy's;
  2. **upgrade from every version, 0001 through 0025**: fixture DBs made by the legacy runner stopped after version N
     (with demo data), each upgraded by the CI4 runner, ending equal to a legacy upgrade (schema + data dump);
  3. legacy SQLite v1/v2/v3 adopt fixtures (VACUUM INTO backup written);
  4. **the PDO connection is closed before the CI4 SQLite3 connection opens** (the handle is released; an
     `EXCLUSIVE` lock / `wal_checkpoint(TRUNCATE)` from the CI4 connection succeeds right after migrate);
  5. **concurrent first-request migration with one connection of each kind**: a PDO runner and a CI4 request start
     together. On SQLite (WAL) the second waits on `BEGIN IMMEDIATE`/busy_timeout. On PG the second waits on
     `pg_advisory_xact_lock(7243100)`. Exactly one applies the steps, and neither errors;
  6. golden tests: each `LegacyFunctions` function's output equals the legacy function's over a corpus
     (Greek/Latin folding, natkey numbers, settings defaults under different env/config).
* `apotheke:migrate` / `bin/migrate.php` give the same output as legacy (golden CLI test).
* **No 0026+ on 3.0.x unless it is backported to 2.5.x** during the 90-day support window (PM L-2), so a rollback from
  3.0.x to 2.5.x never meets an unknown schema. After the window, new migrations land on main in the same format.

### 3.5 Storage contract outside SQL

* `DATA_DIR`: `images/`, `thumbs/`, `files/`, `brand/`, `sessions/`, `tmp/`, `autofill-info.json`.
* **`ORIGINALS_DIR` [#28]**: default `DATA_DIR/originals`. A relative path resolves against the app root, an absolute
  one is used as given. Contents: `<64hex>.<ext>`, `map/<32hex>`, deny file, 0750/0640. Startup is refused if the
  directory is inside the web root, checked against **CI4's `FCPATH`**, also through symlinks. Pre-#28 photos without
  originals keep working.
* Sessions are not preserved (decision). Old `sess_*` files are pruned once by the entrypoint.
* Cookies `theme`, `apo_dev`. CSRF field/header names. The Secure flag follows `trust_proxy` + `X-Forwarded-Proto`
  exactly as legacy (`RequestScheme`). CI4 `proxyIPs` stays empty, so no new trust model.
* Backup zip `apotheke-backup` v1 with `originals/index.json` + stored originals, both directions. Pre-#28 zips restore.

### 3.6 Backup and rollback runbook (Docker; tested in CI)

1. Before upgrading: `docker compose exec apotheke php /var/www/app/bin/backup.php /data/pre-3.0.zip` (includes
   originals), plus a `/data` volume snapshot or `pg_dump -Fc`. Back up `ORIGINALS_DIR` separately if it is outside
   `/data`.
2. Start the 3.0.0 image. Check that `/healthz` is 200, `bin/migrate.php --status` shows nothing pending, and
   `docker-checks.sh` shows `/originals` 404 ×3. Users sign in again.
3. **Rollback A:** the 2.5.x image (**PHP 8.3, the reference**) on the same `/data`. No data step (PM L-2 guarantees no
   unknown migration).
4. **Rollback B:** restore the snapshot or dump, or `bin/restore.php pre-3.0.zip --yes` on 2.5.x.
5. CI job "upgrade-rollback": **legacy@8.3 (2.5.x image) → CI4@8.4 → legacy@8.3**, both drivers, demo data with
   JPEG/PNG/HEIC originals. It compares DB dumps and sha256 of `images/`, `thumbs/`, `originals/`, `map/`.

---

## 4. Phased PR breakdown

### 4.1 Gates on every PR into `feature/ci4`

① **green CI** (all jobs, both DBs, PHP 8.4; legacy and ci4 legs) · ② **QA pass**, with the report committed unchanged
as `docs/qa/ci4-prNN-qa.md` · ③ **gitleaks** clean (`gitleaks git --log-opts=--all`) · ④ **PM re-scan** before merge.
Also: no secrets, tokens, real hostnames or IPs (the Worker is always `https://autofill.example`); Conventional Commits;
docs in the same PR; **unit tests for every new service and coverage ≥ legacy** for the area (L-3); deptrac and the DI
rule green (L-1); **the PR adds its own parity-recorder steps** and they pass at 100% (M-6); parity suites for the area
at 100%. Aim for under ~1,500 changed lines, excluding views moved verbatim.

**Step 0 (not a PR):** the maintainer creates `feature/ci4` and `release/2.5` from
`v2.5.0`.

### 4.2 PR list

19 CI4 PRs (1–10, 11a, 11b, 12–18), plus the main-side `release/2.5` PR and L-4.

| # | PR | Scope | Acceptance criteria |
|---|---|---|---|
| 1 | `docs!: allow CodeIgniter 4 (AGENTS.md, README)` — **first** | rule change in `AGENTS.md`, README requirements, `docs/contributing.md`; ADR-001 (CI4, side by side, no schema change, Docker-only, re-login, PHP 8.4) | wording matches Stathis's decision; link check green |
| 2 | `ci(ci4): branch wiring, PHP 8.4` | `ci.yml` push trigger adds `feature/ci4`; PR 2 runs the CI4 leg (PHP 8.4), legacy@8.3 (the upgrade/rollback reference), and the legacy PHPUnit suite on PHP 8.4 (SQLite and PostgreSQL), accepted by the PM on 2026-10-20 for earlier warning; deptrac + DI rule jobs (empty config); release.yml untouched | CI runs on PRs into and pushes to the branch; legacy PHPUnit on PHP 8.4 is green on both databases; nothing is released from it |
| 3 | `test: legacy suite on PHP 8.4; parity recorder + allowlist` | remaining PHP 8.4 suites only (lint, headless mobile checks, Playwright, Docker build + smoke on a `php:8.4` test image) while the PHP 8.3 cells stay; does not add legacy PHPUnit on 8.4 (that runs in PR 2); fix 8.4 breakage in **separate `fix(php84)` commits** (8.3-compatible, no behaviour change); add `tests/parity/` recorder with the first steps (install, healthz, settings) and `tests/parity/allowlist.yml` pre-filled with N-2, QA L-2, N-4, I-3 (§5.2) (M-6). **Implemented:** one `fix(php84)` commit so PHP 8.4 function JIT cannot compile the QR encoder; the 8.3↔8.4 note is [php84-legacy-diff.md](php84-legacy-diff.md) | the remaining legacy@8.4 suites pass everything legacy@8.3 passes; the 8.3↔8.4 legacy diff is recorded and explained; the recorder runs legacy@8.4 vs legacy@8.4 with zero diffs |
| 4 | `feat(ci4): skeleton + Core` | appstarter 4.7.5, `ApothekeConfig`, **`DsnTranslator`**, `DatabaseFactory`, **`RequestScheme`**, `OriginalsGuard`, security filters, `StoragePreflight`, **DevServer router**, `/healthz`, `/health` | endpoints byte-identical; `/originals` ×3 → 404; refusal cases incl. symlink (generic page N-6, `error_log` N-7); 0750/0640; DSN table test (sqlite paths, pgsql host/port/dbname/sslmode, bad DSN → boot error); Secure cookie matrix (direct HTTP/HTTPS, proxy header with trust on/off, comma list); router rejects `..`, `*.php`, serves assets; `composer audit` clean |
| 5 | `feat(ci4): Migrations module (PDO runner + LegacyFunctions)` | `PdoMigrationRunner`, `LegacyFunctions.php`, `apotheke:migrate`, `bin/migrate.php` | **all six tests in §3.4**, under 8.4 on SQLite and PG |
| 6 | `feat(ci4): settings, branding, settings CLI` | SettingsService, Control Panel, export/import, branding, `SettingsCommand`. **Implemented (core):** `SettingsSchema` (single source; the shim delegates), `SettingsRepository`, `SettingsService` (per-request, from `Config\Services`), branding routes behind `BrandStore`, `apotheke:settings` with `list`/`get`/`set`/`reset`/`export`/`import`; the account and sign-in forms (`users`, `user …`, `roles`, `auth`, `password`) move to PR 7; Control Panel pages, export/import routes and logo upload follow in a later PR | 62 keys validate identically; export identical; sanitizer corpus; **CLI goldens: all 19 forms + 4 error paths, comparing argv → stdout, stderr and exit code between legacy and CI4** (and `migrate`, `seed`, `backup`, `restore` goldens added in their PRs) (M-3) |
| 7 | `feat(ci4): auth, CSRF, roles` | sessions (fresh), Csrf 419, Permission filter, login/register/account, users/roles, rate limits | specs `auth`, `roles`, `users`; permission matrix identical; an old cookie gets a clean sign-in page |
| 8 | `feat(ci4): layout, taxonomy, notes` | layout/menu/theme/errors; locations/categories/tags; **Notes module** | `manage`, `locations-nested`, `theme`, `errors` specs; Markdown golden corpus equals `md_render` (incl. links, XSS cases, 20000 cap) |
| 9 | `feat(ci4): catalog list, search, stats, favourites` | ListQuery, search engines, stats, saved filters, **Favourites module** | `list`, `search`, `saved-filters`, `favourites`, `value` specs; search corpus identical on both DBs |
| 10 | `feat(ci4): media storage (originals + served copy)` | OriginalStore, ServedImageWriter, Heic/placeholder, ImageStore, `/media`, `MAX_FILE_SIZE` | byte-identical originals (JPEG/PNG/WebP/GIF/HEIC); served ≤ `image_full_max` (1600), q82, stripped; thumb ≤480; HEIC → Imagick JPEG or placeholder; only served names reach views/labels/CSV/API; QA L-2 behaviour identical (allowlisted); `originals.spec.ts`, `OriginalsTest` |
| 11a | `feat(ci4): item form, save, photos, undo, activity` (rev-2 PR 10, part a) | item view/form/save; photo add/order/cover/delete/edit; undo; **ActivityController**; browser upload rule + notice | delete and replace remove original + map + served + thumb, released at the next undo prune exactly like legacy (**N-4**, allowlisted as identical); edit gives the new version its own original copy, while autofill still analyses the unedited original (**I-3**, identical); `/activity` paging + deleted-item fallback identical; specs `add`, `item`, `photos`, `undo`, `history` |
| 11b | `feat(ci4): bulk, merge, duplicate, quantity, attachments` (rev-2 PR 10, part b) | **BulkMerge module** (bulk, merge, duplicate, qty), attachments | duplicate copies originals; bulk delete honours undo; specs `bulk`, `merge`, `duplicate`, `stock`, `attachments`; DB-diff parity steps |
| 12 | **autofill client PR** `feat(ci4): autofill client` | AnalysisCopyBuilder, TempFile, ResponseClassifier, client, `/autofill/suggest`, settings test/photo test/token | the matrix in §4.3; `integration_autofill.php` unchanged and green; `autofill-*` specs; no token in logs/HTML/JSON; **live check 1 (≤25 Neurons)** per §4.4 with usage posted |
| 13 | `feat(ci4): labels, barcode, lending, stocktake` | QR SVG, labels, barcode, lent list, stocktake | specs; QR round-trip |
| 14 | `feat(ci4): CSV import/export, backup/restore` | ImportExport; Backup with originals (web, spark, `bin/backup.php`, `bin/restore.php`) | cross-restore legacy ↔ CI4 identical (DB + sha256 of images/thumbs/originals/map); pre-#28 zip; whitelist identical; CLI goldens for backup/restore |
| 15 | `build(ci4): Docker image (PHP 8.4)` | `php:8.4-fpm-alpine` multi-stage, intl, sqlite3, pgsql, pdo_*, Imagick+libheif (L-4), entrypoint (`originals/map`, one-time `sess_*` prune), nginx | smoke + docker-checks (incl. `/originals` ×3) on both DBs; graceful stop; `vendor/`, `writable/`, `.env`, `spark` not reachable; size reported |
| 16 | `test(ci4): full parity gate + upgrade/rollback` | every suite against CI4@8.4 vs legacy@8.4; upgrade-rollback job vs legacy@8.3 (§3.6); performance | 100% green; the allowlist holds only the four approved legacy quirks; p95 within +20% |
| 17 | `docs(ci4): docs rewrite` | §6 | link check; QA reads the upgrade guide end to end |
| 18 | **cutover** `feat!: Apotheke 3.0 on CodeIgniter 4` (→ **main**) | move `ci4/` to the root, delete legacy `src/` (LegacyFunctions stays in the Migrations module), drop the legacy legs, keep golden files | PM re-scan, QA full pass, DevOps deploy test, Stathis sign-off; **image size before/after recorded in the PR**; **live check 2 (≤25 Neurons)** per §4.4 with usage posted; release workflow cuts **3.0.0** |

### 4.3 Autofill acceptance matrix (autofill client PR, #12)

Run against the stub on both engine setups (GD + Imagick/libheif, GD only):

1. The stored original and the served copy are unchanged by analysis (sha256 before and after).
2. Only a **temporary analysis copy** is sent. It is a JPEG, EXIF-rotated, with no EXIF/GPS/maker notes. Its long edge
   is **min(model image size, 1600)**, never enlarged. q80, then q70, then q60 only while the **whole multipart body**
   is over the limit; still too big → `too_large` and **no request**. **Stub cases:**
   - `/v1/info` without `image_sizes` → **1024 px** (today's Worker, I-1);
   - `image_sizes` with a recommended size above 1600 → 1600;
   - **no `max_image_bytes` → limit 983040**;
   - `max_image_bytes` set → that limit.
3. The temp file is gone in every path, including exceptions, and `DATA_DIR/tmp` holds no `autofill-*` afterwards.
4. HEIC: only via Imagick+libheif; GD-only → `unsupported`, nothing sent. Other undecodable input → nothing sent,
   **except on the Imagick build, where a corrupt JPEG that GD rejects is still analysed as a stripped, recovered
   re-encode (N-2, ported as is, allowlisted).** **The original is never sent.**
5. **Five failure cases**: HTML 5xx / 1102, 200 non-JSON, 200 truncated JSON, JSON 5xx that isn't the API's 503 shape,
   and timeout/unreachable. Each makes **exactly one request** (stub `/_stub/count`) and returns `unavailable` with the
   "autofill unavailable … you can save the item" message. The **item still saves** and there is no retry.
6. API JSON 503 `backend_unavailable` + Retry-After ≤ 60 s → `busy` with `retry_after`. The browser retries once, the
   countdown never disables Save, and only a new analysis copy reaches the Worker. Retry-After > 60 s → plain `busy`,
   no retry. Any other API 503 code → `backend_error`.
7. 401/403/413/415/4xx are mapped as today. The token only goes to the saved URL. Logs are ≤80 chars and redacted.

**Status (PR #69).** The client follows v2.6.1, not the multipart sentence in item 2. The cap is the JPEG the service
limits. Quality steps down by 10 from the configured quality, and 60 is tried before the long edge shrinks. The
warn-once pair and the temporary JPEG are bytes behind `Apotheke\Core\Storage\Storage` (`autofill/info.json`,
`autofill/tmp/…`) under `DATA_DIR`. There is no per-request global for that pair, and the database stays the existing
DSN-driven connection. The live Neuron check in §4.4 was not run; tests use the stub. Settings HTML and the item-form
button stay on legacy. Media and Taxonomy are not modules yet, so photo lookup and context names are SQL. Media should
extend this storage interface later rather than add a second one.

### 4.4 Live Worker checks (Neurons) — final (PM, 10:15 EEST)

Every PR runs against the stub only. There are **two live checks, each capped at 25 Neurons (≤50 total, under the 70
Stathis approved)**: one on the **autofill client PR** (PR 12 in this numbering; "PR 11" in earlier messages) and one at
the **cutover** (PR 18). Each runs the same way as #28 (throwaway install, recording proxy without headers, Worker URL
only in QA's private config), stops at the cap, and QA **posts the Neuron usage** in the PR's QA report.

### 4.5 `release/2.5` (M-4)

* Cut from `v2.5.0` at Step 0 and **kept until 3.0.0 + 90 days**, then archived (deleted after a final tag check).
* This needs a small **main-side PR** (owner DevOps; it isn't a CI4 PR):
  - add `release/2.5` to `ci.yml` push branches and to `release.yml` `workflow_run.branches`. Workflow-run triggers
    are read from the default branch, so this has to land on main;
  - add a guard so that a run on `release/*` only cuts **patch** versions (`scripts/release.php` picks the bump from
    commit types and finds the last tag with `git describe`, which on `release/2.5` resolves to `v2.5.x`). A `feat` on
    that branch fails the run;
  - add a `docs/releasing.md` section covering backport flow (fix on main or on the branch, cherry-pick with `-x`),
    security-only scope, the 90-day end date, and that migrations must be backported per PM L-2.
* The gates on `release/2.5` PRs are the same four.

### 4.6 Keeping parity with main; freeze

Main stays **unlocked**, and after each main release it is merged into `feature/ci4`. The new tests run on both legs, so
gaps show up as red ci4 legs and get port PRs. **The parity target freezes when Coder's L-4 patch lands on main.** That
patch covers Imagick+libheif in Docker, N-6 (a generic refusal page), N-7 (`error_log`) and N-8 (HEIC wording). Releases
after that point still merge in, but they are ported as their own PRs against the frozen baseline plus the delta. PRs
4, 10, 12 and 15 target the patched behaviour. Until it lands they carry a "pending L-4" allowlist entry, which QA must
clear before PR 16.

---

## 5. Test strategy

### 5.1 Layers

| Layer | Today (v2.5.0) | On the branch |
|---|---|---|
| Lint | `scripts/lint.sh` | + PHPStan 6 incl. the DI rule, **deptrac** graph, PHP-CS-Fixer, `composer validate/audit` |
| Unit | PHPUnit incl. `OriginalsTest`, `AutofillTest`, `BackupTest` | CI4 services with `CIUnitTestCase`, SQLite and PG; **coverage ≥ legacy per area** (L-3); LegacyFunctions goldens |
| HTTP integration | `tests/integration.php` (~1,004 assertion calls; 1,413 checks passed at #28 per QA), `integration_autofill.php` (~189) | unchanged files; `APOTHEKE_APP=legacy\|ci4` picks the server (ci4 via the DevServer router) |
| CLI | – | **golden argv → stdout/stderr/exit** for `settings` (19 forms + error paths), `migrate`, `seed`, `backup`, `restore` |
| E2E/visual | ~148 Playwright incl. `originals.spec.ts`; mobile checks | same specs against CI4; baselines unchanged |
| Docker | smoke, docker-checks (incl. `/originals` ×3), @smoke | on the CI4 8.4 image, both DBs |

### 5.2 Parity

* **Runtime pairing (M-1):** **parity runs compare legacy@8.4 with CI4@8.4** (legacy PHPUnit on 8.4 from PR 2; the remaining 8.4 suites from PR 3). Legacy@8.3,
  the 2.5.x image, is the **reference only for upgrade and rollback** (§3.6), never for parity diffs.
* **Black-box HTTP and E2E parity:** identical pass lists on both DBs.
* **Differential recorder (`tests/parity/`, from PR 3, M-6):** each PR adds the steps for its area. By PR 16 there are
  ~200 steps: install, users, taxonomy, items with JPEG/PNG/HEIC and over-limit photos, bulk, undo of a delete,
  duplicate, photo edit, lend, stocktake, import/export, backup/restore, settings import/export, the CLI, and autofill
  against the stub in every mode. After each step it compares status, `Location`, security headers, typed JSON, the
  DOM-normalised HTML, the DB dump, the file trees (`images/`, `thumbs/`, `originals/` with sha256 against fixtures,
  `map/`, `tmp/` empty after autofill), file modes, and the stub's recorded payloads (never an original's sha256, always
  JPEG without EXIF, content length ≤ the limit).
* **`tests/parity/allowlist.yml` (QA-approved).** It holds only legacy behaviour that is **ported identically** and
  fixed after the cutover (R12):

  | Id | Legacy behaviour kept as is | Parity expectation |
  |---|---|---|
  | N-2 | Imagick build: a corrupt JPEG that GD rejects is still analysed as a stripped recovered re-encode | identical to legacy (original never sent) |
  | QA L-2 | broken JPEG or >60 MP → 422, item not saved; broken HEIC → saved with placeholder | identical to legacy |
  | N-4 | a deleted item's originals stay on disk until the next undoable action prunes the token | identical to legacy |
  | I-3 | edits apply to the served copy; autofill analyses the unedited original | identical to legacy |

  The entries document known quirks; they do not excuse diffs. Each one asserts **sameness**. Any real difference
  is a red test.
* **Cross-version:** legacy-made data dirs and DBs served by CI4 and the reverse; backups both ways; pre-#28 data.
* **Permission matrix:** every route × role × guest/off mode.

### 5.3 Database tests

See §3.4: fresh migrate with `src/` removed, upgrade from 0001–0025, v1–v3 adopt, PDO closed before SQLite3 opens, and
concurrent mixed-connection first migration. All on **PHP 8.4, SQLite and PG**. Plus schema-dump equality, pragmas,
FTS5, type round-trips and the DSN translator table.

### 5.4 Security tests

CSP on every response and a CSP listener; CSRF 419 on every POST; blocked paths (`vendor/`, `writable/`, `.env`, `spark`,
`app/`, `modules/`, `/originals` in any case including nested); startup refusal; Secure-cookie matrix (trust_proxy);
upload rejection corpus; token-leak grep; gitleaks; `composer audit`.

---

## 6. Docs plan

| Doc | Change |
|---|---|
| `AGENTS.md`, `README.md`, `docs/contributing.md` | **PR 1**: rule change; module and dependency rules (graph, constructor injection) |
| `docs/plans/feature12-ci4/PLAN.md`, `docs/adr/*` | this plan; ADRs (framework, layout + deptrac graph, PDO runner + LegacyFunctions, sessions, CSRF, storage contract, PHP 8.4, release/2.5) |
| `docs/architecture.md` | CI4 request flow, module map, Media storage, Autofill pipeline, Migrations module |
| `docs/database.md` | same schema; storage outside the DB; LegacyFunctions; the PM L-2 migration rule |
| `docs/getting-started.md`, `docs/deployment-and-operations.md` | Docker-only; 3.0 upgrade guide with §3.6, re-login, `ORIGINALS_DIR` rules, PHP 8.4 image |
| `docs/configuration.md` | same env vars (incl. `DB_DSN` forms supported by the translator, `ORIGINALS_DIR`, autofill knobs), `trust_proxy` behaviour, `CI_ENVIRONMENT` |
| `docs/releasing.md` | `release/2.5` flow, patch-only guard, 90-day window (§4.5) |
| `docs/autofill.md`, `docs/autofill-admin-guide.md` | CI4 references; min(model size, 1600) = 1024 px today; Worker URL placeholder `https://autofill.example` |
| `docs/testing.md`, `docs/ci.md` | 8.4 legs, legacy@8.3 reference, parity recorder + allowlist, CLI goldens, deptrac |
| `CHANGELOG.md` | via the release workflow; the cutover carries `BREAKING CHANGE:` (CI4, PHP 8.4 image, Docker-only, re-login) |
| `docs/qa/ci4-prNN-qa.md` | one QA report per PR |

---

## 7. Risks and mitigations

| # | Risk | Mitigation |
|---|---|---|
| R1 | Framework dependency and its advisories (19 on Packagist; 4.7.5 fixed CURLRequest option reuse and upload-validation bypasses) | approved; pin + lockfile; `composer audit`; autofill keeps its own curl; uploads validated by GD/Imagick/magic bytes |
| R2 | No PDO driver in CI4 (types, transactions, pragmas, DSN forms) | casts, `DatabaseFactory`, `DsnTranslator` table tests, driver matrix, typed-JSON parity; PDO only in the Migrations module |
| R3 | Originals leaked or lost | OriginalsGuard, deny file, nginx `~*`, preflight on `FCPATH`, sha256 parity, delete/undo/duplicate/edit/backup tests |
| R4 | Temp analysis files left behind | `TempFile` + `finally`; parity asserts `tmp/` empty; boot cleanup of stale files |
| R5 | Autofill regressions (loops, blocked Save, oversize, cost) | ResponseClassifier table; stub counter; size/limit stub cases; Retry-After ≤60; only two live checks, ≤25 Neurons each (§4.4) |
| R6 | HTML drift breaks baselines/JS hooks | verbatim views; locked baselines; DOM diff |
| R7 | CSP broken by CI4 | toolbar off; own error views; CSP listener |
| R8 | Main keeps moving | merges after each main release; shared tests; freeze at the L-4 patch |
| R9 | Performance (framework boot, Imagick) | OPcache + classmap; `/media` without session; p95 budget |
| R10 | Image size | **~60 MB growth accepted (PM)**; multi-stage build; the cutover PR records before (2.5.x+L-4) and after |
| R11 | Re-login surprises users | release notes, upgrade guide, clean sign-in for old cookies |
| R12 | Scope creep / branch rot; legacy quirks | parity first; quirks (N-2, QA L-2, N-4, I-3) ported identically and **fixed in follow-ups after 3.0.0**; rehearsal merge in a throwaway clone |
| R13 | **PHP 8.4 deprecations/behaviour** mistaken for port bugs | PR 2 runs legacy PHPUnit on 8.4 (both databases) for earlier warning; PR 3 adds the remaining 8.4 suites; parity is legacy@8.4 vs CI4@8.4; legacy@8.3 only for upgrade/rollback |
| R14 | Two maintained lines (2.5.x and 3.0.x) diverge | `release/2.5` security-only, patch-only guard, 90-day end date, PM L-2 migration rule |
| R15 | Module coupling creeps back | deptrac graph + DI rule in CI (L-1) |

---

## 8. Open questions

None. Every PM question from rev. 2 is decided, approved by Stathis at 10:09, and the Neuron plan was finalised by the PM at
10:15 (decisions log). New questions will be raised in PR reviews.

---

## Changelog of this plan

* **PR 6, Settings core (2026-10-24):** `SettingsSchema` is the single source of the 62 keys; `settings_schema`, `setting_default` and `setting_validate` in `LegacyFunctions.php` delegate to it and `settings_cache` keeps no state. `SettingsService` replaces the static cache. `/theme.css`, `/labels.css`, `/manifest.webmanifest`, `/favicon.ico`, `/brand/{file}` are byte-identical to legacy. `apotheke:settings` covers 6 of the 19 forms (goldens compare argv → stdout, stderr, exit code and the `settings` rows); the account and sign-in forms move to PR 7 with the Auth module. `Services::apothekeDb` migrates once per process (`BootMigration`). Deptrac: Settings → Core; Migrations → Core, Settings. Not here: Control Panel pages, `/settings/export.json`, `/settings/import`, logo upload.
* **PR 4 (2026-10-20):** CodeIgniter 4.7.5 skeleton and Core (`ApothekeConfig`, `DsnTranslator`, `DatabaseFactory`, `RequestScheme`, `OriginalsGuard`, security filters, `StoragePreflight`, DevServer router, `/healthz`, `/health`). The ci4 leg runs PHPUnit. deptrac and the DI rule check Core and DevServer from `vendor/bin`. Issue #33 stays on `main`.
* **PR 3 (2026-10-20):** lint, headless mobile checks, Playwright and Docker build + smoke run on PHP 8.4 and stay on PHP 8.3. The parity recorder steps are install, healthz and settings. `fix(php84)` turns PHP 8.4 function JIT off before the QR encoder loads, so `opcache.jit=1235` returns the interpreter matrix; the legacy comparison is [php84-legacy-diff.md](php84-legacy-diff.md). N-4 says "Docker build + smoke" in row 3 and restores the L-b and rev. 3 sentences below. N-5 walks all of `docs/` in the link checker.
* **PM (2026-10-20):** PR 2 keeps the legacy PHPUnit suite on PHP 8.4 (SQLite and PostgreSQL) for earlier warning, with the CI4 leg and legacy@8.3. PR 3 adds only the remaining 8.4 suites (lint, headless mobile checks, Playwright, Docker build), plus `fix(php84)` commits, the parity recorder and the allowlist. This replaces L-b's move of those PHPUnit runs to PR 3.
* **Reviewer fixes (2026-10-20):** (L-a) 19 CI4 PRs (1–10, 11a, 11b, 12–18), plus the main-side `release/2.5` PR and L-4; (L-b) PR 2 runs only the CI4 leg plus legacy@8.3, and the legacy@8.4 runs move to PR 3; (QA) "PM L-2" is the migration-backport rule (§3.4, §6, R14) and "QA L-2" is the broken-JPEG/over-60-MP 422 quirk (allowlist, R12, PR 10); the base note says the v2.5.0 tag check is verified (QA PASS at 10:15 EEST, 87850e3, only VERSION, CHANGELOG and README coverage tables changed).

**rev. 3 (2026-10-20, base v2.5.0 = 87850e3, verified by QA's tag check and the PM's re-scan)**
* Re-based on the v2.5.0 tag: the diff from 5633472 is VERSION, CHANGELOG.md, README coverage tables only, so no code reference changed.
* Folded the PM decisions into the body and replaced the appended section with a **decisions log**, including
  "Approved by Stathis (2026-10-20 10:09 EEST)", the PM's 10:11 review and the 10:15 Neuron decision. §8 is closed.
* **Neurons (final, PM 10:15):** stub-only per PR plus two live checks of ≤25 each (autofill client PR, cutover), ≤50
  total, usage posted; replaces the single ≤70 pass (§4.4).
* **H-1:** new **Migrations module** (PDO runner + `LegacyFunctions.php`, kept after cutover). Exact per-migration call
  sites verified. Tests: fresh migrate with `src/` removed and upgrade from every version 0001–0025, **under PHP 8.4 on
  SQLite and PG**. Also: PDO closed before SQLite3 opens, concurrent mixed-connection migration (WAL / advisory lock),
  and goldens.
* **PHP 8.4:** CI4 image and branch CI on 8.4. New **PR 3** runs legacy on 8.4 with separate `fix(php84)` commits.
  **Parity = legacy@8.4 vs CI4@8.4**; legacy@8.3 = upgrade/rollback reference (M-1). 19 CI4 PRs (1–10, 11a, 11b, 12–18), plus the main-side `release/2.5` PR and L-4.
* **M-2:** `DsnTranslator`, `RequestScheme` (trust_proxy + X-Forwarded-Proto, not proxyIPs), **Notes** module,
  **ActivityController**, **DevServer** router. **M-3:** `bin/settings.php` counted at **11 commands / 19 forms** + error
  paths, with CLI golden tests. **M-4:** `release/2.5` branch + main-side release.yml/ci.yml PR, patch-only guard, docs,
  until 3.0.0 + 90 days. **M-5:** item PR split into 11a/11b; **BulkMerge** and **Favourites** modules. **M-6:** the
  recorder and allowlist arrive in PR 3 and every PR adds steps.
* **L-1:** deptrac dependency graph + constructor injection (`service()` only in controllers). **PM L-2:** no 0026+ on 3.0.x
  unless backported to 2.5.x within 90 days. **L-3:** unit tests per new service and coverage ≥ legacy per PR.
* **QA items:** base note rephrased as an expectation; image-size wording = min(model size, 1600) = 1024 px today
  (I-1), with stub cases for no `image_sizes` → 1024 and no `max_image_bytes` → 983040; matrix row 4 notes N-2; N-2, QA L-2,
  N-4 and I-3 ported as is and listed in `tests/parity/allowlist.yml`, fixed after cutover (R12).
* Gates now include the PR's own parity steps, coverage ≥ legacy and the deptrac/DI checks. R10 updated; new R13–R15.
* Appendix A: #28 is a **merge commit** (two parents), not a squash; `src/autofill.php` = **1,008 lines** (verified);
  N-4 wording corrected; base note rephrased. Appendix B: base note rephrased.

**rev. 2 (2026-10-20, base 5633472 = PR #28)**
* Re-based from 56baf17. No schema change, no new migration (Appendix B re-dumped, identical).
* Added #28's storage model (`ORIGINALS_DIR`, stripped served copy, `/originals` blocks), the browser upload rule, the
  rewritten autofill pipeline and matrix, backup/restore with originals, and the `https://autofill.example` placeholder.
* Applied Stathis's decisions (rule change first, one-time re-login, Docker-only). Added the L-4/N-6/N-7/N-8
  precondition and the four gates. PR list 1–17 (numbering since superseded).

**rev. 1 (2026-10-20, base 56baf17 = v2.4.1)** — first draft.
