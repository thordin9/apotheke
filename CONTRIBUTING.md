# Contributing to Apotheke

Thanks for helping out! Apotheke is deliberately small: plain PHP 8.2+, SQLite or PostgreSQL via PDO, and GD.
No framework, no Composer *runtime* dependencies (PHPUnit is dev-only), no JS build step. Please keep it that way.

## Running the tests

Requirements: PHP 8.1+ (8.3 recommended) with `pdo_sqlite`, `gd`, `mbstring` (and `exif`
for the orientation test). Optional: `node` (JS syntax check) and `shellcheck`.

```bash
tests/run.sh          # lint (php -l, node --check, shellcheck) + integration tests
scripts/lint.sh       # lint only
php tests/integration.php   # integration tests only
```

The integration tests start PHP's built-in server (`php -S`) on throwaway temp data
directories and exercise the app over HTTP: uploads (images generated with GD), search,
quantity, edit/delete, locations and categories (defaults, delete-with-move), schema
migrations v1→v3 and v2→v3, CSRF and bad-upload rejection, password mode and statistics.
They exit non-zero on any failure. Set `KEEP_TEST_DATA=1` to keep the temp dirs.

Headless mobile checks (optional locally, run in CI): see the README's *Tests* section (`tests/e2e/mobile.mjs`).

To smoke-test a running instance (e.g. the Docker container):

```bash
docker compose up -d --build
scripts/smoke.sh http://127.0.0.1:8080
```

### PHPUnit and PostgreSQL

```bash
composer install
vendor/bin/phpunit                                   # SQLite
DB_DRIVER=pgsql TEST_PG_PASSWORD=secret vendor/bin/phpunit   # PostgreSQL (TEST_PG_HOST/PORT/DB/USER)
vendor/bin/phpunit --coverage-html coverage/html     # needs pcov or xdebug
```

A quick throwaway PostgreSQL: `docker run --rm -p 5432:5432 -e POSTGRES_USER=apotheke -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=apotheke postgres:17`.

### Writing a migration

Add `migrations/NNNN_short_name.php` with the next number. Return either `['sqlite' => 'SQL…', 'pgsql' => 'SQL…']`
or `static function (PDO $pdo, string $driver): void { … }`. Never edit a migration that has been released; add a new one.
Keep queries portable: compare against PHP-folded columns (`name_key`, `search`) with `LIKE … ESCAPE '\'`, sort by
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
lint, integration tests on PHP 8.3, Docker build + smoke test, and (on PRs) the commit check.
