# Changelog

All notable changes to Apotheke are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Release sections are written by `scripts/release.php` when a pull request is merged to `main`
(see [docs/releasing.md](docs/releasing.md)); the version lives in `VERSION`.

## [Unreleased]

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

[Unreleased]: https://github.com/thordin9/apotheke/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/thordin9/apotheke/releases/tag/v1.0.0
