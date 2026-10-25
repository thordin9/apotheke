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
  cookie on the first one). POST `/bulk` waits for 11b. Acceptance: the PM accepted the legacy-vs-CI4 golden test
  (`tests/Unit/Ci4CatalogParityTest.php`) in place of the `list`, `search`, `saved-filters`, `favourites` and
  `value` specs; the specs now also run against CI4 (see Playwright on CI4). Like legacy, CI4 routes the exact
  decoded path: a trailing slash or a dot segment (`/stats/`, `/./stats`, `/%2e/stats`, `/a/../stats`) answers 404
  (N-51, `Core\Http\LegacyPathRouter`).
- Items (step 11a, first part): the item page `/item/{id}`, `/new`, `/item/{id}/edit`, POST `/save` (one photo
  through `ImageStore`, `MAX_FILE_SIZE` from `maxUpload()`), `/item/{id}/delete`, POST `/undo` with the undo bar,
  and `/activity` are byte-identical to legacy (`tests/Unit/Ci4ItemParityTest.php`, SQLite and PostgreSQL). Notes
  are drawn and edited with `MarkdownRenderer`; the Notes module is unchanged. The Undo module keeps the tokens;
  applying one is Catalog's `Items\UndoApply`. Not yet: the photo routes (add, order, cover, left/right, delete,
  edit; 11a part 2), quantity, duplicate, attachments upload and lend (11b, 13). Their buttons are drawn and
  answer 404 on CI4 until then.
- Playwright on CI4: `E2E_APP=ci4` serves the specs from `ci4/public`. CI runs `list`, `search`, `saved-filters`,
  `favourites`, `value`, `manage`, `locations-nested`, `theme`, `errors`, `originals`, `branding`, `add`, `item`,
  `undo`, `history` and `expiry` on CI4 in the PHP 8.4 e2e cells, after the unchanged legacy run. A test that needs
  an unported page skips (`CI4_UNPORTED` in `tests/visual/fixtures.ts`, keyed by spec file and describe › title);
  remove its entry when the page lands. `tests/Unit/Ci4E2eSkipListTest.php` fails on an entry whose spec or title
  no longer exists, or that this list does not name:
  - `list`: the labels page has an empty state (`/labels`); quantity +/- on a card (POST `/item/{id}/qty`, 11b).
  - `search`: shows the engine in System (`/settings/system`).
  - `item`: photos: add several, reorder, set cover, delete (11a part 2); quantity +/- on the item page (11b).
  - `undo`: bulk move, then undo; dismiss hides the bar (POST `/bulk`, 11b).
  - Not on CI4 yet: the visual screenshot specs, and the specs of pages not ported (auth, roles, users, control
    panel, photos, duplicate, bulk, merge, stock, attachments, lending, labels, import/export, backup).
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
  `Core\Storage`. GET `/media` serves only served names. The item form's upload uses it; photo order/edit and
  copies for duplicate/merge come with 11a part 2 and 11b.
- Local test databases: the credential tests need a PostgreSQL role with `CREATEROLE` (and on PostgreSQL 16+
  `createrole_self_grant = 'set, inherit'`), see [testing](testing.md) (N-71).
- Sign-in (step 7, part A): `/login`, `/register`, `/logout`, `/account`, `/account/signout` byte-identical to
  legacy (rate limits, first admin, new session ID, `keepSignIn`, CSRF checked in the controller). Failed sign-ins
  answer at a fixed deadline (`Config\SignIn`, 1 s); null-character passwords never match and are refused (422).
- Users & sign-in (step 7, part B, first half): `/settings/security` with its settings, `/settings/password`,
  `/settings/users…` and `/settings/roles…` byte-identical to legacy (`tests/Unit/Ci4UsersRolesParityTest.php`).
- Not ported yet: the CLI account forms (step 7, part B, second half), BulkMerge and Backup.

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
- [#104](https://github.com/thordin9/apotheke/pull/104): PLAN step 7 part B (first half), the users and roles pages.
  Open.

## Lanes

- Coder: step 10 media storage (#95, merged); follow-ups N-30, N-31 and M-25 for Settings (#97, merged); step 9
  catalog (#99); Playwright on CI4 (#101); step 11a item pages (#102, merged). Stays out of the
  auth, account and users/roles files; the Permission filter changed only for N-70 and `/save`.
- Coder2: step 7, sign-in and account pages (#100, merged); step 7 part B: the users and roles pages (#104, open),
  then the CLI account forms (`users`, `user`, `roles`, `auth`, `password`) in a follow-up PR.
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, the AGENTS.md module graph):
  append your own block in a small hunk; do not reorder or reformat other blocks. Merge `feature/ci4` in (no rebase)
  before pushing when it has moved.

## Next steps

1. Review and merge #104, then the CLI account forms (step 7 part B, second half).
2. Port the Docker bind and app-password start-up hardening from `main` [#75](https://github.com/thordin9/apotheke/pull/75)
   (SEC-M3) into the CI4 image.
3. PLAN 11a part 2: the photo routes (add, order, cover, left/right, delete, edit) on `ImageStore`, and the
   `photos` spec on CI4; Autofill reads photos through Media.
4. PLAN 11b: BulkMerge (bulk, merge, duplicate, quantity) and attachments.

## Tracked

- CI4 router (N-63, an N-51 follow-up): PUT, PATCH and DELETE on `/login`, `/register` and `/account` answer 404
  where legacy shows the page (stricter). Owner: Coder2 (CI4 lane).

Backlog: legacy dev server: tighten request path handling (dev-only, not scheduled; `main` takes urgent fixes only).
Markdown: links containing emphasis characters render incorrectly; later joint fix in main and CI4 (hold bare links in placeholders before emphasis).

Cloudflare phase: keep storage behind interfaces, no per-request global state, and the database config from a DSN.
The at-rest key comes in through `SecretKey`, so a Worker secret binding can replace the environment and key file.
