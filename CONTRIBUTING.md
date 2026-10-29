# Contributing to Apotheke

Thanks for helping out! Apotheke is a CodeIgniter 4 app at the repository root (pinned dependencies, PHP 8.4,
SQLite or PostgreSQL via PDO, and GD), with no JavaScript build step. Pull requests target `main`. See
[docs/contributing.md](docs/contributing.md) and [ADR-001](docs/adr/001-codeigniter4.md). For 90 days after 3.0.0, the
2.x line gets security fixes only, as 2.6.x releases from the branch `old` (the former `main`, last release v2.6.26).

```bash
composer install && tests/run.sh    # lint (php -l, node --check, Markdown links, shellcheck) + module suite
vendor/bin/phpunit                                            # root suite (unit + HTTP) on SQLite
DB_DRIVER=pgsql APOTHEKE_TEST_DB=pgsql TEST_PG_PASSWORD=secret vendor/bin/phpunit    # … on PostgreSQL
scripts/check-commits.sh origin/main                          # commit subjects
docker compose up -d --build && scripts/smoke.sh http://127.0.0.1:9091   # smoke-test the container
```

Every commit in a pull request must follow [Conventional Commits](https://www.conventionalcommits.org/)
(`<type>[(scope)][!]: <subject>`, subject line at most 72 characters); CI checks it.

* [docs/contributing.md](docs/contributing.md): ground rules, workflow and adding a feature step by step.
* [docs/testing.md](docs/testing.md): every test layer, Playwright and the screenshot baselines.
* [docs/database.md](docs/database.md#writing-a-migration): writing a migration.
* [docs/ci.md](docs/ci.md#commit-rules): CI jobs and the commit rules.
