# Changelog

All notable changes to Apotheke are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Release sections are written by `scripts/release.php` when a pull request is merged to `main`
(see [docs/releasing.md](docs/releasing.md)); the version lives in `VERSION`.

## [Unreleased]

### Added

- **ci4:** CodeIgniter 4.7.5 skeleton and Core. `/healthz` and `/health` match the legacy responses, and camera originals stay unserved. Photo storage inside the web root stops boot with the same message as the legacy app. A bad DSN stops boot. The page says the database settings could not be used, with no DSN parts. The log line lists only the driver, host, port and database name. The dev server router is `ci4/public/router.php`.
- **ci4:** Migrations module. `apotheke:migrate` and `bin/migrate.php` (when `APOTHEKE_APP=ci4`) apply the 25 migrations through a PDO runner. CI4 checks `pg_namespace` before `CREATE SCHEMA`. A missing schema does not return `/healthz` 200.

### Fixed

- **ci4:** quote every `pg_connect` value, escaping `\` and then `'`, so a password or user with a backslash, quote, space or `=` still connects.
- **ci4:** a PostgreSQL DSN may have spaces around `=`, as libpq does. The log line still lists only the driver, host, port and database name.
- **ci4:** DSN values are read as PDO and libpq read them, so a trailing space before `;` or at the end is dropped, a single-quoted value is unquoted, and `host = db.example ; port = 5432 ;` boots as it does on legacy.
- **ci4:** both CI4 connections follow the shipped (PHP 8.3) credential rule on every PHP version: `DB_USER` and `DB_PASSWORD` win over a `user` or `password` in `DB_DSN`, including the default user and an empty password. A warning, without the values, is logged when they differ. Legacy on PHP 8.4 lets an exact `user=` / `password=` in the DSN win instead.
- **ci4:** a DSN value that contains `user=` or `password=` (in `options`, `application_name` or the `sslpassword` keyword) no longer makes PHP 8.4's PDO drop `DB_USER` and `DB_PASSWORD`: the migration runner writes the credentials into the connection string instead of passing them to PDO.
- **ci4:** read `trust_proxy` from the `DB_SCHEMA` search path, on its own PostgreSQL connection, so closing that connection leaves CI4's connection open.
- **ci4:** a role without CREATE can use an existing schema, and a concurrent create (42P06 / 23505) continues once the schema is there. Connection failures from `bin/migrate.php` and `apotheke:migrate` print only the driver, host, port, database name and schema. The image sets `zend.exception_ignore_args = On`.
- **ci4:** CI4's schema setup reads the SQLSTATE (42501, 42P06, 23505) from the PostgreSQL driver instead of the English message text, so a server with a non-English `lc_messages` gets the same handling.

### Maintenance

- **ci:** `scripts/ci4-pgsql-proof.php` reports SQLSTATE 42P01 as a missing probe table. Any other SQLSTATE is a connection or query failure. The script does not print the DSN or the password.
- **test:** a production CI4 boot with `APOTHEKE_TEST_DB` unset returns 200 and does not create the probe table, on SQLite and as a PostgreSQL role without CREATE.
- **ci:** run CI on pushes to `feature/ci4` and on pull requests into it. A PHP 8.4 matrix covers `legacy` and `ci4` on SQLite and PostgreSQL; legacy@8.3 stays the upgrade/rollback reference. The ci4 leg runs PHPUnit against the CI4 server. `APOTHEKE_TEST_DB` selects that server's database. The pgsql probe is written by `tests/ci4-router.php`, and the ci4@pgsql job checks `public.apotheke_ci4_pgsql_probe`. deptrac and the DI rule check the Core and DevServer modules. Releases stay on `main` only.
- **ci:** run lint, headless mobile checks, Playwright and the Docker build + smoke test on PHP 8.4 as well as PHP 8.3. The shipped image stays PHP 8.3. `PHP_VERSION=8.4` builds the test image from the same Dockerfile. Markdown link checks walk all of `docs/`.
- **test:** compare two legacy installs (fresh install, `/healthz`, a settings save) and record the four parity quirks (N-2, QA L-2, N-4, I-3).
- **fix:** QR labels finish under PHP 8.4 function JIT (`opcache.jit=1235`). The guard disables opcache JIT for the whole PHP worker from the first request, not only for QR encoding. The cost is negligible. `opcache_jit_blacklist()` does not help. The matrix matches the interpreter.

## [2.5.5] - 2026-10-21

### Fixed

