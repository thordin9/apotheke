# Contributing to Apotheke

Thanks for helping out! Apotheke is deliberately small: plain PHP 8.2+, SQLite or PostgreSQL via PDO, and GD.
No framework, no Composer *runtime* dependencies (PHPUnit is dev-only), no JS build step. Please keep it that way.

## Running the tests

Requirements: PHP 8.2+ (8.3 is what CI and the Docker image use) with `pdo_sqlite`, `gd`, `mbstring`,
`zip` (and `exif` for the orientation test). Optional: `node` (JS syntax check), `shellcheck` and
`zbar-tools` (QR round-trip tests; skipped when missing). PHPUnit needs `composer install`.

```bash
tests/run.sh          # lint (php -l, node --check, Markdown links, shellcheck) + integration tests
scripts/lint.sh       # lint only
php tests/integration.php   # integration tests only
```

The integration tests start PHP's built-in server (`php -S`) on throwaway temp data
directories and exercise the app over HTTP: uploads (images generated with GD), search,
quantity, edit/delete, locations and categories (defaults, delete-with-move), schema
migrations v1→v3 and v2→v3, CSRF and bad-upload rejection, password mode and statistics.
They exit non-zero on any failure. Set `KEEP_TEST_DATA=1` to keep the temp dirs.

Headless mobile checks (optional locally, run in CI): see the README's [Testing](README.md#testing) section (`tests/e2e/mobile.mjs`).

To smoke-test a running instance (e.g. the Docker container):

```bash
docker compose up -d --build
scripts/smoke.sh http://127.0.0.1:9091
scripts/docker-checks.sh http://127.0.0.1:9091   # nginx routing, blocked paths, headers, gzip, body limits
E2E_DOCKER_IMAGE=apotheke:latest npx playwright test --project=docker-smoke   # @smoke E2E against the image
```

### PHPUnit and PostgreSQL

```bash
composer install
vendor/bin/phpunit                                   # SQLite
DB_DRIVER=pgsql TEST_PG_PASSWORD=secret vendor/bin/phpunit   # PostgreSQL (TEST_PG_HOST/PORT/DB/USER)
vendor/bin/phpunit --coverage-html coverage/html     # needs pcov or xdebug
```

A quick throwaway PostgreSQL: `docker run --rm -p 5432:5432 -e POSTGRES_USER=apotheke -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=apotheke postgres:17`.

### Visual end-to-end tests (Playwright)

`tests/visual/` drives the real app (PHP built-in server, isolated data dir re-seeded before every test,
server clock frozen with `APOTHEKE_CLOCK`) in WebKit iPhone 13 / iPhone SE, Chromium Pixel 7 and desktop
Chromium, light and dark, plus a `@smoke` subset against PostgreSQL. It needs PHP 8.3 (gd, pdo_sqlite,
zip, mbstring) and Node 20+:

```sh
npm ci
npx playwright install --with-deps chromium webkit
npm run test:e2e                              # everything
npx playwright test --project=desktop-chromium tests/visual/list.spec.ts
TEST_PG_HOST=127.0.0.1 npm run test:e2e:smoke-pg   # PostgreSQL smoke (TEST_PG_* like PHPUnit)
npm run test:e2e:report                       # open the HTML report
```

Screenshots (`toHaveScreenshot`) are compared with the baselines in `tests/visual/__screenshots__/`.
Those are **only** ever rendered in the official `mcr.microsoft.com/playwright:v<version>-noble` image –
the same image the CI `e2e` job runs in – because fonts and anti-aliasing differ between machines.
Locally, screenshot assertions will fail on a non-matching OS; that is expected, run the functional
specs or use the image. To update baselines after an intended UI change, either:

- `npm run test:e2e:update` (= `scripts/update-snapshots.sh`, needs Docker; runs the image plus a
  throw-away PostgreSQL container), or
- add the label **`update-snapshots`** to the PR: the *Update visual snapshots* workflow renders them in
  the image and uploads a `snapshots` artifact; fetch it with
  `scripts/update-snapshots.sh --from-ci <run id>` (needs `gh`).

Review the changed PNGs, then commit them (`test(e2e): update visual baselines`). On failure CI uploads
the HTML report, traces and `*-diff.png` images as the `playwright-report-shard-N` artifacts.

### Writing a migration

Add `migrations/NNNN_short_name.php` with the next number. Return either `['sqlite' => 'SQL…', 'pgsql' => 'SQL…']`
or `static function (PDO $pdo, string $driver): void { … }`. Never edit a migration that has been released; add a new one.
Keep queries portable: compare against PHP-folded columns (`name_key`, `search`) with `LIKE … ESCAPE '!'` (see `like_escape()`; avoid backslashes in SQL literals, which trip PDO's placeholder parser on PHP < 8.4), sort by
`sort_key`, use `CASE` instead of boolean sums, and use `db_insert()` / `db_local_bucket()` for the dialect-specific bits.
Add tests to `tests/Unit/MigrationRunnerTest.php` and, if data changes, to the legacy section of `tests/integration.php`.

## Commit messages

Every commit in a pull request must follow [Conventional Commits](https://www.conventionalcommits.org/);
CI checks this with `scripts/check-commits.sh`.

```
<type>[(scope)][!]: <subject>
```

* `type` is one of `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`
* `scope` is optional, lowercase, e.g. `feat(locations): …`
* `!` marks a breaking change, e.g. `feat(db)!: …`
* the whole subject line is at most 72 characters

Examples: `feat(categories): allow renaming from the item form`, `fix: keep default location after edit`.

Check your branch before pushing:

```bash
scripts/check-commits.sh origin/main
```

## CI

GitHub Actions (`.github/workflows/ci.yml`) runs on every pull request and on pushes to `main`:
lint, PHPUnit (unit + HTTP integration) on PHP 8.3 against SQLite and PostgreSQL, the headless
mobile checks, the Playwright visual E2E suite (`e2e`, 3 shards, inside the Playwright image,
`retries=1`), Docker build (nginx + php-fpm) + smoke test (SQLite and PostgreSQL), web server checks, graceful stop
and the `@smoke` E2E subset against the image, and (on PRs) the commit check.
See the README's [CI](README.md#ci) table.
