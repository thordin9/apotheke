# The router, the filter chain, and the page answers (S0.5, S1.1)

Phase 0's routing skeleton — the route table, the filter chain in the PHP app's own order, problem+json
for every error, and the security headers — plus, since S1.1, the first page: `/login`, rendered byte
for byte as the PHP app renders it, and v3's own error pages back in place of the problem+json
scaffold on page paths. The sign-in itself is S1.2: `/login`, `/logout` and the consent answer
(`/consent`) answer for real, and every other page is P2, so a route without a handler still answers
501 rather than a pretend 200.

`src/http/` holds it:

| File | What it is |
|---|---|
| `routes.generated.ts` | The table: one row per method and path, with the PHP app's handler, permission and filter list. **Generated — do not edit.** |
| `route-table.ts` | Matching: a route key *is* the pattern (CI4 compiles it as one), `HEAD` looks up `GET`, the allowed verbs for a 405 |
| `chain.ts` | The filter order as data, a copy of `app/Config/Filters.php` (the parity test keeps it honest) |
| `filters.ts` | Each filter as a named object; the `DEFERRED` map and why |
| `problem.ts` | problem+json, the two header sets, `isApi`, and `html()` for a page answer |
| `router.ts` | Match → before chain → handler → after chain. That is all it does |

## Where the table comes from

`v4/scripts/gen-routes.mjs` reads the PHP app's own route artifact — `app/Config/RoutesCompiled.php`,
which `php spark apotheke:routes` writes from `app/Config/Routes.php` and which v3's own
`CompiledRoutesTest` keeps fresh — plus `app/Config/Filters.php` and `Config\Feature::$oldFilterOrder`.
It writes two files:

- `src/http/routes.generated.ts` — what the Worker imports (281 rows, 3 distinct chains);
- `test/fixtures/php-routes.json` — the same data as JSON, the oracle the workerd test compares to.

Nothing parses PHP at runtime. Regenerate after changing either PHP file:

```sh
php spark apotheke:routes     # in the repo root, if Routes.php changed
cd v4 && npm run routes       # rewrites both files
```

`npm run routes:check` is what CI runs: it regenerates in memory and fails if either committed file
differs, including when a source's sha256 has changed. So editing `Routes.php` without re-running the
compiler, or editing `Filters.php` at all, turns `v4.yml` red instead of leaving a table that quietly
disagrees with the app.

## The chain, in the app's order

CI4 with `oldFilterOrder = false` runs three passes before the controller and two after:

1. **required before**, every request, routed or not — `opsbackup`, `opsaccounts`, `headasget`,
   `harness`, `originalsguard`, `hostcheck`, `publicbase`, `api`, `routemethod`, `posttoolarge`,
   `consent`, `session`, `sameorigin`, `permission`;
2. **globals**, minus each one's `except` — `sessioncsrf` (except `login`, `register`, `logout`,
   `consent`, `claim`, `api`, `api/*`), `formfields` (except `api`, `api/*`);
3. **per-path** — `apicsrf` on `api` and `api/*`;
4. after the response: `securityheaders`, then the required after filters `requestcleanup`, `api`,
   `consent`.

Plan §5's shorthand — "`hostcheck`, `publicbase`, `session`, `sameorigin`, `permission`,
`sessioncsrf`/`apicsrf`" — is that order; the table has all twenty names.

Two consequences worth knowing:

