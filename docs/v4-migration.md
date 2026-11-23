# Apotheke v4 — TypeScript on Workers/workerd: execution plan

Owner: Apotheke PM · Build: Apotheke FullStack Developer · Branch of record: `experimental-v4`
Created 2026-11-23 12:50 EET · **Revision 9 at 00:35 EET on 2026-11-24** — records S0.8 (the rqlite driver) and, with it, G0's last unproven leg: `rqlite` from inside workerd, proven in run `45279526055` with the two remaining gaps (TLS to a self-signed node, retry across a real election) named rather than glossed. Narrows what is next to S0.6's D1 job, and states that a CI D1 job proves the emulated binding, not hosted D1. Revision 8 at 00:20 EET on 2026-11-24 — records what S0.2–S0.4 actually built
and corrects four §3 rows that had drifted from the code: the vitest plugin (`@cloudflare/vitest-plugin`, the
supported successor to the deprecated `vitest-pool-workers`), the `workerd` override that running
`compatibility_date 2026-10-29` requires, `dependency-cruiser`'s SWC parser, and a hand-written `secretbox.ts` in
place of `libsodium-wrappers` (workerd disables the WebAssembly codegen it needs; the `enc:v1:` format is
unchanged and PHP↔TS interop is proven in both directions). Amends **G0** to a literal allowlist with the
write-idempotency cross-reference (**D13**), adds the **seed gate** (**D14**), records **D10**'s bootstrap
exception, and states which phase-0 slices have landed. Revision 7 at 22:40 EET — repaired the three splice defects the
revision-6 resync introduced (§4 had lost numbered item 1, §7 had a risk row with no impact or mitigation, §5
listed S0.9 before S0.8), and recorded the two phase-0 decisions taken from the dev report: **D11** (the hosted
refusals for inline taxonomy and photo duplication are lifted) and **D12** (the `Installation` DO is kept but
demoted). No scope change; effort and gates stand. Revision 6 at 13:40 EET — folded in the two rqlite phase-0 spikes: FTS5 **is** present in stock `rqlited` v10.7.4 (SQLite 3.54.1) and `?transaction` is genuinely atomic, which turned two assumptions into facts and forced one new rule (R10, write retries) because a `503` does not say whether a batch committed. Also corrected: rows come back **positional**, not associative, and `/readyz` sits behind auth. Revision 5 at 13:25 EET — added §11, self-host packaging as a deliverable (two
compose files, `.env.example`, `docs/self-host-docker.md`, a CI smoke script) and slice S0.9 to pin that contract in
phase 0; recorded D9 (no branch protection). Revision 4 (12:58) — **hosted defaults to Cloudflare D1**; self-host
defaults to rqlite with plain SQLite supported; PostgreSQL is dropped entirely; no migration path is built (v3 export →
v4 import, and hosted installations start on fresh D1 databases). Revision 3 (12:56) dropped Postgres; revision 2
(12:52) recorded D2–D7. Supersedes `docs/future/workers-migration-plan.md`, which stays on `main` as the study; this file is the
committed plan and goes onto the branch as `docs/v4-migration.md` in phase 0.

## 0. The decision, in one paragraph

Port the whole Apotheke application from CodeIgniter 4 / PHP 8.4 to TypeScript running on Cloudflare Workers
(hosted) and on `workerd` (self-host, local development). One Worker, no PHP container, one SQLite database per
installation: **Cloudflare D1 when hosted**, and **rqlite beside `workerd` when self-hosted** (plain local SQLite as
the self-host alternative). All three are SQLite, so there is one schema, one set of migrations and one parity
comparison. Files live in R2 (hosted) or on a disk bucket under `/data` (self-host). The port keeps every externally visible
contract — schema, HTML, cookies, `/api/v1`, backup ZIP, secret format, password hashes — so the existing test
suites are the acceptance test, not a rewrite of them. All work happens on `experimental-v4`; `main` is frozen.

## 1. Constraints that shape everything

| # | Constraint | How it is enforced |
|---|---|---|
| 1 | Nothing new lands on `main` | **Convention, not a lock (D9, 13:17).** No branch protection on `main` or on `experimental-v4`, by Stathis's decision. A `main-freeze` workflow fails any PR into `main` that is not labelled `revert` or `security` and approved by Stathis — a PR gate only, which a direct push bypasses. So the freeze is only as strong as the two bots and the humans obeying it: never push to `main`, never open a PR against `main`, never force-push the branch. `main` keeps the PHP app and its CI exactly as it is today. |
| 2 | All v4 work on `experimental-v4` | Long-lived branch cut from `main` @ `4a187d0f` (v3.72.0). PRs go into `experimental-v4`, never `main`. Nothing is released from the branch: `VERSION` becomes `4.0.0-dev` and no tag is cut. |
| 3 | The current E2E suite must pass on the branch | `tests/visual/*.spec.ts` (Playwright: Chromium + WebKit, light + dark, desktop + mobile projects) runs against the v4 server with **unmodified** screenshot baselines. Functional specs 100% green; any baseline change needs PM sign-off per file. |
| 4 | Everything is tested | Every phase gate below names the suite that proves it: unit (vitest in workerd), schema parity, golden pages, API contract, parity recorder, functional + visual E2E, backup round trip. A phase is not done because the code is written. |
| 5 | No user-visible regressions | HTML/DOM, routes, redirects, status codes, cookies and the API contract are frozen interfaces (§4). Where the port cannot match, the difference is a decision for Stathis, written in the decision log before the code lands. |
| 6 | Old schema stays the schema | v4 makes no incompatible schema change. `schema_migrations` rows and table definitions stay identical so a v4 database remains readable by the PHP app and vice versa. |
| 7 | Three database backends keep working | Hosted runs **D1** (default); self-host runs **rqlite** (default) or plain **local SQLite**. All three are SQLite, so there is **one** schema and no dialect layer. Each backend has its own CI job from phase 0 — nothing is "tested by hand later". |

## 2. Target architecture

```
browser ──► apotheke-api (Worker, TypeScript)
              │  host → installation route (control-plane D1: host + d1_database_id, edge/src/route.ts, kept)
              │  limits, metering, CORS, static assets
              ├─► APP D1  (one database per installation, bound per request — D8)
              │     app handlers (ported modules) over the async Sql driver
              ├─► R2 STORAGE  (installations/<installation>/… files, media, backups)
              ├─► Installation DO  (per installation: alarms, migration lock; nothing else after S0.3)
              └─► AUTOFILL service binding (hosted) | HTTP token call (self-host)
apotheke-ops ──► Ops entrypoint on apotheke-api ──► control plane / Installation DO (RPC, no ops.internal signature)
workerd (self-host/Docker): same bundle, same bundle config, port 9091
                 └─► rqlite over HTTP (default) or a local DO SQLite, files on a disk bucket
```

What disappears: the `ApothekeContainer` Durable Object and the container application, `D1Outbound` / `R2Outbound` /
`AutofillOutbound`, `edge/src/bridge.ts` storage reads (replaced by direct methods), the signed `ops.internal`
channel, `Dockerfile.frankenphp` on the hosted path, `jobs:work` polling.

Kept as-is: control-plane D1 and its migrations, `routeFor()` host routing, `limits.ts` metering, `LifecycleWorkflow`,
`apotheke-ops` and the operator console, the landing site and `/app/` shell in `edge/app/`.

