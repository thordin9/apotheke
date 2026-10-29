# AGENTS.md — working on Apotheke

Apotheke is a small self-hosted catalog for belongings. The legacy app on `main` is plain PHP 8.2+ (CI and the Docker image use 8.3), PDO (SQLite or PostgreSQL), and GD. That legacy tree stays as it is until the 3.0.0 cutover.

**CodeIgniter 4 replaces the no-framework rule.** The port is built side by side in `ci4/` with pinned Composer dependencies, on PHP 8.4. Do not add any other framework. Decision record: [ADR-001](docs/adr/001-codeigniter4.md). Plan: [Feature 12](docs/plans/feature12-ci4/PLAN.md).

Human docs: [README](README.md) and [`docs/`](docs/index.md). Feature workflow: [docs/contributing.md](docs/contributing.md).

## Hard constraints

* Keep the legacy stack small: functions over classes; one file per area (`items.php`, `backup.php`, `views.php`, …). CodeIgniter 4 code lives in `ci4/` and follows the module rules below.
* Every query must work on **both** SQLite and PostgreSQL (see [docs/database.md](docs/database.md)).
* **CSP-safe UI:** no inline `<script>`, no `style="…"`, no `on…=` handlers — put CSS in `public/assets/app.css` and behaviour in `public/assets/app.js` / `theme.js`. `ci4/public/assets/` is a byte copy for CI4: change both (`Ci4LayoutParityTest` compares them).
* Prefer real forms and links first; JS enhances. Mobile-first (tap targets ≥ 44 px, light and dark).
* Escape output with `h()`, use prepared statements, call `check_csrf()` on every state change, write `activity_log()` in the same transaction as the change.
* **No schema change.** The 28 migrations (`0001`–`0028`) stay byte-identical with `main`. They run through a PDO runner plus a `LegacyFunctions` shim (kept after cutover). Do not add a `0026+` migration on 3.0.x unless the same file is backported to 2.5.x during the 90-day window.
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
src/hosts.php             Host allow-list, same-origin rule for no-login mode
src/config.php            config loader (defaults < config.php < env)
src/db.php                PDO, dialects, migrations
src/items.php             domain: items, locations, categories, search, images
src/views.php             HTML pages and fragments
src/backup.php            portable zip backup/restore
src/cli-owner.php         root CLI tools run as the data folder's owner
src/qr.php                pure-PHP QR (SVG). On every PHP version, turns function JIT off, then loads qr-encode.php
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
  * Auth → Settings (Auth holds the actor gate for settings, `CurrentUser`, and the admin guards)
  * Media → Settings
  * Layout → Settings (the page shell, menu and error page; `Config\Services` hands it the user from Auth, and Core filters reach it through the `Core\Http\ErrorPage` interface)
  * Catalog → Taxonomy, Media, Search, Activity, Undo, Notes, Settings
  * BulkMerge → Catalog, Taxonomy, Media, Undo, Layout, Settings (bulk actions, merge, duplicate and quantity; history through `Core\Libraries\ItemHistory`). Attachments are Catalog's (`Items\Attachments`).
  * Favourites → Catalog
  * Labels → Catalog, Taxonomy, Layout, Settings (the QR encoder `Labels\QrCode`, loaded after `QrJit::guard()`); Lending → Catalog, Taxonomy, Media (thumbnail names), Layout, Settings; Stocktake → Catalog, Taxonomy, Media (thumbnail names), Activity (times as the history shows them), Layout, Settings. The barcode lookup is Catalog's (`Barcode`).
  * ImportExport → Catalog, Taxonomy, Lending (a row's borrower), Activity (times as the history shows them), Layout, Settings (CSV import and export)
  * Backup → Catalog (attachment names), Taxonomy (the page base class), Media (photo names, decode limits), Undo (pruned after a restore), Activity (times as the history shows them), Layout, Settings (settings restored through `SettingsService::import()`). It reads and replaces the catalog tables itself, in one transaction.
  * Autofill → Media, Settings, Taxonomy (context names). A stored photo's bytes come from Media's read-only `ImageStore::analysisBytes()` (the kept original, else the served JPEG); the warn-once pair and the temporary JPEG go through `Apotheke\Core\Storage\Storage` (local disk under `DATA_DIR`). deptrac allows Autofill → Core, Settings and Media; the photo row lookup and the category/tag names are still SQL.
  * ControlPanel → Auth (the shared page answers), Layout, Settings (read, save, reset, export and import; `Settings\Branding\LogoInstaller` for the logo), Autofill (the token and Test buttons), Media (upload messages), Search (System's engine), Taxonomy (the default location and category), Catalog (the local date). Users & sign-in stays Auth's.
  * Taxonomy → Layout (the Manage pages). It writes item history through `Core\Libraries\ItemHistory`, which Activity implements, so Taxonomy does not depend on Activity. Activity → Layout (the `/activity` page).
  * Notes → Core only. `Apotheke\Notes\MarkdownRenderer` is the notes Markdown subset (legacy `md_render()`/`md_plain()`, byte-identical, 20000-character cap); Catalog views and the activity diff take it through their constructors.
  * Catalog → Layout, Settings, Taxonomy, Media, Search, Activity, Undo, Notes. It records and shows recently viewed items through `Catalog\RecentItems`, which Favourites implements, so Catalog does not depend on Favourites. Search → Taxonomy (location paths). Undo → Core only: it keeps the tokens and snapshots; applying one lives in Catalog (`Items\UndoApply`). Catalog checks the move permission through `Core\Security\PermissionGate` and draws the autofill attributes through `Items\FormAutofill`, both built in `Config\Services`, so it depends on neither Auth nor Autofill.
  * Auth → Layout (the sign-in and account pages). `/login`, `/register` and `/logout` are `none` and skip the global CSRF filter; their controller checks the token where legacy calls `check_csrf()`. Pass `keepSignIn: true` to `SessionGate::signIn()` where `main` passes it to `login_session()` (your own new password, sign out everywhere). Users & sign-in (`/settings/security`, `/settings/password`, `/settings/users…`, `/settings/roles…`) needs `users`; account changes go through `UserAdmin` (admin guard, last active admin), roles through `RoleAdmin` and sign-in on or off through `SignInSwitch`. The CLI account forms are `apotheke:accounts` (`Auth\Commands`), with `CommandLineActor` as the admin actor.
* **Request hardening.** Required before-filters run in a fixed order (`ci4/app/Config/Filters.php`): Host allow-list (421), the per-request base URL, session parameters (sessions start on demand only; the idle and `session_days` limits end them), then same-origin writes when sign-in is off (403), then the Permission filter (legacy `user_can()`, before CSRF). It derives the permission from the routed path: the matched route's `permission` option in `Config/Routes.php` (legacy `route_perm()` for that route; `none` for routes legacy answers before its check, which skips the check on their exact path only), and legacy `route_perm()` for paths no route matches. Give every new route its `permission` option: a route without one is refused to everyone, and `PermissionFilterTest` checks each option against `route_perm()` (add new non-GET routes to `POST_ROUTES`). Do not start a session in a filter; use `SessionToken` where a page needs a CSRF token. CI4's own `session()` is `NativeSession`: it never starts a session and is read-only; change or end the session only through `SessionGate`. Build absolute URLs with `base_url()`/`site_url()` (the `publicbase` filter sets them from `public_url` and `trust_proxy`), never from the request's Host, and send redirects with a relative `Location`. Read the client address through `ClientAddress`, never `X-Forwarded-For` directly. See [ADR 001](docs/adr/001-codeigniter4.md).
* **Constructor injection.** Services, models and commands take their collaborators through constructors.
* **`service()` only in controllers and filters.** `service()` and `Services::` are allowed only in controllers, in filters (CI4 builds them without constructor arguments; only inside `before()`/`after()`, with no properties or statics, see [ADR 001](docs/adr/001-codeigniter4.md)) and in `Config/Services.php` factories.

## Local run

```bash
docker compose up -d --build          # http://127.0.0.1:9091/
# or without Docker:
cp config.example.php config.php      # optional; env vars override
php bin/migrate.php
php -S 127.0.0.1:9091 -t public public/index.php
```

Password: `APP_PASSWORD` (the Docker image refuses to start with sign-in off unless `ALLOW_NO_LOGIN=1`; compose binds `127.0.0.1:9091`). Optional Postgres: set `DB_DRIVER=pgsql` and use the Compose `postgres` profile (see README).

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
