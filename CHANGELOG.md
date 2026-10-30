# Changelog

All notable changes to Apotheke are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Release sections are written by `scripts/release.php` when a pull request is merged to `main`
(see [docs/releasing.md](docs/releasing.md)); the version lives in `VERSION`.
Releases before 2.6.0 are summarised once per minor version; their full notes are in git history.

## [Unreleased]

## [3.1.0] - 2026-10-30

### Added

- **core:** add ApiResponse with problem+json errors ([#166](https://github.com/thordin9/apotheke/pull/166))
- **core:** use the __Host- session cookie on https ([#166](https://github.com/thordin9/apotheke/pull/166))
- **core:** read the client address from the edge or REAL_IP_HEADER ([#166](https://github.com/thordin9/apotheke/pull/166))
- **core:** route /api with the API headers and opt-in CORS ([#166](https://github.com/thordin9/apotheke/pull/166))
- **core:** add ApiCsrf for non-safe /api requests ([#166](https://github.com/thordin9/apotheke/pull/166))
- **auth:** add the /api/v1 session endpoints and GET /me ([#166](https://github.com/thordin9/apotheke/pull/166))

### Maintenance

- **api:** test: add the OpenAPI document and a contract-test harness ([#166](https://github.com/thordin9/apotheke/pull/166))
- docs: describe the /api/v1 session API ([#166](https://github.com/thordin9/apotheke/pull/166))
- **frontend:** chore: design tokens, base styles and brand assets ([#163](https://github.com/thordin9/apotheke/pull/163))
- **api:** docs: keep the OpenAPI contract at docs/api/openapi.yaml ([#166](https://github.com/thordin9/apotheke/pull/166))
- **qa:** docs: add the PR 166 API foundation QA review ([#166](https://github.com/thordin9/apotheke/pull/166))

## [3.0.1] - 2026-10-30

### Fixed

- **release:** a merge titled like a squash commit, `subject (#N)`, now links its commits to PR #N in the release notes ([#164](https://github.com/thordin9/apotheke/pull/164))

### Maintenance

- **ci:** test: pin release.yml to its main-only triggers ([#164](https://github.com/thordin9/apotheke/pull/164))
- **changelog:** docs: note the merge-subject PR links ([#164](https://github.com/thordin9/apotheke/pull/164))
- **golden:** test: mask VERSION in the page goldens ([#164](https://github.com/thordin9/apotheke/pull/164))
- **golden:** test: run CI on the VERSION mask ([#164](https://github.com/thordin9/apotheke/pull/164))
- **qa:** docs: add the PR 164 release squash-links QA review ([#164](https://github.com/thordin9/apotheke/pull/164))

## [3.0.0] - 2026-10-29

**3.0.0 is the CodeIgniter 4 app** (PHP 8.4, Docker only, no schema change, one re-login). Back up first and read
[Upgrading to 3.0](https://github.com/thordin9/apotheke/blob/main/docs/upgrade-3.0.md) for the switch and both rollbacks. For 90 days the 2.x line gets security
fixes only, as 2.6.x releases from the branch `old` (the former `main`, last release v2.6.26). Notes below that name `APOTHEKE_APP=ci4`, `legacy` or a sync of
`main` into `feature/ci4` describe the port before the cutover, on 2.6 images or the former branches.

### Breaking changes

- allow CodeIgniter 4 (AGENTS.md, README)
- serve the CI4 app from the repository root ([#161](https://github.com/thordin9/apotheke/pull/161))

### Added

- **autofill:** add a local storage interface ([#69](https://github.com/thordin9/apotheke/pull/69))
- **autofill:** port the suggest client ([#69](https://github.com/thordin9/apotheke/pull/69))
- **feat!:** Apotheke 3.0 runs on CodeIgniter 4 from the repository root: `app/`, `modules/`, `spark`, one `composer.json` and one `public/`. The legacy app is removed, the bin tools call spark without `APOTHEKE_APP`, the Docker image serves this app only (`APOTHEKE_APP=legacy` exits 64), the legacy tests and CI legs are gone and coverage measures the CI4 suites (artifact `coverage-ci4-sqlite`). Known differences from 2.x are listed in [Upgrading to 3.0](docs/upgrade-3.0.md#known-differences-from-2x). BREAKING CHANGE: the legacy app is removed; PHP 8.4 and the Docker image are the supported install, and everyone signs in again once after the upgrade. ([#161](https://github.com/thordin9/apotheke/pull/161))
- **ci4:** CodeIgniter 4.7.5 skeleton and Core. `/healthz` and `/health` match the legacy responses, and camera originals stay unserved. Photo storage inside the web root stops boot with the same message as the legacy app. A bad DSN stops boot. The page says the database settings could not be used, with no DSN parts. The log line lists only the driver, host, port and database name. The dev server router is `ci4/public/router.php`. ([#38](https://github.com/thordin9/apotheke/pull/38))
- **ci4:** Migrations module. `apotheke:migrate` and `bin/migrate.php` (when `APOTHEKE_APP=ci4`) apply the 27 migrations through a PDO runner. CI4 checks `pg_namespace` before `CREATE SCHEMA`. A missing schema does not return `/healthz` 200. ([#53](https://github.com/thordin9/apotheke/pull/53))
- **ci4:** Settings module. `SettingsSchema` is the single source of the 64 settings keys (defaults and validation); the migration shim's `settings_schema`, `setting_default` and `setting_validate` delegate to it. `SettingsService` (all, get, save, reset a section, export, import up to 256 KiB, the write-only autofill token with its own getter, token cleared when the service address moves) is built per request by `Config\Services`. `/theme.css`, `/labels.css`, `/manifest.webmanifest`, `/favicon.ico` and `/brand/{file}` match the legacy bytes and headers and start no session; brand files sit behind a `BrandStore` interface. `apotheke:settings` and `bin/settings.php` (when `APOTHEKE_APP=ci4`) run `list`, `get`, `set`, `reset`, `export` and `import` with the legacy output and exit codes; the account and sign-in forms stay on legacy until the Auth module. ([#64](https://github.com/thordin9/apotheke/pull/64))
- **ci4:** Autofill client. `Services::autofill()` reads every autofill setting and the token through `SettingsService`. POST `/autofill/suggest`, `/settings/autofill/token`, `/settings/autofill/test` and `/settings/autofill/test-photo` return the legacy JSON (the session CSRF token is required, as on every other CI4 POST). The analysis JPEG is capped to `enforced_image_bytes`, then the configured maximum (`auto` is `/v1/info` `max_image_bytes`, or 983040), then the value as stored. An HTTP 413 refetches `/v1/info` once and retries once. Quality steps down by 10, and 60 is tried before the long edge shrinks. The stored photo is not rewritten. The warn-once pair and the temporary JPEG go through `Storage` (local disk under `DATA_DIR`). The Control Panel page stays on legacy until the settings UI exists. ([#69](https://github.com/thordin9/apotheke/pull/69))
- **ci4:** saving or clearing the AI autofill token from a plain form returns 303. The redirect does not start CodeIgniter's session beside the native one. A token typed into Test with a photo is removed from the HTTP 500 and 503 log lines. Local storage refuses a symlink and replaces a file by writing a temporary file and renaming it. ([#69](https://github.com/thordin9/apotheke/pull/69))
- **ci4:** autofill reads a stored photo through Media: `/autofill/suggest` with `photo_id` analyses the kept original, else the served JPEG, as legacy does, with the bytes going through `Storage`. A missing photo still answers `no_image` without a request. ([#118](https://github.com/thordin9/apotheke/pull/118))
- **ci4:** request and sign-in hardening ported from `main` (SEC-M3, SEC-L2, SEC-N6, SEC-M1, SEC-M2). Unknown host names get 421 without the name echoed (`ALLOWED_HOSTS` and `PUBLIC_URL` add names; `*` turns the check off; `/healthz` included). With sign-in off, cross-site writes get 403. Sessions start only when a page needs one, and a session ends after `session_days` without a request. `TRUSTED_PROXIES` limits which proxies may set `X-Forwarded-For`. Only admins can import sign-in settings; the CLI is unrestricted. The Auth module holds the admin guards and "sign out everywhere" for the sign-in pages. ([#76](https://github.com/thordin9/apotheke/pull/76))
- **ci4:** Layout module (PLAN PR 8, layout part). The page shell, menu, simple header, icons, brand logo and toasts match legacy `page_start()`, `page_end()` and `menu_html()` byte for byte, with every value escaped and no inline script or style, so the CSP is unchanged. POST `/theme` sets the Auto / Light / Dark cookie and returns to a same-site path, or answers `{ok, theme}` to a fetch. Error answers that legacy draws as a page (404, the Permission filter's 403, the CSRF 419, 413, the same-origin 403 and 500) use legacy's error page; when the page cannot be drawn it falls back to legacy's plain page. The bundled assets are served from `ci4/public/assets`, a copy kept equal to `public/assets` by a test. ([#94](https://github.com/thordin9/apotheke/pull/94))
- **ci4:** Media module (PLAN step 10). Uploads keep a byte-identical original under `ORIGINALS_DIR` and a stripped served copy and thumbnail in `main`'s layout, with the same bytes as legacy. A HEIC that cannot be converted keeps its original and shows a placeholder. `main`'s decode limits apply before any decode, and a failed upload leaves no files. GET `/media/{full|thumb}/{name}` serves only served names, with legacy's cache headers. ([#95](https://github.com/thordin9/apotheke/pull/95))
- **ci4:** Catalog, Search and Favourites modules (PLAN step 9). The list and search (`/`), the overview (`/stats`), saved filters (`/filters`), the star and clearing recently viewed answer byte-identical to legacy on SQLite and PostgreSQL, with every write checked (M-25). Driver error row details stay out of the logs, and a stored secret is not sealed inside an open transaction (QA N-33 to N-36). ([#99](https://github.com/thordin9/apotheke/pull/99))
- **ci4:** item photos (PLAN step 11a, part 2). Adding photos, cover, left/right, delete, the drag order and crop and rotate answer as on legacy and leave the same files: served copy, thumbnail and the original, which an edited photo copies. A failed save or photo add now keeps no stored photo and no new location or category. Two crop/rotate saves of one photo at once keep its original, where legacy can lose it, and photos added at once stay within the photo limit. ([#103](https://github.com/thordin9/apotheke/pull/103))
- **ci4:** item pages (PLAN step 11a, first part). The item page (`/item/{id}`), the add form (`/new`), the edit form (`/item/{id}/edit`), saving (with one photo upload through Media and `MAX_FILE_SIZE` in the form), delete, undo (`/undo` and the undo bar) and the activity page (`/activity`) answer byte-identical to legacy on SQLite and PostgreSQL, with every write checked. Notes are shown and edited through the Notes renderer, and opening an item records it under Recently viewed. ([#102](https://github.com/thordin9/apotheke/pull/102))
- **ci4:** Taxonomy module (PLAN step 8, first part). `/locations` and `/categories` (list, add with an optional parent, rename, move, default, delete with its confirmation page, and the fetch answers) match legacy byte for byte on SQLite and PostgreSQL, on the Layout shell, with View for GET and Edit for POST on every route and the session CSRF token. Deleting writes the same item history rows, through the new Activity module's writer. Tag storage (`TagRepository`) is in place for Catalog. A failed database statement rolls the change back and answers 500, as on legacy, also with DBDebug off (`Core\Database\Writes`). CodeIgniter's router normalises slashes, so a path with trailing slashes or a doubled leading slash (`/locations/`, `//locations`, `//locations/9/rename`) is served as the route, with the same permission, where legacy answers 404 or shows the home page; and a POST without a CSRF token gets 419 before an unknown entry's 404. ([#96](https://github.com/thordin9/apotheke/pull/96))
- **ci4:** Notes module (PLAN step 8, second part). `MarkdownRenderer` renders item notes with legacy's safe Markdown subset (headings, lists, quotes, code, emphasis, http/https/mailto links, the 20000-character cap) and matches `md_render()` and `md_plain()` byte for byte on a golden corpus and seeded random input. Nothing from the input becomes live HTML. ([#98](https://github.com/thordin9/apotheke/pull/98))
- **ci4:** sign-in pages (PLAN step 7, first part). `/login`, `/register`, `/logout`, `/account` (a new password) and `/account/signout` (sign out everywhere) match legacy byte for byte on SQLite and PostgreSQL, on the Layout shell, with legacy's sign-in and registration rate limits (same table, keys, windows and answers), the first admin from `APP_PASSWORD`, a new session ID on sign-in and sign-out, and `keepSignIn` for the session that changed its own password or signed out everywhere. A failed sign-in (an unknown user name or a wrong password) answers at a fixed deadline from the start of the attempt, whatever the stored password hash (1 s by default, `Config\SignIn`; legacy waits 400 ms after the check). An unknown name is checked against a fixed hash at PHP's default cost, and a password containing a null character never signs in and is refused as a new password (422). `UserRepository::find()` hides only database errors. The users and roles pages follow. ([#100](https://github.com/thordin9/apotheke/pull/100))
- **ci4:** users and roles pages (PLAN step 7, second part). Control Panel → Users & sign-in (`/settings/security`), its settings form and reset, sign-in on or off and your own password (`/settings/password`), accounts (`/settings/users…`: add, role, password, rename, approve, delete) and roles (`/settings/roles…`: add, edit, delete with the accounts moved) match legacy byte for byte on SQLite and PostgreSQL. Every route needs the Users permission and the CSRF token. Only an admin changes an admin account or grants admin, the last active admin stays, a role change that would leave nobody able to manage users is refused, and a new password signs the account's other sessions out. Writes go through `Core\Database\Writes`. A password with a null character is refused with 422 (legacy answered 500). ([#104](https://github.com/thordin9/apotheke/pull/104))
- **ci4:** CLI account forms (PLAN step 7, second part). With `APOTHEKE_APP=ci4`, `php bin/settings.php users`, `user add|password|role|approve|rename|delete|signout`, `roles [reset]`, `auth on|off` and `password [PASSWORD|--off]` run as `spark apotheke:accounts` with legacy's output, prompts, exit codes and stored state on SQLite and PostgreSQL, through the same account, role and sign-in services as the pages. The first admin comes from `APP_PASSWORD` where legacy creates it. `auth status` keeps running in `bin/settings.php`, because it must not migrate. A typed password is not shown on a terminal (the terminal settings come back afterwards, also after an error or Ctrl-C, which exits 130) and never reaches the output. A failed write exits 1 with a short message and changes nothing (legacy: exit 255); a password with a null character is refused with exit 1, as on legacy since #106. ([#105](https://github.com/thordin9/apotheke/pull/105))
- **ci4:** bulk actions, merge, duplicate, quantity and attachments (PLAN step 11b). POST `/item/{id}/qty`, `/item/{id}/duplicate` (with copies of the photos and their originals), GET and POST `/item/{id}/merge`, POST `/bulk` (move, category, add or remove a tag, delete with its confirmation page and Undo, labels) and the attachment routes (`/item/{id}/attachments`, its delete, GET `/attachment/{id}` as a sandboxed download) answer as legacy does, with the same tables and files, on SQLite and PostgreSQL. Each bulk action also needs its own permission (move, delete, labels or edit). Writes go through `Core\Database\Writes`; a failed write changes no table and leaves no new file. New module `BulkMerge`. ([#108](https://github.com/thordin9/apotheke/pull/108))
- **ci4:** QR labels, barcode lookup and lending (PLAN step 13, first part). `/labels` (all locations, one location with its sub-locations or its items, or the list's selection), `/item/{id}/label`, `/barcode`, `/barcode/{code}`, lending and returning an item and `/lent` answer as legacy does on SQLite and PostgreSQL. The QR codes are the same SVG as legacy's, every name in them is escaped, and the encoder loads only after function JIT is turned off. A failed lend or return changes nothing and answers 500. ([#114](https://github.com/thordin9/apotheke/pull/114))
- **ci4:** stocktakes (PLAN step 13, second part). Starting a stocktake, ticking or scanning items, the report, finishing (with or without moving misplaced items) and cancelling answer as legacy does on SQLite and PostgreSQL, fetch answers included. Every name is escaped; a failed step changes nothing and answers 500. ([#115](https://github.com/thordin9/apotheke/pull/115))
- **ci4:** CSV import and export (PLAN step 14, first part). `/export.csv`, the upload, column mapping, dry-run preview and import answer as legacy does on SQLite and PostgreSQL, with the same rows and the same waiting upload. A run locks the import first, so on SQLite it waits for another write instead of failing, and a second submit of the same import imports nothing and shows the done page. A failed step changes nothing. ([#127](https://github.com/thordin9/apotheke/pull/127))
- **ci4:** backup and restore (PLAN step 14, second part). The Backup & restore page, the download (`/backup.zip`), the dry-run upload, the confirmed restore and `php bin/backup.php` / `php bin/restore.php` (when `APOTHEKE_APP=ci4`) answer as legacy does on SQLite and PostgreSQL, with the same zip entries, tables, photos, attachments and originals, and the `backup` permission on every route. A backup from either app restores on the other, and pre-originals backups still restore. `backup.json` is written row by row, so memory no longer grows with the catalog. A restore takes the write lock before it deletes, so a write at the same time waits and is then replaced. Where CI4 is stricter (a zip with two entries of one name is refused; a failed restore shows a general message; a missing thumbnail is drawn again only within the image decode limits; an unwritable CLI output path exits 1 with a message), see docs/HANDOFF.md. ([#133](https://github.com/thordin9/apotheke/pull/133))
- **ci4:** Docker image for CI4 (PLAN step 15). The shipped image moves from PHP 8.3.36 to PHP 8.4.27 for both apps; on it, legacy's `DB_DSN` credential precedence follows the PHP 8.4 rule (see `db.password` in the configuration docs). The image also holds CI4 with its Composer packages (no dev packages) and the `intl` and `pgsql` extensions; `APOTHEKE_APP=ci4` at run time serves `ci4/public` (the entrypoint refuses a build older than PHP 8.4, and any other `APOTHEKE_APP` value). CI4 gets the same start-up hardening as legacy (#75: compose publishes on localhost only, no start without `APP_PASSWORD` or sign-in on, unless `ALLOW_NO_LOGIN=1`), and the first CI4 start removes the legacy session files once. CI4 reads its failed-password deadline from `SIGN_IN_FAIL_MS` like legacy (400–10000 ms, default 1000; L-400) instead of `signin.failDeadlineMs`. Hardening (L-430): the entrypoint refuses to start when a data directory is a symbolic link, `chown` no longer follows links, the CI4 session marker is written as `www-data`, and nginx keeps its temp files under `/run` instead of `/tmp`. CI also covers an unknown `APOTHEKE_APP`, the legacy-only session handling and CI4's former deadline names (N-432). CI checks the CI4 image on SQLite and PostgreSQL. ([#132](https://github.com/thordin9/apotheke/pull/132))
- **ci4:** parity gate (PLAN step 16, part 1). `scripts/parity-gate.php` runs one scenario over every ported area (all but backup and restore) on a legacy and a CI4 server on PHP 8.4 and compares each answer, every table and the data directory; CI runs it on SQLite and PostgreSQL and fails on any difference, and prints the p95 per area; `PARITY_P95_MAX_PCT` and `PARITY_P95_MAX_ABS_MS` fail the run when CI4's p95 is too far above legacy's (CI: within legacy + 8 ms on SQLite and + 20 ms on PostgreSQL, a temporary budget tightened when the boot-cost step lands; compared on the median gap, `PARITY_BUDGET_STAT=median`, since the p95 gap failed on a slow runner). Each answer's Cache-Control, Pragma, Expires, every Set-Cookie with its attributes and the security headers are compared, and the users the app created sign in with their stored passwords. Fixes it found: the autofill JSON answers (`/autofill/suggest`, the settings test and token routes) send `Cache-Control: no-store` alone, as legacy, instead of `no-store, max-age=0, no-cache`; the attachments directory is created with legacy's mode (`0770` before the umask, was `0700`); on PostgreSQL the undo snapshot stores integers, floats and booleans typed as legacy does, not as strings; the item form shown again after a failed save (422) sends legacy's `Cache-Control: no-store, no-cache, must-revalidate` instead of `no-store`. ([#134](https://github.com/thordin9/apotheke/pull/134))
- **ci4:** Control Panel. `/settings` and every section (General, Appearance with the logo upload, Locale, List, New item defaults with the default location and category, Limits, QR labels, Uploads, AI autofill with its access token and Test buttons, Backup & data with the settings export and import, System) answer as legacy does on SQLite and PostgreSQL, with the `settings` permission on every route and the CSRF check after it. The autofill token stays write-only. The parity gate covers the pages, and the Control Panel and AI autofill end-to-end specs run on CI4. Users & sign-in was already ported. ([#151](https://github.com/thordin9/apotheke/pull/151))
- **ci4:** `bin/seed.php [--demo]`, `bin/settings.php auth status` and `bin/settings.php key rotate` run as `spark apotheke:seed`, `spark apotheke:accounts auth status` and `spark apotheke:settings key rotate` when `APOTHEKE_APP=ci4` is set, with legacy's output, exit codes, rows and photo bytes; the Docker sign-in check follows. `auth status` opens its own connection and never migrates. Only `key generate` stays in `bin/settings.php` (L-680). ([#159](https://github.com/thordin9/apotheke/pull/159))

### Fixed

- check links in AGENTS.md, ADRs and plans ([#31](https://github.com/thordin9/apotheke/pull/31))
- **php84:** keep QR labels working under JIT 1235 ([#34](https://github.com/thordin9/apotheke/pull/34))
- **php84:** detect function JIT from the CRTO trigger ([#34](https://github.com/thordin9/apotheke/pull/34))
- **autofill:** redirect token forms and redact typed tokens ([#69](https://github.com/thordin9/apotheke/pull/69))
- **storage:** refuse symlinks and write files atomically ([#69](https://github.com/thordin9/apotheke/pull/69))
- **core:** mark the SecretBox key and plain value as sensitive ([#161](https://github.com/thordin9/apotheke/pull/161))
- **ci:** keep the gitleaks allowlist on its historical path ([#161](https://github.com/thordin9/apotheke/pull/161))
- **ci4:** a restore that fails leaves the files as they were: photos, thumbnails, attachments and camera originals it wrote are removed again, and photos are linked to the backup's camera originals only after the catalog rows are saved. A damaged entry in the zip, a file that cannot be written and a database error all answer "The backup could not be restored. Nothing was changed." (QA L-440). ([#133](https://github.com/thordin9/apotheke/pull/133))
- **ci4:** a backup whose `backup.json` would not fit in PHP's memory limit is refused before it is read or decoded, on the web (422) and in `bin/restore.php` (exit 1), with "This backup is too large to restore within the memory limit. Nothing was changed." instead of a server error (QA L-441). ([#133](https://github.com/thordin9/apotheke/pull/133))
- **ci4:** one restore runs at a time, on the web and in `bin/restore.php`: a restore started while another is running is refused at once with "Another restore is running. Try again when it has finished." (409 on the page, where the upload can be confirmed again; exit 1 on the command line), so two restores no longer work on the same files (QA L-450). The lock file works whichever user made it (a root `bin/restore.php` no longer blocks later web restores, L-455), and a lock file that is a symbolic link is refused (N-456). ([#133](https://github.com/thordin9/apotheke/pull/133))
- **ci4:** restore is streamed: `backup.json` is read a value at a time and every other zip entry is copied to a temporary file and checked before it is stored, so memory no longer grows with the backup or with one large value; only a single value larger than the memory left is refused (QA L-441, L-451). A zip entry larger than 200 MiB is refused from the zip's directory, before anything is read or written (N-442, L-480). On PostgreSQL a restore that loses a deadlock to another write is tried again up to three times, then refused with a message, and nothing is changed (N-445). A failed restore also removes the folders it had to create (N-453), and a full disk leaves no partial temporary file behind (N-452). After a successful restore a linked camera original is made 0640, as legacy does since [2.6.23] ([#135](https://github.com/thordin9/apotheke/pull/135)). ([#136](https://github.com/thordin9/apotheke/pull/136))
- **ci4 (hardening):** restore checks tightened. A backup's settings are restored only for a user who also has the settings permission; otherwise the restore says they were skipped (`bin/restore.php` restores them, as before; N-443). `true` and `false` are refused as table values (N-448). The restore lock is created without following a link and checked again after it is opened; it must be a plain file, with a message that names it (N-458, N-459); a link at a restored file's path stops the restore and is left untouched (N-481). `bin/backup.php` into a missing folder prints `Cannot write …`, and the zip is readable by its owner only (N-446). The name, photo, thumbnail and confirm-token checks have tests (N-444). ([#136](https://github.com/thordin9/apotheke/pull/136))
- **ci4:** a restore into a fresh install makes `files/` with legacy's mode (0770 before the umask, so 0750 under the usual 022; it was 0700). The other folders a restore needs already match legacy. The camera link map is written through a temp file in its folder; a link at a map name is left as it is and the other map entries are still written (before, the first such link ended the rest). A link at a camera file's name, dangling or not, where a new camera file would go or at an existing one, stops the restore before anything is changed (422 on the page, exit 1 on the command line, as on legacy), and after the rows are in a camera file's mode is set only while it is a plain file (L-560). The restore lock's name is looked up afresh right before it is opened (N-562). Backup and storage writes also check that the file was flushed and closed, so a write the close reports late fails and leaves no temp file. A file that storage replaces keeps its mode where no fixed mode applies (L-590). A restore stopped by a fatal error before the rows are in removes the files it wrote, as legacy does. ([#142](https://github.com/thordin9/apotheke/pull/142))
- **ci4 (hardening):** `php spark apotheke:backup`, `apotheke:migrate`, `apotheke:settings` and `apotheke:accounts` started as root also run as the owner of `DATA_DIR` before CodeIgniter starts, or stop with exit 1 and name their own tool, as legacy's CLI tools do (QA L-622). When `DATA_DIR`'s group is root's, the owner's own group is used; a switch that would keep root's group stops instead (QA N-621). ([#149](https://github.com/thordin9/apotheke/pull/149))
- **ci4:** the restore lock is made in place where the file system has no hard links (QA N-520); a lock name that is gone or changed before the open is refused with that reason (QA N-521), and the name is looked up afresh before the open (QA N-581). The temp copies a restore makes in `tmp/` are removed after a fatal error, also while the backup is read, and so is a file it wrote just before the error (QA N-611). Camera files are written 0640, and only one kept with another mode is changed (QA N-582). ([#149](https://github.com/thordin9/apotheke/pull/149))
- **ci4:** `php spark apotheke:restore` and `bin/restore.php` print the warnings the restore itself added (camera files not linked, settings not restored) after `Restored:` (QA N-593). A confirmed restore that fails, other than a busy lock (409), removes its upload at once instead of at the hourly sweep, and an upload copy that stopped part-way is removed (QA N-567). Tests cover a link to a real file at a new photo, thumbnail or attachment name (QA N-565) and a camera map that cannot be written (QA N-592, N-563). ([#149](https://github.com/thordin9/apotheke/pull/149))
- **ci4 (hardening):** every `php ci4/spark` command started as root, the framework's own included, runs as the owner of `DATA_DIR` before CodeIgniter starts, or stops with exit 1 (QA L-700). With `APOTHEKE_APP=ci4`, `bin/backup.php`, `bin/migrate.php` and the `bin/settings.php` forms check the user themselves before they start spark, and a user that cannot be told counts as root (QA N-625). A PHP build without a posix function the switch needs, `posix_initgroups` and `posix_getgroups` included, stops with the usual message and exit 1 instead of a PHP error (QA N-681, N-702, N-781). The Control Panel's settings import reads an uploaded file only up to its size cap (QA L-740). ([#157](https://github.com/thordin9/apotheke/pull/157))
- **ci4 (hardening):** `bin/restore.php` started as root runs as the owner of `DATA_DIR` (and its group) before it does anything else, so every file a restore writes belongs to the data-dir owner and the web user can read a replaced camera map entry. When `DATA_DIR` is owned by root, or the switch is not possible, it stops with exit 1 and asks to run it as the data-dir owner (`sudo -u <owner> php bin/restore.php backup.zip`). `php spark apotheke:restore` does the same, also before CodeIgniter starts, so nothing at start-up runs as root (M-620); other `spark` commands are not affected. Storage sets a replaced file's mode as the file is created (L-610). ([#146](https://github.com/thordin9/apotheke/pull/146))
- **ci4:** `/login`, `/register` and `/account` register each web method instead of using `add()`, which also registered them as command-line routes; they answer the same methods as before (QA N-380). ([#147](https://github.com/thordin9/apotheke/pull/147))
- **ci4:** `/import` and `/import/{id}` with `/preview` and `/commit` answer OPTIONS, TRACE and CONNECT as legacy does, instead of 404: `/import` shows its page, and an import redirects when done and otherwise answers 404. CONNECT needs the CSRF token, as every non-safe method does on CI4 (QA N-420). ([#147](https://github.com/thordin9/apotheke/pull/147))
- **ci4 (hardening):** on PostgreSQL, the export's read-only transaction sets `statement_timeout` (60 s) and `idle_in_transaction_session_timeout` (5 min) for itself only, so a download that stops being read cannot hold it open indefinitely (QA N-421). ([#147](https://github.com/thordin9/apotheke/pull/147))
- **test:** the export tests check that each batch of 500 items reads only its own items' tags (QA N-422); the `titles()` docblock in `tests/lib.php` sits on its function (QA N-381); `Ci4ImportRaceTest` runs the double submit on legacy too, and the CSV parity tests pass against this tree's legacy on SQLite and PostgreSQL (QA N-424). ([#147](https://github.com/thordin9/apotheke/pull/147))
- **ci4:** autofill for a stored photo sends a camera photo turned upright, as legacy does, also when PHP's `allow_url_fopen` is off; the EXIF orientation is read from memory instead of a `data:` URI (QA L-270). ([#119](https://github.com/thordin9/apotheke/pull/119))
- **ci4:** a request path with a trailing slash, a dot segment (`/stats/`, `/./stats`, `/%2e/stats`, `/a/../stats`), surrounding spaces or a `+` answers 404, as on legacy, instead of the page it trimmed to; `//stats` is the list, as on legacy. CI4 routes legacy's exact decoded path (N-51). A log line's database `DETAIL:` is removed in any case and up to the driver's last line (N-53). ([#101](https://github.com/thordin9/apotheke/pull/101))
- **ci4:** POST `/theme` with a control character (NUL, `\x01`, DEL) in `back` redirects to `/` (303) instead of failing with a 500; legacy redirects to the raw path, or for NUL answers an empty 200. The 419, 413 and same-origin refusals are tested to send `Cache-Control: no-store`, on the JSON answers and the HTML pages (N-18, N-19). ([#96](https://github.com/thordin9/apotheke/pull/96))
- **ci4:** a commit the database refuses (a deferred constraint) now always fails the write with the step named and keeps nothing, whether the driver returns false or raises its own error. `Writes::transaction()` documents that nesting is flat, as in legacy `db_tx()` (N-32). ([#98](https://github.com/thordin9/apotheke/pull/98))
- **ci4:** the 419, 413 and same-origin 403 JSON answers send `application/json; charset=utf-8` and `Cache-Control: no-store`, as legacy `json_out()` does; a fetch that hits a server error or a `UserError` gets legacy's `{ok:false,error}` JSON. A double-encoded branding path is decoded once, as legacy decodes it (#93 N-16). The CI4 module tests no longer read the runner's own `APP_KEY`, `APP_KEY_FILE` or `APOTHEKE_SODIUM` (#93 N-17). ([#94](https://github.com/thordin9/apotheke/pull/94))
- **ci4:** a branding or health path with a trailing slash (for example `/theme.css/`) needs the permission legacy gives it (View); only the exact path is answered before the check, as on legacy. A redirect route gets legacy `route_perm()` for its path, like a path without a route (#91 N-15). ([#93](https://github.com/thordin9/apotheke/pull/93))
- **ci4:** a request path with characters CodeIgniter's router does not accept (control characters, `;`) gets a 400 "Bad request" with the security headers instead of a 500, and is not logged as a server error. The CI4 dev server serves no static file for a path with a NUL byte (#91 N-15). ([#93](https://github.com/thordin9/apotheke/pull/93))
- **ci4:** the built-in roles stand in only when the `roles` table cannot be read (before migration 0022, or no database connection), as legacy does; any other error is no longer treated as a missing table (#91 N-14). ([#93](https://github.com/thordin9/apotheke/pull/93))
- **ci4:** CI4 applies the Permission filter to its routes (PLAN PR 7). A required filter runs legacy's per-route permission check on every request, routed or not, before the CSRF check. It derives the permission from the routed path: each route records legacy's permission, a route without one is refused to everyone, and a path no route matches gets legacy's rule for the path CI4's router tried (decoded, trimmed, relative to the base URL). Permissions come from the role in the `roles` table, `admin` has all of them, an unknown role has none, the anonymous guest counts as not signed in, and with sign-in off everything but the own-account pages is allowed. `/autofill/suggest` needs **Add items** and `/settings/autofill/*` needs **Settings**, as in legacy. Refusals match legacy: 401 JSON (`Not signed in`) or a 303 to `/login` before sign-in (a bare 401 under `/media/`), and 403 with the same message for a role without the permission. Until the Layout module, the HTML 403 page is legacy's plain error page without the app layout. ([#91](https://github.com/thordin9/apotheke/pull/91))
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
- **ci4:** sign-in limits match legacy (#106): an attempt is recorded before the window is counted, so requests that arrive together see each other, and sign-ins from one address have a cap of 30 per 15 minutes for every name, kept in the same `auth_attempts` rows as legacy. ([#109](https://github.com/thordin9/apotheke/pull/109))
- **ci4:** sign-in limits match legacy 2.6.16 and 2.6.17 (#111, #112): per-address limits count an IPv6 client by its /64 (addresses that carry an IPv4 address stay whole), a successful sign-in takes back only its own per-address attempt, and the admin password that turns sign-in back on is limited like a sign-in. A name with a null character inside never matches an account, and a cover replaced inside a larger save keeps its file until that save commits. ([#113](https://github.com/thordin9/apotheke/pull/113))
- **ci4:** account hardening matches legacy (#106): the current password on Your account and on Users & sign-in counts as a sign-in try for that account and against the per-address cap of 30 per 15 minutes (429 at either limit), and a right one takes back only its own per-address try (L-170); changing the role of or deleting an admin checks and writes under a lock on the active admins, also from `bin/settings.php`, so one active admin always stays when two changes arrive together (L-110); role edits and deletes take that lock first, so role edits sent together all go through on SQLite too; an `APP_PASSWORD` with a null character stops the first start; and the CLI password prompt restores the terminal on SIGTERM too, exiting 143 (N-133). ([#110](https://github.com/thordin9/apotheke/pull/110))
- **ci4:** a sign-in name with a null character anywhere, also at the start or end, is refused like a wrong password on the sign-in form (401) and on the form that turns sign-in back on (403, no account created), after both sign-in limits have counted it, as on legacy 2.6.18 (#116). ([#117](https://github.com/thordin9/apotheke/pull/117))
- **ci4:** registering, adding a user and renaming one refuse a user name with a null character anywhere, also at the start or end, with the invalid-name message (422) instead of trimming it into a shorter name. Legacy does the same since 2.6.19 (#121). ([#120](https://github.com/thordin9/apotheke/pull/120))
- **ci4:** hardening, as on legacy 2.6.20 (#122): turning sign-in back on always spends one password check, whatever the name. Every refusal there, also of a right password for an account that is not an admin, and a wrong current password answer at the same deadline as a failed sign-in, whatever the cost of the stored hash. ([#124](https://github.com/thordin9/apotheke/pull/124))
- **ci4:** lending and stocktakes under concurrent requests (PLAN step 13 follow-ups). A lend or return locks the item first, so on SQLite it waits for another write instead of failing, and a return that waited for another one logs nothing twice. Finishing or cancelling a stocktake closes it only while it is still open, so a second finish or cancel answers 409 and changes nothing. Control characters are removed from the borrower's name. ([#123](https://github.com/thordin9/apotheke/pull/123))
- **ci4:** `/login`, `/register` and `/account` show their page for PUT, PATCH and DELETE as legacy does, instead of 404; on `/account` these methods still need the CSRF token, as every non-safe method does on CI4. ([#125](https://github.com/thordin9/apotheke/pull/125))
- **ci4:** a stocktake tick changes lines only while the stocktake is still open: one that meets a finish or cancel waits for it and answers 409, and on SQLite it waits for another write instead of failing. `/login`, `/register` and `/account` treat a POST as a POST whatever its `_method` field, as legacy does, and also answer OPTIONS, TRACE and CONNECT with their page. ([#128](https://github.com/thordin9/apotheke/pull/128))
- **ci4:** GET `/export.csv` streams the file as legacy does: items are read in batches of 500 (from a cursor in a read-only snapshot on PostgreSQL), each batch reads only its own tags, and every line goes out as it is written, so memory no longer grows with the number of items. At 200,000 items the export used about 360 MB (SQLite) or 490 MB (PostgreSQL) and failed at the 256 MB limit; it now stays near 3 MB (QA L-370). ([#131](https://github.com/thordin9/apotheke/pull/131))
- **ci4:** the export tests cover cells that start with a TAB or a carriage return, so the formula guard for them is checked (QA N-375). ([#131](https://github.com/thordin9/apotheke/pull/131))
- **ci4:** the import pages check the CSRF token first, as every CI4 POST does. A POST without the token to an import that is already done answers 419 where legacy redirects to the done page (303), and a POST without the token to `/import/{id}` answers 419 where legacy answers 404. Stricter; no change (QA N-371). ([#131](https://github.com/thordin9/apotheke/pull/131))
- **ci4:** `/import` shows its page for PUT, PATCH and DELETE as legacy does, instead of 404, and `/import/{id}` with these methods redirects a done import and otherwise answers 404, as on legacy. They need the `import` permission and the CSRF token, as every non-safe method does on CI4 (QA N-372). ([#131](https://github.com/thordin9/apotheke/pull/131))
- **ci4:** a preview sent while a submit of the same import is still running waits for it and then shows the done page (303 to `/import?done=…`), as a later submit does, instead of 410 "The uploaded file is gone". Legacy still answers 410 here (QA N-373). ([#131](https://github.com/thordin9/apotheke/pull/131))
- **ci4:** an upload left waiting for more than a day loses its stored file in `DATA_DIR/tmp` on the next upload or visit to the import page; only files of imports still waiting are removed, and the row stays in the history (opening it asks for the file again, 410). Legacy keeps these files (QA N-374). ([#131](https://github.com/thordin9/apotheke/pull/131))
- **ci4:** boot cost (QA #134 L-472). A request logs in to the database once, as legacy does: the migration check reads `schema_migrations` on CI4's own connection, and the migration runner opens its own connection only when a migration is missing. The `DB_DSN` credential warning and the sanitized connect-refusal line come from CI4's connect. On PostgreSQL the check reads `pg_class` instead of `information_schema`, and the schema is selected in two statements instead of three. Boot no longer builds CodeIgniter's user-agent lists, discovers module routes, filters, events or registrars, or reads CodeIgniter's dotted environment overrides (`app.*`, `database.*`, …; Apotheke's settings are unchanged). The parity gate no longer times the test harness's PostgreSQL probe. A SQLite file that cannot be opened gives only the sanitized line, as on PostgreSQL, and no longer a CodeIgniter log entry with a stack trace on every request (QA #137 L-500). PostgreSQL now meets the +20 % p95 bar locally; SQLite does not yet. ([#137](https://github.com/thordin9/apotheke/pull/137))
- **ci4:** step 16 part 3: CI4 loads its routes from a compiled file (`app/Config/RoutesCompiled.php`, written by `php ci4/spark apotheke:routes`) instead of building them from `app/Config/Routes.php` on every request; it falls back to `Routes.php` when that file has changed since compiling. CI4's cache handler is the dummy one (Apotheke caches nothing through CodeIgniter), and the SQLite `synchronous` pragma is set once. The parity gate now enforces the step 16 bar (decision by Stathis, 2026-10-27, via the PM): PostgreSQL p95 within +20 % of legacy, SQLite median within + 3 ms. The layout menu reads the signed-in user once instead of once per permission it checks (up to 14 fewer queries on every page with the menu). The parity gate times at least 200 requests per area and app, and alternates which app is asked first, so one slow request no longer decides a p95 that had only 15 samples. The upgrade and rollback gate also requires `files/` in legacy's mode (0750 under the usual umask). The upgrade and rollback mutation checks must name the table or file they changed (QA N-546). ([#150](https://github.com/thordin9/apotheke/pull/150))
- **ci4:** with `APOTHEKE_APP=ci4`, `bin/backup.php` and `bin/restore.php` print the same one-line message as legacy and exit 1 when the database cannot be reached, instead of the exception class and "Server error". ([#158](https://github.com/thordin9/apotheke/pull/158))
- **ci4 (hardening):** CI4 `migrate`, `settings` and the account forms refuse a short `APP_PASSWORD` exactly as legacy: same minimum length, message and exit code, and nothing is stored. Before, CI4 `migrate` could create the account `admin` with it. One check, `Accounts::appPasswordProblem()`, serves the first account, the migrations and the settings forms, and never puts the value in a message or log. ([#159](https://github.com/thordin9/apotheke/pull/159))
- **ci4:** `bin/settings.php list` creates the account `admin` from `APP_PASSWORD` when sign-in is on and there is no account yet, as legacy does (it was settings CLI deviation 4). ([#159](https://github.com/thordin9/apotheke/pull/159))
- **ci4:** with `APOTHEKE_APP=ci4`, a refused connection with a user or password in `DB_DSN` that differs from `DB_USER` / `DB_PASSWORD` prints only legacy's one-line message from `bin/backup.php`, `bin/restore.php` and `bin/migrate.php`; the differing-credentials notice is logged once connected (QA N-900). ([#160](https://github.com/thordin9/apotheke/pull/160))
- **ci4:** `spark apotheke:seed` started as root names `php bin/seed.php` in its message, like the other data commands. ([#160](https://github.com/thordin9/apotheke/pull/160))

### Performance

- **ci4:** check the schema on the request's own connection ([#137](https://github.com/thordin9/apotheke/pull/137))
- **ci4:** read the boot schema checks from the pg catalog ([#137](https://github.com/thordin9/apotheke/pull/137))
- **ci4:** skip user-agent tables and module discovery at boot ([#137](https://github.com/thordin9/apotheke/pull/137))
- **ci4:** stop CI4 configs reading dotted env overrides ([#137](https://github.com/thordin9/apotheke/pull/137))
- **ci4:** load the routes from a compiled file ([#150](https://github.com/thordin9/apotheke/pull/150))
- **ci4:** dummy cache handler and one synchronous pragma ([#150](https://github.com/thordin9/apotheke/pull/150))
- **ci4:** read the user once for the layout menu's permission checks ([#150](https://github.com/thordin9/apotheke/pull/150))

### Changed

- **ci4:** allow shared services in filters ([#74](https://github.com/thordin9/apotheke/pull/74))
- **ci4:** allow filter services only in before() and after() ([#74](https://github.com/thordin9/apotheke/pull/74))
- **ci4:** pin main's admin rule for the anonymous guest ([#82](https://github.com/thordin9/apotheke/pull/82))
- **ci4:** APP_KEY_FILE roots reuse the config's originals path ([#93](https://github.com/thordin9/apotheke/pull/93))
- **ci4:** undo unfinished uploads through a request cleanup list ([#95](https://github.com/thordin9/apotheke/pull/95))
- **ci4:** log a failing request cleanup step (N-30, N-31) ([#97](https://github.com/thordin9/apotheke/pull/97))
- **ci4:** settings saves through Core Writes (M-25) ([#97](https://github.com/thordin9/apotheke/pull/97))
- **ci4:** keep driver row detail out of the logs (N-33, N-36) ([#99](https://github.com/thordin9/apotheke/pull/99))
- **ci4:** skip the seal in a transaction, log failures (N-34, N-35) ([#99](https://github.com/thordin9/apotheke/pull/99))
- **ci4:** probe the search engine before the rebuild (M-50) ([#99](https://github.com/thordin9/apotheke/pull/99))
- **ci4:** store a saved photo before the save's transaction (M-91) ([#103](https://github.com/thordin9/apotheke/pull/103))
- **ci4:** pace every refused turn-on check (L-330) ([#124](https://github.com/thordin9/apotheke/pull/124))
- **ci4:** drop the export prime and the 410 re-read (L-370) ([#131](https://github.com/thordin9/apotheke/pull/131))
- **ci4:** open the SQLite file without CI4's stack-trace log ([#137](https://github.com/thordin9/apotheke/pull/137))
- **ci4:** tidy parity docblock and HANDOFF next step ([#139](https://github.com/thordin9/apotheke/pull/139))
- **ci4:** route every-method pages without Services (N-380) ([#147](https://github.com/thordin9/apotheke/pull/147))
- move the CI4 tree to the repository root ([#161](https://github.com/thordin9/apotheke/pull/161))
- point paths at the root layout ([#161](https://github.com/thordin9/apotheke/pull/161))
- **bin:** run the data tools through spark ([#161](https://github.com/thordin9/apotheke/pull/161))
- **core:** move the config loader into the Core module ([#161](https://github.com/thordin9/apotheke/pull/161))
- remove src/ and the files that still used it ([#161](https://github.com/thordin9/apotheke/pull/161))

### Maintenance

- docs: add Feature 12 CI4 plan verbatim
- docs: apply reviewer fixes to the CI4 plan
- **qa:** docs: add CI4 PR 1 QA report
- docs: qualify legacy docs and CI4 constraints
- docs: fix CI4 plan status and doc paths
- **qa:** docs: add CI4 PR 1 recheck report
- docs: use CI4-leg wording and one offline rule ([#31](https://github.com/thordin9/apotheke/pull/31))
- **plan:** docs: note early legacy 8.4 runs in PR 2 ([#31](https://github.com/thordin9/apotheke/pull/31))
- **qa:** docs: add CI4 PR 2 QA report ([#31](https://github.com/thordin9/apotheke/pull/31))
- ci: check links under all of docs ([#34](https://github.com/thordin9/apotheke/pull/34))
- **parity:** test: record install, healthz and settings ([#34](https://github.com/thordin9/apotheke/pull/34))
- docs: describe PHP 8.4 suites, parity and N-4 ([#34](https://github.com/thordin9/apotheke/pull/34))
- **changelog:** docs: drop shipped entries from Unreleased ([#35](https://github.com/thordin9/apotheke/pull/35))
- **qa:** docs: add PR 35 sync QA report ([#35](https://github.com/thordin9/apotheke/pull/35))
- docs: say the PHP 8.4 JIT guard covers the worker ([#34](https://github.com/thordin9/apotheke/pull/34))
- **qa:** docs: add CI4 PR 3 QA report ([#34](https://github.com/thordin9/apotheke/pull/34))
- docs: describe the CI4 skeleton and CI legs ([#38](https://github.com/thordin9/apotheke/pull/38))
- ci: cache both lockfiles and document the audit gate ([#38](https://github.com/thordin9/apotheke/pull/38))
- ci: run the ci4 pgsql cells on PostgreSQL ([#38](https://github.com/thordin9/apotheke/pull/38))
- docs: correct the CI table and the DSN note ([#38](https://github.com/thordin9/apotheke/pull/38))
- test: separate the originals guard from a plain 404 ([#38](https://github.com/thordin9/apotheke/pull/38))
- ci: report SQLSTATE from the pgsql proof script ([#38](https://github.com/thordin9/apotheke/pull/38))
- test: production boot does not create the probe table ([#38](https://github.com/thordin9/apotheke/pull/38))
- test: use the postgres service for the probe check ([#38](https://github.com/thordin9/apotheke/pull/38))
- **qa:** docs: add QA report for CI4 PR 4 ([#38](https://github.com/thordin9/apotheke/pull/38))
- **qa:** docs: add QA report for the main sync ([#43](https://github.com/thordin9/apotheke/pull/43))
- **e2e:** ci: retry apt and PPA steps in e2e-container-setup ([#52](https://github.com/thordin9/apotheke/pull/52))
- **qa:** docs: add the PR #51 ci4 sync QA report ([#51](https://github.com/thordin9/apotheke/pull/51))
- **qa:** docs: add the PR #53 ci4 migrations QA report ([#53](https://github.com/thordin9/apotheke/pull/53))
- **docker:** build: keep one zend.exception_ignore_args line ([#61](https://github.com/thordin9/apotheke/pull/61))
- **docker:** test: read the PHP pin from the ARG default ([#61](https://github.com/thordin9/apotheke/pull/61))
- **qa:** docs: add the #61 ci4 sync and v2.6.1 QA report ([#61](https://github.com/thordin9/apotheke/pull/61))
- **docker:** build: drop the trailing blank line in php.ini ([#65](https://github.com/thordin9/apotheke/pull/65))
- **changelog:** docs: link the #61 and #65 Unreleased bullets ([#65](https://github.com/thordin9/apotheke/pull/65))
- ci: check legacy DSN credential precedence in the Docker job ([#66](https://github.com/thordin9/apotheke/pull/66))
- **changelog:** docs: note the Docker legacy DSN precedence check ([#66](https://github.com/thordin9/apotheke/pull/66))
- ci: check DSN-mention cases e and e2 in the legacy precedence step ([#66](https://github.com/thordin9/apotheke/pull/66))
- **qa:** docs: add the #65 ci4 sync nits QA report ([#65](https://github.com/thordin9/apotheke/pull/65))
- **changelog:** docs: link the #66 entries ([#66](https://github.com/thordin9/apotheke/pull/66))
- **changelog:** docs: link the #64 entries ([#64](https://github.com/thordin9/apotheke/pull/64))
- **qa:** docs: add the #66 ci4 migrations follow-ups QA report ([#66](https://github.com/thordin9/apotheke/pull/66))
- **qa:** docs: add the #64 ci4 settings core QA report ([#64](https://github.com/thordin9/apotheke/pull/64))
- **changelog:** docs: link the older ci4 Unreleased bullets ([#67](https://github.com/thordin9/apotheke/pull/67))
- ci: add case e3 to the legacy DSN precedence check ([#68](https://github.com/thordin9/apotheke/pull/68))
- test: share skipOutsideCi as a trait and pin its CI branch ([#68](https://github.com/thordin9/apotheke/pull/68))
- **changelog:** docs: link the settings CLI deviations to #64 ([#68](https://github.com/thordin9/apotheke/pull/68))
- **changelog:** docs: note the #68 test follow-ups ([#68](https://github.com/thordin9/apotheke/pull/68))
- **qa:** docs: add QA review for PR #67 changelog links ([#67](https://github.com/thordin9/apotheke/pull/67))
- **qa:** docs: add QA2 review for #68 review follow-ups ([#68](https://github.com/thordin9/apotheke/pull/68))
- **autofill:** test: pin caps, 413 retry, and originals ([#69](https://github.com/thordin9/apotheke/pull/69))
- **changelog:** docs: note the ci4 auth hardening ([#74](https://github.com/thordin9/apotheke/pull/74))
- **qa:** docs: add QA2 review for #74 ci4 auth hardening ([#74](https://github.com/thordin9/apotheke/pull/74))
- **changelog:** docs: link #68 on the e3 credentials bullet ([#76](https://github.com/thordin9/apotheke/pull/76))
- **autofill:** test: send the session CSRF token ([#69](https://github.com/thordin9/apotheke/pull/69))
- **autofill:** docs: describe the CI4 client contract ([#69](https://github.com/thordin9/apotheke/pull/69))
- **changelog:** docs: note the ci4 request and auth hardening ([#76](https://github.com/thordin9/apotheke/pull/76))
- **autofill:** docs: name the form fields and the single 413 retry ([#69](https://github.com/thordin9/apotheke/pull/69))
- **qa:** docs: add the #69 CI4 autofill module QA report ([#69](https://github.com/thordin9/apotheke/pull/69))
- **qa:** docs: add QA2 review for #76 ci4 request and auth hardening ([#76](https://github.com/thordin9/apotheke/pull/76))
- **changelog:** docs: link the #82 CI4 session and base URL fixes ([#82](https://github.com/thordin9/apotheke/pull/82))
- **lint:** ci: skip elided (…#anchor) placeholders in the link check ([#83](https://github.com/thordin9/apotheke/pull/83))
- **changelog:** docs: record the main v2.6.8 sync ([#83](https://github.com/thordin9/apotheke/pull/83))
- **changelog:** docs: cover main v2.6.9 in the ci4 sync note ([#83](https://github.com/thordin9/apotheke/pull/83))
- **qa:** docs: add PR #83 ci4 sync v2.6.9 QA ([#83](https://github.com/thordin9/apotheke/pull/83))
- **qa:** docs: add the #82 CI4 session and base URL QA report ([#82](https://github.com/thordin9/apotheke/pull/82))
- test: use APP_PASSWORD fixtures of at least 8 characters ([#85](https://github.com/thordin9/apotheke/pull/85))
- **lint:** ci: skip only (…) and (…#anchor) placeholders in link check ([#85](https://github.com/thordin9/apotheke/pull/85))
- **autofill:** docs: say CI4 suggest has no photo size limits yet ([#85](https://github.com/thordin9/apotheke/pull/85))
- **changelog:** docs: record the main v2.6.10 sync ([#85](https://github.com/thordin9/apotheke/pull/85))
- **qa:** docs: add QA review for #89 CI4 secret encryption ([#89](https://github.com/thordin9/apotheke/pull/89))
- **qa:** docs: add QA review for #90 CI4 session limits ([#90](https://github.com/thordin9/apotheke/pull/90))
- **qa:** docs: add QA review for #91 CI4 permission filter ([#91](https://github.com/thordin9/apotheke/pull/91))
- test: link check test without the removed superpowers docs ([#92](https://github.com/thordin9/apotheke/pull/92))
- **qa:** docs: add QA review for #93 CI4 review follow-ups ([#93](https://github.com/thordin9/apotheke/pull/93))
- **qa:** docs: add QA review for #94 Layout module ([#94](https://github.com/thordin9/apotheke/pull/94))
- docs: hand-off lanes, taxonomy changelog and module graph ([#96](https://github.com/thordin9/apotheke/pull/96))
- ci: install the HEIC decoder on ci4 legs and prove the test runs ([#95](https://github.com/thordin9/apotheke/pull/95))
- docs: slash variants served by CI4 routes and checked writes (N-26) ([#96](https://github.com/thordin9/apotheke/pull/96))
- **qa:** docs: PR 95 CI4 media storage QA report ([#95](https://github.com/thordin9/apotheke/pull/95))
- **qa:** docs: add QA review for #96 taxonomy ([#96](https://github.com/thordin9/apotheke/pull/96))
- **qa:** docs: PR 97 CI4 media and settings follow-ups QA report ([#97](https://github.com/thordin9/apotheke/pull/97))
- **qa:** docs: add QA review for #98 Notes ([#98](https://github.com/thordin9/apotheke/pull/98))
- **qa:** docs: PR 99 CI4 catalog QA report ([#99](https://github.com/thordin9/apotheke/pull/99))
- **e2e:** test: run the Playwright specs against CI4 with E2E_APP=ci4 ([#101](https://github.com/thordin9/apotheke/pull/101))
- ci: run the ported Playwright specs on CI4 in the PHP 8.4 e2e cells ([#101](https://github.com/thordin9/apotheke/pull/101))
- test: accept a CI4 module run whose only issue is skips (N-39) ([#101](https://github.com/thordin9/apotheke/pull/101))
- docs: changelog for #101 ([#101](https://github.com/thordin9/apotheke/pull/101))
- **qa:** docs: PR 101 CI4 e2e target and router QA report ([#101](https://github.com/thordin9/apotheke/pull/101))
- **qa:** docs: PR 102 CI4 item pages QA report ([#102](https://github.com/thordin9/apotheke/pull/102))
- **qa:** docs: PR 100 CI4 auth pages QA report ([#100](https://github.com/thordin9/apotheke/pull/100))
- **qa:** docs: PR 103 CI4 item photos QA report ([#103](https://github.com/thordin9/apotheke/pull/103))
- **qa:** docs: PR 104 CI4 users and roles QA report ([#104](https://github.com/thordin9/apotheke/pull/104))
- docs: changelog bullet for the main sync ([#107](https://github.com/thordin9/apotheke/pull/107))
- **qa:** docs: PR 105 CI4 CLI account forms QA report ([#105](https://github.com/thordin9/apotheke/pull/105))
- test: skip CI4 hardening probes until the CI4 port ([#107](https://github.com/thordin9/apotheke/pull/107))
- docs: drop the stale NUL-password CLI deviation ([#107](https://github.com/thordin9/apotheke/pull/107))
- docs: HANDOFF lane for the CI4 sign-in limit port ([#109](https://github.com/thordin9/apotheke/pull/109))
- **qa:** docs: PR 108 CI4 bulk and merge QA report ([#108](https://github.com/thordin9/apotheke/pull/108))
- **qa:** docs: PR 109 CI4 rate limit QA report ([#109](https://github.com/thordin9/apotheke/pull/109))
- docs: HANDOFF lane for the #111/#112 CI4 port ([#113](https://github.com/thordin9/apotheke/pull/113))
- **e2e:** test: run the labels, barcode and lending specs on CI4 ([#114](https://github.com/thordin9/apotheke/pull/114))
- **e2e:** test: run the stocktake spec on CI4 ([#115](https://github.com/thordin9/apotheke/pull/115))
- docs: note the role edit lock in CHANGELOG and HANDOFF ([#110](https://github.com/thordin9/apotheke/pull/110))
- **qa:** docs: PR 113 CI4 main sync and ports QA report ([#113](https://github.com/thordin9/apotheke/pull/113))
- test: drop the #113 null-name probe, superseded by #116's ([#117](https://github.com/thordin9/apotheke/pull/117))
- docs: HANDOFF entry for the CI4 sign-in name port ([#117](https://github.com/thordin9/apotheke/pull/117))
- **qa:** docs: add QA review for #114 labels and lending ([#114](https://github.com/thordin9/apotheke/pull/114))
- **qa:** docs: add QA review for #115 stocktakes ([#115](https://github.com/thordin9/apotheke/pull/115))
- **qa:** docs: add QA review for #110 accounts hardening ([#110](https://github.com/thordin9/apotheke/pull/110))
- docs: HANDOFF lane and CHANGELOG for CI4 autofill via Media ([#118](https://github.com/thordin9/apotheke/pull/118))
- **qa:** docs: PR 117 CI4 sign-in name QA report ([#117](https://github.com/thordin9/apotheke/pull/117))
- **qa:** docs: PR 118 CI4 autofill via Media QA report ([#118](https://github.com/thordin9/apotheke/pull/118))
- **qa:** docs: PR 119 CI4 EXIF rotation QA report ([#119](https://github.com/thordin9/apotheke/pull/119))
- **qa:** docs: move PR 119 QA report after the feature/ci4 sync ([#119](https://github.com/thordin9/apotheke/pull/119))
- docs: note #119 merged and #120 in the handoff ([#120](https://github.com/thordin9/apotheke/pull/120))
- **qa:** docs: PR 120 CI4 null names and EXIF tests QA report ([#120](https://github.com/thordin9/apotheke/pull/120))
- **qa:** docs: move PR 120 QA report after the feature/ci4 sync ([#120](https://github.com/thordin9/apotheke/pull/120))
- test: one admin change goes through, sign-out only if deleted (N-211) ([#123](https://github.com/thordin9/apotheke/pull/123))
- test: no-CSRF parity cases send no valid token (N-232, N-243) ([#123](https://github.com/thordin9/apotheke/pull/123))
- ci: fail on skipped tests in the allow_url_fopen off step ([#124](https://github.com/thordin9/apotheke/pull/124))
- docs: changelog and hand-off for the step-13 follow-ups ([#123](https://github.com/thordin9/apotheke/pull/123))
- docs: changelog and hand-off for N-63 ([#125](https://github.com/thordin9/apotheke/pull/125))
- **qa:** docs: add the QA review of the step-13 follow-ups ([#123](https://github.com/thordin9/apotheke/pull/123))
- **qa:** docs: add the QA review of the N-63 auth methods ([#125](https://github.com/thordin9/apotheke/pull/125))
- docs: changelog, hand-off and plan for step 14, part 1 ([#127](https://github.com/thordin9/apotheke/pull/127))
- **qa:** docs: add PR 124 QA report ([#124](https://github.com/thordin9/apotheke/pull/124))
- test: settle cookie Max-Age in parity comparisons ([#128](https://github.com/thordin9/apotheke/pull/128))
- docs: changelog and hand-off for PR 128 ([#128](https://github.com/thordin9/apotheke/pull/128))
- **qa:** docs: add the QA review of the CSV import and export ([#127](https://github.com/thordin9/apotheke/pull/127))
- test: export 24 MB under a 24 MB memory limit (L-370) ([#131](https://github.com/thordin9/apotheke/pull/131))
- docs: record the CSRF-first answers on the import pages (N-371) ([#131](https://github.com/thordin9/apotheke/pull/131))
- docs: update HANDOFF for the #127 QA follow-ups ([#131](https://github.com/thordin9/apotheke/pull/131))
- docs: link #131 and mark #127 merged in HANDOFF ([#131](https://github.com/thordin9/apotheke/pull/131))
- **qa:** docs: add the QA review of QA notes batch 3 ([#128](https://github.com/thordin9/apotheke/pull/128))
- **qa:** docs: add PR 129 QA report ([#129](https://github.com/thordin9/apotheke/pull/129))
- **qa:** docs: add the QA review of the step 14 follow-ups ([#131](https://github.com/thordin9/apotheke/pull/131))
- **qa:** docs: move PR 129 QA report after the #131 merge ([#129](https://github.com/thordin9/apotheke/pull/129))
- ci: run the data spec on CI4 ([#133](https://github.com/thordin9/apotheke/pull/133))
- **qa:** docs: add the QA review of step 14 backup and restore ([#133](https://github.com/thordin9/apotheke/pull/133))
- **qa:** docs: drop the #133 QA doc until the L-440/L-441 re-check ([#133](https://github.com/thordin9/apotheke/pull/133))
- **qa:** docs: add PR 132 QA report ([#132](https://github.com/thordin9/apotheke/pull/132))
- **docker:** ci: run the restore checks on the CI4 image too (N-431) ([#133](https://github.com/thordin9/apotheke/pull/133))
- ci: run the parity gate on SQLite and PostgreSQL ([#134](https://github.com/thordin9/apotheke/pull/134))
- ci: parity-gate p95 budget, legacy + 5 ms SQLite, + 12 ms PG ([#134](https://github.com/thordin9/apotheke/pull/134))
- ci: parity-gate budget to legacy + 8 ms SQLite, + 20 ms PG ([#134](https://github.com/thordin9/apotheke/pull/134))
- ci: parity-gate budget compares the median gap ([#134](https://github.com/thordin9/apotheke/pull/134))
- ci: run auth, qa-fixes and qa-v2 Playwright specs on CI4 ([#134](https://github.com/thordin9/apotheke/pull/134))
- docs: record streaming restore in CHANGELOG and HANDOFF ([#136](https://github.com/thordin9/apotheke/pull/136))
- **qa:** docs: add PR 134 QA report ([#134](https://github.com/thordin9/apotheke/pull/134))
- docs: update HANDOFF for #135, #134 and the next steps ([#136](https://github.com/thordin9/apotheke/pull/136))
- **qa:** docs: add the PR 136 streaming restore QA review ([#136](https://github.com/thordin9/apotheke/pull/136))
- **qa:** docs: add PR 137 QA report ([#137](https://github.com/thordin9/apotheke/pull/137))
- **qa:** docs: drop PR 137 QA report before merge ([#137](https://github.com/thordin9/apotheke/pull/137))
- **qa:** docs: add the PR 142 restore dir modes QA review ([#142](https://github.com/thordin9/apotheke/pull/142))
- **qa:** docs: shorten the L-610 note in the PR 142 review ([#146](https://github.com/thordin9/apotheke/pull/146))
- **qa:** docs: mark L-610 fixed and list plain verdicts in the PR 142 review ([#146](https://github.com/thordin9/apotheke/pull/146))
- **qa:** docs: add the PR 146 restore privilege drop QA review ([#146](https://github.com/thordin9/apotheke/pull/146))
- test: move the titles() docblock onto its function (N-381) ([#147](https://github.com/thordin9/apotheke/pull/147))
- test: run the import race on legacy too, after #130 (N-424) ([#147](https://github.com/thordin9/apotheke/pull/147))
- docs: record QA notes batch 4 part A in HANDOFF and CHANGELOG ([#147](https://github.com/thordin9/apotheke/pull/147))
- docs: record QA notes batch 4 part B in HANDOFF and CHANGELOG ([#149](https://github.com/thordin9/apotheke/pull/149))
- **changelog:** docs: note restored files removed after a fatal error ([#149](https://github.com/thordin9/apotheke/pull/149))
- **qa:** docs: add the PR 147 batch 4 routes and export QA review ([#147](https://github.com/thordin9/apotheke/pull/147))
- **qa:** docs: add the PR 149 batch 4 restore CLI QA review ([#149](https://github.com/thordin9/apotheke/pull/149))
- ci: allowlist a historic Control Panel test placeholder ([#153](https://github.com/thordin9/apotheke/pull/153))
- ci: match the Control Panel allowlist entry in #151 ([#153](https://github.com/thordin9/apotheke/pull/153))
- **upgrade:** test: undo each mutation, pass the files/ mode (N-641-N-643) ([#154](https://github.com/thordin9/apotheke/pull/154))
- **qa:** docs: add the PR 150 step 16 part 3 QA review ([#150](https://github.com/thordin9/apotheke/pull/150))
- **qa:** docs: add the PR 154 step 17 docs QA review ([#154](https://github.com/thordin9/apotheke/pull/154))
- **qa:** docs: add the PR 151 CI4 Control Panel settings QA review ([#151](https://github.com/thordin9/apotheke/pull/151))
- **upgrade:** test: check file mutations undone before the DB ones ([#156](https://github.com/thordin9/apotheke/pull/156))
- docs: note the CI4 gaps #149 closes in the v2.6.26 sync ([#155](https://github.com/thordin9/apotheke/pull/155))
- test: build the parity placeholder token at run time ([#157](https://github.com/thordin9/apotheke/pull/157))
- docs: say the Control Panel is on CI4 in the upgrade guide (N-800) ([#156](https://github.com/thordin9/apotheke/pull/156))
- docs: CI4 data tools switch to the data folder's owner (N-840) ([#156](https://github.com/thordin9/apotheke/pull/156))
- **qa:** docs: add the PR 156 step 17 follow-up QA review ([#156](https://github.com/thordin9/apotheke/pull/156))
- **qa:** docs: add the PR 157 CI4 L-700 follow-up QA review ([#157](https://github.com/thordin9/apotheke/pull/157))
- test: load the legacy app only in tests that run it ([#159](https://github.com/thordin9/apotheke/pull/159))
- **qa:** docs: add the PR 158 CI4 DB-connect error handling QA review ([#158](https://github.com/thordin9/apotheke/pull/158))
- docs: changelog and handoff for the seed message and N-900 ([#160](https://github.com/thordin9/apotheke/pull/160))
- **qa:** docs: add the PR 159 CI4 spark seed, auth and key commands QA review ([#159](https://github.com/thordin9/apotheke/pull/159))
- build: one Composer manifest for the app and its dev tools ([#161](https://github.com/thordin9/apotheke/pull/161))
- test: run the upgrade gate against a v2.6.26 worktree ([#161](https://github.com/thordin9/apotheke/pull/161))
- **docker:** build: ship the CI4 app only ([#161](https://github.com/thordin9/apotheke/pull/161))
- test: drop the legacy and legacy-comparison tests ([#161](https://github.com/thordin9/apotheke/pull/161))
- test: turn the CI4-versus-CI4 comparisons into plain CI4 tests ([#161](https://github.com/thordin9/apotheke/pull/161))
- ci: test the one app and port coverage to the CI4 suites ([#161](https://github.com/thordin9/apotheke/pull/161))
- **auth:** test: pin the sensitive parameter of appPasswordProblem ([#161](https://github.com/thordin9/apotheke/pull/161))
- docs: describe the root layout and the 3.0 cutover ([#161](https://github.com/thordin9/apotheke/pull/161))
- **changelog:** docs: link the cutover bullet to #161 ([#161](https://github.com/thordin9/apotheke/pull/161))
- **qa:** docs: add the PR 160 CI4 seed owner check and N-900 QA review ([#160](https://github.com/thordin9/apotheke/pull/160))
- test: check the N-900 connect line on the one app ([#161](https://github.com/thordin9/apotheke/pull/161))
- test: cover PdoMigrationRunner after the cutover (M-960) ([#161](https://github.com/thordin9/apotheke/pull/161))
- **media:** test: pin the served copy's flatten and CMYK step (L-962) ([#161](https://github.com/thordin9/apotheke/pull/161))
- test: pin goldens for the item pages and list JSON (L-961) ([#161](https://github.com/thordin9/apotheke/pull/161))
- ci: name the upgrade job 2.6.26 → CI4@8.4 → 2.6.26 ([#161](https://github.com/thordin9/apotheke/pull/161))
- docs: describe the one CI4 app instead of src/ and ci4/ (L-963) ([#161](https://github.com/thordin9/apotheke/pull/161))
- docs: fix stale command and phpstan-di comments (N-964) ([#161](https://github.com/thordin9/apotheke/pull/161))
- test: pin the dev router's originals guard (N-965) ([#161](https://github.com/thordin9/apotheke/pull/161))
- **qa:** docs: add the PR 161 CI4 step 18 cutover QA review ([#161](https://github.com/thordin9/apotheke/pull/161))
- ci: run pushes on main only after the branch switch ([#162](https://github.com/thordin9/apotheke/pull/162))
- docs: describe main as the CI4 branch after the switch ([#162](https://github.com/thordin9/apotheke/pull/162))
- docs: fix stale CLI, autofill and parity notes ([#162](https://github.com/thordin9/apotheke/pull/162))
- test: pin the restore wrapper's data-dir owner check ([#162](https://github.com/thordin9/apotheke/pull/162))
- test: add the item edit form to the page goldens ([#162](https://github.com/thordin9/apotheke/pull/162))
- docs: link the branch-switch PR in HANDOFF ([#162](https://github.com/thordin9/apotheke/pull/162))
- docs: name 2.6.x on old as the 2.x security-fix line ([#162](https://github.com/thordin9/apotheke/pull/162))
- **qa:** docs: add the PR 162 branch-switch QA review ([#162](https://github.com/thordin9/apotheke/pull/162))
- **test:** the parity tests compare cookie lifetimes rounded to 10 s (`cookie_times_settled()`), so two requests that straddle a second compare equal; `/register` with PUT, PATCH and DELETE is also checked with registration open. ([#128](https://github.com/thordin9/apotheke/pull/128))
- **test:** CI4 key tests cover keys of 16–33 bytes, a key file without sodium and the password hash, which is never sealed; the settings service tests no longer depend on `APP_KEY` or `APP_KEY_FILE` in the runner's environment; the `APP_KEY_FILE` backup roots reuse the config's originals path (#89 N-1, N-3, N-11). ([#93](https://github.com/thordin9/apotheke/pull/93))
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
- **ci4:** sync `main` 32b1ef8 (2.6.10) into `feature/ci4`. The legacy app gets main's #81 follow-ups (an `APP_PASSWORD` of at least 8 characters, nginx's 421 with the app's text, the ImageMagick policy check and the release-notes dedupe). Short `APP_PASSWORD` test fixtures are now 8 characters or more, the link check skips only `(…)` and `(…#anchor)` placeholders, and the autofill docs say CI4 suggest has no photo size limits yet. ([#85](https://github.com/thordin9/apotheke/pull/85))
- **ci4:** sync `main` c917cdb (#106 hardening) into `feature/ci4`. The legacy app gets the sign-in limit, NUL-password, last-admin and photo-save hardening. The CI4 photo and users goldens now compare those cases in full; the auth golden leaves out the attempt counts until CI4 gets the per-address cap and the current-password throttle. ([#107](https://github.com/thordin9/apotheke/pull/107))
- **ci4:** sync `main` 0d9f6f3 (2.6.17) into `feature/ci4`. The legacy app gets #111 and #112 (sign-in caps, the photo limit, clean-up after commit). New `HardeningHttpTest` probes for the current-password checks and the last admin are skipped on CI4 until those ports land; the null-character name probe is skipped on legacy PostgreSQL, which cuts the name at it. ([#113](https://github.com/thordin9/apotheke/pull/113))
- **ci4:** sync `main` ff23b38 (2.6.18) into `feature/ci4`. The legacy app gets #116; its `HardeningHttpTest` probe for null-character names runs on CI4 and on both legacy drivers and replaces the narrower #113 probe. ([#117](https://github.com/thordin9/apotheke/pull/117))
- **ci4:** sync `main` 935f144 (2.6.19, 2.6.20) into `feature/ci4`. The legacy app gets #121 and #122. `Ci4AuthParityTest` now checks that both apps refuse a registered name with a null character at either end, a CI4 register-form test covers it too, and the `allow_url_fopen` off step fails on a skipped test again. ([#124](https://github.com/thordin9/apotheke/pull/124))
- **ci4:** sync `main` dd019fb (2.6.21, 2.6.22) into `feature/ci4`. The legacy app gets #126 (lend, return and stocktake locks, `SIGN_IN_FAIL_MS`, the shared migrate connection) and #130 (the CSV import lock); the test server sets `SIGN_IN_FAIL_MS` for both apps, CI4's `ApothekeConfig` loads `sign_in_fail_ms` as legacy does (CI4 paces with it since [#132](https://github.com/thordin9/apotheke/pull/132); its deadline starts at the controller, not at the request start), and the migrate connection test runs legacy `bin/migrate.php` on the CI4 legs too. CI4 already has the lend, return and stocktake close locks and its own sign-in deadline. ([#129](https://github.com/thordin9/apotheke/pull/129))
- **ci4:** sync `main` 458a3c5 (2.6.23) into `feature/ci4`. The legacy app gets #135 (one restore at a time, and a failed restore's files are undone); the CI4 restore is unchanged. ([#139](https://github.com/thordin9/apotheke/pull/139))
- **ci4:** sync `main` 18dc959 (2.6.24, 2.6.25) into `feature/ci4`. The legacy app gets #141 (the restore entry cap, no writes through links, short-write checks and the camera link map written through a temp file) and #145 (`bin/restore.php` started as root runs as the data folder's owner); CI4 has these from #142 and #146. ([#143](https://github.com/thordin9/apotheke/pull/143))
- **ci4:** sync `main` 5f63656 (2.6.26) into `feature/ci4`. The legacy app gets #148: the `bin/` data tools started as root run as the data folder's owner (L-622), `bin/settings.php key generate` runs before any configuration is read (L-680), the owner switch stops when PHP has neither `posix_initgroups` nor `posix_getgroups` (N-681), a camera file a restore creates is 0640 from the start (N-682) and the 2.6.26 notes lead with the upgrade note (N-683). With `APOTHEKE_APP=ci4`, `key generate` stays on legacy and so gets L-680; the CI4 owner switch and CI4 restore are unchanged (#149 closes every gap except N-625 and N-681). ([#155](https://github.com/thordin9/apotheke/pull/155))
- **docs:** step 17: the CI4 app in the architecture, setup, configuration, database, deployment, Control Panel and CI docs, and a 3.0 upgrade and rollback guide (`docs/upgrade-3.0.md`). Tests: the menu's permission checks per role (N-640); the upgrade/rollback mutations are each undone before the next and compare the apps' `files/` mode (N-641 to N-643). ([#154](https://github.com/thordin9/apotheke/pull/154))
- **docs:** the 3.0 upgrade guide says to keep `APP_KEY` / `APP_KEY_FILE` unchanged and stored apart from the backup, checks that `/originals/` answers 404 after the switch, and names the 2.x image for rollback; the upgrade/rollback job checks the file mutations are undone before the database ones (L-760, L-761, N-762, N-763). The upgrade guide and the Control Panel page say the Control Panel is on CI4, the deployment page says the CI4 data tools started as root switch to the data folder's owner or stop (N-840), the hand-off lists #149, #151 and #155 as merged (N-820), and the Rollback A line is wrapped (N-800). ([#156](https://github.com/thordin9/apotheke/pull/156))
- **test:** the two N-611 cleanup steps a fatal error relies on have tests that fail without them (QA N-701); the Control Panel parity test builds its made-up token at run time, and the `.gitleaks.toml` comment for it uses the wording proposed in [#152](https://github.com/thordin9/apotheke/pull/152) (N-720). ([#157](https://github.com/thordin9/apotheke/pull/157))
- **test:** a restore's drawn thumbnail is removed by the fatal-error cleanup (QA N-881); the owner switch is refused while group 0 remains (QA N-880); the architecture doc says `apotheke:routes` started as root cannot write in a root-owned checkout (QA N-883). ([#158](https://github.com/thordin9/apotheke/pull/158))
- **test:** the PHPUnit bootstrap no longer loads the legacy app: the legacy and legacy-comparison tests require `tests/legacy-bootstrap.php`. `tests/lib.php` takes the PostgreSQL settings from the environment, the Playwright fixtures run through the CI4 code with `E2E_APP=ci4` (`tests/visual/ci4-fixtures.php`), and the Media test checks served copies against the source picture instead of the legacy app's bytes. ([#159](https://github.com/thordin9/apotheke/pull/159))

### Upgrade notes

- **ci4 (security):** with `APOTHEKE_APP=ci4`, `php bin/settings.php get autofill_token` and `get password_hash` no longer print the stored secret; they exit 1 with `“KEY” is write-only.` Scripts that read the token must stop doing so. To rotate the AI autofill token, set the new `AUTOFILL_TOKEN` on the autofill service, then enter it under **Control Panel → AI autofill → Access token → Replace the token** and press **Save token** (**Clear token** removes it). `bin/settings.php set autofill_token` is refused on legacy and CI4 alike, so the Control Panel is the supported path. Passwords are per account: `php bin/settings.php user password NAME` or **Settings → Users & sign-in**. ([#64](https://github.com/thordin9/apotheke/pull/64))

- **docker:** the default image runs PHP 8.4 (8.4.27, was 8.3.36), and PHP 8.4's PDO changes how the legacy app combines `DB_DSN` with `DB_USER` and `DB_PASSWORD` on PostgreSQL. With credentials in both, legacy now connects as the DSN's role, not as `DB_USER`. With `user=` or `password=` text elsewhere in `DB_DSN` (for example in `options` or `application_name`) and no DSN credentials, it no longer connects. 6 of the 7 precedence cases differ from PHP 8.3. Keep the credentials in one place: `DB_USER` and `DB_PASSWORD`, with no `user=` or `password=` in `DB_DSN`. See `db.password` in [Every setting](docs/configuration.md#every-setting). ([#132](https://github.com/thordin9/apotheke/pull/132))
- **docker:** the container refuses to start (exit code 78) when `images`, `thumbs`, `originals`, `originals/map` or `sessions` in the data directory is a symbolic link (L-430). Put camera originals elsewhere with `ORIGINALS_DIR`, or mount the real directory at that path instead of linking it. ([#132](https://github.com/thordin9/apotheke/pull/132))

### Intentional deviations (settings CLI, `APOTHEKE_APP=ci4`, [#64](https://github.com/thordin9/apotheke/pull/64))

1. **Secrets are write-only.** `get autofill_token` and `get password_hash` print `“KEY” is write-only.` on stderr and exit 1; legacy printed the stored value.
2. **`get` of an unknown key** prints `Unknown setting “KEY”.` and exits 1; legacy stopped with an uncaught exception (PHP fatal, exit 255).
3. **`import` of a missing file** prints the same message and exits 1 like legacy, without PHP's `file_get_contents(…)` warning line.

Every other form, output and exit code is byte-identical to legacy (`tests/Unit/Ci4SettingsCliTest.php`).

## [2.6.26] - 2026-10-28

**Upgrade note:** the data tools in `bin/` (`backup.php`, `migrate.php`, `seed.php`, `settings.php` and `restore.php`) started as root now run as the data folder's owner. When the data folder is owned by root they stop with exit 1 and change nothing: run them as the data folder's owner, for example `sudo -u www-data php bin/migrate.php`. `bin/backup.php` writes its zip as that owner, so the backup target has to be a folder the data folder's owner can write. `php bin/settings.php key generate` uses no data folder and runs as any user (L-622, L-680).

### Fixed

- **backup:** hardening: `bin/backup.php`, `bin/migrate.php`, `bin/seed.php` and `bin/settings.php` started as root run as the data folder's owner, like `bin/restore.php`, so a backup zip or a new database no longer belongs to root; when the data folder is owned by root they stop with exit 1 and change nothing (L-622). The switch never keeps root's group: a data folder whose group is root's uses the owner's own group (N-621). A user that cannot be told counts as root, as on CI4 (N-625). The docs say the config file must not be writable by the web server's user (N-623). ([#148](https://github.com/thordin9/apotheke/pull/148))
- **backup:** hardening: a link at a new photo, thumbnail or attachment name stops the restore and is left as it is, as at a camera file's name (N-565). A new camera file is 0640 from the start; only a kept older file with another mode is changed after the restore (N-582). ([#148](https://github.com/thordin9/apotheke/pull/148))
- **backup:** `bin/restore.php` prints the warnings from the restore itself (camera links that could not be written, settings not restored) after `Restored:` (N-593). A failed restore in the browser removes its upload at once instead of at the hourly sweep, and so does an upload that could not be stored whole (N-567). The docs say which full-disk cases fail the restore and which skip camera files (N-580). Tests cover the lock's fresh name lookup (N-581) and a map that cannot be written (N-563). ([#148](https://github.com/thordin9/apotheke/pull/148))
- **backup:** hardening: `php bin/settings.php key generate` prints its key before it reads the configuration or switches users, so it uses no data folder (L-680). The switch to the data folder's owner stops with exit 1 when PHP has neither `posix_initgroups` nor `posix_getgroups` (N-681). A camera file a restore creates keeps the 0640 it was created with, whatever the umask, and a test covers it (N-682). ([#148](https://github.com/thordin9/apotheke/pull/148))

### Maintenance

- **restore:** test: cover the CLI owner switch, links and map failures ([#148](https://github.com/thordin9/apotheke/pull/148))
- **restore:** docs: CLI owner, config file and full-disk cases ([#148](https://github.com/thordin9/apotheke/pull/148))
- **changelog:** docs: note the QA notes batch 4 fixes ([#148](https://github.com/thordin9/apotheke/pull/148))
- **e2e:** test: apply migration 0025 without the CLI tool ([#148](https://github.com/thordin9/apotheke/pull/148))
- **changelog:** docs: lead 2.6.26 with the CLI owner upgrade note ([#148](https://github.com/thordin9/apotheke/pull/148))
- **ci:** chore: allow the Control Panel test placeholder in gitleaks ([#148](https://github.com/thordin9/apotheke/pull/148))
- **qa:** docs: add the PR 148 batch 4 restore CLI QA review ([#148](https://github.com/thordin9/apotheke/pull/148))

## [2.6.25] - 2026-10-28

### Fixed

- **backup:** reliability: `bin/restore.php` runs as the user that owns the data folder; started as root it switches to that user before it touches the catalog or any file, so every restored file belongs to the data folder's owner, and when the data folder is owned by root it stops with exit 1 and changes nothing. ([#145](https://github.com/thordin9/apotheke/pull/145))

### Maintenance

- **restore:** test: cover a root CLI restore and a root-owned data dir ([#145](https://github.com/thordin9/apotheke/pull/145))
- **changelog:** docs: note the CLI restore runs as the data-dir owner ([#145](https://github.com/thordin9/apotheke/pull/145))
- **qa:** docs: add the PR 145 restore privilege drop QA review ([#145](https://github.com/thordin9/apotheke/pull/145))

## [2.6.24] - 2026-10-27

### Fixed

- **backup:** reliability: a restore refuses a backup holding a file bigger than one restore unpacks (200 MiB, `backup.json` aside) before reading it, in the browser (422) and with `bin/restore.php` (exit 1); a restore stopped by a fatal error, such as running out of memory, still removes the files it had written; a link where a restore would write a new file, or at an existing camera file's name, stops the restore and is left as it is; a short write, such as on a full disk, fails the restore; the photos' camera-file links are written through a temp file and renamed into place, and one whose name is taken by a link is skipped while the restore continues; the restore lock is only ever a plain file. A failed restore leaves the catalog and the existing files as they were; folders it made (`images/`, `thumbs/`, `files/`, `originals/`) and the camera-files deny file can stay, and a restore whose process is killed can leave the new files it had written, which nothing refers to. ([#141](https://github.com/thordin9/apotheke/pull/141))

### Maintenance

- **readme:** docs: refresh the coverage table from the CI run ([#141](https://github.com/thordin9/apotheke/pull/141))
- **readme:** docs: refresh the coverage table from the head's CI run ([#141](https://github.com/thordin9/apotheke/pull/141))
- **qa:** docs: add the PR 141 restore entry cap QA review ([#141](https://github.com/thordin9/apotheke/pull/141))
- **backup:** test: cover the entry size cap, links, the lock file, and failed and refused restores in the browser ([#141](https://github.com/thordin9/apotheke/pull/141))

## [2.6.23] - 2026-10-27

### Fixed

- **backup:** reliability: a restore that fails part-way leaves the catalog, the photo, thumbnail, attachment and camera files, and the photos' camera-file links as they were, and one restore runs at a time: another one started meanwhile, in the browser (409) or with `bin/restore.php` (exit 1), is told to try again when the first has finished. ([#135](https://github.com/thordin9/apotheke/pull/135))

### Maintenance

- **backup:** test: cover failed and concurrent restores and the lock ([#135](https://github.com/thordin9/apotheke/pull/135))
- **qa:** docs: add the PR 135 restore failure QA review ([#135](https://github.com/thordin9/apotheke/pull/135))

## [2.6.22] - 2026-10-26

### Fixed

- **import:** hardening: a CSV import run takes the import's lock as its first write, reads the uploaded file after it, and a commit marks the import done in its own transaction, so submits at the same time import a file once and no longer answer with a server error; a submit that finds the import done goes to the done page. ([#130](https://github.com/thordin9/apotheke/pull/130))

### Maintenance

- test: concurrent CSV import commit and preview ([#130](https://github.com/thordin9/apotheke/pull/130))
- **qa:** docs: add PR 130 QA report ([#130](https://github.com/thordin9/apotheke/pull/130))

## [2.6.21] - 2026-10-26

### Fixed

- **security:** hardening: lending, returning, finishing, cancelling and ticking a stocktake take their lock or check the stocktake is still open as their first write, so requests at the same time no longer apply twice or change a closed stocktake; every failed password check (sign-in, current password, turning sign-in back on) answers a fixed time after the request began (`SIGN_IN_FAIL_MS`, default 1000 ms); `bin/migrate.php` shares its connection with code that runs inside a migration. ([#126](https://github.com/thordin9/apotheke/pull/126))

### Maintenance

- test: concurrent writes, password pacing and migrate after-commit ([#126](https://github.com/thordin9/apotheke/pull/126))
- **qa:** docs: add PR 126 QA report ([#126](https://github.com/thordin9/apotheke/pull/126))

## [2.6.20] - 2026-10-26

### Fixed

- **security:** hardening: an unknown sign-in name spends a password check at the current default cost on every PHP version, turning sign-in back on always spends one password check, and cleanups queued inside a migration wait for its commit. ([#122](https://github.com/thordin9/apotheke/pull/122))

### Maintenance

- test: sign-in timing for known names, failed import and migrate queue ([#122](https://github.com/thordin9/apotheke/pull/122))
- **qa:** docs: add PR 122 QA report ([#122](https://github.com/thordin9/apotheke/pull/122))

## [2.6.19] - 2026-10-26

### Fixed

- **security:** hardening: a new user name with a null character anywhere is refused like any other invalid name when registering, adding or renaming an account, and a sign-in with such a name spends the same password-check time as an unknown name. ([#121](https://github.com/thordin9/apotheke/pull/121))

### Maintenance

- test: null-character names, address cap and after-commit drains ([#121](https://github.com/thordin9/apotheke/pull/121))
- test: queue migrate's drain cleanup up front on every PHP version ([#121](https://github.com/thordin9/apotheke/pull/121))
- **qa:** docs: PR 121 main auth hardening QA report ([#121](https://github.com/thordin9/apotheke/pull/121))

## [2.6.18] - 2026-10-26

### Fixed

- **security:** hardening: a sign-in name with a null character anywhere is refused like a wrong password, on the sign-in form and when turning sign-in back on, on both databases. ([#116](https://github.com/thordin9/apotheke/pull/116))

### Maintenance

- test: sign-in names with a null character on both drivers ([#116](https://github.com/thordin9/apotheke/pull/116))
- **qa:** docs: PR 116 main sign-in name QA report ([#116](https://github.com/thordin9/apotheke/pull/116))

## [2.6.17] - 2026-10-26

### Fixed

- **security:** hardening: password checks outside the sign-in form also count toward the per-address cap; clean-up after a save runs only after its commit, and one failing step no longer skips the rest; upgrading resets the per-address counters once for IPv4-compatible (`::/96`, including `::1`) and NAT64 (`64:ff9b::/96`) clients. ([#112](https://github.com/thordin9/apotheke/pull/112))

### Maintenance

- test: cover after-commit order, account keys and password caps ([#112](https://github.com/thordin9/apotheke/pull/112))
- docs: password checks count toward the address cap ([#112](https://github.com/thordin9/apotheke/pull/112))
- docs: keep the #112 changelog entry under Unreleased ([#112](https://github.com/thordin9/apotheke/pull/112))
- **qa:** docs: PR 112 main hardening follow-up 2 QA report ([#112](https://github.com/thordin9/apotheke/pull/112))

## [2.6.16] - 2026-10-26

### Fixed

- **security:** hardening: a successful sign-in no longer resets the per-address cap; IPv6 clients count by their /64 for it; turning sign-in back on is limited like a sign-in; photo adds at the same time stay within the photo limit; a replaced photo's files are removed only after the save commits. ([#111](https://github.com/thordin9/apotheke/pull/111))

### Maintenance

- test: cover password limits, admin races and photo adds ([#111](https://github.com/thordin9/apotheke/pull/111))
- docs: sign-in address cap and trusted proxies ([#111](https://github.com/thordin9/apotheke/pull/111))
- **qa:** docs: PR 111 main hardening follow-up QA report ([#111](https://github.com/thordin9/apotheke/pull/111))

## [2.6.15] - 2026-10-25

### Fixed

- **security:** hardening: sign-in limits now hold for requests that arrive together and also cover the current-password check and all names from one address; passwords and `APP_PASSWORD` with a null character are refused; the last active admin always stays; a photo save that fails leaves no files or new places, and a photo edit keeps its original. ([#106](https://github.com/thordin9/apotheke/pull/106))

### Maintenance

- **qa:** docs: drop QA reports older than v2.6.0 and add an index ([#88](https://github.com/thordin9/apotheke/pull/88))
- **changelog:** docs: summarise pre-2.6.0 releases and fix QA nits ([#88](https://github.com/thordin9/apotheke/pull/88))
- test: use 8-character APP_PASSWORD fixtures ([#88](https://github.com/thordin9/apotheke/pull/88))
- docs: trim README and docs, merge overlapping pages ([#88](https://github.com/thordin9/apotheke/pull/88))
- ci: allowlist S-L5 CI4 placeholder test keys in gitleaks ([#88](https://github.com/thordin9/apotheke/pull/88))
- **security:** test: cover sign-in limits, admins and photo saves ([#106](https://github.com/thordin9/apotheke/pull/106))
- docs: sign-in limits and password rules ([#106](https://github.com/thordin9/apotheke/pull/106))
- docs: link the changelog entry to its pull request ([#106](https://github.com/thordin9/apotheke/pull/106))
- **qa:** docs: PR 106 main hardening QA report ([#106](https://github.com/thordin9/apotheke/pull/106))

## [2.6.14] - 2026-10-24

### Fixed

- **auth:** hardening: a new sign-in after a session was ended now starts a fresh session lifetime. ([#87](https://github.com/thordin9/apotheke/pull/87))

### Maintenance

- test: new sign-in after a dropped session gets a new time ([#87](https://github.com/thordin9/apotheke/pull/87))
- docs: changelog entry for fresh sign-in time ([#87](https://github.com/thordin9/apotheke/pull/87))
- **qa:** docs: add v2.6.12 release QA doc ([#87](https://github.com/thordin9/apotheke/pull/87))
- test: cover remaining keepSignIn call sites ([#87](https://github.com/thordin9/apotheke/pull/87))
- **qa:** docs: add v2.6.13 release QA doc ([#87](https://github.com/thordin9/apotheke/pull/87))
- **qa:** docs: add PR 87 session re-sign-in QA doc ([#87](https://github.com/thordin9/apotheke/pull/87))

## [2.6.13] - 2026-10-24

### Fixed

- **ui:** the footer version label has a fixed width, so a release bump no longer shifts the centred footer links. ([#86](https://github.com/thordin9/apotheke/pull/86))

### Maintenance

- **e2e:** test: update the list footer baselines ([#86](https://github.com/thordin9/apotheke/pull/86))
- **changelog:** docs: add the footer version width fix ([#86](https://github.com/thordin9/apotheke/pull/86))

## [2.6.12] - 2026-10-24

**Upgrade note:** a sign-in from before the upgrade is stamped on its first request after the upgrade and ends `session_days` later (default 30). After that, lowering `session_days` ends older sessions on their next request. Migration 0028 keeps existing sessions valid, so the upgrade signs nobody out.

### Fixed

- **auth:** a sign-in ends on the server once it is older than `session_days`, counted from sign-in. A session from before the upgrade is stamped on its next request instead of being ended. The idle stamp moves at most once a minute. Sign-out of an already ended session redirects. Sign out everywhere (Your account, and `php bin/settings.php user signout NAME`) and a new password replace the account's session epoch (migration 0028), so older sessions are dropped. SEC-N6. ([#84](https://github.com/thordin9/apotheke/pull/84))

### Maintenance

- docs: state the server-side sign-in limit ([#84](https://github.com/thordin9/apotheke/pull/84))
- docs: record the session lifetime limit ([#84](https://github.com/thordin9/apotheke/pull/84))
- **e2e:** test: update the security settings baseline ([#84](https://github.com/thordin9/apotheke/pull/84))
- test: use 8-character password in session lifetime tests ([#84](https://github.com/thordin9/apotheke/pull/84))
- test: stale sign-out deletes the live session file ([#84](https://github.com/thordin9/apotheke/pull/84))
- docs: name migration 0028 in the changelog ([#84](https://github.com/thordin9/apotheke/pull/84))
- **qa:** docs: add v2.6.11 release QA doc ([#84](https://github.com/thordin9/apotheke/pull/84))
- **qa:** docs: add PR 84 session lifetime QA doc ([#84](https://github.com/thordin9/apotheke/pull/84))

## [2.6.11] - 2026-10-24

**Upgrade note:** stored secrets (the AI autofill access token) are encrypted only when `APP_KEY` or `APP_KEY_FILE` is set. Without a key nothing changes: they stay plaintext and the Control Panel says to set one. Set the key before you save a token, see [Stored secrets](https://github.com/thordin9/apotheke/blob/main/docs/configuration.md#stored-secrets).

### Fixed

- **security:** stored secrets are encrypted at rest when `APP_KEY` is set (S-L5). With no key they stay plaintext and the Control Panel says to set one. A configured but unusable key refuses secret saves. `php bin/settings.php key rotate` re-encrypts them. ([#78](https://github.com/thordin9/apotheke/pull/78))

### Maintenance

- **changelog:** docs: link stored-secret encryption pull request ([#78](https://github.com/thordin9/apotheke/pull/78))
- **visual:** test: refresh autofill screenshots for the key warning ([#78](https://github.com/thordin9/apotheke/pull/78))
- ci: assert the sodium extension in the image ([#78](https://github.com/thordin9/apotheke/pull/78))
- docs: show APP_KEY_FILE mount and key backup advice ([#78](https://github.com/thordin9/apotheke/pull/78))
- **security:** test: cover unusable-key and moved-address cases ([#78](https://github.com/thordin9/apotheke/pull/78))
- docs: correct key file permissions ([#78](https://github.com/thordin9/apotheke/pull/78))
- docs: use numeric gid 82 for key file permissions ([#78](https://github.com/thordin9/apotheke/pull/78))
- **visual:** test: re-render baselines for the wider v2.6.10 footer ([#78](https://github.com/thordin9/apotheke/pull/78))
- **qa:** docs: add v2.6.10 release QA doc ([#78](https://github.com/thordin9/apotheke/pull/78))
- **qa:** docs: add PR 78 stored-secret encryption QA doc ([#78](https://github.com/thordin9/apotheke/pull/78))

## [2.6.10] - 2026-10-24

**Upgrade note:** a new install's `APP_PASSWORD` now needs at least 8 characters, not only spaces (hardening, #75 QA L-2). A shorter one stops the first start with a message that doesn't show it. An install that already has an account is unaffected.

### Fixed

- **auth:** hardening (#75 QA L-2): `APP_PASSWORD` meets the same 8-character minimum as every other password. A shorter value stops the Docker start check (exit 78), `bin/migrate.php`, `bin/settings.php` (exit 1, no stack trace) and the first-account seed, and the value is never logged. ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docker:** hardening (#75 QA L-1, N-2; #73 QA N-1, N-2): the start check is tested for a database that can't be read, and each try waits at most 5 s for PostgreSQL. The ImageMagick policy check fails on any delegate or `@file` path rule that grants rights. nginx's 421 on the restore upload has the app's short plain-text body. ([#81](https://github.com/thordin9/apotheke/pull/81))
- **release:** a handwritten Fixed bullet replaces the lines generated from the same PR's `fix:` commits, whatever their scope (v2.6.5 release QA L-1). ([#81](https://github.com/thordin9/apotheke/pull/81))

### Maintenance

- **docker:** test: cover the exit 78 when the database can't be read ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** docs: say what the sign-in start check may change ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docker:** docs: bind the 1.x upgrade port examples to 127.0.0.1 ([#81](https://github.com/thordin9/apotheke/pull/81))
- **hosts:** docs: name the 2.6.2 Host rule that health checks follow ([#81](https://github.com/thordin9/apotheke/pull/81))
- **hosts:** docs: say PUBLIC_URL only works on a new install ([#81](https://github.com/thordin9/apotheke/pull/81))
- **health:** docs: use a version placeholder in the /health example ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: cover a failing account query and the admin seed ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: give the APP_PASSWORD fixtures 8 or more characters ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docker:** test: rewrap the sign-in checks header ([#81](https://github.com/thordin9/apotheke/pull/81))
- **changelog:** docs: record the SEC-M3 follow-up hardening ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: settings CLI exits 1 on a short APP_PASSWORD ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: give the idle-session fixture an 8-character password ([#81](https://github.com/thordin9/apotheke/pull/81))
- **qa:** docs: add v2.6.9 release QA ([#81](https://github.com/thordin9/apotheke/pull/81))
- **qa:** docs: add PR #81 SEC-M3 follow-up QA ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docs:** health checks follow the Host rule from 2.6.2, `PUBLIC_URL` only works on a new install (an existing one uses Control Panel → General), the `/health` example uses a version placeholder, the 1.x upgrade port examples bind to `127.0.0.1`, and the start check's wording says what it may change (#73 QA L-1, v2.6.2 release QA L-1 and N-4, #75 QA N-1 and N-3). ([#81](https://github.com/thordin9/apotheke/pull/81))

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

## [2.5.x] - 2026-10-20 to 2026-10-23

Releases 2.5.0 to 2.5.8.

- **autofill:** a separate stripped JPEG is sent, and the uploaded camera file is kept byte for byte under `originals/`, outside the web root. A 503 is retried only when `Retry-After` is at most 60 s.
- **photos:** the Docker image decodes HEIC. Imagick flattens transparency onto white, converts CMYK to sRGB and writes the analysis JPEG with 4:2:0 subsampling. The image builds Imagick from pinned source tarballs.
- **qr:** function JIT is turned off before the QR encoder loads; on PHP 8.2 to 8.4 outside Docker it could write wrong codes.
- **db:** `CREATE SCHEMA` is skipped when `DB_SCHEMA` already exists, and `bin/migrate.php` prints only the driver, host, port, dbname and `DB_SCHEMA`.
- **db:** seed, backup, restore and settings exit 1 when they can't connect to the database, and print only those same fields.
- **docker:** the Docker image now builds on PHP 8.3.36, the PHP security release of 21 Oct 2026. Exception traces omit function arguments.
- **ci:** a gitleaks secret scan and job timeouts.

## [2.4.x] - 2026-10-19

Releases 2.4.0 and 2.4.1.

- **autofill:** photos are analyzed on demand with an *Analyze* button; *Analyze photos automatically* is off on every install (migration 0025). Model and image size dropdowns come from `/v1/info`. One analysis runs at a time, and a second one only fills empty fields.

## [2.3.x] - 2026-10-17 to 2026-10-18

Releases 2.3.0 to 2.3.2.

- **autofill:** a progress estimate, one retry when the service is busy or warming up, and *Test with a photo*. An untouched default category can be replaced by a suggestion.
- **seed:** new installs also get the Clothing, Shoes, Books and Lighting categories.

## [2.2.0] - 2026-10-14

- **autofill:** optional AI autofill: Control Panel settings, *Test connection*, and name, category, tags and notes suggested from a photo. The stored token is sent only to the saved address and is cleared when the address changes.

## [2.1.0] - 2026-10-14

- **ui:** phone overview, two-column form, safe Save bar, one Edit button with Delete in a danger zone.
- **export:** CSV cells are protected against formula injection.
- **auth:** `Secure` cookie behind trusted proxies and a configurable sign-in lifetime.

## [2.0.0] - 2026-10-13

- **docker:** breaking: the image serves on port 9091 (it was 80 inside the container, and compose published 8080).

## [1.2.0] - 2026-10-13

- Location stocktakes, user accounts with roles (custom roles, guest browsing, self-registration), ranked full-text search with highlights and typo tolerance, CSV import with column mapping and a dry run, and in-browser photo crop, rotate and reordering.

## [1.1.x] - 2026-10-13

Releases 1.1.0 and 1.1.1.

- Markdown notes and attachments, bulk QR label printing, favourites and recently viewed, merging duplicates and undo.
- Automatic SemVer releases with this changelog and the README coverage table.

## [1.0.0] - 2026-10-13

- First versioned release, everything merged before automatic releases started: the mobile-first UI with dark mode, SQLite and PostgreSQL with versioned migrations, photos, tags and custom fields, QR labels, backup and restore, history, low-stock alerts, bulk actions, lending, nested locations, expiry dates, value totals, saved filters, barcodes, the Control Panel and the nginx + php-fpm Docker image.

[Unreleased]: https://github.com/thordin9/apotheke/compare/v3.1.0...HEAD
[3.1.0]: https://github.com/thordin9/apotheke/compare/v3.0.1...v3.1.0
[3.0.1]: https://github.com/thordin9/apotheke/compare/v3.0.0...v3.0.1
[3.0.0]: https://github.com/thordin9/apotheke/compare/v2.6.26...v3.0.0
[2.6.26]: https://github.com/thordin9/apotheke/compare/v2.6.25...v2.6.26
[2.6.25]: https://github.com/thordin9/apotheke/compare/v2.6.24...v2.6.25
[2.6.24]: https://github.com/thordin9/apotheke/compare/v2.6.23...v2.6.24
[2.6.23]: https://github.com/thordin9/apotheke/compare/v2.6.22...v2.6.23
[2.6.22]: https://github.com/thordin9/apotheke/compare/v2.6.21...v2.6.22
[2.6.21]: https://github.com/thordin9/apotheke/compare/v2.6.20...v2.6.21
[2.6.20]: https://github.com/thordin9/apotheke/compare/v2.6.19...v2.6.20
[2.6.19]: https://github.com/thordin9/apotheke/compare/v2.6.18...v2.6.19
[2.6.18]: https://github.com/thordin9/apotheke/compare/v2.6.17...v2.6.18
[2.6.17]: https://github.com/thordin9/apotheke/compare/v2.6.16...v2.6.17
[2.6.16]: https://github.com/thordin9/apotheke/compare/v2.6.15...v2.6.16
[2.6.15]: https://github.com/thordin9/apotheke/compare/v2.6.14...v2.6.15
[2.6.14]: https://github.com/thordin9/apotheke/compare/v2.6.13...v2.6.14
[2.6.13]: https://github.com/thordin9/apotheke/compare/v2.6.12...v2.6.13
[2.6.12]: https://github.com/thordin9/apotheke/compare/v2.6.11...v2.6.12
[2.6.11]: https://github.com/thordin9/apotheke/compare/v2.6.10...v2.6.11
[2.6.10]: https://github.com/thordin9/apotheke/compare/v2.6.9...v2.6.10
[2.6.9]: https://github.com/thordin9/apotheke/compare/v2.6.8...v2.6.9
[2.6.8]: https://github.com/thordin9/apotheke/compare/v2.6.7...v2.6.8
[2.6.7]: https://github.com/thordin9/apotheke/compare/v2.6.6...v2.6.7
[2.6.6]: https://github.com/thordin9/apotheke/compare/v2.6.5...v2.6.6
[2.6.5]: https://github.com/thordin9/apotheke/compare/v2.6.4...v2.6.5
[2.6.4]: https://github.com/thordin9/apotheke/compare/v2.6.3...v2.6.4
[2.6.3]: https://github.com/thordin9/apotheke/compare/v2.6.2...v2.6.3
[2.6.2]: https://github.com/thordin9/apotheke/compare/v2.6.1...v2.6.2
[2.6.1]: https://github.com/thordin9/apotheke/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/thordin9/apotheke/releases/tag/v2.6.0
