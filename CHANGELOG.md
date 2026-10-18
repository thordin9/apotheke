# Changelog

All notable changes to Apotheke are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Release sections are written by `scripts/release.php` when a pull request is merged to `main`
(see [docs/releasing.md](docs/releasing.md)); the version lives in `VERSION`.

## [Unreleased]

## [2.3.1] - 2026-10-18

### Fixed

- **autofill:** replace an untouched preselected default ([#22](https://github.com/thordin9/apotheke/pull/22))
- **autofill:** fetch the service info when the token is saved ([#22](https://github.com/thordin9/apotheke/pull/22))
- **autofill:** tell a busy service apart from a warming model ([#22](https://github.com/thordin9/apotheke/pull/22))

### Maintenance

- **visual:** test: rebaseline the progress state for the linear estimate ([#22](https://github.com/thordin9/apotheke/pull/22))
- **visual:** test: rebaseline suggestions that now fill the category ([#22](https://github.com/thordin9/apotheke/pull/22))

## [2.3.0] - 2026-10-17

### Added

- **autofill:** progress bar, retry on busy, test with a photo ([#21](https://github.com/thordin9/apotheke/pull/21))
- **seed:** add Clothing, Shoes, Books and Lighting for new installs ([#21](https://github.com/thordin9/apotheke/pull/21))
- **autofill:** fetch the service info when the settings are saved ([#21](https://github.com/thordin9/apotheke/pull/21))

### Fixed

- **autofill:** keep the progress row so the form does not jump ([#21](https://github.com/thordin9/apotheke/pull/21))
- **autofill:** cache /v1/info only when testing the saved address ([#21](https://github.com/thordin9/apotheke/pull/21))
- **autofill:** say each photo-test check's state in words ([#21](https://github.com/thordin9/apotheke/pull/21))
- **autofill:** make the progress percentage match the time left ([#21](https://github.com/thordin9/apotheke/pull/21))
- **autofill:** own message for a 503 backend_error ([#21](https://github.com/thordin9/apotheke/pull/21))

### Maintenance

- **visual:** test: baselines for autofill progress and photo test ([#21](https://github.com/thordin9/apotheke/pull/21))
- **visual:** test: rebaseline autofill form states after the layout fix ([#21](https://github.com/thordin9/apotheke/pull/21))

## [2.2.0] - 2026-10-14

### Added

- **autofill:** settings and Test connection for the AI autofill service ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** suggest name, category, tags and notes from a photo ([#20](https://github.com/thordin9/apotheke/pull/20))

### Fixed

- **autofill:** send the stored token only to the saved address ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** report a connect timeout as unreachable ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** say a non-http(s) test address is invalid ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** say a token is required on 401 when none is set ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** reject unreadable non-images instead of sending them ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** settings polish and docs for Test connection limits ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** clear the stored token when the saved address changes ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** accept an upper-case URL scheme and normalise it ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** clear the token when import or restore moves the address ([#20](https://github.com/thordin9/apotheke/pull/20))
- **autofill:** clear the token when the CLI changes the address ([#20](https://github.com/thordin9/apotheke/pull/20))

### Maintenance

- **e2e:** test: no autofill screenshots in the smoke projects ([#20](https://github.com/thordin9/apotheke/pull/20))
- **e2e:** test: visual baselines for the AI autofill screens ([#20](https://github.com/thordin9/apotheke/pull/20))
- **e2e:** test: visual baselines for the autofill settings fixes ([#20](https://github.com/thordin9/apotheke/pull/20))

## [2.1.0] - 2026-10-14

### Added

- **ui:** phone overview, ★ hint, two-column form, safe Save bar ([#19](https://github.com/thordin9/apotheke/pull/19))

### Fixed

- **ci:** avoid SIGPIPE in smoke.sh by grepping a saved page ([#19](https://github.com/thordin9/apotheke/pull/19))
- **ci:** ignore comments in the pipefail grep lint ([#19](https://github.com/thordin9/apotheke/pull/19))
- **export:** protect CSV cells against formula injection ([#19](https://github.com/thordin9/apotheke/pull/19))
- **ui:** fixed-size chart labels; hide the chart with little history ([#19](https://github.com/thordin9/apotheke/pull/19))
- **ui:** single Edit button; move Delete into a danger zone ([#19](https://github.com/thordin9/apotheke/pull/19))
- **auth:** Secure cookie behind trusted proxies; configurable lifetime ([#19](https://github.com/thordin9/apotheke/pull/19))

### Maintenance

- **e2e:** test: update visual baselines ([#19](https://github.com/thordin9/apotheke/pull/19))
- **e2e:** test: photo editor baselines with the new Save-bar padding ([#19](https://github.com/thordin9/apotheke/pull/19))

## [2.0.0] - 2026-10-13

### Breaking changes

- **docker:** serve on port 9091 by default ([#17](https://github.com/thordin9/apotheke/pull/17))

### Fixed

- **ci:** start the mobile-check server on port 9091 ([#17](https://github.com/thordin9/apotheke/pull/17))

### Maintenance

- **e2e:** test: wait for sign-in to finish before opening settings ([#17](https://github.com/thordin9/apotheke/pull/17))
- **e2e:** test: update visual baselines ([#17](https://github.com/thordin9/apotheke/pull/17))

## [1.2.0] - 2026-10-13

### Added

- **stocktake:** location stocktakes with scanning, report and moves ([#16](https://github.com/thordin9/apotheke/pull/16))
- **auth:** user accounts with admin/editor/viewer roles ([#16](https://github.com/thordin9/apotheke/pull/16))
- **search:** ranked full-text search with highlights and typo tolerance ([#16](https://github.com/thordin9/apotheke/pull/16))
- **import:** CSV import with column mapping, dry run and preview ([#16](https://github.com/thordin9/apotheke/pull/16))
- **photos:** in-browser crop/rotate and photo reordering ([#16](https://github.com/thordin9/apotheke/pull/16))
- **auth:** configurable roles, guest browsing and self-registration ([#16](https://github.com/thordin9/apotheke/pull/16))

### Fixed

- **ui:** unique quick-add fields, 375px tiles, FAB clearance ([#16](https://github.com/thordin9/apotheke/pull/16))
- **photos:** keep the editor's exact-values box clear of the action bar ([#16](https://github.com/thordin9/apotheke/pull/16))
- **ui:** keep the filter button at 44px in WebKit ([#16](https://github.com/thordin9/apotheke/pull/16))

### Maintenance

- **e2e:** test: visual checks for stocktake, search, import, photos, roles ([#16](https://github.com/thordin9/apotheke/pull/16))
- **e2e:** test: wait for the un-favourite request before checking the DB ([#16](https://github.com/thordin9/apotheke/pull/16))
- **e2e:** test: update visual baselines ([#16](https://github.com/thordin9/apotheke/pull/16))
- docs: screenshots for stocktake, import, photo editor and roles ([#16](https://github.com/thordin9/apotheke/pull/16))

## [1.1.1] - 2026-10-13

### Fixed

- **ci:** fail the coverage run when no HTTP coverage was collected ([#15](https://github.com/thordin9/apotheke/pull/15))
- **test:** log why the coverage collector skips a request ([#15](https://github.com/thordin9/apotheke/pull/15))
- **test:** trace the coverage collector per request ([#15](https://github.com/thordin9/apotheke/pull/15))
- **test:** load the coverage collector from the router on PHP 8.3 ([#15](https://github.com/thordin9/apotheke/pull/15))

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

[Unreleased]: https://github.com/thordin9/apotheke/compare/v2.3.1...HEAD
[2.3.1]: https://github.com/thordin9/apotheke/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/thordin9/apotheke/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/thordin9/apotheke/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/thordin9/apotheke/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/thordin9/apotheke/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/thordin9/apotheke/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/thordin9/apotheke/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/thordin9/apotheke/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/thordin9/apotheke/releases/tag/v1.0.0
