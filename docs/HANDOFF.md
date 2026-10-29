# Hand-off: `feature/ci4`

A one-screen status of the CodeIgniter 4 port. Every pull request into `feature/ci4` updates this page. Plan and module
rules: [PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [AGENTS.md](../AGENTS.md).

## Current state

- `feature/ci4` carries `main` up to v2.6.22 (sync [#129](https://github.com/thordin9/apotheke/pull/129)), including the legacy session limits, `user signout NAME`
  and #87's `keepSignIn` rule. The shipped image still runs the legacy app; `APOTHEKE_APP=ci4` selects CI4 in CI and
  in development, and at run time on the PHP 8.4 image (step 15, [#132](https://github.com/thordin9/apotheke/pull/132)).
  The CI4 image serves the backup pages since step 14's second part (#133).
- CI4 modules: Core (config, DSN, filters, storage, secret sealing), Migrations (PDO runner, 28 migrations shared with
  `main`), Settings (schema, service, branding, `apotheke:settings`), Autofill (client and its four POST routes; stored photos read through Media), Auth
  (current user, admin guards, session epoch, sign out everywhere, the Permission filter, the sign-in and account pages), Layout (page
  shell, menu, simple header, icons, toasts, POST `/theme`, the error page), Media (originals,
  served copy and thumbnail, HEIC placeholder, decode limits, GET `/media`), Taxonomy and Activity, Catalog, Search
  and Favourites, ImportExport and Backup (below).
- Layout: byte-identical to legacy `page_start()`, `page_end()`, `menu_html()` and `view_error()`. Every error answer
  that legacy draws as a page (404, 403, 419, 413, the same-origin 403, 500) is that page; a fetch gets legacy's JSON.
  The bundled assets are a copy under `ci4/public/assets`, kept equal to `public/assets` by a test. The undo bar joins
  the page end with the Undo module.
- Taxonomy (step 8, first part): `/locations` and `/categories` (list, add, rename, move, default, delete and its
  confirmation page) are byte-identical to legacy, with View for GET and Edit for POST on each route. Tag storage
  (`TagRepository`) is in place for Catalog; tags have no pages. Item history is written through Core's `ItemHistory`,
  which the Activity module implements. Writes go through `Core\Database\Writes`: with DBDebug off a failed
  statement still rolls back and answers 500; nesting is flat, as in `db_tx()`.
- Notes (step 8, second part): `MarkdownRenderer`, byte-identical to `md_render()`/`md_plain()`; no pages of its
  own (item notes are shown and edited with Catalog).
- Catalog (step 9): GET `/` (list, search, filters, sorts, paging, chips, toasts, the overview), GET `/stats` and
  `/filters` (saved filters) are byte-identical to legacy. Search (FTS5 or pg_trgm, typo matching) rebuilds its index
  in one transaction when the data changes. Favourites: POST `/item/{id}/fav` and `/recent/clear`; the list reads
  recently viewed through Catalog's `RecentItems`, which also records an opened item page (and sets the device
  cookie on the first one). POST `/bulk` is step 11b (below). Acceptance: the PM accepted the legacy-vs-CI4 golden test
  (`tests/Unit/Ci4CatalogParityTest.php`) in place of the `list`, `search`, `saved-filters`, `favourites` and
  `value` specs; the specs now also run against CI4 (see Playwright on CI4). Like legacy, CI4 routes the exact
  decoded path: a trailing slash or a dot segment (`/stats/`, `/./stats`, `/%2e/stats`, `/a/../stats`) answers 404
  (N-51, `Core\Http\LegacyPathRouter`).
- Items (step 11a): the item page `/item/{id}`, `/new`, `/item/{id}/edit`, POST `/save` (one photo through
  `ImageStore`, `MAX_FILE_SIZE` from `maxUpload()`), `/item/{id}/delete`, POST `/undo` with the undo bar, and
  `/activity` are byte-identical to legacy (`tests/Unit/Ci4ItemParityTest.php`, SQLite and PostgreSQL). Notes
  are drawn and edited with `MarkdownRenderer`; the Notes module is unchanged. The Undo module keeps the tokens;
  applying one is Catalog's `Items\UndoApply`.
- Photos (step 11a, part 2): POST `/item/{id}/photos` (add), `/item/{id}/photos/{pid}/(cover|left|right|delete)`,
  `/item/{id}/photos/order`, and GET/POST `/item/{id}/photos/{pid}/edit` (crop and rotate) answer as legacy does,
  with the same files on disk (`tests/Unit/Ci4ItemPhotosParityTest.php`: served copy, thumbnail and original by
  content). `Catalog\Items\PhotoRepository` writes through `Writes`; Media's `ImageStore::edit()` makes the new
  version with its own copy of the original. A stored photo stays held for `RequestCleanup` until its write
  commits, and `/save` creates a typed-in location or category in the save's transaction, so a failed save or
  photo add keeps no files and no new location or category (L-81; legacy keeps them). `/save` stores and decodes
  the photo before its transaction (M-91). A photo add locks the item first, so adds at once stay within
  `max_photos` (P6). An edit replaces the photo only if it still shows the file it read (compare-and-swap); the
  other request of a double submit drops its own files (M-90). `tests/Unit/Ci4PhotoRacesTest.php` sends these
  requests at once and `ci4/tests/Catalog/PhotoRepositoryTest.php` interleaves them. The lend buttons answer
  since step 13 (below).
- Bulk, merge, duplicate, quantity and attachments (step 11b, #108): POST `/item/{id}/qty`, `/item/{id}/duplicate`,
  GET/POST `/item/{id}/merge`, POST `/bulk` and the attachment routes answer as legacy does, with the same tables
  and files (`tests/Unit/Ci4BulkMergeParityTest.php`, SQLite and PostgreSQL, failed writes included). New module
  `BulkMerge`; attachments are Catalog's `Items\Attachments`. Duplicate copies photos through `ImageStore::copy()`.
- Labels, barcode and lending (step 13, first part, #114): GET `/labels` (all locations, one location, with its
  sub-locations, per item, or `?ids=` from the list), `/item/{id}/label`, `/barcode` and `/barcode/{code}`, POST
  `/item/{id}/lend` and `/item/{id}/return`, and `/lent` answer as legacy does, QR codes included
  (`tests/Unit/Ci4LabelsLendingParityTest.php`, SQLite and PostgreSQL, failed writes included;
  `Ci4LabelsQrParityTest` compares the SVG with legacy `qr_svg()` and decodes it with zbarimg). New modules `Labels`
  (`QrCode`, legacy's encoder; `QrJit::guard()` turns function JIT off before it loads) and `Lending`; the lookup is
  Catalog's `Barcode`. Every path under `/barcode/` is the lookup (`LegacyPathRouter`), as on legacy. Lend and
  return lock the item first (#123).
- Stocktake (step 13, second part, #115, built on #114): GET/POST `/stocktake` and `/stocktake/{id}` with `/check`,
  `/report`, `/finish` and `/cancel` answer as legacy does, fetch JSON included, with the same `stocktakes` and
  `stocktake_lines` rows (`tests/Unit/Ci4StocktakeParityTest.php`, SQLite and PostgreSQL, failed writes included).
  New module `Stocktake`; looking is `view`, changes `stocktake`, finishing with moves also `move`. Finish and
  cancel close it only while it is open; the second one answers 409 (#123), and so does a late tick (#128).
  `ci4/tests/Lending` and `ci4/tests/Stocktake` hold a transaction open in another process (`TestDatabase::holdInAnotherClient()`).
- CSV import and export (step 14, first part, [#127](https://github.com/thordin9/apotheke/pull/127)): GET `/export.csv`, GET/POST `/import` and
  `/import/{id}` with `/preview` and `/commit` answer as legacy does, with the same rows and the same waiting upload
  in `DATA_DIR/tmp` (`tests/Unit/Ci4ImportExportParityTest.php`, SQLite and PostgreSQL, failed writes included).
  New module `ImportExport`; rows are saved through Catalog, Taxonomy and Lending. A run locks the import first; a
  second submit or a preview of one import waits, finds it done and shows the done page (`Ci4ImportRaceTest`, #131).
  The export streams its lines (`Core\Http\StreamedResponse`, batches of 500; a cursor in a read-only snapshot on
  PostgreSQL), so memory stays flat (`ExportMemoryTest`). Uploads left waiting for a day lose their file (#131).
- Backup and restore (step 14, second part, [#133](https://github.com/thordin9/apotheke/pull/133)): GET `/backup`, `/backup.zip`, `/backup/restore/check`,
  POST `/backup/restore` (dry run) and `/backup/restore/confirm`, and `bin/backup.php` / `bin/restore.php`
  (`spark apotheke:backup|restore`) answer as legacy does, with the same zip entries, tables and files; a zip from
  either app restores on the other (`Ci4BackupParityTest`, `Ci4BackupCliTest`, SQLite and PostgreSQL). New module
  `Backup`. A restore takes the write lock first, so a concurrent write waits and is replaced (`Ci4BackupRaceTest`);
  `backup.json` is written row by row (`BackupMemoryTest`, 24 MB limit).
- Playwright on CI4: `E2E_APP=ci4` serves the specs from `ci4/public`. CI runs `list`, `search`, `saved-filters`,
  `favourites`, `value`, `manage`, `locations-nested`, `theme`, `errors`, `originals`, `branding`, `add`, `item`,
  `undo`, `history`, `expiry`, `photos`, `bulk`, `merge`, `duplicate`, `stock`, `attachments`, `users`, `roles`,
  `labels`, `barcode`, `lending`, `stocktake`, `import`, `data`, `auth`, `qa-fixes` and `qa-v2` (#134, N-477)
  on CI4 in the PHP 8.4 e2e cells, after the unchanged legacy run. A test
  that needs an unported page skips (`CI4_UNPORTED` in `tests/visual/fixtures.ts`, keyed by spec file and describe ›
  title); remove its entry when the page lands. `tests/Unit/Ci4E2eSkipListTest.php` fails on an entry whose spec,
  describe or test no longer exists (N-85), or that this list does not name:
  - `labels`: print labels for a selection; sizes from the Control Panel @smoke (`/settings/labels`).
  - `search`: shows the engine in System (`/settings/system`).
  - Not on CI4 yet: the visual screenshot specs, and the specs of pages not ported (control panel).
- Permissions: a required filter applies legacy's per-route check (`user_can()`) to every CI4 request, before CSRF,
  with legacy's 401, 303 and 403 answers. The permission comes from legacy's own path, the one CI4 routes (N-70):
  each route's `permission` option (a route without one is refused; `/save` adds legacy's edit-or-create rule), or
  legacy `route_perm()` where no route matches or the route redirects. Routes marked `none` (health, branding) skip
  the check only on their exact path. The built-in roles stand in only when the
  `roles` table cannot be read (a database error), as on legacy.
- Sessions: native `apothekesid` files, started on demand. CI4 applies `session_idle_hours` and `session_days` from
  sign-in, and each account has a session epoch (migration 0028), as `main` does.
- Stored secrets (S-L5): with `APP_KEY` or `APP_KEY_FILE` set, CI4 seals the AI autofill token in the same `enc:v1:`
  format as `main`, so both apps read one database. `key generate` and `key rotate` stay in `bin/settings.php`.
- Media: `ImageStore::store()` keeps the upload byte-identical under `ORIGINALS_DIR` (`<64hex>.<ext>`, `map/<32hex>`,
  0640) and writes `images/` and `thumbs/` with the same bytes as legacy. It applies `main`'s decode limits from #72 and #79
  (`ImageLimits`) and removes everything again when a decode fails. If the request dies first (a fatal error), the
  per-request `Core\Http\RequestCleanup` removes it: the exception handler runs it before it exits, and the
  `requestcleanup` after filter runs it at the end of every request; a failing step is logged with its key and class
  only. Settings saves go through `Core\Database\Writes` (M-25), as Taxonomy's writes do. All bytes go through
  `Core\Storage`. GET `/media` serves only served names. The item form and the photo routes use it; copies for
  duplicate/merge come with 11b.
- Local test databases: the credential tests need a PostgreSQL role with `CREATEROLE` (and on PostgreSQL 16+
  `createrole_self_grant = 'set, inherit'`), see [testing](testing.md) (N-71).
- Sign-in (step 7, part A): `/login`, `/register`, `/logout`, `/account`, `/account/signout` byte-identical to
  legacy (rate limits, first admin, new session ID, `keepSignIn`, CSRF checked in the controller). Failed sign-ins
  answer at a fixed deadline (`SIGN_IN_FAIL_MS`, 1 s by default), and so do a wrong current password and a failed check to
  turn sign-in on (L-310); null-character passwords never match and are refused (422).
- Users & sign-in (step 7, part B, first half): `/settings/security` with its settings, `/settings/password`,
  `/settings/users…` and `/settings/roles…` byte-identical to legacy (`tests/Unit/Ci4UsersRolesParityTest.php`).
- CLI account forms (step 7, part B, second half): `bin/settings.php users|user|roles|auth|password` run as
  `spark apotheke:accounts` with `APOTHEKE_APP=ci4` (`tests/Unit/Ci4AccountsCliTest.php`); `auth status` stays in
  `bin/settings.php` (no migration).
- Not ported yet: the Control Panel pages (`/settings/labels` …).

## Pull requests

- [#89](https://github.com/thordin9/apotheke/pull/89): stored-secret encryption for CI4 (S-L5). Merged.
- [#90](https://github.com/thordin9/apotheke/pull/90): the CI4 session port. Merged.
- [#85](https://github.com/thordin9/apotheke/pull/85): sync `main` v2.6.13 into `feature/ci4`. Merged.
- [#91](https://github.com/thordin9/apotheke/pull/91): PLAN PR 7, the Permission filter for CI4 routes. Merged.
- [#92](https://github.com/thordin9/apotheke/pull/92): sync `main` v2.6.14 (#87, #88) into `feature/ci4`. Merged.
- [#93](https://github.com/thordin9/apotheke/pull/93): follow-ups from the #89 and #91 reviews. Merged.
- [#94](https://github.com/thordin9/apotheke/pull/94): the Layout module, plus #93 N-16 and N-17. Merged.
- [#95](https://github.com/thordin9/apotheke/pull/95): PLAN step 10, media storage. Merged.
- [#96](https://github.com/thordin9/apotheke/pull/96): PLAN step 8, taxonomy (locations, categories, tags), plus N-18
  and N-19. Merged.
- [#97](https://github.com/thordin9/apotheke/pull/97): #95/#96 follow-ups N-30, N-31, M-25 for Settings. Merged.
- [#98](https://github.com/thordin9/apotheke/pull/98): PLAN step 8, Notes (the Markdown renderer), plus N-32. Merged.
- [#99](https://github.com/thordin9/apotheke/pull/99): PLAN step 9, catalog, plus #97 N-33 to N-36. Merged.
- [#100](https://github.com/thordin9/apotheke/pull/100): PLAN step 7, sign-in, register and account pages, rate
  limits, failed sign-in pacing, plus N-38. Merged.
- [#101](https://github.com/thordin9/apotheke/pull/101): Playwright on CI4, exact-path routing (N-51). Merged.
- [#102](https://github.com/thordin9/apotheke/pull/102): PLAN step 11a (first part), item pages, undo, activity,
  plus #101 N-70 to N-75. Merged.
- [#103](https://github.com/thordin9/apotheke/pull/103): PLAN step 11a part 2, the photo routes, plus #102 L-80,
  L-81 and N-85. Merged.
- [#104](https://github.com/thordin9/apotheke/pull/104): PLAN step 7 part B (first half), the users and roles pages.
  Merged.
- [#105](https://github.com/thordin9/apotheke/pull/105): PLAN step 7 part B (second half), the CLI account forms,
  plus N-111. Merged.
- [#108](https://github.com/thordin9/apotheke/pull/108): PLAN step 11b, bulk, merge, duplicate, quantity and
  attachments, plus #104 N-112 (`users` and `roles` on CI4). Merged.
- [#109](https://github.com/thordin9/apotheke/pull/109): CI4 port of #106's sign-in limits: the attempt is
  recorded before the count (L-61) and the per-address cap (`login_address`, L-66). Merged.
- [#113](https://github.com/thordin9/apotheke/pull/113): sync `main` v2.6.17 (#111, #112) and port their CI4
  counterparts (L-120, N-124, N-125, N-174, L-170 for turning sign-in on, N-126), plus N-160 and N-162. Merged.
- [#110](https://github.com/thordin9/apotheke/pull/110): CI4 port of #106 for accounts: the current-password limit,
  per account and per address (L-170), the last-admin lock (L-110; role edits take it first, also on SQLite), the
  `APP_PASSWORD` null-character check and SIGTERM at the CLI prompt (N-133). Merged.
- [#117](https://github.com/thordin9/apotheke/pull/117): sync `main` v2.6.18 (#116) and its CI4 counterpart (N-221,
  null-character sign-in names checked before trimming). Merged.
- [#118](https://github.com/thordin9/apotheke/pull/118): PLAN step 12, autofill reads stored photos through Media
  (`ImageStore::analysisBytes()`: the kept original, else the served JPEG). Live check 1 run by QA after the PM's
  go-ahead (passed, 11.75 Neurons). Merged.
- [#114](https://github.com/thordin9/apotheke/pull/114): PLAN step 13 (first part), QR labels, barcode lookup and
  lending. Merged.
- [#115](https://github.com/thordin9/apotheke/pull/115): PLAN step 13 (second part), stocktake; built on #114. Merged.
- [#119](https://github.com/thordin9/apotheke/pull/119): #118 follow-up L-270: EXIF orientation is read from a memory
  stream, so a rotated stored photo goes out upright with `allow_url_fopen` off. Merged.
- [#120](https://github.com/thordin9/apotheke/pull/120): QA notes. N-261: register, admin-add and rename refuse a
  user name with a null character before trimming (stricter than legacy until main's N-251). N-280: the EXIF test
  checks corner colours for orientations 2-8. N-281: the stub helpers use curl, and CI runs the Autofill and Media
  suites with `allow_url_fopen` off. Merged.
- [#124](https://github.com/thordin9/apotheke/pull/124): sync `main` v2.6.20 (#121, #122) and the CI4 counterparts:
  N-304 (turning sign-in on always spends one password check), L-310 and L-330 (every refusal there and a wrong
  current password answer at the sign-in deadline), the null-name parity check, N-290 and N-291. Merged.
- [#123](https://github.com/thordin9/apotheke/pull/123): #114/#115 QA notes: lend/return lock first (L-230),
  stocktake finish/cancel only while open (L-240), borrower name without control characters (N-234), test fixes
  N-211, N-232/N-243, N-233, and the Routes comment (N-242). Merged.
- [#125](https://github.com/thordin9/apotheke/pull/125): N-63, `/login`, `/register` and `/account` show their page
  for PUT, PATCH and DELETE as legacy does (`/account` still needs the CSRF token there). Merged.
- [#127](https://github.com/thordin9/apotheke/pull/127): PLAN step 14, first part (CSV import and export). Merged.
- [#128](https://github.com/thordin9/apotheke/pull/128): QA notes batch 3. A stocktake tick changes lines only while it
  is open, with the write lock first (L-340, N-341); `/register` PUT/PATCH/DELETE with registration open (N-351); the
  auth pages ignore `_method` (N-352) and answer every method CI4 routes (N-353); cookie Max-Age settled in the
  parity tests. Merged.
- [#129](https://github.com/thordin9/apotheke/pull/129): sync `main` v2.6.21 and v2.6.22 (#126: the legacy port of L-230,
  L-240, N-234 and L-340, `SIGN_IN_FAIL_MS`, the shared migrate connection; #130: the legacy CSV import lock). `ApothekeConfig` loads `sign_in_fail_ms` as legacy does. Merged.
- [#131](https://github.com/thordin9/apotheke/pull/131): #127 QA notes: streamed export (L-370), CSRF-first answers
  recorded (N-371), PUT/PATCH/DELETE on the import pages (N-372), a waiting preview shows the done page (N-373),
  stale uploads swept (N-374), TAB/CR cells tested (N-375). Merged at 57eae25.
- [#132](https://github.com/thordin9/apotheke/pull/132): PLAN step 15, the Docker image on PHP 8.4 (the shipped
  default now, legacy and CI4 with `APOTHEKE_APP=ci4`), with the #75 port (localhost bind, sign-in required at
  start), L-400 (CI4 reads `SIGN_IN_FAIL_MS`), L-430 (entrypoint data-directory hardening) and N-432 tests. Merged at
  7e927f7.
- [#133](https://github.com/thordin9/apotheke/pull/133): PLAN step 14, second part (backup and restore), with
  L-440 and the restore lock (L-450, L-455, N-456). Merged at 31de273.
- [#134](https://github.com/thordin9/apotheke/pull/134): PLAN step 16 part 1, the parity gate (196 steps, 0 differences on SQLite and PostgreSQL;
  p95 reported, +20 % not met on light pages; CI enforces a temporary budget of legacy + 8 ms on SQLite and + 20 ms
  on PostgreSQL, on the median gap). Merged at 27c422b.
- [#135](https://github.com/thordin9/apotheke/pull/135) (`main`): the legacy port of L-440 and the restore lock.
  Merged into `main` at e8cf10d, released as v2.6.23.
- [#136](https://github.com/thordin9/apotheke/pull/136): streaming restore (L-441, L-451) and the #133 and #135 QA
  restore notes (N-442 to N-459, L-480, N-481). Merged at 0173924.
- [#137](https://github.com/thordin9/apotheke/pull/137): boot cost (L-472): one database login per request, cheaper
  PostgreSQL schema checks, a lighter CI4 boot; PostgreSQL meets +20 % locally, SQLite does not yet. Merged at
  0d801b7.
- [#139](https://github.com/thordin9/apotheke/pull/139): sync `main` v2.6.23 (#135: the legacy restore lock and
  failed-restore cleanup in `src/backup.php` and `public/index.php`). CI4 restore code unchanged. Merged at 3660252.
- [#142](https://github.com/thordin9/apotheke/pull/142): a restore makes `files/` with legacy's mode (0770 before the
  umask; it was 0700); a link at a camera map name is skipped and the other map entries are still written; and the
  #141 parity items: a link at a camera file's name stops the restore (L-560), the lock's stat cache is cleared before
  its open (N-562), and writes check flush and close. A replaced file keeps its mode, and a restore stopped by a fatal
  error removes its files. #143's three link tests and its 64M fatal-error test pass on it. Merged at 537684b.
- [#146](https://github.com/thordin9/apotheke/pull/146): the restore command line started as root runs as the
  owner of `DATA_DIR` (`Backup\Commands\DataDirOwner`, in `bin/restore.php`, in `ci4/spark` before boot for
  `apotheke:restore` only, and in `RestoreCommand`), and refuses with
  exit 1 when `DATA_DIR` is root's; storage no longer changes a file's owner or mode after creating it. Merged at
  b12fbbb.
- [#141](https://github.com/thordin9/apotheke/pull/141) (`main`): the restore follow-up (L-480, N-481 to N-486, the
  camera link map through a temp file, L-560, L-561, N-562, N-564). Merged into `main` at 3f81db2, released as
  v2.6.24.
- [#140](https://github.com/thordin9/apotheke/pull/140): PLAN step 16 part 2, the upgrade and rollback gate
  (`scripts/upgrade-rollback.sh`, CI job `upgrade-rollback`, SQLite and PostgreSQL): legacy@8.3 → CI4@8.4 →
  legacy@8.3 on one data directory and database, and cross-restore both ways; DB dump and file sha256 equal. Merged at
  b1bc52d.
- [#143](https://github.com/thordin9/apotheke/pull/143): sync `main` v2.6.24 (#141: the legacy restore entry cap,
  no writes through links, short-write checks and the camera link map written through a temp file in `src/backup.php`)
  and v2.6.25 (#145: the legacy CLI restore runs as the owner of `DATA_DIR`, ported to CI4 in #146). CI4 restore code
  unchanged. Merged at ed06bb2.
- [#150](https://github.com/thordin9/apotheke/pull/150) (replaces #144): step 16 part 3: compiled routes, no file cache
  handler, the layout menu reads the user once; CI enforces PostgreSQL p95 +20 % and SQLite median + 3 ms (decision by
  Stathis, 2026-10-27, via the PM), timing at least 200 requests per area; N-546. Merged at 24830e7; step 16 is done.
- [#153](https://github.com/thordin9/apotheke/pull/153): CI allowlist for a historic Control Panel test placeholder
  (with [#152](https://github.com/thordin9/apotheke/pull/152) on `main`). Merged at 767126a.

## Lanes

- Coder: step 10 media storage (#95, merged); follow-ups N-30, N-31 and M-25 for Settings (#97, merged); step 9
  catalog (#99); Playwright on CI4 (#101); step 11a item pages (#102, merged); step 11a part 2, the photo routes
  (#103, merged). Stays out of the auth, account, users/roles, taxonomy and Notes files
  (only the route table in `PermissionFilterTest` gets the new photo routes). #106 port to CI4: `RateLimiter::hit`
  records first, the per-address cap on `/login` (#109, merged); #111/#112 port (#113, merged): IPv6 /64 address
  scope, a success takes back only its own address row, turning sign-in on is limited, replaced covers removed after
  the outer commit. Coder2 ports the current-password throttle with its per-address cap (L-170), the last-admin lock
  and the `APP_PASSWORD` null-character check (#110). `HardeningHttpTest` has no CI4 skips left.
  #116 port (#117, merged): null-character sign-in names refused before trimming on `/login` and turning sign-in on.
  Step 12, autofill client: stored photos through Media (#118, merged; live check 1 passed); L-270 (#119, merged); QA notes N-261, N-280, N-281 (#120, merged); sync v2.6.20 (#124, merged); sync v2.6.21 and v2.6.22 (#129, merged).
  Touches `Media\ImageStore` and the Autofill module only; #124 also touches Coder2's `Accounts`, `SecurityController`
  and `SignInAccountsTest` (listed in the PR). Step 15, the Docker image (#132, merged): `Dockerfile`, `docker/`, the
  docker scripts and CI job, `Config\Services::accounts()` and its `SignInAccountsTest` case. Stays out of the step 14
  import/export and Backup files. Step 16 part 1, the parity gate (#134, merged at 27c422b): `tests/parity/gate.php`,
  `scripts/parity-gate.php`, the `parity-gate` CI job, and the three CI4 fixes it found (autofill Cache-Control,
  attachments directory mode, PostgreSQL undo snapshot types). Stays out of Backup; part 2 is unblocked (#133 is merged).
  Sync v2.6.23 (#139, merged). Step 16 part 2 (#140, merged). Step 16 part 3 (#150, merged; step 16 done): compiled
  routes (`CompiledRouteCollection`, `app/Config/RoutesCompiled.php`, `spark apotheke:routes`), the cache handler, the
  parity-gate budget lines in `ci.yml`, and N-546 in `scripts/upgrade-rollback.sh`.
  Step 17, the docs rewrite ([#154](https://github.com/thordin9/apotheke/pull/154), open): `docs/upgrade-3.0.md` (new), the CI4
  sections of the architecture, getting-started, configuration, database, deployment, control-panel and CI pages,
  README and the docs index. Docs only; stays out of code.
- Coder2: step 7, sign-in and account pages (#100, merged), users and roles pages (#104, merged), CLI account
  forms (#105, merged); step 11b (#108, merged); #106 port for accounts (#110, merged); step 13 labels, barcode,
  lending (#114, merged) and stocktake (#115, merged); their QA follow-ups (#123, merged); N-63 (#125, merged);
  step 14 part 1, CSV import and export (#127, merged); QA notes batch 3 (#128, merged); the step 14
  follow-ups (#131, merged at 57eae25); step 14 part 2, backup and restore (#133, merged); streaming restore and its QA notes (#136, merged); restored folder modes (#142, merged); `main` restore follow-up (#141, merged into `main` at 3f81db2, v2.6.24);
  CLI restore as the data-dir owner (#145 on `main`, v2.6.25; #146, merged at b12fbbb). QA notes batch 4, part A
  ([#147](https://github.com/thordin9/apotheke/pull/147), open): `Config/Routes.php` (the auth and import blocks),
  `Core/Http/WebRoutes.php`, `CsvExport.php`, `CsvExportTest`, `PermissionFilterTest`, `Ci4ImportExportParityTest`,
  `Ci4ImportRaceTest`, `tests/lib.php`. Part B (restore and CLI) and a `main` PR follow.
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, the AGENTS.md module graph):
  append your own block in a small hunk; do not reorder or reformat other blocks. Merge `feature/ci4` in (no rebase)
  before pushing when it has moved.
- Coder2, QA notes batch 4 part B ([#149](https://github.com/thordin9/apotheke/pull/149), open): `Backup/Restorer.php`,
  `Backup/Commands/DataDirOwner.php`, `Backup/Commands/BackupCli.php`, `Backup/Controllers/BackupController.php`,
  `ci4/spark`, the legacy copies `bin/restore.php` and `public/index.php` (same change as #148 on `main`),
  `BackupHelpersTest`, `Ci4BackupRestoreChecksTest`, `BackupFailureTest`. Overlap: `ci4/spark` and the legacy copies
  with Coder's syncs; part A is #147, the `main` notes are #148.

## Next steps

1. #133 (step 14's backup and restore) is merged (31de273); the docker scripts run their restore checks on CI4 too
   (N-431). #135 is merged into `main` (e8cf10d, v2.6.23). #136 (streaming restore) is merged (0173924). #141 (`main`
   follow-up) is merged into `main` (3f81db2, v2.6.24); #142 and #146 are merged (b12fbbb). Now: QA notes batch 4,
   part A (#147: N-380, N-381, N-420 to N-422, N-424), then part B (restore and CLI notes) and the `main` notes PR. Coder's step 16 part 3 (#150, merged) made the `files/` mode a hard check in the
   upgrade/rollback job. Coder's v2.6.23 sync (#139) is merged (3660252) and merged into #142; Coder's v2.6.24 sync is #143.
2. #129 (sync v2.6.22) is merged. The legacy port of L-230, L-240, N-234, L-340 and N-341 is done on `main` by
   [#126](https://github.com/thordin9/apotheke/pull/126) (v2.6.21): a stocktake tick's first write is
   `UPDATE … WHERE status = 'open'`, so a late tick gets 409 and changes no line.
3. Deferred (PM call: `main` gets urgent fixes only): porting to legacy on `main` the done check for a waiting preview
   (N-373) and the stale-upload sweep (N-374) from [#131](https://github.com/thordin9/apotheke/pull/131).
4. After #135: the restore settings permission (N-443) and the streamed restore stay CI4-only unless the PM decides
   to port them to `main`.
5. Later: pin the `composer` build stage by digest, not only by tag (N-433).
6. Later, #132 QA nits (deferred): the exit-78 message for a linked data directory should name `ORIGINALS_DIR` or
   mounting the real directory (N-436); the window between the entrypoint's link checks and its `mkdir`, `chmod` and
   `chown -h` (needs a concurrent `www-data` process at start; not reproduced, N-437); a runtime test for `chown -h`
   and for the PHP 8.3 refusal path, which CI no longer builds (N-438).
7. Step 16 part 2 (upgrade/rollback gate) is merged (#140). The `files/` mode check is hard since #150 (after #142, Coder2's
   `files/` 0750 fix and the Restorer port). The parity gate marks two autofill steps `pending` (the html
   fragment of the Control Panel page); drop the marks when that page is ported.
8. Step 16 done criterion (decision by Stathis, 2026-10-27, via the PM): the `parity-gate` job passes with
   PostgreSQL p95 within +20 % of legacy per area and SQLite median within + 3 ms per area (PostgreSQL keeps its
   + 20 ms median guard). On SQLite, CI4's database work alone costs about as much as legacy's whole request, so
   +20 % is not reachable there without bypassing the framework. Part 3 (compiled routes, #150) sets this in `ci.yml`;
   step 16 is done (#150 merged at 24830e7).
9. #134 QA review: M-470 and L-471 are fixed in #134 (the gate compares every Set-Cookie in full, the security
   headers and Cache-Control exactly but for one documented pair; password hashes keep algorithm and cost, and the
   users the app created sign in). N-475, N-476 and N-477 are folded in. Deferred: N-473 and N-474 (as in QA's
   #134 report), and L-472, which is the boot-cost lane in item 8.
   Boot cost (#137, L-472): `BootMigration` checks the schema on CI4's connection (one login per request,
   `Ci4OneConnectionTest`); the runner connects only to migrate. Local gate: PostgreSQL within +20 % p95 in every
   area; SQLite still +64 to +144 % in 8 areas (about 1 to 2 ms of framework boot at the median: route table,
   request and response, filters, configs), a further step. #137 is merged; the temporary budget is replaced by the item 8
   bar (#150).
10. Then the Control Panel pages.
11. The Control Panel settings pages port is Coder2's (in parallel with step 17) and blocks step 18 (cutover).
12. After step 18: the `release/2.5` patch-only flow in `docs/releasing.md` and its `release.yml` guard (owner: Apotheke Coder).

## Tracked

- Backup and restore (#133), where CI4 is stricter than legacy; no change unless the PM decides otherwise:
  a failed restore shows a general message without the database's own text (page and CLI); a zip that holds two
  entries of one name is refused; a missing thumbnail is drawn again only within Media's decode limits; restored
  files are written through `Core\Storage`; on PostgreSQL the restore locks the catalog tables before it deletes;
  `bin/backup.php` to a path it cannot write prints `Cannot write …` and exits 1. Restore is streamed (#136):
  `backup.json` a value at a time, each entry through a temp file, at most 200 MiB per entry; backup reads `DATA_DIR` and `ORIGINALS_DIR` directly
  (Cloudflare phase: move behind `Storage`).
- Restore files (#133, L-440), stricter than legacy: a CI4 restore that fails at any point (an entry that cannot be
  read or written, a database error) removes the files it created and rewrites the camera-original links only
  after the rows commit, so files and links stay as they were. Legacy keeps the files and the new links; pinned in
  `Ci4BackupParityTest`, for a later `main` PR. Restore memory (L-441, L-451, #136): memory no longer grows
  with the backup; only one value larger than the memory left is refused ("too large to restore within the memory
  limit", 422 or exit 1), where legacy answers 500.
- Restore lock (#133, L-450), CI4 only: one restore runs at a time, web and CLI alike, behind an exclusive lock on
  `DATA_DIR/tmp/restore.lock` held for the files and the rows. A second restore started meanwhile is refused at once
  with "Another restore is running. Try again when it has finished." (409 on the page, which keeps the upload so it
  can be confirmed again; exit 1 in `bin/restore.php`). The lock opens read-only when another user made the file (L-455) and refuses a link (N-456), also when it is swapped in while it is opened, with a message that names the file
  (N-458, N-459). Legacy gets the lock with #135.
- Restore on CI4 (#136), stricter than legacy: the backup's settings are restored only for a user who also has the
  settings permission (the page says they were skipped; `bin/restore.php` restores them, N-443); `true`/`false` are
  refused as table values (N-448); on PostgreSQL a lost deadlock is tried again, then refused with a message (N-445).
- Sign-in deadline setting: both apps pace with `SIGN_IN_FAIL_MS` (#126; CI4 since #132, L-400), default 1000 ms,
  clamped to 400–10000; CI4's `signin.failDeadlineMs` / `signin_failDeadlineMs` are gone. Legacy starts the deadline
  at the request's start, CI4 at the controller's.
- Import pages (#131): CI4 checks the CSRF token first, so a tokenless POST to a done import or to `/import/{id}`
  answers 419 (legacy 303 and 404), and PUT, PATCH and DELETE on `/import` need the token (legacy shows the page).
  Stricter; no change. Until the `main` port (Next steps), legacy keeps waiting uploads and answers 410 to a preview
  that raced a submit.
- `/account` with PUT, PATCH or DELETE (#125, N-63): CI4 wants the CSRF token, as on every non-safe method, where
  legacy shows the page without it. Stricter; no change. The same holds for CONNECT (#128).
- Auth pages with a method CI4 cannot route (#128, N-353): WebDAV methods such as PROPFIND get 404 on CI4, where
  legacy shows the page or redirects. CI4's router knows only the standard methods; pinned in `Ci4AuthParityTest`.
- Legacy photo edit race (#103 M-90): on `main`, two crop/rotate requests for one photo at once can commit a photo
  without its original, and `image_original_copy()` succeeds when the original is gone. CI4 compares and swaps
  `item_photos.file` and fails the copy. Candidate for the next `main` hardening PR.
- Photo edit routes check CSRF before the item/photo 404 (#103 N-93): CI4 answers 419 where legacy answers 404,
  so CI4 is stricter and does not reveal whether the item exists. No change.
- Edit undo tokens keep old photo files (#103 N-94): a file kept for an edit token is not in the token's
  `files`, so it stays after the token expires. Same on legacy; disk use only. Fix on both together.
- R2 readiness (#103 N-95): served files and originals go through `Core\Storage`. Still local: `ImagickDecoder`'s
  temp file. `UndoTokens::holds()` is a `LIKE` scan. Storage has no compare-and-swap, so the photo edit relies
  on the database row (M-90).

Backlog: legacy dev server: tighten request path handling (dev-only, not scheduled; `main` takes urgent fixes only).
Markdown: links containing emphasis characters render incorrectly; later joint fix in main and CI4 (hold bare links in placeholders before emphasis).

Cloudflare phase: keep storage behind interfaces, no per-request global state, and the database config from a DSN.
The at-rest key comes in through `SecretKey`, so a Worker secret binding can replace the environment and key file.
