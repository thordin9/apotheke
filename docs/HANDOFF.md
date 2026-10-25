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
  (current user, admin guards, session epoch, sign out everywhere, the Permission filter; no pages yet), Layout (page
  shell, menu, simple header, icons, toasts, POST `/theme`, the error page), Media (originals,
  served copy and thumbnail, HEIC placeholder, decode limits, GET `/media`), Taxonomy and Activity (below).
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
- Permissions: a required filter applies legacy's per-route check (`user_can()`) to every CI4 request, before CSRF,
  with legacy's 401, 303 and 403 answers. The permission comes from the routed path: each route's `permission` option
  (a route without one is refused), or legacy `route_perm()` where no route matches or the route redirects. Routes
  marked `none` (health, branding) skip the check only on their exact path. The built-in roles stand in only when the
  `roles` table cannot be read (a database error), as on legacy.
- Sessions: native `apothekesid` files, started on demand. CI4 applies `session_idle_hours` and `session_days` from
  sign-in, and each account has a session epoch (migration 0028), as `main` does.
- Stored secrets (S-L5): with `APP_KEY` or `APP_KEY_FILE` set, CI4 seals the AI autofill token in the same `enc:v1:`
  format as `main`, so both apps read one database. `key generate` and `key rotate` stay in `bin/settings.php`.
- Media: `ImageStore::store()` keeps the upload byte-identical under `ORIGINALS_DIR` (`<64hex>.<ext>`, `map/<32hex>`,
  0640) and writes `images/` and `thumbs/` with the same bytes as legacy. It applies `main`'s decode limits from #72 and #79
  (`ImageLimits`) and removes everything again when a decode fails. If the request dies first (a fatal error), the
  per-request `Core\Http\RequestCleanup` removes it: the exception handler runs it before it exits, and the
  `requestcleanup` after filter runs it at the end of every request. All bytes go through `Core\Storage`. GET `/media`
  serves only served names. The upload routes, photo order/edit and copies for duplicate/merge come with 11a/11b.
- Not ported yet: sign-in, account and Users pages, the Items, Undo and Backup modules. When those pages
  arrive they pass `keepSignIn` to `SessionGate::signIn()` where `main` passes it to `login_session()` (sign out
  everywhere, and a new password for your own account on Account, the password page and Users).

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
- [#98](https://github.com/thordin9/apotheke/pull/98): PLAN step 8, Notes (the Markdown renderer), plus N-32. In review.

## Lanes

- Coder: step 10 media storage (#95, merged).
- Coder2: step 8 Notes (#98), then the rest of step 7 (sign-in, register, account, users and roles pages,
  rate limits), then step 9 (catalog). Step 11a waits until media and catalog are in.
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, the AGENTS.md module graph):
  append your own block in a small hunk; do not reorder or reformat other blocks. Merge `feature/ci4` in (no rebase)
  before pushing when it has moved.

## Next steps

1. The sign-in, register, account, users and roles pages and rate limits, on the Layout. Pass
   `keepSignIn` as above.
2. Port the Docker bind and app-password start-up hardening from `main` [#75](https://github.com/thordin9/apotheke/pull/75)
   (SEC-M3) into the CI4 image.
3. PLAN 11a: item form and photo routes on `ImageStore` (`MAX_FILE_SIZE` from `maxUpload()`); Autofill reads photos
   through Media.

Backlog: legacy dev server: tighten request path handling (dev-only, not scheduled; `main` takes urgent fixes only).

Cloudflare phase: keep storage behind interfaces, no per-request global state, and the database config from a DSN.
The at-rest key comes in through `SecretKey`, so a Worker secret binding can replace the environment and key file.
