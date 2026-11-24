# The router and the filter chain (S0.5)

Phase 0's routing skeleton: the route table, the filter chain in the PHP app's own order, problem+json
for every error, and the security headers. It answers nothing a browser can use — sign-in is P1, the
pages are P2 — and that is the point: what exists is the machinery, and it fails closed.

`src/http/` holds it:

| File | What it is |
|---|---|
| `routes.generated.ts` | The table: one row per method and path, with the PHP app's handler, permission and filter list. **Generated — do not edit.** |
| `route-table.ts` | Matching: a route key *is* the pattern (CI4 compiles it as one), `HEAD` looks up `GET`, the allowed verbs for a 405 |
| `chain.ts` | The filter order as data, a copy of `app/Config/Filters.php` (the parity test keeps it honest) |
| `filters.ts` | Each filter as a named object; the `DEFERRED` map and why |
| `problem.ts` | problem+json, the two header sets, `isApi` |
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

- **`permission` is required, so it runs before the CSRF filters.** A write to a route that needs a
  session answers 401 before any token is looked at. Only a route that passes the permission check
  (`none`, or the API's `public`) can reach `sessioncsrf`/`apicsrf`.
- **A refusal skips the globals after filters but not the required ones.** CI4 returns a before
  filter's response before the controller, and `CodeIgniter::run()` still runs the required after
  filters on it — which is why a refusal under `/api` carries the API headers (`api` is one of them)
  and a page refusal carries its own set from the filter that refused it.

## Fail closed, by name

| Situation | Answer | Code |
|---|---|---|
| Route needs a session (every permission but `none`/`public`) | 401 problem+json | `auth.required` |
| Route with no permission recorded | 403 problem+json | `permission.denied` |
| Unsafe write, no session to hold a token | 403 problem+json | `csrf.unavailable` |
| Unsafe write from another site | 403 problem+json | `origin.refused` |
| Unknown Host | 421 `text/plain`, legacy's text | — |
| Path no route serves (page) / under `/api` | 404 problem+json | `not_found` |
| Wrong method | 405 problem+json + `Allow` | `method_not_allowed` |
| Body past `MAX_UPLOAD_MB` | 413 problem+json | `upload.too_large` |
| Controller not ported yet | 501 problem+json | `route.not_implemented` |
| Hosted operator console path (`/internal/…`) | 501 problem+json | `route.not_implemented` |

Nothing answers 200. `test/http/router.spec.ts` turns **every row** of the table into a request and
asserts the answer is one of the above — the only 2xx in phase 0 is the CORS preflight (204), which
carries no data and needs no session.

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
| `sessioncsrf` | the token lives in the session | an unsafe request is refused (403) instead of allowed |
| `apicsrf` | Origin + token need the session | an unsafe `/api` write is refused (403) |
| `consent` | the consent cookie arrives with the session layer | the writes it would refuse are refused first (401/403) |
| `formfields` | a validation rule, not an access decision | the writes it would 422 are refused before it |
| `harness` | marks a PHPUnit request in the PHP process | nothing to read in a Worker, and nothing to allow |
| `opsbackup`, `opsaccounts` | the hosted operator console is C2 | the `/internal` paths they own answer 501 rather than pass |
| `requestcleanup` | frees an unfinished PHP upload's files | there are no uploads in phase 0 |
| `api` (CORS half) | `CORS_ALLOWED_ORIGINS` is not in v4's env contract | the list is empty, which is the PHP side's self-host default too |

## Differences from v3 while phase 0 is all there is

These are scaffolding, not the contract — P1/P2 restore v3's behaviour, and §4 freezes what must match
then:

- **A page route that needs a session answers 401 problem+json**, where v3 redirects (303) to
  `/login`. There is no `/login` page to redirect to until P2, and a redirect to a 501 helps nobody.
  Under `/api` this is v3's own answer.
- **404 and 405 for page paths are problem+json**, where v3 renders the app's error page. The
  templates are P2. Under `/api` the 404 is the frozen one (§4 rule 3).
- **`/healthz` and `/readyz` are answered before the chain**, in `src/index.ts`. `/healthz` is the
  container probe (§11 rule 7) and must answer 200 whatever a filter thinks of the Host header; the
  legacy `/health` alias goes through the chain like any other route.
- **Every unsafe `/api` write is refused with 403 in phase 0**, the sign-in POST included, because the
  Origin/token rules need the session contract (P1). It is a refusal, not a bypass.
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
| `router.spec.ts` | no row answers 2xx except the preflight; every refusal is problem+json (or the two plain-text guards); the `/api` 404 and the `Allow` header are v3's |
