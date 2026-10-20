# AGENTS.md — working on Apotheke

Apotheke is a small self-hosted catalog for belongings. The legacy app on `main` is plain PHP 8.2+ (CI and the Docker image use 8.3), PDO (SQLite or PostgreSQL), and GD. That legacy tree stays as it is until the 3.0.0 cutover.

**CodeIgniter 4 replaces the no-framework rule.** The port is built side by side in `ci4/` with pinned Composer dependencies, on PHP 8.4. Do not add any other framework. Decision record: [ADR-001](docs/adr/001-codeigniter4.md). Plan: [Feature 12](docs/plans/feature12-ci4/PLAN.md).

Human docs: [README](README.md) and [`docs/`](docs/index.md). Feature workflow: [docs/contributing.md](docs/contributing.md).

## Hard constraints

* Keep the legacy stack small: functions over classes; one file per area (`items.php`, `backup.php`, `views.php`, …). CodeIgniter 4 code lives in `ci4/` and follows the module rules below.
* Every query must work on **both** SQLite and PostgreSQL (see [docs/database.md](docs/database.md)).
* **CSP-safe UI:** no inline `<script>`, no `style="…"`, no `on…=` handlers — put CSS in `public/assets/app.css` and behaviour in `public/assets/app.js` / `theme.js`.
* Prefer real forms and links first; JS enhances. Mobile-first (tap targets ≥ 44 px, light and dark).
* Escape output with `h()`, use prepared statements, call `check_csrf()` on every state change, write `activity_log()` in the same transaction as the change.
* **No schema change.** The 25 migrations stay byte-identical. They run through a PDO runner plus a `LegacyFunctions` shim (kept after cutover). Do not add a `0026+` migration on 3.0.x unless the same file is backported to 2.5.x during the 90-day window.
* **Docker-only after cutover.** The 3.0.0 install is the Docker image (no non-Docker install and no vendored zip). Until then, the legacy run below still works.
* **One re-login.** Sessions are not migrated. Users sign in again once at upgrade.
* **PHP 8.4** for the CI4 image. The CI4 leg on `feature/ci4` runs PHP 8.4 (legacy@8.3 and legacy@8.4 PHPUnit also run on that branch). The legacy 2.5.x image stays on PHP 8.3.
* **2.5.x security fixes** land on `release/2.5` and continue for 90 days after 3.0.0. `main` stays the legacy app until the cutover.
* No CDN and no JavaScript build step, for the legacy app and for CI4. Both work fully offline on a LAN. The only runtime Composer packages are CodeIgniter 4 and its pinned dependencies.

## Layout

```
public/index.php          front controller / router (treats HEAD like GET for reads)
public/assets/            app.css, app.js, theme.js, icons
src/bootstrap.php         sessions, auth, CSRF, helpers (incl. item_noun / stats_brief / no_store)
src/config.php            config loader (defaults < config.php < env)
src/db.php                PDO, dialects, migrations
src/items.php             domain: items, locations, categories, search, images
src/views.php             HTML pages and fragments
src/backup.php            portable zip backup/restore
src/qr.php                pure-PHP QR (SVG). On PHP 8.2, 8.3 and 8.4, turns function JIT off, then loads qr-encode.php
migrations/NNNN_*.php     versioned schema steps
bin/                      migrate, seed, backup, restore CLIs
tests/                    integration.php, Unit/, visual/ (Playwright), e2e/, parity/
docker/                   nginx, php-fpm, entrypoint
docs/                     full manual
```

Data lives in `DATA_DIR` (Compose: `./data` → `/data`): SQLite file, `images/`, `thumbs/`, `sessions/`. Never serve `src/` or `data/` over HTTP (nginx blocks them).

## CI4 module rules

These apply to code under `ci4/`. deptrac enforces the graph in CI; a violation fails lint.

* **Dependency graph.** Every module may depend on Core. Core depends on nothing. No cycles. An edge `A → B` means A may depend on B:
  * Migrations → Settings (schema only)
  * Auth → Settings
  * Media → Settings
  * Catalog → Taxonomy, Media, Search, Activity, Undo, Notes, Settings
  * BulkMerge → Catalog, Taxonomy, Media, Activity, Undo
  * Favourites → Catalog
  * Lending, Stocktake and Labels → Catalog, Taxonomy
  * ImportExport → Catalog, Taxonomy
  * Backup → all repositories, plus Media and Settings
  * Autofill → Media (read-only `originalPathOrServed`), Settings, Taxonomy (context names)
* **Constructor injection.** Services, models and commands take their collaborators through constructors.
* **`service()` only in controllers.** `service()` and `Services::` are allowed only in controllers and in `Config/Services.php` factories.

## Local run

```bash
docker compose up -d --build          # http://127.0.0.1:9091/
# or without Docker:
cp config.example.php config.php      # optional; env vars override
php bin/migrate.php
php -S 127.0.0.1:9091 -t public public/index.php
```

Optional password: `APP_PASSWORD`. Optional Postgres: set `DB_DRIVER=pgsql` and use the Compose `postgres` profile (see README).

**Note:** Compose only mounts `./data`. Code changes require `docker compose up -d --build` (or a bind-mount) to appear in the container.

## Tests (run before claiming done)

```bash
tests/run.sh                              # lint + HTTP integration (no Composer)
composer install && vendor/bin/phpunit    # unit + same integration via PHPUnit
scripts/docker-checks.sh http://127.0.0.1:9091   # nginx behaviour of the image
npx playwright test --grep-invert visual  # functional E2E (after npm ci + browsers)
```

Prefer TDD for behaviour changes: extend `tests/integration.php` and/or `tests/Unit/`, then implement. Both databases matter for SQL/migration changes (`DB_DRIVER=pgsql`).

## Commits and PRs

* Conventional Commits (`feat`, `fix`, `chore`, `docs`, `refactor`, `test`); subject ≤ 72 characters (`scripts/check-commits.sh`).
* Small, focused PRs. UI screenshot changes need the `update-snapshots` label and baseline update (see [docs/testing.md](docs/testing.md)).
* CI4 pull requests target `feature/ci4`. Legacy `main` stays as it is until 3.0.0.
* Do not force-push shared branches; do not commit secrets (`.env`, real `config.php`, `data/`).

## Adding a feature (short)

The steps below are for the legacy app. CI4 features go in `ci4/` under the module rules and target `feature/ci4`.

1. Migration if schema changes.  
2. Domain logic in `src/items.php` (or a focused new `src/*.php`).  
3. Routes in `public/index.php` (`UserError` for 4xx).  
4. Views + CSS/JS.  
5. Tests (unit + integration + Playwright when UI).  
6. Docs (README feature bullet + user guide as needed).

Details and naming conventions: [docs/contributing.md](docs/contributing.md).
