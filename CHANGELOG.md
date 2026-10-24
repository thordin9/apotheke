# Changelog

All notable changes to Apotheke are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Release sections are written by `scripts/release.php` when a pull request is merged to `main`
(see [docs/releasing.md](docs/releasing.md)); the version lives in `VERSION`.

## [Unreleased]

### Upgrade notes

- **ci4 (security):** with `APOTHEKE_APP=ci4`, `php bin/settings.php get autofill_token` and `get password_hash` no longer print the stored secret; they exit 1 with `“KEY” is write-only.` Scripts that read the token must stop doing so. To rotate the AI autofill token, set the new `AUTOFILL_TOKEN` on the autofill service, then enter it under **Control Panel → AI autofill → Access token → Replace the token** and press **Save token** (**Clear token** removes it). `bin/settings.php set autofill_token` is refused on legacy and CI4 alike, so the Control Panel is the supported path. Passwords are per account: `php bin/settings.php user password NAME` or **Settings → Users & sign-in**. ([#64](https://github.com/thordin9/apotheke/pull/64))

### Intentional deviations (settings CLI, `APOTHEKE_APP=ci4`, [#64](https://github.com/thordin9/apotheke/pull/64))

1. **Secrets are write-only.** `get autofill_token` and `get password_hash` print `“KEY” is write-only.` on stderr and exit 1; legacy printed the stored value.
2. **`get` of an unknown key** prints `Unknown setting “KEY”.` and exits 1; legacy stopped with an uncaught exception (PHP fatal, exit 255).
3. **`import` of a missing file** prints the same message and exits 1 like legacy, without PHP's `file_get_contents(…)` warning line.
4. **`list` is read-only.** It does not create the account `admin` from `APP_PASSWORD` (sign-in on, no accounts yet), so its `users` line can show 0 where legacy shows 1 until the Auth PR ports that bootstrap. The `sign-in` line is the same.

Every other form, output and exit code is byte-identical to legacy (`tests/Unit/Ci4SettingsCliTest.php`).

### Added

- **ci4:** CodeIgniter 4.7.5 skeleton and Core. `/healthz` and `/health` match the legacy responses, and camera originals stay unserved. Photo storage inside the web root stops boot with the same message as the legacy app. A bad DSN stops boot. The page says the database settings could not be used, with no DSN parts. The log line lists only the driver, host, port and database name. The dev server router is `ci4/public/router.php`. ([#38](https://github.com/thordin9/apotheke/pull/38))
- **ci4:** Migrations module. `apotheke:migrate` and `bin/migrate.php` (when `APOTHEKE_APP=ci4`) apply the 27 migrations through a PDO runner. CI4 checks `pg_namespace` before `CREATE SCHEMA`. A missing schema does not return `/healthz` 200. ([#53](https://github.com/thordin9/apotheke/pull/53))
- **ci4:** Settings module. `SettingsSchema` is the single source of the 64 settings keys (defaults and validation); the migration shim's `settings_schema`, `setting_default` and `setting_validate` delegate to it. `SettingsService` (all, get, save, reset a section, export, import up to 256 KiB, the write-only autofill token with its own getter, token cleared when the service address moves) is built per request by `Config\Services`. `/theme.css`, `/labels.css`, `/manifest.webmanifest`, `/favicon.ico` and `/brand/{file}` match the legacy bytes and headers and start no session; brand files sit behind a `BrandStore` interface. `apotheke:settings` and `bin/settings.php` (when `APOTHEKE_APP=ci4`) run `list`, `get`, `set`, `reset`, `export` and `import` with the legacy output and exit codes; the account and sign-in forms stay on legacy until the Auth module. ([#64](https://github.com/thordin9/apotheke/pull/64))
- **ci4:** Autofill client. `Services::autofill()` reads every autofill setting and the token through `SettingsService`. POST `/autofill/suggest`, `/settings/autofill/token`, `/settings/autofill/test` and `/settings/autofill/test-photo` return the legacy JSON (the session CSRF token is required, as on every other CI4 POST). The analysis JPEG is capped to `enforced_image_bytes`, then the configured maximum (`auto` is `/v1/info` `max_image_bytes`, or 983040), then the value as stored. An HTTP 413 refetches `/v1/info` once and retries once. Quality steps down by 10, and 60 is tried before the long edge shrinks. The stored photo is not rewritten. The warn-once pair and the temporary JPEG go through `Storage` (local disk under `DATA_DIR`). The Control Panel page stays on legacy until the settings UI exists. ([#69](https://github.com/thordin9/apotheke/pull/69))
- **ci4:** saving or clearing the AI autofill token from a plain form returns 303. The redirect does not start CodeIgniter's session beside the native one. A token typed into Test with a photo is removed from the HTTP 500 and 503 log lines. Local storage refuses a symlink and replaces a file by writing a temporary file and renaming it. ([#69](https://github.com/thordin9/apotheke/pull/69))
- **ci4:** request and sign-in hardening ported from `main` (SEC-M3, SEC-L2, SEC-N6, SEC-M1, SEC-M2). Unknown host names get 421 without the name echoed (`ALLOWED_HOSTS` and `PUBLIC_URL` add names; `*` turns the check off; `/healthz` included). With sign-in off, cross-site writes get 403. Sessions start only when a page needs one, and a session ends after `session_days` without a request. `TRUSTED_PROXIES` limits which proxies may set `X-Forwarded-For`. Only admins can import sign-in settings; the CLI is unrestricted. The Auth module holds the admin guards and "sign out everywhere" for the sign-in pages. ([#76](https://github.com/thordin9/apotheke/pull/76))

### Fixed

- **ci4:** a signed-in session ends after `session_idle_hours` with no request, and `session_days` after sign-in even while it is in use, as on `main`. A session without a sign-in time gets one on its next request. Each account has a session epoch (migration 0028, the same file as `main`): sign out everywhere and a new password store a new one, so the password is no longer rehashed. Signing the same account in again keeps its sign-in time only when the caller asks for it, as `main` [#87](https://github.com/thordin9/apotheke/pull/87). ([#90](https://github.com/thordin9/apotheke/pull/90))
- **ci4:** CodeIgniter's session service is read-only, and a session a request left open is closed before the next request in a long-lived worker. The per-request base URL sets the scheme and port as well as the host, so `current_url()` matches `base_url()`. ([#90](https://github.com/thordin9/apotheke/pull/90))
- **ci4 (security):** stored secrets are encrypted at rest when `APP_KEY` (or `APP_KEY_FILE`) is set, as on `main` (S-L5). CI4 reads and writes the same `enc:v1:` values as legacy, seals a plaintext AI autofill token the first time it reads it with a key, and logs the same once-per-request lines. A key that is set but cannot be used (malformed, unreadable, inside the data directory, or no sodium extension) refuses the token save with a 422 that names the cause and keeps the stored value. The settings CLI prints the same `APP_KEY` notice as legacy. `key generate` and `key rotate` stay in `bin/settings.php`. ([#89](https://github.com/thordin9/apotheke/pull/89))
- **ci4:** `Services::apothekeDb` brings the schema up to date once per process and database, not on every request in a long-lived worker. Under php-fpm and spark that is once per request or command, as legacy `db()` does; `apotheke:migrate` stays the explicit way. The settings cache lives in the per-request `SettingsService`, not in a static. ([#64](https://github.com/thordin9/apotheke/pull/64))
- **ci4:** `get` of an unknown key or of a write-only key exits 1 with a message instead of a PHP fatal or printing the secret. ([#64](https://github.com/thordin9/apotheke/pull/64))
- **ci4:** quote every `pg_connect` value, escaping `\` and then `'`, so a password or user with a backslash, quote, space or `=` still connects. ([#38](https://github.com/thordin9/apotheke/pull/38))
- **ci4:** a PostgreSQL DSN may have spaces around `=`, as libpq does. The log line still lists only the driver, host, port and database name. ([#53](https://github.com/thordin9/apotheke/pull/53)) ([#66](https://github.com/thordin9/apotheke/pull/66))
- **ci4:** DSN values are read as PDO and libpq read them, so a trailing space before `;` or at the end is dropped, a single-quoted value is unquoted, and `host = db.example ; port = 5432 ;` boots as it does on legacy. ([#66](https://github.com/thordin9/apotheke/pull/66))
- **ci4:** both CI4 connections follow the shipped (PHP 8.3) credential rule on every PHP version: `DB_USER` and `DB_PASSWORD` win over a `user` or `password` in `DB_DSN`, including the default user and an empty password. A warning, without the values, is logged once per request (php-fpm) or `spark` run when they differ. Legacy on PHP 8.4 lets an exact `user=` / `password=` in the DSN win instead. ([#66](https://github.com/thordin9/apotheke/pull/66))
- **ci4:** when another DSN setting mentions `user=` or `password=`, the migration runner writes the resolved user and password into the PDO DSN as quoted keys (other DSNs still pass them to PDO), so `user=` or `password=` text inside another setting (`options`, `application_name`, the `sslpassword` keyword) no longer makes PHP 8.4's PDO drop `DB_USER` and `DB_PASSWORD`. Only a `;` in `DB_USER` or `DB_PASSWORD` (which PDO cannot read inside the DSN) combined with that text stops boot and `apotheke:migrate`, with a message that contains no values (see the upgrade notes). ([#66](https://github.com/thordin9/apotheke/pull/66))
- **ci4:** a failed CI4 PostgreSQL connect logs only that it could not connect, without libpq's text, which names the user. No connect failure on either connection puts the DSN, user or password in the logs, stderr or the page. ([#66](https://github.com/thordin9/apotheke/pull/66))
- **ci4:** read `trust_proxy` from the `DB_SCHEMA` search path, on its own PostgreSQL connection, so closing that connection leaves CI4's connection open. ([#38](https://github.com/thordin9/apotheke/pull/38))
- **ci4:** a role without CREATE can use an existing schema, and a concurrent create (42P06 / 23505) continues once the schema is there. Connection failures from `bin/migrate.php` and `apotheke:migrate` print only the driver, host, port, database name and schema. The image sets `zend.exception_ignore_args = On`. ([#53](https://github.com/thordin9/apotheke/pull/53))
- **ci4:** CI4's schema setup reads the SQLSTATE (42501, 42P06, 23505) from the PostgreSQL driver instead of the English message text, so a server with a non-English `lc_messages` gets the same handling. ([#66](https://github.com/thordin9/apotheke/pull/66))
- **ci4:** 404, 500 and `UserError` responses carry the same security headers as legacy error pages (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy`). SEC-N8. ([#74](https://github.com/thordin9/apotheke/pull/74))
- **ci4:** the session cookie lasts `session_days` (1–365, 30 when unset or unreadable), as on legacy, instead of a fixed 30 days. SEC-N9. ([#74](https://github.com/thordin9/apotheke/pull/74))
- **ci4:** `/health` and `/healthz` answer GET and HEAD only; other methods get the plain 404. SEC-N10. ([#74](https://github.com/thordin9/apotheke/pull/74))
- **ci4:** every POST, PUT, PATCH and DELETE needs the session CSRF token in the `csrf` field or the `X-CSRF-Token` header, with legacy's 419 answer (JSON for fetch clients). GET and HEAD never start a session for it. CI4's own cookie CSRF stays off. SEC-N10. ([#74](https://github.com/thordin9/apotheke/pull/74))
- **ci4:** `trust_proxy` is read once per request instead of twice, so a request opens one settings connection fewer. SEC-N11. ([#74](https://github.com/thordin9/apotheke/pull/74))
- **ci4:** `Config\App` no longer opens its own database connection to read `trust_proxy`; the request's settings service reads it on CI4's connection (#74 N-2). ([#76](https://github.com/thordin9/apotheke/pull/76))
- **ci4:** CodeIgniter's own session service no longer starts a second session beside Apotheke's session on HTML responses, so a signed-in session keeps its id and CSRF token (#76 L-1). ([#82](https://github.com/thordin9/apotheke/pull/82))
- **ci4:** links and redirects built by CodeIgniter use the Control Panel's public address (or `PUBLIC_URL`), else the request's scheme with "Trust the reverse proxy" and an allowed host name, as the legacy app does. Saving or clearing the AI autofill token from a plain form redirects with a relative address (#76 L-2). ([#82](https://github.com/thordin9/apotheke/pull/82))
- **ci4:** each request starts without session data, so a long-lived worker never carries one request's session into the next (#76 L-3). ([#82](https://github.com/thordin9/apotheke/pull/82))

### Maintenance

- **ci:** `scripts/ci4-pgsql-proof.php` reports SQLSTATE 42P01 as a missing probe table. Any other SQLSTATE is a connection or query failure. The script does not print the DSN or the password. ([#38](https://github.com/thordin9/apotheke/pull/38))
- **test:** CI4 module tests (`ci4/phpunit.xml.dist`, CIUnitTestCase) run inside the main PHPUnit run on SQLite and PostgreSQL. Goldens compare the settings schema, the settings CLI and the branding routes with legacy. ([#64](https://github.com/thordin9/apotheke/pull/64))
- **test:** a production CI4 boot with `APOTHEKE_TEST_DB` unset returns 200 and does not create the probe table, on SQLite and as a PostgreSQL role without CREATE. ([#38](https://github.com/thordin9/apotheke/pull/38))
- **ci:** run CI on pushes to `feature/ci4` and on pull requests into it. A PHP 8.4 matrix covers `legacy` and `ci4` on SQLite and PostgreSQL; legacy@8.3 stays the upgrade/rollback reference. The ci4 leg runs PHPUnit against the CI4 server. `APOTHEKE_TEST_DB` selects that server's database. The pgsql probe is written by `tests/ci4-router.php`, and the ci4@pgsql job checks `public.apotheke_ci4_pgsql_probe`. deptrac and the DI rule check the Core and DevServer modules. Releases stay on `main` only. ([#31](https://github.com/thordin9/apotheke/pull/31))
- **ci:** run lint, headless mobile checks, Playwright and the Docker build + smoke test on PHP 8.4 as well as PHP 8.3. The shipped image stays PHP 8.3 (`ARG PHP_VERSION=8.3.36`). The 8.4 leg passes `PHP_VERSION=8.4.27` and builds the test image from the same Dockerfile. Markdown link checks walk all of `docs/`. ([#34](https://github.com/thordin9/apotheke/pull/34))
- **test:** compare two legacy installs (fresh install, `/healthz`, a settings save) and record the four parity quirks (N-2, QA L-2, N-4, I-3). ([#34](https://github.com/thordin9/apotheke/pull/34))
- **fix:** QR labels finish under PHP 8.4 function JIT (`opcache.jit=1235`). The guard disables opcache JIT for the whole PHP worker from the first request, not only for QR encoding. The cost is negligible. `opcache_jit_blacklist()` does not help. The matrix matches the interpreter. ([#34](https://github.com/thordin9/apotheke/pull/34))
- **ci4:** sync `main` e2628f2 (2.6.1) into `feature/ci4`. Migration 0026 (`autofill_max_image_bytes`) is in the CI4 settings schema copy. CI4 database connect failures print the same `Could not connect to the database (driver – host port dbname (schema …))` line as `bin/migrate.php`, `/healthz` and `/health` answer 500 with an empty body and log only that line while the database is down, as legacy does, and an unwritable data dir exits 1. The Dockerfile pins `ARG PHP_VERSION=8.3.36`; the CI 8.4 leg builds `8.4.27`, and the pin assert reads the ARG default. ([#61](https://github.com/thordin9/apotheke/pull/61))
- **test:** in CI (`CI` or `GITHUB_ACTIONS` set) the refused CI4 PostgreSQL connect test fails instead of skipping when PostgreSQL is down or accepts any password. The shipped-boot probe test reads the PostgreSQL host, port and database from `TEST_PG_*`. ([#65](https://github.com/thordin9/apotheke/pull/65))
- **ci:** the Docker job runs `scripts/legacy-dsn-precedence.php` with the image's PHP against its PostgreSQL and checks legacy `db_connect()` credential precedence (golden cases a–d, plus e, e2 and e3 where `user=` or `password=` text sits inside `application_name` or `options`, e3 with credentials in both the DSN and the environment, for that PHP version) with `current_user`. On CI, the refused-connect log test fails instead of skipping when it cannot run. ([#66](https://github.com/thordin9/apotheke/pull/66)) ([#68](https://github.com/thordin9/apotheke/pull/68))
- **test:** the CI4 boot migration key is pinned to the schema and the user, the credentials warning is pinned to once per request, and `skipOutsideCi()` is a shared trait with a test for its CI-only fail branch. ([#68](https://github.com/thordin9/apotheke/pull/68))
- **ci4:** sync `main` 3f729a3 (2.6.9) into `feature/ci4`. The legacy app gets main's 2.6.2–2.6.9 hardening (Host allow-list, sign-in import limits, restore check, ImageMagick policy, image decode limits and the PNG cap, the CLI admin fix, Docker localhost bind and required sign-in, the `session_idle_hours` idle sign-out). Migration 0027 (`session_idle_hours`) is in the CI4 settings schema copy; CI4 does not enforce the idle limit yet. `scripts/lint.sh` runs `release.php check-links`, so an unlinked `[Unreleased]` bullet fails lint on `feature/ci4` too, and the link check skips elided `(…#anchor)` placeholders in quoted QA suggestions. ([#83](https://github.com/thordin9/apotheke/pull/83))

## [2.6.9] - 2026-10-24

### Fixed

- **auth:** a signed-in session ends after `session_idle_hours` hours with no request (default 168, at most 8760). The stay-signed-in limit is unchanged. The idle limit is set under Users & sign-in and is a sign-in setting on import, restore, and the command line. SEC-N6. ([#80](https://github.com/thordin9/apotheke/pull/80))

### Maintenance

- **changelog:** docs: record the idle session limit ([#80](https://github.com/thordin9/apotheke/pull/80))
- **e2e:** test: update the security settings baseline ([#80](https://github.com/thordin9/apotheke/pull/80))
- **qa:** docs: add #80 session idle hours QA doc ([#80](https://github.com/thordin9/apotheke/pull/80))
- **qa:** docs: add v2.6.8 release QA doc ([#80](https://github.com/thordin9/apotheke/pull/80))

## [2.6.8] - 2026-10-24

### Fixed

- **images:** a PNG above 26 megapixels is refused from the header before it is decoded, with the same message as an oversized photo. WebP and GIF stay at 40 megapixels. The cap is the size an alpha PNG still fits under the Docker image's memory_limit of 256M (peak 231931904 bytes with the upload held). A camera file saved before processing dies, including a fatal error, is removed before the request ends. Suggest returns that same message with status 422 when a decode is refused as too large. ([#79](https://github.com/thordin9/apotheke/pull/79))

### Maintenance

- **changelog:** docs: record the PNG upload hardening ([#79](https://github.com/thordin9/apotheke/pull/79))
- **auth:** test: pin web guest admin and CLI password off ([#79](https://github.com/thordin9/apotheke/pull/79))
- **changelog:** docs: keep the #79 note out of v2.6.6 ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add the v2.6.5 release QA report ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add v2.6.6 release QA doc ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add v2.6.7 release QA doc ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add #79 PNG cap and upload clean-up QA doc ([#79](https://github.com/thordin9/apotheke/pull/79))

## [2.6.7] - 2026-10-24

**Upgrade note:** Docker installs are private by default (hardening, SEC-M3). Compose publishes the app on `127.0.0.1:9091` only: if phones or other machines open it directly, change the port line in `docker-compose.yml` to `"9091:9091"` (all interfaces) or `"<LAN-IP>:9091:9091"` on purpose; a reverse proxy on the same server needs no change. The container also refuses to start with sign-in off. An install with sign-in on starts unchanged. Without sign-in, either turn it on with `docker compose run --rm -u www-data apotheke php bin/settings.php password` (setting `APP_PASSWORD` alone doesn't, once the database exists), or set `ALLOW_NO_LOGIN=1` to keep running without sign-in. A new install needs `APP_PASSWORD` or `ALLOW_NO_LOGIN=1`.

### Fixed

- **docker:** hardening (SEC-M3): compose publishes the port on `127.0.0.1` only, and the container refuses to start with sign-in off unless `ALLOW_NO_LOGIN=1` is set. ([#75](https://github.com/thordin9/apotheke/pull/75))

### Maintenance

- **docker:** test: check the sign-in requirement at container start ([#75](https://github.com/thordin9/apotheke/pull/75))
- **docker:** docs: localhost port and required sign-in (SEC-M3) ([#75](https://github.com/thordin9/apotheke/pull/75))
- **changelog:** docs: record the SEC-M3 Docker defaults ([#75](https://github.com/thordin9/apotheke/pull/75))
- **docker:** test: hand the scratch volume back before deleting it ([#75](https://github.com/thordin9/apotheke/pull/75))
- **release:** test: check the whole upgrade note leads the notes ([#75](https://github.com/thordin9/apotheke/pull/75))
- **qa:** docs: add the v2.6.3 release QA report ([#75](https://github.com/thordin9/apotheke/pull/75))
- **qa:** docs: add the v2.6.4 release QA report ([#75](https://github.com/thordin9/apotheke/pull/75))
- **qa:** docs: add the #75 docker localhost sign-in QA report ([#75](https://github.com/thordin9/apotheke/pull/75))

## [2.6.6] - 2026-10-24

### Fixed

- **auth:** the command line can change sign-in settings and admin accounts when guest browsing is on. ([#77](https://github.com/thordin9/apotheke/pull/77))

### Maintenance

- **changelog:** docs: note the CLI guest-browsing admin fix ([#77](https://github.com/thordin9/apotheke/pull/77))
- **changelog:** docs: keep the CLI admin note out of v2.6.4 ([#77](https://github.com/thordin9/apotheke/pull/77))
- **qa:** docs: add the #77 CLI admin with guest browsing QA report ([#77](https://github.com/thordin9/apotheke/pull/77))

## [2.6.5] - 2026-10-24

### Fixed

- **autofill:** refuse an over-limit photo on suggest ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** a JPEG of up to 250 megapixels is kept and decoded already reduced, so the decoded frame stays within 128 megapixels. A HEIC or HEIF photo above 128 megapixels, or above 16,384 px on a side, is refused from the header before it is decoded. A 48 MP photo still passes. The camera file of a photo that is kept stays byte-identical. (S-M1, SEC-N5) ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** a JPEG above 40 megapixels is decoded already reduced. Photos at or under 40 megapixels still go through GD. Imagick is used with an explicit format, and its resource limits are set at startup. (S-M1, SEC-N5) ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** a photo Imagick refuses because of a resource limit, including a HEIC frame the decoder will not allocate, is declined with the same “too large” message as an oversized frame. The upload is not stored, and the page does not show a server error. A photo over that same header limit posted to `/autofill/suggest` gets `Image dimensions are too large.` with status 422, and nothing is stored or sent on. (S-M1, SEC-N5) ([#72](https://github.com/thordin9/apotheke/pull/72))

### Maintenance

- **images:** docs: note the limit on photo dimensions ([#72](https://github.com/thordin9/apotheke/pull/72))
- **changelog:** docs: record the large-photo decode limits ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** test: require the iloc error only when reported ([#72](https://github.com/thordin9/apotheke/pull/72))
- **changelog:** docs: keep #72 notes under Unreleased ([#72](https://github.com/thordin9/apotheke/pull/72))
- **qa:** docs: add the #72 image decode limits QA report ([#72](https://github.com/thordin9/apotheke/pull/72))

## [2.6.4] - 2026-10-24

**Upgrade note:** Health checks (`/healthz` and `/health`) follow the same rule. A probe that uses `localhost` or an IP address needs no change, but one that calls the app by a name needs that name in `ALLOWED_HOSTS` or `PUBLIC_URL`.

### Fixed

- **docker:** hardening (SEC-M3, #70 N-3): a restore upload with an unknown host name gets 421 from nginx, not 500. ([#73](https://github.com/thordin9/apotheke/pull/73))

### Maintenance

- **security:** test: cover the restore check 403 without Backup ([#73](https://github.com/thordin9/apotheke/pull/73))
- **changelog:** docs: say health checks need an allowed host ([#73](https://github.com/thordin9/apotheke/pull/73))
- **changelog:** docs: record the #70 follow-up hardening ([#73](https://github.com/thordin9/apotheke/pull/73))
- **release:** test: check the whole upgrade note leads the notes ([#73](https://github.com/thordin9/apotheke/pull/73))
- **qa:** docs: add the v2.6.2 release QA report ([#73](https://github.com/thordin9/apotheke/pull/73))
- **qa:** docs: add the #73 hardening follow-ups QA report ([#73](https://github.com/thordin9/apotheke/pull/73))
- **docker:** hardening (S-M1, #70 N-2): the ImageMagick policy check also fails unless delegates and `@file` paths have no rights and the time limit is 60 s or less. ([#73](https://github.com/thordin9/apotheke/pull/73))
- **deploy:** docs: health checks need an allowed host name (#70 L-2), plus the restore check under nginx, the container host name, the sign-in session, TLS proxies and the README Host row (#70 N-3 to N-7); a test covers the restore check's 403 (#70 N-1). ([#73](https://github.com/thordin9/apotheke/pull/73))

## [2.6.3] - 2026-10-24

### Fixed

- **auth:** a settings import or a backup restore applies sign-in settings (guest browsing, registration, the registration role, approval, session length, and trust-the-proxy) only for an admin account. Other settings in the file still apply, and skipped keys are named with the other protected keys. A file cannot turn sign-in off. A restore never replaces accounts, roles, or role permissions; a zip that names the users or roles table is refused. The command line still applies the sign-in settings. SEC-M1. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** only an admin account can grant the admin role, turn sign-in off, or change an existing admin account (role, name, password, approval, or deletion). The Users permission on its own is not enough. Other accounts can still be changed, and the command line can still do all of it. SEC-L1. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** sign-in attempts are counted per client and account (10 in 15 minutes). `X-Forwarded-For` is used only when Trust the reverse proxy is on and the connection comes from an address in `trusted_proxies` / `TRUSTED_PROXIES`; the client is the rightmost hop that is not itself listed. Registration stays 5 per hour per address. SEC-M2. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** the `page` query on the catalog and the activity log is limited to 1–100000, so a very large value is an ordinary page. SEC-N2. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** the Control Panel database line shows host, port, and dbname. A full DSN is parsed the same way as `config_dsn_public_fields()`, and the password is not included. SEC-N3. ([#71](https://github.com/thordin9/apotheke/pull/71))

### Maintenance

- **auth:** docs: document who can change sign-in and how the limit works ([#71](https://github.com/thordin9/apotheke/pull/71))
- **changelog:** docs: record the sign-in hardening ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** docs: limit sign-in import and admin-account changes to admins ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** docs: align the proxy wording with the host checks ([#71](https://github.com/thordin9/apotheke/pull/71))
- **changelog:** docs: keep the #71 notes out of v2.6.2 ([#71](https://github.com/thordin9/apotheke/pull/71))
- **qa:** docs: add the #71 security hardening QA report ([#71](https://github.com/thordin9/apotheke/pull/71))

## [2.6.2] - 2026-10-24

**Upgrade note:** Apotheke now answers only to IP addresses, `localhost`, the machine's own host name, the host of
`PUBLIC_URL` and the names in the new `ALLOWED_HOSTS` setting. Any other host name gets *Unknown host name* (421).
If you open the app by a name, for example `http://nas.local:9091` or `https://apotheke.example`, add that name
before you upgrade (comma-separated; ports are ignored):

```
ALLOWED_HOSTS=nas.local,apotheke.example
```

Setting `PUBLIC_URL` to that address works too. Opening the app by its IP address needs no change. Details:
[Host names](https://github.com/thordin9/apotheke/blob/main/docs/deployment-and-operations.md#host-names).

### Fixed

- **security:** hardening (SEC-M3): the app answers only to IP addresses, `localhost`, its own host name, `PUBLIC_URL`'s host and the new `ALLOWED_HOSTS` list; other host names get 421, and label links never use an unlisted host. With sign-in off, a change must come from the app's own pages (`Sec-Fetch-Site` or `Origin`); requests without either header still work. If you open the app by a name, add it to `ALLOWED_HOSTS` or set `PUBLIC_URL`. ([#70](https://github.com/thordin9/apotheke/pull/70))
- **security:** hardening (SEC-L2, SEC-L3): a session starts only when a page with a form is shown or someone signs in, so 404s, icons, health checks and redirects leave no session file. The session cookie is `Secure` when `PUBLIC_URL` is `https://` and the request uses that host. ([#70](https://github.com/thordin9/apotheke/pull/70))
- **docker:** hardening (SEC-L2): the image accepts a restore upload larger than 22 MB only from a signed-in user with the Backup permission, or while sign-in is off; nginx checks this before it reads the body. ([#70](https://github.com/thordin9/apotheke/pull/70))

### Maintenance

- **autofill:** test: post a JPEG that exactly fills the cap ([#62](https://github.com/thordin9/apotheke/pull/62))
- **release:** ci: reject an Unreleased bullet with no PR link ([#62](https://github.com/thordin9/apotheke/pull/62))
- **qa:** docs: add the v2.6.1 release QA report ([#62](https://github.com/thordin9/apotheke/pull/62))
- **qa:** docs: add the #62 v2.6.1 follow-ups QA report ([#62](https://github.com/thordin9/apotheke/pull/62))
- **qa:** docs: add QA2 review for #70 security hardening ([#70](https://github.com/thordin9/apotheke/pull/70))
- **docker:** hardening (S-M1, S-L1): the image ships a restrictive ImageMagick policy (size, memory, disk and time limits; text, vector, page and URL coders, delegates and `@file` off), and the build fails if it is not in effect. ([#70](https://github.com/thordin9/apotheke/pull/70))
- **deploy:** docs: describe `trust_proxy`, Host names, the restore limit and the ImageMagick policy, and add HSTS to the Caddy and nginx examples (SEC-L3). ([#70](https://github.com/thordin9/apotheke/pull/70))

- **changelog:** the first upload and the 413 retry are tested with a JPEG that fills the cap, and a handwritten bullet must link its pull request. ([#62](https://github.com/thordin9/apotheke/pull/62))

## [2.6.1] - 2026-10-24

### Fixed

- **release:** a handwritten changelog bullet replaces the generated bullet with the same scope, so the release notes do not list that change twice. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** an image-limit clamp is logged once per distinct pair of sizes. A later photo, and the rebuild after an HTTP 413, does not log that pair again. The line still has no token. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** JPEG quality steps down by 10 from the configured quality. 60 is still tried before the long edge shrinks, so a quality of 95 no longer jumps straight to 70. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** the byte cap is applied to the JPEG the service limits. The multipart wrapper, about 320 bytes, is not counted. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** a limit that would display as 0 MiB is shown in KB (1000 bytes is 1 KB). ([#60](https://github.com/thordin9/apotheke/pull/60))

### Maintenance

- **autofill:** docs: say the clamp warning is once per pair ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** docs: describe the ten-point quality step ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** docs: say a tiny limit is shown in KB ([#60](https://github.com/thordin9/apotheke/pull/60))
- **qa:** docs: add the #60 max image size nits QA report ([#60](https://github.com/thordin9/apotheke/pull/60))
- **qa:** docs: add the v2.6.0 release QA report ([#60](https://github.com/thordin9/apotheke/pull/60))

## [2.6.0] - 2026-10-23

### Added

- **autofill:** add a configurable maximum image size ([#59](https://github.com/thordin9/apotheke/pull/59))
- **autofill:** Control Panel → AI autofill has **Maximum image size (bytes)** (`autofill_max_image_bytes`, default `auto`). `auto` uses `max_image_bytes` from the service’s `GET /v1/info`, or 983040 bytes when that answer is unavailable. An explicit value must be a whole number from 65536 to 10485760. There is no environment override. An explicit value is capped to `enforced_image_bytes` when that field is present, otherwise to `max_image_bytes`, and used as stored only when neither is known. A lower cap logs a warning and never the token. An HTTP 413 refetches `/v1/info` once, clamps again, and retries the upload once. The copy is a hard maximum: JPEG quality steps down, then the long edge, until it fits. The stored photo stays byte-identical. ([#59](https://github.com/thordin9/apotheke/pull/59))

### Fixed

- **release:** build the release body from the full section ([#59](https://github.com/thordin9/apotheke/pull/59))
- **release:** keep blank lines inside changelog notes ([#59](https://github.com/thordin9/apotheke/pull/59))
- **bootstrap:** write the data-dir error to stderr ([#59](https://github.com/thordin9/apotheke/pull/59))
- **bootstrap:** avoid the STDERR constant under php-fpm ([#59](https://github.com/thordin9/apotheke/pull/59))
- **autofill:** cap an explicit size to the service limit ([#59](https://github.com/thordin9/apotheke/pull/59))
- **db:** while the database is down, every route (CSS, the manifest and /login included) returns 500. The log has only the sanitized connect line. The database user, the password and the DSN are not logged. ([#59](https://github.com/thordin9/apotheke/pull/59))

### Maintenance

- **e2e:** test: update autofill settings screenshot baselines ([#59](https://github.com/thordin9/apotheke/pull/59))
- **db:** docs: record the 500 while the database is down ([#59](https://github.com/thordin9/apotheke/pull/59))
- **changelog:** docs: drop the duplicate docker and db lines ([#59](https://github.com/thordin9/apotheke/pull/59))
- **qa:** docs: add the v2.5.8 release QA report ([#59](https://github.com/thordin9/apotheke/pull/59))
- **release:** test: run apply and check the notes file ([#59](https://github.com/thordin9/apotheke/pull/59))
- **changelog:** docs: link the image-size note to #59 ([#59](https://github.com/thordin9/apotheke/pull/59))
- **autofill:** docs: say a blank image size saves as auto ([#59](https://github.com/thordin9/apotheke/pull/59))
- **e2e:** test: update autofill settings baselines ([#59](https://github.com/thordin9/apotheke/pull/59))
- **qa:** docs: add the v2.6.0 max image size QA report ([#59](https://github.com/thordin9/apotheke/pull/59))

## [2.5.8] - 2026-10-23

### Fixed

- **release:** move handwritten Unreleased notes into the version ([#58](https://github.com/thordin9/apotheke/pull/58))
- **db:** seed, backup, restore and settings exit 1 when they can't connect to the database, and name only the driver, host, port, dbname and DB_SCHEMA. The database user and the server's error text are not printed or logged. /healthz logs that same line and answers as before. SQLite open errors are unchanged. ([#58](https://github.com/thordin9/apotheke/pull/58))
- **bootstrap:** a data directory that is not writable exits 1.
- **docker:** exception traces in the image omit function arguments, so a password passed into a call is not copied into the trace.

### Maintenance

- test: disable JIT in the healthz connect log ([#58](https://github.com/thordin9/apotheke/pull/58))
- **changelog:** docs: add the 2.5.7 PHP rebuild note ([#58](https://github.com/thordin9/apotheke/pull/58))
- docs: add --pull to the plain docker build ([#58](https://github.com/thordin9/apotheke/pull/58))
- **docker:** test: align the PHP pin check with CI ([#58](https://github.com/thordin9/apotheke/pull/58))
- **docker:** ci: print the expected PHP pin on mismatch ([#58](https://github.com/thordin9/apotheke/pull/58))
- **qa:** docs: add the v2.5.7 release QA report ([#58](https://github.com/thordin9/apotheke/pull/58))
- **qa:** docs: add the PR 58 v2.5.8 fix QA report ([#58](https://github.com/thordin9/apotheke/pull/58))

## [2.5.7] - 2026-10-23

Apotheke publishes no image, so rebuild with `docker compose build --pull && docker compose up -d`,
and `docker compose exec apotheke php -v` should then show 8.3.36.

### Fixed

- **docker:** the Docker image now builds on PHP 8.3.36, which fixes the PHP security issues released on 21 Oct 2026. Self-hosters should rebuild with `docker compose build --pull` and restart. ([#57](https://github.com/thordin9/apotheke/pull/57))

### Maintenance

- **changelog:** docs: write the 2.5.6 notes in plain language ([#56](https://github.com/thordin9/apotheke/pull/56))
- **qa:** docs: add the v2.5.6 release QA report ([#56](https://github.com/thordin9/apotheke/pull/56))
- **docker:** ci: assert the image runs the pinned PHP patch ([#57](https://github.com/thordin9/apotheke/pull/57))
- **qa:** docs: add the PR 57 PHP pin QA report ([#57](https://github.com/thordin9/apotheke/pull/57))

## [2.5.6] - 2026-10-22

### Fixed

- **migrate:** bin/migrate.php exits 1 when the database refuses the connection and prints only the driver, host, port, dbname and DB_SCHEMA. The server's error text (it names the database user), the password and the full DSN are not printed or logged. SQLite open errors are unchanged. ([#55](https://github.com/thordin9/apotheke/pull/55))

### Maintenance

- **docker:** the image builds Imagick from a pinned source tarball, falls back to a second pinned tarball from PECL, and fetches both over https only ([#48](https://github.com/thordin9/apotheke/pull/48))
- **qa:** docs: add QA report for #48 imagick PECL fallback ([#48](https://github.com/thordin9/apotheke/pull/48))
- **changelog:** the 2.5.5 notes describe, in plain language, the schema fix, the migrate banner, the QR helper rename and the pinned Imagick build ([#49](https://github.com/thordin9/apotheke/pull/49))
- **config:** tests call the migrate banner in this process, so coverage includes it ([#50](https://github.com/thordin9/apotheke/pull/50))
- **ci:** the secret scan allowlists the migrate DSN test placeholder only in that test file ([#50](https://github.com/thordin9/apotheke/pull/50))
- **qr:** the QR helper comment again says that function JIT is turned off before the encoder loads ([#50](https://github.com/thordin9/apotheke/pull/50))
- **qa:** docs: add the v2.5.5 release QA report ([#50](https://github.com/thordin9/apotheke/pull/50))
- **qa:** docs: add the PR #50 cleanup QA report ([#50](https://github.com/thordin9/apotheke/pull/50))
- ci: add job timeouts and retry the zbar and E2E apt installs ([#54](https://github.com/thordin9/apotheke/pull/54))
- **qa:** docs: add the PR #55 fix(migrate) QA report ([#55](https://github.com/thordin9/apotheke/pull/55))

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

[Unreleased]: https://github.com/thordin9/apotheke/compare/v2.6.9...HEAD
[2.6.9]: https://github.com/thordin9/apotheke/compare/v2.6.8...v2.6.9
[2.6.8]: https://github.com/thordin9/apotheke/compare/v2.6.7...v2.6.8
[2.6.7]: https://github.com/thordin9/apotheke/compare/v2.6.6...v2.6.7
[2.6.6]: https://github.com/thordin9/apotheke/compare/v2.6.5...v2.6.6
[2.6.5]: https://github.com/thordin9/apotheke/compare/v2.6.4...v2.6.5
[2.6.4]: https://github.com/thordin9/apotheke/compare/v2.6.3...v2.6.4
[2.6.3]: https://github.com/thordin9/apotheke/compare/v2.6.2...v2.6.3
[2.6.2]: https://github.com/thordin9/apotheke/compare/v2.6.1...v2.6.2
[2.6.1]: https://github.com/thordin9/apotheke/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/thordin9/apotheke/compare/v2.5.8...v2.6.0
[2.5.8]: https://github.com/thordin9/apotheke/compare/v2.5.7...v2.5.8
[2.5.7]: https://github.com/thordin9/apotheke/compare/v2.5.6...v2.5.7
[2.5.6]: https://github.com/thordin9/apotheke/compare/v2.5.5...v2.5.6
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
