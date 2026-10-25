# Contributing to Apotheke

Thanks for helping out! The legacy app is deliberately small: plain PHP 8.2+, SQLite or PostgreSQL via PDO, and GD,
with no JavaScript build step. It stays that way on `main` until the 3.0.0 cutover. The port adopts CodeIgniter 4
(pinned dependencies, PHP 8.4, side by side in `ci4/`). See [docs/contributing.md](docs/contributing.md) and
[ADR-001](docs/adr/001-codeigniter4.md). After 3.0.0, 2.5.x gets security fixes on `release/2.5` for 90 days.

```bash
tests/run.sh                         # lint (php -l, node --check, Markdown links, shellcheck) + HTTP integration suite
composer install && vendor/bin/phpunit                        # unit + HTTP suites on SQLite
DB_DRIVER=pgsql TEST_PG_PASSWORD=secret vendor/bin/phpunit    # … on PostgreSQL
scripts/check-commits.sh origin/main                          # commit subjects
docker compose up -d --build && scripts/smoke.sh http://127.0.0.1:9091   # smoke-test the container
```

Every commit in a pull request must follow [Conventional Commits](https://www.conventionalcommits.org/)
(`<type>[(scope)][!]: <subject>`, subject line at most 72 characters); CI checks it.

* [docs/contributing.md](docs/contributing.md): ground rules, workflow and adding a feature step by step.
* [docs/testing.md](docs/testing.md): every test layer, Playwright and the screenshot baselines.
* [docs/database.md](docs/database.md#writing-a-migration): writing a migration.
* [docs/ci.md](docs/ci.md#commit-rules): CI jobs and the commit rules.
