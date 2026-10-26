# Hand-off: `feature/ci4`

A one-screen status of the CodeIgniter 4 port. Every pull request into `feature/ci4` updates this page. Plan and module
rules: [PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [AGENTS.md](../AGENTS.md).

## Current state

- `feature/ci4` carries `main` up to v2.6.14 and the docs trim [#88](https://github.com/thordin9/apotheke/pull/88)
  (sync [#92](https://github.com/thordin9/apotheke/pull/92)), including the legacy session limits, `user signout NAME`
  and #87's `keepSignIn` rule. The shipped image still runs the legacy app; `APOTHEKE_APP=ci4` selects CI4 in CI and
  in development.
- CI4 modules: Core (config, DSN, filters, storage, secret sealing), Migrations (PDO runner, 28 migrations shared with
  `main`), Settings (schema, service, branding, `apotheke:settings`), Autofill (client and its four POST routes), Auth
  (current user, admin guards, session epoch, sign out everywhere, the Permission filter, the sign-in and account pages), Layout (page
  shell, menu, simple header, icons, toasts, POST `/theme`, the error page), Media (originals,
  served copy and thumbnail, HEIC placeholder, decode limits, GET `/media`), Taxonomy and Activity, Catalog, Search
  and Favourites (below).
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
  Catalog's `Barcode`. Every path under `/barcode/` is the lookup (`LegacyPathRouter`), as on legacy.
- Stocktake (step 13, second part, #MM, built on #114): GET/POST `/stocktake` and `/stocktake/{id}` with `/check`,
  `/report`, `/finish` and `/cancel` answer as legacy does, fetch JSON included, with the same `stocktakes` and
  `stocktake_lines` rows (`tests/Unit/Ci4StocktakeParityTest.php`, SQLite and PostgreSQL, failed writes included).
  New module `Stocktake`; looking is `view`, changes `stocktake`, finishing with moves also `move`.
- Playwright on CI4: `E2E_APP=ci4` serves the specs from `ci4/public`. CI runs `list`, `search`, `saved-filters`,
  `favourites`, `value`, `manage`, `locations-nested`, `theme`, `errors`, `originals`, `branding`, `add`, `item`,
  `undo`, `history`, `expiry`, `photos`, `bulk`, `merge`, `duplicate`, `stock`, `attachments`, `users`, `roles`,
  `labels`, `barcode`, `lending` and `stocktake`
  on CI4 in the PHP 8.4 e2e cells, after the unchanged legacy run. A test
  that needs an unported page skips (`CI4_UNPORTED` in `tests/visual/fixtures.ts`, keyed by spec file and describe ›
  title); remove its entry when the page lands. `tests/Unit/Ci4E2eSkipListTest.php` fails on an entry whose spec,
  describe or test no longer exists (N-85), or that this list does not name:
  - `labels`: print labels for a selection; sizes from the Control Panel @smoke (`/settings/labels`).
  - `search`: shows the engine in System (`/settings/system`).
  - Not on CI4 yet: the visual screenshot specs, and the specs of pages not ported (auth, control panel,
    import/export, backup).
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
  answer at a fixed deadline (`Config\SignIn`, 1 s); null-character passwords never match and are refused (422).
- Users & sign-in (step 7, part B, first half): `/settings/security` with its settings, `/settings/password`,
  `/settings/users…` and `/settings/roles…` byte-identical to legacy (`tests/Unit/Ci4UsersRolesParityTest.php`).
- CLI account forms (step 7, part B, second half): `bin/settings.php users|user|roles|auth|password` run as
  `spark apotheke:accounts` with `APOTHEKE_APP=ci4` (`tests/Unit/Ci4AccountsCliTest.php`); `auth status` stays in
  `bin/settings.php` (no migration).
- Not ported yet: the Control Panel pages (`/settings/labels` …), import/export and Backup.

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
- [#110](https://github.com/thordin9/apotheke/pull/110): CI4 port of #106's account hardening (current-password
  throttle, last admin, `APP_PASSWORD`). Open.
- [#114](https://github.com/thordin9/apotheke/pull/114): PLAN step 13 (first part), QR labels, barcode lookup and
  lending. Open.
- [#MM](https://github.com/thordin9/apotheke/pull/MM): PLAN step 13 (second part), stocktake; built on #114, merge
  after it. Open.

## Lanes

- Coder: step 10 media storage (#95, merged); follow-ups N-30, N-31 and M-25 for Settings (#97, merged); step 9
  catalog (#99); Playwright on CI4 (#101); step 11a item pages (#102, merged); step 11a part 2, the photo routes
  (#103, merged). Stays out of the auth, account, users/roles, taxonomy and Notes files
  (only the route table in `PermissionFilterTest` gets the new photo routes). #106 port to CI4: `RateLimiter::hit`
  records first, the per-address cap on `/login` (#109); Coder2 ports the current-password throttle, the last-admin
  lock and the `APP_PASSWORD` null-character check (still skipped on CI4 in `HardeningHttpTest`).
- Coder2: step 7, sign-in and account pages (#100, merged), users and roles pages (#104, merged), CLI account
  forms (#105, merged); step 11b (#108, merged); #106 port (#110); step 13 labels, barcode, lending (#114) and
  stocktake (#MM). Stays out of the Auth files of the #106 hardening port. Owns the
  N-63 router follow-up below.
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, the AGENTS.md module graph):
  append your own block in a small hunk; do not reorder or reformat other blocks. Merge `feature/ci4` in (no rebase)
  before pushing when it has moved.

## Next steps

1. Review and merge #110, then #114 and #MM (step 13; #MM is built on #114).
2. Port the Docker bind and app-password start-up hardening from `main` [#75](https://github.com/thordin9/apotheke/pull/75)
   (SEC-M3) into the CI4 image.
3. Autofill reads photos through Media.
4. Port the #106 hardening to CI4.

## Tracked

- CI4 router (N-63, an N-51 follow-up): PUT, PATCH and DELETE on `/login`, `/register` and `/account` answer 404
  where legacy shows the page (stricter). Owner: Coder2 (CI4 lane). Not folded into step 13: it changes the Auth
  controllers that #110 also changes, so it gets its own small PR after #110.
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
