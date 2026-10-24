# Contributing to Apotheke

Thanks for helping out! Apotheke is deliberately small: plain PHP 8.2+, SQLite or PostgreSQL via PDO, and GD.
No framework, no Composer *runtime* dependencies (PHPUnit is dev-only), no JS build step. Please keep it that way.

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