### Code layout during the branch

```
v4/                         new workspace (own package.json, tsconfig, wrangler.jsonc, vitest config)
  src/core/                 router, filter chain, problem+json, config, template runtime, permission map
  src/platform/             Sql, Blobs, Clock, Secrets, Autofill, Jobs, ImageProcessor adapters
  src/app/<module>/         one folder per CI4 module, same names as today
  src/index.ts              Worker entry (fetch + Ops entrypoint), later folded into edge/src
  test/                     vitest (@cloudflare/vitest-plugin), fixtures, golden dumps
  migrations/               ported runner + the 35 existing migration definitions
```

Why a separate `v4/` workspace instead of growing inside `edge/`: `edge/` is the deployed hosted path from `main`;
keeping the port out of it means the branch cannot break main's deploy job, and phase 6 folds `v4/src` into
`edge/src` in one reviewable commit. It also lets `npm test` in `v4/` be a single command with no PHP.

## 3. Stack choices (made here, so the work does not stall)

| Concern | Choice | Why |
|---|---|---|
| Language | TypeScript 7, `strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, ESM | Already the version in `edge/`. |
| Runtime | Workers (`workerd`), `compatibility_date` shared with `edge/wrangler.jsonc` (2026-10-29), `nodejs_compat`, with a `workerd` override pinned at `1.20261117.1` in `v4/package.json` | One compatibility date for hosted and self-host, checked by a test. The override is part of the stack, not a stray dev dependency: the `workerd` that the toolchain pulls in by default refuses that date. |
| HTTP | Hand-written router + middleware chain mirroring `app/Config/Routes.php` and the 14 filters in `app/Config/Filters.php` | CI4 semantics (permission per route, CSRF, host check) are the contract; a framework's conventions would have to be un-taught. No Hono. |
| Views | Typed template functions returning HTML strings, one file per CI4 view, escaped through a single `h()` helper | Golden-page byte equality is achievable; no client build step for the UI. |
| SQL | No ORM. Ported SQL behind an **async** `Sql` interface with three drivers: D1 (`env.DB.prepare/bind/all` + `batch`), rqlite (HTTP), and `ctx.storage.sql` (local DO SQLite). One SQLite dialect, so the SQL text is shared verbatim. ADR-002's 100-param-per-statement cap is **D1's own limit** and is kept as the ceiling for all three. | The SQL is already written and tested; an ORM would rewrite it and hide the parameter discipline. Async everywhere because two of the three drivers are network hops, and D1's `batch()` is the strongest atomic primitive D1 offers. |
| Database | Hosted: **D1**, one database per installation (a binding, `env.DB`), with the control-plane D1 unchanged. Self-host: **rqlite** (default) or local SQLite for a single-process install. Chosen per environment by the binding or `DB_DRIVER`. | D1 is the Cloudflare-native SQLite with `batch()` atomicity and no server to run; rqlite gives self-hosters SQLite over HTTP with replication and snapshots. One dialect, three backends (§10). |
| D1 provisioning | One D1 database per hosted installation (D8), created through the Cloudflare API in the existing lifecycle job, its id recorded in the control plane; deletion = drop the database; backup/export is per database. | Isolation matches today's per-installation Durable Object, a backup/export is per database, and deleting an installation is a single drop. |
| rqlite driver | Plain `fetch()` against rqlite's HTTP API (`/db/execute`, `/db/query`), parameterised statements as `[sql, ...values]`, positional rows (`{columns, types, values}`) zipped by the driver, `error_v2` handling, `db_timeout`, basic auth or TLS. No Node shims, no `cloudflare:sockets`. | The HTTP API works in workerd with nothing but `fetch`, and it is the only interface rqlite promises to keep stable. |
| Files | R2 behind a `Blobs` adapter; keys unchanged (`installations/<do_name>/…`) | Same buckets, same keys, no migration. |
| Crypto | WebCrypto HMAC for CSRF/tokens; `bcryptjs` for password hashes; hand-written XSalsa20-Poly1305 in `src/platform/secretbox.ts` for `enc:v1:` values | `bcryptjs` verifies existing PHP `password_hash(…, PASSWORD_DEFAULT)` hashes unchanged, so no user is locked out. `libsodium-wrappers` is **out**: workerd disables WebAssembly codegen, so the library cannot load at all. The format is libsodium's own secretbox, so `enc:v1:` values stay byte-compatible — proven in both directions against real `sodium_crypto_secretbox` (PHP 8.4.23), which is the only proof that matters here. |
| ZIP | `fflate` (streaming) | Backup/restore format must stay readable by 2.6.x and the PHP image; PHP writes and TS reads, and the reverse, are both CI tests. |
| QR/labels | `qrcode` (pure JS), output compared against the PHP `Labels\QrCode` fixtures | Same pixels on the label sheet. |
| Images | `ImageProcessor` adapter: Images binding (hosted) / WASM codecs (workerd, lower pixel cap) / browser-side pre-resize | See decision D2. The adapter keeps the choice out of the app code. |
| Unit tests | `@cloudflare/vitest-plugin` 1.9.1 (runs the real bundle in workerd with real DO SQLite and R2) | Tests exercise the runtime that ships, not a Node mock of it. `vitest-pool-workers` 0.23.0 is deprecated; the plugin is its supported successor, and it is the `workerd` process that the suite actually boots. Revisit when its bundled miniflare leaves beta, or at phase-1 start, whichever comes first. |
| Lint | Biome + `dependency-cruiser` (SWC parser) with rules copied from the deptrac module graph | `deptrac` and the DI rule stay for PHP; the TS graph gets the same boundaries. Version 18 cannot parse TypeScript 7 without its `swc` parser configured, and without it it cruises **zero** modules and passes vacuously — a green lint that checks nothing is worse than a red one. |
| CI | New `.github/workflows/v4.yml` on PRs into `experimental-v4` and pushes to it. `ci.yml` on `main` untouched | The frozen branch keeps its own green/red signal. |

## 4. Frozen interfaces (what "no regression" means concretely)

1. **Schema.** All three backends are SQLite, so there is exactly one target schema. Nothing to normalise across
   dialects. Test: schema parity against the PHP runner on an empty database and on the v3.72 dump, on every
   backend (§6, G0).
2. **HTML/DOM.** Same element ids, names, classes, data attributes, form fields, `<meta>` and asset URLs for every page. Test: `tests/Unit/Ci4PagesGoldenTest.php` becomes a golden corpus replayed against v4, then Playwright visual baselines.
3. **Routes.** Same paths, methods, redirects (303 after POST), and status codes, including the 404 problem+json fallback under `/api`.
4. **Cookies.** `__Host-apotheke` session cookie (Secure, Path=/, no Domain), `apotheke_consent`, idle timeout and `session_days` semantics, session epoch invalidation on password/role change.
5. **API.** `docs/api/openapi.yaml` unchanged except for additions of the catalog endpoints already planned on `main`; `ApiContract.php` runs against v4 and must pass.
6. **Backup.** Same ZIP layout, same manifest, same dump format, both directions PHP↔TS.
7. **Secrets at rest.** `enc:v1:` libsodium secretbox values decrypt in v4 and values written by v4 decrypt in PHP.
8. **Passwords.** Existing bcrypt hashes verify unchanged; new hashes stay `$2y$` bcrypt so PHP can still verify them (no one-way door while both runtimes exist).
9. **Timestamps and text.** UTC stored as `YYYY-MM-DD HH:MM:SS`; markdown rendering byte-identical to `MarkdownRenderer` (20,000-char cap included); same case-folding rules for search.

## 5. Phases

Each phase ends with a gate. A phase may be split into several PRs into `experimental-v4`; the gate is what closes
it. Sizes are rough person-weeks for one developer, tests and docs included.

### Phase 0 — Foundation and guardrails (2–3 pw)

Slices:
- S0.1 Branch and freeze: branch `experimental-v4` is already cut from `main` @ `4a187d0f`; add the `main-freeze` workflow (PR gate only — no branch protection, D9); commit this plan as `docs/v4-migration.md`; set `VERSION` to `4.0.0-dev`.
- S0.2 `v4/` workspace: package.json, tsconfig, `wrangler.jsonc` (worker `apotheke-v4`, no routes, no containers), `@cloudflare/vitest-plugin`, biome, dependency-cruiser, npm scripts (`dev`, `test`, `typecheck`, `lint`).
- S0.3 Platform adapters + `Installation` DO: `Sql` (async, driver-pluggable), `Blobs`, `Clock`, `Secrets`, config loader, `blockConcurrencyWhile` migration-on-open, schema-newer-than-code refusal, `/healthz`, and the seed gate (D14: the seed runs inside `blockConcurrencyWhile` before the first request is served, `/readyz` answers 503 while it is pending, `/healthz` answers 200 always). The DO's fate is decided (D12): it stays as scheduler + migration lock, owns the database only under `DB_DRIVER=sqlite`.
- S0.4 Migration runner: the 35 migrations as typed steps, one SQLite dialect (shared by D1, rqlite and local SQLite), refuse-if-newer, unit of work supplied per driver (D1 `batch()`, one `?transaction` request on rqlite, `transactionSync` locally), plus the **transaction audit**: every `atomically()`/`inUnit()` call site (10 across 7 files) classified as expressible-in-one-batch or not, with the rewrite listed for each. Code is written to the batch contract, the weakest of the three.
- S0.5 Router + filters: route table generated from `Routes.php` (method, path, permission, filter list), the filter chain in the real order (`hostcheck`, `publicbase`, `session`, `sameorigin`, `permission`, `sessioncsrf`/`apicsrf`), problem+json errors, security headers/CSP.
- S0.6 CI `v4.yml`: typecheck → lint → unit → schema parity → workerd smoke (boot, `/healthz`, one table read), plus a **rqlite job** that starts a real `rqlited` and repeats migration, parity and handler suites, plus a **D1 job** using the Miniflare-emulated D1 binding under `@cloudflare/vitest-plugin`. Real-D1 smoke (a database created through the API) runs on the branch only when a Cloudflare token is present, because the emulator is SQLite and would hide D1-specific limits.
- S0.7 D1 driver: the `Sql` implementation over `env.DB` (`prepare`/`bind`/`all`, `batch` as the unit of work, `meta.changes`/`last_row_id` for results), the binding-name contract, and the local emulation path so the same code runs in tests.
- S0.8 rqlite driver (self-host default): `Sql` implementation over the HTTP API, `DB_DRIVER=rqlite` boot path, plan-then-flush unit of work (`/db/execute?transaction`), read-consistency and retry/timeout policy, auth/TLS from the environment, and a documented `RQLITE_URL` contract for self-host.
- S0.9 Self-host compose skeleton: `docker-compose.yml` (`apotheke` + `rqlite`), `docker-compose.sqlite.yml`
  (single process), `.env.example`, `scripts/selfhost-smoke.sh` — run against a stub `workerd` that serves `/healthz`,
  so ports, volumes, healthcheck ordering and env names are pinned before the app exists. Contract in §11.

Timeboxed spikes inside phase 0 (one day each, result written into the phase notes):
FTS5 available in DO SQLite under local `workerd`; DO alarms firing with `localDisk` storage; the bundled R2 disk
bucket surviving `wrangler dev` restarts; PHP↔TS `enc:v1:` secretbox interop — **closed 2026-11-23 23:44**: real
`sodium_crypto_secretbox` under a fixed key and nonce sealed a value the TS `secretbox.ts` opened, and a TS-sealed
value opened in PHP, byte for byte, so v3 secrets stay readable in v4 and the reverse;
`bcryptjs` verify cost for `PASSWORD_DEFAULT` hashes at cost 12; the Images binding's offline limits;
rqlite from `workerd` (boot against a real `rqlited`, parameterised round trip, one atomic batch, TLS and basic auth,
retry across a leader restart); FTS5 availability in the stock `rqlited` build; and the batch model carrying the real
write paths from the S0.4 audit — the spike that can send the database design back to Stathis if a call site cannot be
expressed as one batch.

Phase-0 spike status (2026-11-23 13:25 EET, updated 2026-11-24 00:35 EET; detail in `shared/apotheke/v4-phase0-notes.md`): **FTS5 in stock
`rqlited` — pass** (`fts5(..., tokenize='unicode61 remove_diacritics 2')` and `fts5vocab` from
`migrations/0019_search.php` apply in one batch; `MATCH 'kabel*'`, `bm25()` and the `engine()` probe all behave), so a
self-host install reports `fts5` and keeps `like` as the safety net. **rqlite over HTTP — pass**
(`[[sql, ...args]]` params, `?transaction` atomic with full rollback on a failed statement, per-statement
`last_insert_id`/`rows_affected`, `error_v2.sqlite.code`, in-batch `last_insert_rowid()` resolving, TLS + basic auth
rejecting both missing creds and plain HTTP on the TLS port). **rqlite from workerd — pass, 2026-11-24 00:26 EET**
(run 45279526055, job `rqlite (driver, parity, workerd boot)` 13/13): `wrangler dev` with `DB_DRIVER=rqlite` against a
real `rqlited` v10.7.4 started with `-auth` answers `/healthz 200 {"ok":true,"driver":"rqlite"}` and
`/readyz 200 {"ok":true,"schema":"0036","seed":"done","driver":"rqlite"}`, so migrations, the seed phase and the
readiness gate all run over loopback HTTP from inside the Worker. That closes the leg the noisy host could not settle
and G0 is no longer blocked on it. Two gaps stay open and are recorded, not waved away: **TLS from workerd to a
`rqlited` with a self-signed certificate** is implemented but unexercised, and **retry across a real leader election**
is proven only against a stub `503` — killing and restarting a leader belongs with G3's restart-with-data.

Slices landed by 2026-11-24 00:26 EET: S0.1 (`0e46d908`), S0.2 (`9aedb9e0`), S0.4 with its audit (`f1473b17`;
audit `ac08ea6f` merged as `f27b79de`), S0.3 (`744bc946`), S0.8 (`94309317`, five commits `bc801feb` driver and
tests, `bc472f50` the rqlite job, `4d179942` and `864a0efb` its two defects fixed, `94309317` the gaps recorded).
S0.7's D1 driver landed early inside S0.3 because S0.4's parity test needs a backend, and S0.8 delivered S0.6's
rqlite job with it. **Next is S0.6's remaining D1 job** — the last thing G0's CI sentence names and the only leg that
has never run outside the emulator — and G0 is signed when that job is green. S0.5 (router) and S0.9 (compose) are
not G0 inputs; S0.9 follows, now that S0.8 has pinned the `RQLITE_URL`, `RQLITE_AUTH` and `RQLITE_READ_CONSISTENCY`
contract it has to expose. Known limit, stated so it is not mistaken for coverage: a CI D1 job proves the **emulated**
D1 binding, not hosted D1; real D1 is first exercised when a hosted installation is provisioned, and that belongs to
phase 1, not to G0.

**Gate G0 (amended by D13):** TS runner vs PHP runner schema identical (normalised) on an empty database and on a
v3.72 dump, on **all three** backends (one SQLite schema, so one comparison run three times), where "identical" means
both directions held at once: the TS set contains every one of the PHP oracle's 64 objects (36 tables, 28 indexes),
and the TS set minus the literal pair `_write_log` + `idx_write_log_created` — the two objects 0036 adds, inside the
migration chain and not bolted on afterwards — equals the oracle exactly. No other object may appear on either side.
`/healthz` 200 from `wrangler dev` on D1 and SQLite and from a workerd-only boot against a real `rqlited`; `/readyz`
200 only once the seed is complete, 503 before it (D14); `v4.yml` green including the rqlite and D1 jobs.

### Phase 1 — Auth, sessions, page shell (3–4 pw)

Sign-in/out and first-admin claim; session store and epoch; rate limiter; `__Host-` cookie; CSRF for forms and `/api`;
permission filter proven against the `PermissionFilterTest` matrix; layout/page shell, CSS/JS assets served from the
Worker; account pages; users and roles admin; `/api/v1/session`.

**Gate G1:** `visual-login.spec.ts` and the auth functional specs green against v4 with unchanged baselines; the
permission matrix matches PHP route-for-route; no session cookie leaks a `Domain` attribute.

### Phase 2 — Read-only catalog, HTML and API (3–4 pw)

Item list with every filter/sort/page (`ListFilters::DEFS`, `ItemList::search`), item detail, search, taxonomy pages,
labels/QR rendering, media GET (`/media/full|thumb`) from R2, and the catalog API (`/api/v1/items`, `/items/{id}`,
locations, categories, tags) with `openapi.yaml` + `docs/api.md` in the same PR.

**Gate G2:** golden pages byte-identical for list/detail/search/taxonomy; `visual.spec.ts` green; `ApiContract.php`
green against v4.

### Phase 3 — Catalog writes (5–6 pw)

Item create/edit/delete through the same validation and history path, photos metadata, taxonomy writes, notes and
markdown, tags, favourites, lending, stocktake, bulk actions and merge, undo tokens, activity feed.

**Gate G3:** full functional Playwright suite green against v4; write-replay test — the same recorded write sequence
on D1, on local SQLite **and** on rqlite.

### Phase 4 — Media and autofill (3–4 pw, plus the D2 spike)

Upload, rotate, crop, reorder, cover flag, originals, thumbnails, HEIC path per D2; autofill client, cached `/v1/info`,
analysis copy generation, token policy.

**Gate G4:** photo round trip (upload → thumbnail → download → backup → restore) identical on PHP and v4;
autofill visual specs green; no upload path exceeds the Worker memory ceiling in the test matrix.

### Phase 5 — Backup, restore, import, export, jobs (4–6 pw)

ZIP writer/reader (fflate, streamed), restore with the existing chunked upload API, restore fence and sweep, CSV
import and export, `jobs` table replaced by DO alarms with the same observable behaviour (`/api/v1/jobs`).

**Gate G5:** backup round trip PHP → v4 → PHP byte-comparable in content; restore of a real v3.72 backup on v4 and of
a v4 backup on the PHP image; import/export specs green; jobs run from alarms and survive a restart.

### Phase 6 — Ops, self-host on workerd, packaging (3–4 pw)

`Ops` entrypoint calls object methods directly (no `ops.internal` signature); lifecycle export built in the object;
workerd distribution (capnp config, multi-stage Docker image, `/data` volume, port 9091, secrets from the
environment); the two compose files, `.env.example` and `docs/self-host-docker.md` per §11; fold `v4/src` into `edge/src` and delete the container code paths.

**Gate G6:** lifecycle tests green without a container; `scripts/selfhost-smoke.sh` passes on **both** compose files
(`docker-compose.yml` with rqlite, `docker-compose.sqlite.yml` single process) — `/healthz`, first-admin claim, create an
item, upload a file, take a backup, restore it, restart the stack and find the same catalogue (`/data` still there);
`docs/self-host-docker.md` followed literally by someone other than its author; `docs/self-host-workerd.md` written and followed literally by someone other than its author, covering
both database options.

### Phase 7 — Parity gate and merge decision (1–2 pw)

Full matrix: every Playwright project against v4 with untouched baselines; parity recorder zero unexplained diffs;
contract tests; performance sanity (list/detail p95 within 1.5× of PHP on the same data); upgrade notes; a merge
proposal with the diff summary.

**Gate G7:** E2E 100% green on `experimental-v4` with unmodified baselines, and Stathis says go. Merging `main` ←
`experimental-v4` is the release decision, not a build decision.

### Phase 8 — Decommission (post-merge, only with explicit approval)

Delete `ApothekeContainer` with a `deleted_classes` migration (destroys that object's storage — owner approval);
retire the container image, the `d1.internal` bridge and the Postgres wiring in the self-host docs; delete the PHP
application from the branch once v4 is `main`. **The PHP app keeps its Postgres support on `main` for v3 only; v4 never
speaks Postgres** — an operator coming from v3 Postgres moves to rqlite by export and import (§10.2), because rqlite,
not Postgres, is the self-host database.

Total: about 29–35 pw of build work (dropping Postgres removed the dialect layer; the rqlite driver, the D1 driver and provisioning, and the transaction audit add about 4 pw).

## 6. Test strategy (the gate machinery)

| Suite | Runs against | Proves |
|---|---|---|
| `v4/test` vitest (pool: workers) | real workerd, DO SQLite, R2 | unit and handler behaviour |
| Schema parity | PHP runner vs TS runner, empty + v3.72 dump, **on D1, local SQLite and rqlite** | database compatibility both ways |
| rqlite matrix (real `rqlited` in the job) | the TS driver against a real server | the self-host path does not rot |
| D1 matrix (emulated binding) | the D1 driver and its `batch()` unit of work | the hosted path does not rot |
| Golden pages | PHP-produced HTML corpus | DOM/route/text equality |
| API contract (`tests/ApiContract.php`) | base URL of v4 | `/api/v1` unchanged |
| Parity recorder (`tests/parity/recorder.php`, third target) | PHP app and v4 on the same steps | response equality with the existing allowlist quirks |
| Write replay | PHP and v4 from the same seed, on **all three** backends | identical table dumps and blob lists after writes |
| Playwright functional (`--grep-invert visual`) | v4 | every user flow |
| Playwright visual (`tests/visual/*.spec.ts`, 8 projects) | v4 | pixel equality with existing baselines |
| Backup round trip | v4 ↔ PHP image | format compatibility both directions |
| workerd self-host smoke | the built image, SQLite volume and a rqlite node | the self-host promise on both engines |

Rule: a phase gate is only signed off with a link to the CI run. "It works locally" is not a gate. The E2E runner
gets one new switch: `APOTHEKE_BASE_URL`, so the same suite points at PHP or v4 without edits.

## 7. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Image pipeline (GD/Imagick, HEIC, 40 MP) cannot fit 128 MB | Uploads regress, self-host loses HEIC | D2 decision + phase 4 spike; browser-side pre-resize; adapter isolates the choice |
| bcrypt CPU cost on many sign-ins | slow sign-in, CPU billing | keep cost 12, measure; `limits.cpu_ms` raised on hosted; no rehash migration |
| FTS5 or alarms missing/experimental on local workerd | search or jobs differ self-hosted | phase 0 spikes; fallback `like` path already exists in `SearchIndex`; alarm sweep on next request |
| libsodium secretbox incompatibility | settings with secrets unreadable | fixture test with a PHP-produced value in phase 0 |
| A write path cannot be expressed as one rqlite batch (no transaction across requests) | the save/import paths get restructured late | transaction audit in S0.4 classifies all 10 call sites before phase 1; dependent steps move into SQL (`last_insert_rowid()` inside the batch) or the case comes back to Stathis |
| The rqlite path rots because everyone develops on the DO | self-host breaks surface late, after hosted is green, and the self-host default is the one nobody runs | the `v4.yml` rqlite job runs from S0.8 on every PR against a real `rqlited`, repeating migration, parity and handler suites; G0/G3/G6 run on all three backends; no slice is accepted with rqlite red |
| View porting drift (thousands of DOM details) | visual suite fails late and expensively | golden corpus per page in phase 2, visual per phase, no baseline updates without sign-off |
| ZIP format drift | backups unreadable by 2.6.x/PHP | two-direction round trip in gate G5 |
| Long-lived branch diverges from main | painful merge | `main` frozen by rule, so divergence is bounded; weekly rebase of the branch onto `main` is forbidden — only forward merges of approved security fixes |
| workerd features still experimental (localDisk, bucket, r2.js) | self-host fragility | pin workerd version; document the pin; smoke test on every PR |
| Scope creep into an SPA rewrite | doubles the work | D3: v4 keeps the server-rendered UI; the SPA stays a separate track |

## 8. Decisions (answered 2026-11-23, 12:52–12:59 EET)

| # | Decision | Answer | Effect on the plan |
|---|---|---|---|
| D1 | Database backends | **Revised 12:58 — Postgres dropped.** Hosted defaults to **D1**; self-host defaults to **rqlite**, plain SQLite supported; no migration path, v3 export → v4 import | Plan §10, S0.7 (D1) and S0.8 (rqlite), all three backends in G0/G3/G6 |
| D9 | Branch protection on `main` and `experimental-v4` | **No** — neither branch is protected (13:17). The `main-freeze` workflow is the only gate, and it only sees pull requests | Nothing to configure; the freeze is discipline. Direct pushes to `main` are possible and would not be caught by CI |
| D8 | Hosted D1 tenancy | **Answered 12:59 — one D1 database per installation**, no shared database. No data move from current hosted installs: they start fresh and re-import a backup if needed | Control plane stores the D1 database id; provisioning and deletion run through the existing lifecycle jobs |
| D2 | Media pipeline | **Yes as recommended**: server work capped at 12 MP, big phone photos pre-resized in the browser, HEIC decoded client-side, originals stored untouched | Phase 4 as planned; non-browser uploads over 12 MP get a clear 422 |
| D3 | UI through the port | **Server-rendered HTML stays for now**; the SPA remains a separate track | Phase 2 ports views, not a client app |
| D4 | Evidence before `main` ← `experimental-v4` | **G7 only** — every suite green on the branch with untouched baselines, then Stathis's go. No dress rehearsal, no shadow period | Phase 7 is G7 + the merge proposal: 1–2 pw, nothing extra |
| D5 | Sessions at cutover | **One re-login per installation** is acceptable | PHP session serialisation is not ported |
| D6 | Workers Paid and CPU budget | **Acceptable** | bcrypt cost 12 and image work stay; cost re-checked at phase 4 |
| D7 | Switch-over | **Ping Stathis when the branch work is complete and ready to switch**; he makes the go call | G7 ends with a written merge proposal, never a merge |
| D10 | Write retries on rqlite after a `503` | **Answered 13:40 — every write unit carries an idempotency key** (`_write_log`, UUID per unit of work, conflict mapped to success). Reads retry freely; connect failures retry without a key; nothing after bytes leave the socket does | Rule 10 in §10; one table and one index in the schema, swept by the alarm job; same envelope on D1 so call sites are written once |
| D11 | Hosted refusals for inline taxonomy (`422`) and photo duplication (`501`) | **Answered 21:15 — both are lifted in v4.** They exist only because the pre-batch code could not return a `Ref` from taxonomy create and `PhotoRepository::insert()` took `int`; the batch path removes the constraint, so re-shipping the refusal would port a limitation we just deleted | Audit sites #7 (`ItemController::save()`) and #8 (`Duplicate::copy()`) are rewritten in phase 1; `docs/api/openapi.yaml` and `docs/api.md` change in the same PR as the code. Revisit only if returning a `Ref` drags permission/auth paths into the rewrite, or if an external consumer of the documented `422`/`501` is found |
| D12 | What is left of the `Installation` DO once it stops holding the database | **Answered 21:00 — keep one per installation, demoted** from database owner to scheduler + lock: `alarms` for per-installation jobs (Cron Triggers are per-Worker, not per-installation), `blockConcurrencyWhile` as the migration lock and refuse-if-newer gate, lease/cursor state only. No application data, off the request path. **Exception:** `DB_DRIVER=sqlite` still uses `ctx.storage.sql`, so the DO owns the database in exactly that one mode | S0.3; hosted D1 and rqlite both run the reduced DO, and no handler may know which mode it is in (the async `Sql` adapter's job). §10.1 updated |
| D13 | What "identical schema" means now that v4 adds `_write_log` | **Answered 23:22 — a literal allowlist, checked in both directions.** The PHP runner's oracle is 64 objects (36 tables, 28 indexes) and v4 adds exactly `_write_log` and `idx_write_log_created`, both inside 0036 in the migration chain. G0 asserts TS ⊇ PHP object-for-object **and** TS minus that literal pair = PHP exactly | G0 amended in §5; the parity test compares a literal set pair, so a new object on either side fails CI instead of silently widening the allowlist |
| D14 | First boot: seeding, and what readiness means before it finishes | **Answered 23:22 — a seed gate.** 64 settings, 4 roles and the first user are seeded inside `blockConcurrencyWhile` before the first request is served, and marked by a seed row rather than inferred from "the tables look empty"; `/readyz` answers **503 while a seed is pending**, `/healthz` answers **200 always** | S0.3; the PHP fixture's `seed_rows` (64 settings, 4 roles, 1 user) are compared on D1 and on DO SQLite, and a second seed is idempotent. The compose healthcheck probes `/healthz`, so an unseeded container is never restarted mid-seed |

### 8.1 D4 in plain terms (answered: G7 only)

G7 is the branch's own evidence: every suite green on `experimental-v4` — unit, schema parity on both engines, golden
pages, API contract, write replay, and the full Playwright E2E with untouched visual baselines. Stathis chose G7 as
sufficient: no dress rehearsal on a copy of real data, no shadow period. Two consequences we accept with that:

1. Quirks that only exist in real data (40 MP phone photos, HEIC uploads, old imports, odd encodings) surface after
   the switch rather than before it. Mitigation that stays inside this decision: the fixture set is widened during P5
   (restore of real-shaped backups), and the PHP image remains buildable from `main` as a rollback artifact without
   being deployed in parallel.
2. The switch is a single cutover on Stathis's word, so the merge proposal at G7 carries an explicit rollback recipe
   (re-point the Worker at the PHP container image and the pre-switch database/backup).

## 9. Decision log

- 2026-11-23 12:50 EET — v4 scheduled as branch work, not a study: `experimental-v4`, `main` frozen, E2E + schema + backup parity as gates. Rationale: the study (`docs/future/workers-migration-plan.md`) already answered the architecture questions; the missing piece was an owner, a branch and gates.
- 2026-11-23 12:50 EET — DO SQLite kept as the app database; D1 stays control plane. Rationale: no data move, real transactions, in-process SQL. (Superseded 12:58: hosted app data moves to D1, one database per installation.)
- 2026-11-23 12:50 EET — v4 built in a new `v4/` workspace, folded into `edge/` in phase 6. Rationale: the branch must not be able to break `main`'s deploy job.
- 2026-11-23 12:50 EET — bcrypt retained (bcryptjs) instead of rehashing to PBKDF2. Rationale: no lockout risk, one code path, PHP keeps verifying v4 hashes during the dual-run window.
- 2026-11-23 12:52 EET — D1: PostgreSQL **not dropped**. Self-host keeps a real Postgres beside `workerd`; hosted stays DO SQLite. Rationale: removing Postgres would force every existing self-hoster onto an engine they did not choose, and the app is already dialect-abstracted. Cost accepted: +3 pw.
- 2026-11-23 12:52 EET — D2: server image work capped at 12 MP, browser pre-resize, client-side HEIC, originals untouched. Rationale: fits the Worker memory ceiling and removes the last Imagick dependency.
- 2026-11-23 12:52 EET — D3: HTML UI ported as is; SPA stays a separate track.
- 2026-11-23 12:52 EET — D5: one re-login per installation at cutover (same as 3.0).
- 2026-11-23 12:52 EET — D6: Workers Paid accepted for hosted; CPU cost re-checked at phase 4.
- 2026-11-23 12:52 EET — D7: merge/switch is a Stathis decision taken when the branch work is done; I report readiness, I do not merge.
- 2026-11-23 12:54 EET — D4: **G7 only**. No rehearsal on a copy of real data, no parallel run. Rationale accepted: the existing suites are broad (E2E + parity + write replay + backup round trip); the cost of the rehearsal was a week of calendar. Mitigation: real-shaped fixtures during P5 and a written rollback recipe in the G7 merge proposal.

- 2026-11-23 12:59 EET — **D8 answered: one D1 database per installation.** No shared database with an
  `installation_id` column. Rationale: it matches today's per-installation isolation, makes delete a single drop and
  backup/export a per-database operation, and keeps a failing installation from touching anyone else's data. Cost: the
  binding is resolved per request from the control plane instead of being declared in `wrangler.jsonc`, and
  databases-per-account ceilings become a real limit to watch (§10.4).
- 2026-11-23 12:59 EET — **No data move from current hosted installations.** They start on fresh D1 databases and
  re-import a backup if they hold data worth keeping. Rationale: hosted has been live since 2026-11-12, so the set is
  small and a copy path would cost more than it saves. Consequence: the switch has no unmigrated-data risk left, and
  the hosted cutover is a provisioning job, not a data job.
- 2026-11-23 13:25 EET — **Self-host packaging promoted to a deliverable (§11, S0.9), on Stathis's request.** Two compose files (`apotheke` + `rqlite`, and single-process SQLite), `.env.example`, a literal walkthrough in `docs/self-host-docker.md`, and `scripts/selfhost-smoke.sh` that CI runs against both. Rationale: a self-host promise that lives only in prose is a promise nobody tests; the compose file is also the cheapest way to pin env names, volumes and ports before the app hardens around them. Cost: about 1 pw inside P6, near zero for the phase-0 skeleton with a stub image.
- 2026-11-23 13:17 EET — **D9: no branch protection on `main` or `experimental-v4`.** Rationale: Stathis's call, and it removes a step that was twice reported as pending on him. What it costs: the `main-freeze` workflow only gates pull requests, so a direct push or a force-push to `main` is unguarded, and a mis-aimed push would publish PHP-replacing code with no review. Mitigation: both bots are instructed never to push to `main`, never to open a PR against `main`, and the PM reads `main`'s head before signing off any gate. Revisit if `main` ever moves without a release run.
- 2026-11-23 12:58 EET — **D1 revised again, on Stathis's clarification: hosted defaults to Cloudflare D1.** Self-host
  defaults to rqlite with plain SQLite supported; Postgres stays dropped. Rationale: D1 is the native hosted SQLite —
  no server to run, `batch()` atomicity, FTS5 already proven on that path in production, and provisioning/deletion map
  cleanly onto the existing lifecycle jobs. Consequences in §10: three backends, one dialect; the batch contract becomes
  the single unit-of-work model (which rqlite forces anyway); the `d1.internal` bridge and the DO-as-database-proxy
  become redundant on hosted. Net effort +1 pw against the original plan.
- 2026-11-23 12:56 EET — **D1 reversed: PostgreSQL is dropped.** v4 supports SQLite (DO) and rqlite; rqlite is the self-host default. No migration path is built — a v3 backup ZIP exported and imported into v4 is sufficient, for v3 SQLite and v3 Postgres installs alike. Rationale: rqlite is SQLite over HTTP, so the SQL text, the schema, the migrations and the parity comparison stay single-dialect; it needs no Node shims under workerd; and it gives self-hosters replication and snapshots without a second engine to test. What it costs: no transaction spans a request (audit in S0.4), one extra process in the self-host stack, and every query becomes an HTTP round trip. Supersedes the 12:52 entry that kept Postgres.
- 2026-11-23 12:56 EET — Clock correction: the earlier D1–D7 entries were stamped 13:05/13:22, ahead of the actual time. Relabelled 12:52/12:54; content unchanged.
- 2026-11-24 00:20 EET — **Revision 8: the plan now describes the code that exists.** Rationale: four §3 rows had
drifted (vitest plugin, `workerd` override, SWC parser, `secretbox.ts`), and three rules the slices settled (D13's
allowlist, D14's seed gate, D10's bootstrap exception) lived only in the phase notes, so the next reader would have
re-derived them. No scope change; effort and gates stand.
- 2026-11-24 00:35 EET — **Revision 9: S0.8 lands, and G0's rqlite leg is proven.** Rationale: the spike that read
  "unproven, not a pass" was settled by the rqlite job in run `45279526055` — workerd answers `/readyz 200
  {"schema":"0036","seed":"done","driver":"rqlite"}` against a real `rqlited` v10.7.4 with `-auth` — so the plan could
  no longer describe it as open. Two red runs on the way (`45277610646`, `45278160385`) were the builder's own defects,
  both fixed with the cause in the commit: a log-grep assertion that a green run never satisfies, and a fixture that
  left schema version `9999` behind and made the next boot refuse correctly. What it costs: nothing; it narrows the
  next slice to S0.6's D1 job. What it does **not** prove, and says so: TLS from workerd to a self-signed `rqlited`,
  retry across a real election (G3), and real hosted D1 (phase 1).
- 2026-11-24 00:20 EET — **Phase-0 order set by the gate, not by the slice list: S0.8 next.** Rationale: G0 needs the
rqlite leg and `v4.yml`'s rqlite job, nothing else outstanding does; the router (S0.5) and the compose skeleton (S0.9)
are not G0 inputs, and S0.9 is cheaper after S0.8 because it pins the `RQLITE_URL` contract. What it costs: the
phase-0 packaging deliverable slips behind the driver, which is the honest order anyway.

## 10. Database backends (D1, revised) — D1, rqlite, SQLite

Three backends, one dialect, one code path.

| | Hosted (`apotheke-api`) | Self-host (`workerd`), default | Self-host, single process |
|---|---|---|---|
| Engine | **Cloudflare D1** | **rqlite** | local Durable Object SQLite |
| Why | Cloudflare-native SQLite: no server to run, `batch()` gives atomicity, provisioning and deletion are API calls, FTS5 already proven on this path | SQLite semantics over HTTP with replication and snapshot backups; nothing new to learn, nothing to translate | One binary, one volume, no second process; the "just try it" install |
| Driver | `env.DB` (`prepare`/`bind`/`all`, `batch`) | `fetch` against `/db/execute` and `/db/query` (S0.8) | `ctx.storage.sql` wrapped in the async interface |
| Files | R2 | disk bucket `/data` | disk bucket `/data` |
| Selected by | binding `DB` | `DB_DRIVER=rqlite` + `RQLITE_URL` | `DB_DRIVER=sqlite` |
| Isolation unit | one D1 database per installation (D8) | one rqlite node/cluster per installation | one DO per installation |

Rules that make one code path work on all three:

1. `Sql` is async everywhere. D1 and rqlite are network hops; local SQLite answers immediately and the driver returns a
   resolved promise. No handler knows which backend it is on.
2. **The unit of work is a batch, written for the weakest backend.** D1's `batch()` runs its statements atomically;
   rqlite has no transaction across requests (its docs call manual `BEGIN`/`COMMIT`/`SAVEPOINT` undefined and require
   dependent steps in one request); local SQLite has `transactionSync` but the app does not rely on it. So:
   **plan-then-flush** — statements are collected and sent as one request. This is a simplification of revision 3, not
   a compromise: the two production backends now share one model.
   - `atomically()`/`inUnit()` stop being a live callback over a connection. All 10 call sites (7 files:
     `ItemRepository` ×4, `Imports`, `UndoApply`, `ItemController`, `Duplicate`, `BulkService`, `UserRepository`) are
     audited in S0.4 and classified.
   - Where a later statement needs an id from an earlier one, the dependency moves into SQL
     (`INSERT … SELECT last_insert_rowid()`), because the batch is sent in one shot and TypeScript never sees an
     intermediate result.
   - A call site that genuinely needs read-after-write inside one unit of work is reported to me before phase 1.
3. Parameter discipline is unchanged and now has a reason again: **D1's limit is 100 bound parameters per statement**,
   which is exactly ADR-002's cap. The same ceiling applies to rqlite and local SQLite, so chunking is unchanged and no
   backend sees a query the others could not run.
4. Migrations carry **one** dialect. All three are SQLite: the 35 migrations, the schema and the parity diff are
   written once. No `Dialect` branching in the runner.
5. Text rules come free: `LIKE` behaves identically on all three, so the PHP `lower()` comparisons stay as they are.
6. Ids and sequences are unchanged (`INTEGER PRIMARY KEY AUTOINCREMENT`). D1 returns `meta.last_row_id` and
   `meta.changes`; rqlite returns `last_insert_id` and `rows_affected`; only in-batch dependencies need the SQL form.
7. **FTS5** is known-good on D1 — the hosted path already builds FTS5 virtual tables through the container bridge
   (`modules/Migrations/D1SchemaDump.php` handles the shadow tables) — so nothing new is assumed there. What is unproven
   is **FTS5 in a stock `rqlited` build**, which is a phase-0 spike. The existing engine probe (`SearchIndex::engine()`)
   already degrades to `like` when `search_fts` is missing, so the fallback is real code: if stock rqlite lacks FTS5,
   that install runs substring search, the docs say which engine an install is on, and the parity gate compares search
   only within the same engine.
8. **Read consistency:** D1 is read-your-writes within a request; rqlite's default is Weak (leader-forwarded), which
   on a single node is exact. `RQLITE_READ_CONSISTENCY=weak` is the only knob, and a read that follows a write in the
   same request is sent with `level=strong` so a lagging second node can never answer it stale. Bounded retries live
   in the drivers — but writes may only retry under rule 10.
9. **Backups:** Apotheke's ZIP stays the format of record on every backend. rqlite's native snapshot and D1's
   export/snapshot are operational extras documented in the guides; neither is a second product format.

10. **A write retry is not free, because a `503` does not say whether the batch committed.** Phase-0 spike: rqlite
    answers `503` for ~1.6 s during a leader election and `200` again at 2.0 s with the data intact. The HTTP API has
    no idempotency key, so a blind retry of `execute` can apply the same batch twice. The rule the driver implements,
    and the only one:
    - **Reads retry freely** (connect error, `503`, timeout) with bounded exponential backoff — replaying a
      `SELECT` is harmless.
    - **Every write unit carries an idempotency key.** The batch opens with
      `INSERT INTO _write_log (id, created_at) VALUES (?, ?)`, the id a UUID minted per unit of work. The spike proved
      a failed statement aborts the whole transaction (the preceding `CREATE TABLE` and `INSERT` both rolled back), so
      a replayed batch dies on the primary-key conflict *before* any other statement runs; the driver maps
      `UNIQUE constraint failed: _write_log.id` to success. Exactly-once, with no idempotency feature from rqlite and no
      per-call-site judgement: a single-statement write uses the same envelope as a ten-statement one.
    - **A connect failure** (connection refused, DNS failure, TLS handshake) is safe to retry without the key — no
      bytes reached the server. Anything after the request left the socket is not safe, and is not retried without it.
    - `_write_log` is part of the schema on all three backends, carries a `created_at` index, is swept by the existing
      alarm job (`DELETE FROM _write_log WHERE created_at < ?`, 24 h), travels in the backup ZIP, and a restore keeps
      its keys so a retry that crosses a restore cannot double-apply.
    - **Bootstrap exception.** A unit that runs *before* 0036 exists cannot open with an insert into `_write_log`,
      because the table is not there yet. Those units — the migration runner's own apply-units — key on
      `schema_migrations`' row instead: the unit inserts its `schema_migrations` row first, so a replay dies on that
      primary key. Once 0036 is in, every write unit uses the `_write_log` envelope and no call site chooses which.
    - D1 does not need this (`batch()` is atomic and the binding reports per-statement failures), but it gets the same
      envelope so a call site is written once and tested once.

### 10.1 What the revisions removed

Between revision 1 and 4 the database work lost: PostgreSQL and its dialect layer, `postgres.js`/`pg` and their spike,
the `postgres:16` CI service, `DB_DRIVER=postgres`, dual-dialect migrations, and the `LIKE`/`ILIKE` divergence rules
(about 3 pw out). It gained: the rqlite driver, the D1 driver, D1-per-installation provisioning, and the transaction
audit (about 4 pw in). Net +1 pw against the original plan, one dialect instead of two.

Also gone earlier than planned: the `d1.internal` outbound bridge and the Installation-DO-as-database-proxy
(`edge/src/d1.ts`) exist only because the PHP container could not hold the D1 binding. With TypeScript in the Worker,
hosted talks to D1 directly. **Resolved in S0.3 (D12, revision 7):** the `Installation` Durable Object stays, one per installation, demoted from
database owner to scheduler and lock — `alarms` for per-installation jobs (retention and the 24h `_write_log` sweep,
backup scheduling, metering), because Cron Triggers are per-Worker and not per-installation; `blockConcurrencyWhile`
as the migration lock and refuse-if-newer gate; lease and cursor state only. It holds no application data and is off
the request path. The one exception is `DB_DRIVER=sqlite`, where `ctx.storage.sql` *is* the database and the DO
therefore owns it; hosted D1 and rqlite both run the reduced DO. No handler may know which mode it is in — that is
the async `Sql` adapter's job.

**Known defect carried into v4, logged and not fixed on `main`:** `RoleAdmin::updateLocked()`/`deleteLocked()`
compute the "at least one manager remains" invariant in PHP and lean on a unit lock that a batch backend does not
have, so two concurrent role edits can both pass — on D1 today, not only on rqlite. `UserAdmin` already carries the
equivalent guard as SQL, so the asymmetry is visible. v4 makes it one in-batch `EXISTS` assert (audit site #10).
Whether the same fix ships on frozen `main` as a security patch is Stathis's call; until he says so, `main` is untouched.

### 10.2 Migration path: none, by decision

- **Self-host, v3 → v4:** export a v3 backup ZIP (from the PHP app, SQLite or Postgres alike) and import it into a
  fresh v4 installation. P5's importer must therefore accept real v3.72 ZIPs and is tested against fixtures produced by
  `main`. No in-place upgrade, no dual read, no shadow install.
- **Hosted, current installs → D1:** no data move. Hosted installations start on fresh D1 databases and re-import a
  backup if they have data worth keeping (decided 12:59). This removes the only piece of the switch that was not
  covered by the existing suites.
- Postgres installs leave v3 the same way as anyone else: export, then import into v4 on rqlite.

### 10.3 Self-host footprint

Default: `rqlited` (one process, `/data/raft` + `/data/db`) beside `workerd`. Single-process option: `workerd` alone
with `DB_DRIVER=sqlite`, accepting one node and local disk. Both documented in the self-host guide with ports, volumes
and backup steps.

### 10.4 Hosted tenancy (D8): one D1 database per installation

Decided 12:59. Each hosted installation gets its own D1 database; there is no shared database with an
`installation_id` column. What that implies, for P6:

- The control plane gains the database id per installation (`d1_database_id`), written at provisioning time and read by
  the route (`edge/src/route.ts` already resolves a host to a DO name; it resolves to a D1 database instead).
- Provisioning creates the database through the Cloudflare API inside the existing lifecycle job, runs the migrations,
  and records the id. Deletion drops the database. Both are audited like the current lifecycle steps.
- A worker serving host `x` binds that one installation's database per request, so the binding is resolved at runtime
  rather than declared in `wrangler.jsonc`; the DO named for the installation keeps whatever remains of its role after
  S0.3 (alarms, migration lock), and nothing else.
- Backup/export and restore are per database, which is what the ZIP format already assumes.
- Practical limits worth watching: databases-per-account ceilings and per-database size. If a ceiling is hit, the answer
  is a site-limit decision for Stathis, not a silent switch to a shared database.

Out of scope: PostgreSQL anywhere in v4; Hyperdrive; multi-tenant Postgres on the hosted service.

## 11. Self-host packaging: one compose file, two containers

The promise to an end user, in one line: clone, `cp .env.example .env`, `docker compose up -d`, open
`http://localhost:9091`, claim the first admin. Nothing to install, nothing to compile, no database to prepare.

| File | Purpose |
|---|---|
| `docker-compose.yml` | default stack: `apotheke` + `rqlite` |
| `docker-compose.sqlite.yml` | single-process variant: `apotheke` only, `DB_DRIVER=sqlite` |
| `.env.example` | every knob with a safe default and a comment; no real secrets |
| `docs/self-host-docker.md` | the literal walkthrough, including upgrade, backup, restore, troubleshooting |
| `scripts/selfhost-smoke.sh` | boots a stack, runs the acceptance steps, tears it down; used by CI and by operators |

Service contract (this is what S0.9 pins, and what the docs are written from):

| Service | Image | Published | Volumes | Key env |
|---|---|---|---|---|
| `apotheke` | `ghcr.io/thordin9/apotheke:<version>` | `9091:9091` | `apotheke-data:/data` (uploads, exports) | `DB_DRIVER=rqlite`, `RQLITE_URL=http://rqlite:4001`, `APP_KEY`, `PUBLIC_BASE_URL` |
| `rqlite` | `rqlite/rqlite:10` (pinned to the tested major: v10.7.4, SQLite 3.54.1) | **nothing** (4001 stays on the compose network) | `rqlite-raft:/data/raft`, `rqlite-db:/data/db` | `-http-addr 0.0.0.0:4001`, `-raft-addr 0.0.0.0:4002`, optional `-auth` file |

Rules that keep the self-host path honest:

1. **The database is an implementation detail.** rqlite's HTTP port is not published by default; publishing it is a
   documented debug step, not the normal install. The app reaches it at `http://rqlite:4001` on the compose network.
2. **Start order is handled twice.** `depends_on: condition: service_healthy` on rqlite, *and* the driver's retry/timeout
   policy (S0.8), because `depends_on` does not survive a rqlite restart mid-session. The app must come up correctly if
   it wins the race.
3. **Named volumes, not relative bind mounts,** in the default file — root-owned files from a relative bind mount are
   the most common self-host foot-gun. Bind mounts stay documented for NAS/Proxmox users who want them.
4. **Secrets live in `.env` only** (`APP_KEY`, SMTP, `RQLITE_AUTH`). No secret in either compose file, and
   `docker compose config` is safe to paste into an issue.
5. **Upgrade is `docker compose pull && docker compose up -d`.** Migrations run on boot under
   `blockConcurrencyWhile`; a database newer than the image is refused with an explicit message rather than
   half-migrated. Downgrade is "restore the backup", stated plainly.
6. **Backup is the app's ZIP** (the format of record). `rqlite-backup` and a raw volume snapshot are documented as
   operational extras; neither is a second product format, and restore is always "upload the ZIP in the UI".
7. **Healthchecks use the app's own `/healthz`** — no assumption that `curl` exists in the image. The rqlite
   healthcheck is the awkward one: `/readyz` requires credentials as soon as `-auth` is on (a bare `curl` gets 401),
   so it passes `RQLITE_AUTH` from `.env`, and with no auth configured it degrades to a TCP connect on 4001. A 401
   read as "unhealthy" would put the app in a restart loop against a perfectly good database.
8. **Both files are smoke-tested in CI** on every PR that touches packaging, so the docs cannot drift from the
   artifacts: `scripts/selfhost-smoke.sh docker-compose.yml` and `... docker-compose.sqlite.yml`.
9. No Postgres service, no external object store, no volume the operator must pre-create.

Acceptance for G6 (and for S0.9 once the stub image exists): stack up on a clean machine with no Docker cache, claim
admin, create an item, upload a photo, download the backup ZIP, restore it, `docker compose restart`, and the catalogue
is still there — twice, once per compose file.