- **db:** CREATE SCHEMA is skipped when DB_SCHEMA already exists, so a role without CREATE no longer gets a 500 ([#41](https://github.com/thordin9/apotheke/issues/41), [#44](https://github.com/thordin9/apotheke/pull/44))
- **db:** concurrent first-start creation of the same schema no longer fails ([#44](https://github.com/thordin9/apotheke/pull/44))
- **migrate:** bin/migrate.php prints only the driver, host, port, dbname and DB_SCHEMA, never the user or the password ([#44](https://github.com/thordin9/apotheke/pull/44))

### Changed

- **qr:** the QR helper is renamed from qr_php84_jit_is_tracing to qr_jit_is_tracing ([#45](https://github.com/thordin9/apotheke/pull/45))

### Maintenance

- **changelog:** docs: add the Imagick colour note to 2.5.4 ([#40](https://github.com/thordin9/apotheke/pull/40))
- docs: clarify that only the autofill JPEG is one channel ([#40](https://github.com/thordin9/apotheke/pull/40))
- **changelog:** docs: link #37 from the 2.5.4 images line ([#40](https://github.com/thordin9/apotheke/pull/40))
- **qa:** docs: add the v2.5.4 release QA report ([#40](https://github.com/thordin9/apotheke/pull/40))
- **qa:** docs: add QA report for the QR helper rename ([#45](https://github.com/thordin9/apotheke/pull/45))
- **docker:** the image builds Imagick from a pinned source tarball ([#47](https://github.com/thordin9/apotheke/pull/47))
- **qa:** docs: add QA report for #46 imagick pinned source ([#47](https://github.com/thordin9/apotheke/pull/47))
- **db:** test: cover mixed-case and quoted schema names ([#44](https://github.com/thordin9/apotheke/pull/44))
- **ci:** chore: allowlist the migrate DSN placeholder ([#44](https://github.com/thordin9/apotheke/pull/44))
- **qa:** docs: add QA report for the DB schema fix ([#44](https://github.com/thordin9/apotheke/pull/44))

## [2.5.4] - 2026-10-20

### Fixed

- **images:** flatten Imagick transparency onto white and convert CMYK to sRGB ([#33](https://github.com/thordin9/apotheke/issues/33), [#37](https://github.com/thordin9/apotheke/pull/37))

### Changed

- **images:** share Imagick colour helpers ([#37](https://github.com/thordin9/apotheke/pull/37))

### Maintenance

- **images:** test: lock Imagick fallback fixtures and original sha256 ([#37](https://github.com/thordin9/apotheke/pull/37))
- docs: drop the hand-written Unreleased changelog note ([#37](https://github.com/thordin9/apotheke/pull/37))
- **changelog:** docs: add the QR function-JIT note to 2.5.3 ([#39](https://github.com/thordin9/apotheke/pull/39))
- docs: note Imagick grayscale JPEG is one channel ([#37](https://github.com/thordin9/apotheke/pull/37))
- test: count the HEIC writer as a fourth Imagick test ([#37](https://github.com/thordin9/apotheke/pull/37))
- **qa:** docs: add the v2.5.3 release QA report ([#39](https://github.com/thordin9/apotheke/pull/39))
- **qa:** docs: add QA report for the Imagick colour fix ([#37](https://github.com/thordin9/apotheke/pull/37))

## [2.5.3] - 2026-10-20

### Fixed

- **qr:** disable function JIT before the encoder loads ([#36](https://github.com/thordin9/apotheke/pull/36))
- **qr:** On 2.5.x, function JIT could produce a wrong QR code on installs outside Docker that enable it. PHP 8.2 and 8.3 with `opcache.jit=1235` miscompiled 1 of 240 codes; on PHP 8.4 that mode can hang `GET /labels`, and other function-JIT modes such as `1205` wrote wrong codes. `src/qr.php` now turns JIT off for the whole worker from the first request when function JIT is active. The cost is negligible. `opcache_jit_blacklist()` does not help. Tracing JIT stays on. The Docker image leaves JIT off, so its behaviour is unchanged.

### Maintenance

- **qr:** docs: name PHP 8.2, 8.3 and 8.4 as affected ([#36](https://github.com/thordin9/apotheke/pull/36))
- **qa:** docs: add QA report for the QR function-JIT fix ([#36](https://github.com/thordin9/apotheke/pull/36))

## [2.5.2] - 2026-10-20

### Fixed

- **photos:** force 4:2:0 in Imagick JPEG encodes ([#32](https://github.com/thordin9/apotheke/pull/32))
- limit 4:2:0 to analysis copy, tidy docs, CI ([#32](https://github.com/thordin9/apotheke/pull/32))

### Maintenance

- **photos:** test: fail a size miss and cover Imagick 4:4:4 ([#32](https://github.com/thordin9/apotheke/pull/32))
- **photos:** docs: describe Imagick JPEG 4:2:0 subsampling ([#32](https://github.com/thordin9/apotheke/pull/32))
- **changelog:** docs: drop notes already listed in 2.5.0 and 2.5.1 ([#32](https://github.com/thordin9/apotheke/pull/32))
- **qa:** docs: add QA report for PR 32 ([#32](https://github.com/thordin9/apotheke/pull/32))
- test: make HEIC display test portable to Alpine GD ([#32](https://github.com/thordin9/apotheke/pull/32))
- **qa:** docs: add PR 32 recheck report ([#32](https://github.com/thordin9/apotheke/pull/32))

## [2.5.1] - 2026-10-20

### Fixed

- **bootstrap:** hide the originals path from the browser
- **photos:** decode HEIC uploads in the Docker image

### Maintenance

- docs: describe untouched uploads and server-side HEIC
- **autofill:** test: cover a 524288 analysis-copy limit
- **qa:** docs: add QA report for L-4 Imagick and HEIC

## [2.5.0] - 2026-10-20

### Added

- **autofill:** send a stripped JPEG and keep originals
- **photos:** keep the picked file unless it is over the limit

### Fixed

- **autofill:** retry a 503 only when Retry-After is at most 60s
- **photos:** refuse originals inside the web root
- **nginx:** match /originals without regard to case

### Maintenance

- ci: add gitleaks scan, ignore rules, and read-only tokens ([#27](https://github.com/thordin9/apotheke/pull/27))
- ci: scan every git ref with pinned gitleaks
- **e2e:** test: update autofill-down baselines
- **e2e:** test: expect unavailable from a slow photo test
- docs: document upload limits, retries, and the Worker URL
- **e2e:** test: upload a PNG that GD can decode
- **qa:** docs: add PR #28 resize end-to-end QA report

## [2.4.1] - 2026-10-19

### Fixed

- **autofill:** focus, cold photo-test retry, and size limit ([#26](https://github.com/thordin9/apotheke/pull/26))

### Maintenance

- **qa:** docs: add Apotheke 2.4.1 QA results ([#26](https://github.com/thordin9/apotheke/pull/26))
- chore: align VERSION with v2.4.0 and backfill changelog ([#26](https://github.com/thordin9/apotheke/pull/26))

## [2.4.0] - 2026-10-19

### Added

- **autofill:** on-demand Analyze (Feature 8); auto-analyze is off by default (migration 0025) ([#25](https://github.com/thordin9/apotheke/pull/25))
- **autofill:** Model and Image size dropdowns from `/v1/info` ([#25](https://github.com/thordin9/apotheke/pull/25))
- **autofill:** one analysis at a time; a second analysis only fills empty or untouched fields ([#25](https://github.com/thordin9/apotheke/pull/25))

### Maintenance

- **docs:** admin and user guides, upgrade notes, and the 2.4.0 QA pass ([#25](https://github.com/thordin9/apotheke/pull/25))

## [2.3.2] - 2026-10-18

### Fixed

- **autofill:** keep an untouched default for an unknown category ([#23](https://github.com/thordin9/apotheke/pull/23))
- **autofill:** explain a long wait at 95 % ([#23](https://github.com/thordin9/apotheke/pull/23))

### Maintenance

- **visual:** test: baseline the 95 % loading-or-busy hint ([#23](https://github.com/thordin9/apotheke/pull/23))

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

[Unreleased]: https://github.com/thordin9/apotheke/compare/v2.5.5...HEAD
[2.5.5]: https://github.com/thordin9/apotheke/compare/v2.5.4...v2.5.5
[2.5.4]: https://github.com/thordin9/apotheke/compare/v2.5.3...v2.5.4
[2.5.3]: https://github.com/thordin9/apotheke/compare/v2.5.2...v2.5.3
[2.5.2]: https://github.com/thordin9/apotheke/compare/v2.5.1...v2.5.2
[2.5.1]: https://github.com/thordin9/apotheke/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/thordin9/apotheke/compare/v2.4.1...v2.5.0
[2.4.1]: https://github.com/thordin9/apotheke/compare/v2.4.0...v2.4.1
[2.4.0]: https://github.com/thordin9/apotheke/compare/v2.3.2...v2.4.0
[2.3.2]: https://github.com/thordin9/apotheke/compare/v2.3.1...v2.3.2
[2.3.1]: https://github.com/thordin9/apotheke/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/thordin9/apotheke/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/thordin9/apotheke/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/thordin9/apotheke/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/thordin9/apotheke/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/thordin9/apotheke/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/thordin9/apotheke/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/thordin9/apotheke/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/thordin9/apotheke/releases/tag/v1.0.0