- **`permission` is required, so it runs before the CSRF filters.** A write to a route whose role may
  not do it is refused before any token is looked at — v3's answer, not a 401: 303 to `/login` when
  nobody is signed in, 403 when the role lacks the permission. Only a route that passes the permission
  check (`none`, or the API's `public`) can reach `sessioncsrf`/`apicsrf`.
- **A refusal skips the globals after filters but not the required ones.** CI4 returns a before
  filter's response before the controller, and `CodeIgniter::run()` still runs the required after
  filters on it — which is why a refusal under `/api` carries the API headers (`api` is one of them)
  and a page refusal carries its own set from the filter that refused it.

## Fail closed, by name

| Situation | Answer | Code |
|---|---|---|
| Route needs a session (page) | 303 to `/login`; a fetch asking for JSON gets 401 JSON | — |
| Route needs a session (under `/api`) | 401 problem+json | `auth.required` |
| Role lacks the route's permission | 403, v3's error page (fetch: 403 JSON); `/api`: 403 problem+json | `permission.denied` |
| Route with no permission recorded | 403 problem+json | `permission.denied` |
| Unsafe write without the session's token (S1.3) | 419: v3's error page, or `{"ok":false,…}` for a fetch | — (`CsrfCheck`) |
| Unsafe write from another site | 403 problem+json | `origin.refused` |
| Unknown Host | 421 `text/plain`, legacy's text | — |
| Path no route serves (page) | 404, the app's error page | — |
| Path no route serves (under `/api`) | 404 problem+json | `not_found` |
| Wrong method (page) | 405, the app's error page + `Allow` | — |
| Wrong method (under `/api`, or a fetch) | 405 problem+json + `Allow` | `method_not_allowed` |
| Body past `MAX_UPLOAD_MB` | 413 problem+json | `upload.too_large` |
| Controller not ported yet | 501 problem+json | `route.not_implemented` |
| Page answer (S1.1) | 200 `text/html`, `no-store` | — |
| Sign-in accepted, a sign-out, a consent answer (S1.2) | 303, `Location`, `no-store` | — |
| Hosted operator console path (`/internal/…`) | 501 problem+json | `route.not_implemented` |

The 2xx answers are the page routes — `/login` (GET and HEAD) and, on a hosted install, `/claim` —
plus, since S1.3, `GET /settings/security` for a role that has the `users` permission, and the CORS
preflight (204, which carries no data and needs no session). `test/http/router.spec.ts` turns **every
row** of the table into a request and asserts both: the answer is one of the above, and the 2xx rows
of that sweep are exactly `/login` — a route that starts answering 200 without being in that list
fails the test. The sweep signs nobody in, so `/settings/security` answers its 303 there and its 200
is proved by `test/http/security.spec.ts` instead. It runs with `APOTHEKE_HOSTED` unset, so `/claim`
is the 404 below, not a 2xx.

`problem+json` is the PHP app's shape (`ApiResponse::problem()`): `type` is `about:blank`, `title` is
the HTTP reason phrase, `status`, `detail` for a human, and `code` for a client to match on.
`/readyz` keeps the body it has had since S0.3 (`title` names the reason); it is a v4-only probe and
the walkthrough and smoke test are written against it.

The header sets are copied, not tuned: `SecurityPolicy` (four headers) on pages, `ApiSecurityPolicy`
on `/api` (CSP `default-src 'none'`, `no-store`, HSTS only when `PUBLIC_URL` is https, no
`X-Frame-Options`). A problem+json answer keeps the set its filter chose.

## What is deferred, and why it is still closed

`DEFERRED` in `filters.ts`, asserted by name in the tests:

| Filter | Why not yet | What keeps it closed |
|---|---|---|
| `session` | the session store is P1 | it records "no identity", and `permission` refuses every route that needs one |
| `apicsrf` | Origin + token need the session | an unsafe `/api` write is refused (403) |
| `consent` | the consent cookie arrives with the session layer | the writes it would refuse are refused first (401/403) |
| `formfields` | a validation rule, not an access decision | the writes it would 422 are refused before it |
| `harness` | marks a PHPUnit request in the PHP process | nothing to read in a Worker, and nothing to allow |
| `opsbackup`, `opsaccounts` | the hosted operator console is C2 | the `/internal` paths they own answer 501 rather than pass |
| `requestcleanup` | frees an unfinished PHP upload's files | there are no uploads in phase 0 |
| `api` (CORS half) | `CORS_ALLOWED_ORIGINS` is not in v4's env contract | the list is empty, which is the PHP side's self-host default too |

## Differences from v3 so far

These are scaffolding, not the contract — P1/P2 restore v3's behaviour, and §4 freezes what must match
then:

- **A page route that needs a session answers v3's 303 to `/login` again**, and a signed-in role that
  lacks the permission answers v3's 403 with its message. Since S1.3 the check is `PermissionCheck`
  itself: the actor's role and the roles table's permissions, resolved once per request before the
  chain (`Ctx.actor`), because a filter cannot await. The phase-0 401 problem+json that stood here was
  scaffolding for a `/login` that did not exist yet; it does, so the redirect is real. A fetch asking
  for JSON still gets 401 JSON (`WantsJson`), and under `/api` the 401/403 problem+json is v3's own
  answer.
- **`POST /login` signs the user in**, as v3 does, since S1.2: the CSRF token, the per-address and
  per-account throttle, the password check and `session_regenerate_id(true)` on success. `/logout` and
  `/consent` answer with it. A fetch asking for JSON still gets JSON on a 405 (`WantsJson`).
- **`/healthz` and `/readyz` are answered before the chain**, in `src/index.ts`. `/healthz` is the
  container probe (§11 rule 7) and must answer 200 whatever a filter thinks of the Host header; the
  legacy `/health` alias goes through the chain like any other route.
- **Every unsafe `/api` write is refused with 403 so far**, because the Origin/token rules the API's
  CSRF check needs are part of the session contract (P1). It is a refusal, not a bypass. The page
  routes' own POSTs — `/login`, `/logout`, `/consent`, `/claim`, `/settings/users…` — are not `/api`
  writes and answer as their handlers do above; `/login` has signed in since S1.2.
- **The Users & sign-in page answers, and so does its users half (S1.3).** `GET /settings/security`
  renders `SecurityView::page()` for a role with `users`, and `POST /settings/users…` creates, changes,
  approves, renames and deletes accounts. A refusal re-renders *that page* with v3's message and v3's
  status (409 last admin, 422 a taken name, 403 the admin-only guards, 404 an unknown action) rather
  than an error page of ours — the page is the answer, as it is in v3. Two parts of the document are
  not there yet: the header (`Page::simpleHeader()` and the menu behind it) is P2, so the section
  renders without the bar above it; and the roles editor's own POSTs and the section's settings form
  still answer 501, which is what the generated table says they do.
- **`ALLOWED_HOSTS` is not read.** It is not in v4's sixteen-name environment contract (§11), so
  `hostcheck` accepts IP literals, `localhost`, `*.localhost` and `PUBLIC_URL`'s host only. A self-hoster
  who adds the name to `PUBLIC_URL` is fine; one who relies on `ALLOWED_HOSTS` is not, yet.

## Adding a route in P1/P2

1. Change `app/Config/Routes.php` in the PHP app, run `php spark apotheke:routes`, then `npm run routes`.
2. Write the handler and register it in `HANDLERS` in `src/http/router.ts` (keyed by the handler string the
   table records). Nothing else changes: the chain, the permission check and the 404/405 behaviour come
   from the table.

## What the tests prove

| Test | Proves |
|---|---|
| `routes-parity.spec.ts` | every row exists once, with the PHP app's method, path, handler, permission and filter list — and nothing extra. Removing a row, adding one or changing a permission fails it |
| `chain.spec.ts` | the chain a request runs is the one `Config/Filters.php` declares, asserted by *running* it (the trace), for the representative paths and for all 281 rows |
| `filters.spec.ts` | each filter on its own, including the fail-closed branches, and that the deferred list is exactly the written-down one |
| `router.spec.ts` | with nobody signed in, no row answers 2xx except `/login` and the preflight; every refusal is problem+json (or the two plain-text guards); the page 404/405 are v3's error page; the `/api` 404 and the `Allow` header are v3's |
| `http/security.spec.ts` | the Users & sign-in page renders for `users` and is refused to a viewer; a users POST without the token is v3's 419; a create writes the row and redirects with v3's toast; a refusal re-renders the page at v3's status; the roles POSTs still answer 501 |
| `html/security.spec.ts` | `SecurityView::page()` is byte-identical to the PHP app's output on a pinned state, in both its normal and its re-rendered form |
| `html/golden.spec.ts` | `/login`, the 404 and the 405 are byte-identical to the PHP app's own output, headers included |
| `html/assets.spec.ts` | every bundled asset is served with the SHA-256 the bundle recorded from `public/assets/`, the documented content types and cache headers, and a miss is uncacheable |
| `html/unported.spec.ts` | every page route that does not need a session still answers 501 `route.not_implemented` — as a list from the table, so one that silently starts answering 200 fails |

## The page answers (S1.1)

`src/html/` is the view layer, ported from the PHP views rather than re-designed:

| File | What it is |
|---|---|
| `escape.ts` | `Page::e()`: `htmlspecialchars(…, ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')`, plus PHP's `ucfirst()` |
| `page.ts` | The shell: `Page::start()`, `consentNotice()`, `pageEnd()` and `brandLogo()`, and `Theme::safeBack()` |
| `views.ts` | `AuthViews::login()` and `Layout\ErrorPage::html()` |
| `icons.ts` | `Icons::svg()` and its 39 icons, generated from `Icons.php` |
| `md5.ts` | `md5()`, for the `?v=` cache keys PHP derives — pinned to RFC 1321's vectors and to values PHP computed |

The whitespace is PHP's, including the artifacts: a `<?php ?>` block standing on its own line prints its
leading spaces and eats the newline after `?>`, which is why the login form's first field is indented by
eight spaces. That is v3's markup, and the golden fixture is what says so.

**Assets.** `public/assets/` is embedded in the bundle by `scripts/gen-assets.mjs` (a Worker has no
filesystem) and served by `src/http/assets.ts`, which is nginx's `location ^~ /assets/`: the file, its
content type (`text/css; charset=utf-8`, `text/javascript; charset=utf-8`), `Cache-Control:
public, max-age=31536000, immutable`, `nosniff`, and an uncacheable 404 for anything not in the bundle.
It answers before the readiness gate and outside the filter chain, as a static file does in v3.
`npm run assets:check` fails when the bundle and `public/assets/` disagree — and because the bundle
records each file's SHA-256, the test that serves it compares hashes, not bytes-by-eye.

**The golden fixture.** `test/fixtures/php-html.json` is the PHP app's own output for `GET /login` (200),
`GET /nope` (404) and `POST /settings` (405), captured by `scripts/gen-html-fixtures.sh` from a real
v3 install — CodeIgniter booted, SQLite migrated and seeded, served by `php -S`. `test/html/golden.spec.ts`
compares v4's answers with it byte for byte, normalised only for the CSRF token and a session id.

That script needs PHP 8.4 with `intl` and `pdo_sqlite`, which CI does not carry, so the fixture is
generated and committed and `npm run fixtures:check` (CI) hashes every PHP file and asset it depends
on: a change to one of them without a regeneration fails the build. Two details make it reproducible:
the script pins every asset's mtime to 0 before rendering (`AssetUrl::url()` hashes `mtime . size`, and
a checkout's mtimes belong to the checkout), and it renders into a fresh `DATA_DIR` with `APP_PASSWORD`
set and no `.env`, so the settings row is the fresh-install one.

Not ported, because nothing renders them yet: the menu, the undo bar, the toast, the header, custom
branding (`logo`/`app_icon`) and the `/theme.css` stylesheet a non-default accent links to. The page
shell prints the default accent's markup and the consent notice a first visit gets; the `theme` cookie
and the consent cookie arrive with S1.2.
