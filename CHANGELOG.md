# Changelog

All notable changes to Apotheke are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Release sections are written by `scripts/release.php` when a pull request is merged to `main`
(see [docs/releasing.md](docs/releasing.md)); the version lives in `VERSION`.

## [Unreleased]

## [1.1.0] - 2026-10-13

### Added

- **items:** Markdown notes and PDF/image attachments ([#14](https://github.com/thordin9/apotheke/pull/14))
- **labels:** bulk QR label printing with configurable size and grid ([#14](https://github.com/thordin9/apotheke/pull/14))
- **items:** favourites with filter and chip, recently viewed list ([#14](https://github.com/thordin9/apotheke/pull/14))
- **items:** merge duplicate items with a confirm screen ([#14](https://github.com/thordin9/apotheke/pull/14))
- **history:** undo for edit, move, delete and bulk actions ([#14](https://github.com/thordin9/apotheke/pull/14))

### Fixed

- **ui:** recently viewed above the overview, readable undo bar ([#14](https://github.com/thordin9/apotheke/pull/14))
- **e2e:** scroll mobile tap targets clear of the sticky action bar ([#14](https://github.com/thordin9/apotheke/pull/14))
- **ci:** show the server log when the coverage run fails ([#14](https://github.com/thordin9/apotheke/pull/14))
- **ci:** keep coverage-run server logs and print their errors ([#14](https://github.com/thordin9/apotheke/pull/14))
- **ci:** find the server logs in PHP's own temp dir ([#14](https://github.com/thordin9/apotheke/pull/14))
- **ci:** keep test data of the coverage run until it has passed ([#14](https://github.com/thordin9/apotheke/pull/14))
- **test:** commit the coverage collector ignored by .gitignore ([#14](https://github.com/thordin9/apotheke/pull/14))

### Maintenance

- **e2e:** test: add visual checks for batch 4 features ([#14](https://github.com/thordin9/apotheke/pull/14))
- **release:** ci: automatic SemVer releases, changelog and GitHub Releases ([#14](https://github.com/thordin9/apotheke/pull/14))
- **coverage:** test: README coverage table from merged pcov reports ([#14](https://github.com/thordin9/apotheke/pull/14))
- **e2e:** test: update visual baselines ([#14](https://github.com/thordin9/apotheke/pull/14))
- docs: screenshots for notes, labels, favourites, merge and undo ([#14](https://github.com/thordin9/apotheke/pull/14))

## [1.0.0] - 2026-10-13

First versioned release: everything merged before automatic releases started (pull requests #1–#13).

### Added

- **ui:** polished mobile-first UI, explicit light/dark mode switch, filter sheet ([#1](https://github.com/thordin9/apotheke/pull/1))
- **db:** PostgreSQL support next to SQLite, `config.php`, versioned migrations and seeding ([#2](https://github.com/thordin9/apotheke/pull/2))
- QR labels, multiple photos per item, tags and custom fields, portable backup/restore, item history ([#3](https://github.com/thordin9/apotheke/pull/3))
- **e2e:** Playwright visual end-to-end suite on WebKit and Chromium phones, light and dark, in CI ([#4](https://github.com/thordin9/apotheke/pull/4))
- low-stock alerts, bulk actions, lending with due dates, duplicating items, nested locations ([#7](https://github.com/thordin9/apotheke/pull/7))
- location roll-up in search and stats, expiry/warranty dates, purchase value totals, saved filters, barcodes ([#8](https://github.com/thordin9/apotheke/pull/8))
- **branding:** Apotheke logo for favicons, app icons, header, login and docs ([#10](https://github.com/thordin9/apotheke/pull/10))
- **settings:** Control Panel at `/settings` with DB-backed settings, accent colour, logo and password ([#13](https://github.com/thordin9/apotheke/pull/13), supersedes the closed [#12](https://github.com/thordin9/apotheke/pull/12))

### Fixed

- audit hardening, HEAD requests routed like GET, `AGENTS.md` ([#9](https://github.com/thordin9/apotheke/pull/9))

### Maintenance

- docs: README rewritten for the current feature set ([#5](https://github.com/thordin9/apotheke/pull/5))
- build: Docker image switched to nginx + php-fpm ([#6](https://github.com/thordin9/apotheke/pull/6))

[Unreleased]: https://github.com/thordin9/apotheke/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/thordin9/apotheke/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/thordin9/apotheke/releases/tag/v1.0.0
