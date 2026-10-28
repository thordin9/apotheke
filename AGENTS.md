# AGENTS.md — working on Apotheke

Apotheke is a small self-hosted catalog for belongings: plain PHP 8.2+ (CI/Docker use 8.3), PDO (SQLite or PostgreSQL), and GD. No framework, no runtime Composer packages, no JavaScript build step, no CDNs. It must work fully offline on a LAN.

Human docs: [README](README.md) and [`docs/`](docs/index.md). Feature workflow: [docs/contributing.md](docs/contributing.md).

## Hard constraints

* Keep the stack small: functions over classes; one file per area (`items.php`, `backup.php`, `views.php`, …).
* Every query must work on **both** SQLite and PostgreSQL (see [docs/database.md](docs/database.md)).
* **CSP-safe UI:** no inline `<script>`, no `style="…"`, no `on…=` handlers — put CSS in `public/assets/app.css` and behaviour in `public/assets/app.js` / `theme.js`.
* Prefer real forms and links first; JS enhances. Mobile-first (tap targets ≥ 44 px, light and dark).
* Escape output with `h()`, use prepared statements, call `check_csrf()` on every state change, write `activity_log()` in the same transaction as the change.
* Do **not** add runtime Composer dependencies, frameworks, or CDN assets. If something seems to need one, open an issue first.

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
src/cli-owner.php         root CLI restore runs as the data folder's owner
src/qr.php                pure-PHP QR (SVG). On every PHP version, turns function JIT off, then loads qr-encode.php
migrations/NNNN_*.php     versioned schema steps
bin/                      migrate, seed, backup, restore CLIs
tests/                    integration.php, Unit/, visual/ (Playwright), e2e/
docker/                   nginx, php-fpm, entrypoint
docs/                     full manual
```

Data lives in `DATA_DIR` (Compose: `./data` → `/data`): SQLite file, `images/`, `thumbs/`, `sessions/`. Never serve `src/` or `data/` over HTTP (nginx blocks them).

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
* Do not force-push shared branches; do not commit secrets (`.env`, real `config.php`, `data/`).

## Adding a feature (short)

1. Migration if schema changes.  
2. Domain logic in `src/items.php` (or a focused new `src/*.php`).  
3. Routes in `public/index.php` (`UserError` for 4xx).  
4. Views + CSS/JS.  
5. Tests (unit + integration + Playwright when UI).  
6. Docs (README feature bullet + user guide as needed).

Details and naming conventions: [docs/contributing.md](docs/contributing.md).
