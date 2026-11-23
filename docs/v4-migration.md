# Apotheke v4 — TypeScript on Workers/workerd: execution plan

Owner: Apotheke PM · Build: Apotheke FullStack Developer · Branch of record: `experimental-v4`
Created 2026-11-23 12:50 EET · Revision 4 at 12:58 EET — **hosted defaults to Cloudflare D1**; self-host defaults to
rqlite with plain SQLite supported; PostgreSQL is dropped entirely; no migration path is built (v3 export → v4 import,
and hosted installations start on fresh D1 databases). Revision 3 (12:56) dropped Postgres; revision 2 (12:52) recorded
D2–D7. Supersedes `docs/future/workers-migration-plan.md`, which stays on `main` as the study; this file is the
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
| 1 | Nothing new lands on `main` | Branch protection on `main` (no direct pushes, required review). A `main-freeze` workflow fails any PR into `main` that is not labelled `revert` or `security` and approved by Stathis. `main` keeps the PHP app and its CI exactly as it is today. |
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
  test/                     vitest (@cloudflare/vitest-pool-workers), fixtures, golden dumps
  migrations/               ported runner + the 35 existing migration definitions
```

Why a separate `v4/` workspace instead of growing inside `edge/`: `edge/` is the deployed hosted path from `main`;
keeping the port out of it means the branch cannot break main's deploy job, and phase 6 folds `v4/src` into
`edge/src` in one reviewable commit. It also lets `npm test` in `v4/` be a single command with no PHP.

## 3. Stack choices (made here, so the work does not stall)

| Concern | Choice | Why |
|---|---|---|
| Language | TypeScript 7, `strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, ESM | Already the version in `edge/`. |
| Runtime | Workers (`workerd`), `compatibility_date` shared with `edge/wrangler.jsonc` (2026-10-29), `nodejs_compat` | One compatibility date for hosted and self-host, checked by a test. |
| HTTP | Hand-written router + middleware chain mirroring `app/Config/Routes.php` and the 14 filters in `app/Config/Filters.php` | CI4 semantics (permission per route, CSRF, host check) are the contract; a framework's conventions would have to be un-taught. No Hono. |
| Views | Typed template functions returning HTML strings, one file per CI4 view, escaped through a single `h()` helper | Golden-page byte equality is achievable; no client build step for the UI. |
| SQL | No ORM. Ported SQL behind an **async** `Sql` interface with three drivers: D1 (`env.DB.prepare/bind/all` + `batch`), rqlite (HTTP), and `ctx.storage.sql` (local DO SQLite). One SQLite dialect, so the SQL text is shared verbatim. ADR-002's 100-param-per-statement cap is **D1's own limit** and is kept as the ceiling for all three. | The SQL is already written and tested; an ORM would rewrite it and hide the parameter discipline. Async everywhere because two of the three drivers are network hops, and D1's `batch()` is the strongest atomic primitive D1 offers. |
| Database | Hosted: **D1**, one database per installation (a binding, `env.DB`), with the control-plane D1 unchanged. Self-host: **rqlite** (default) or local SQLite for a single-process install. Chosen per environment by the binding or `DB_DRIVER`. | D1 is the Cloudflare-native SQLite with `batch()` atomicity and no server to run; rqlite gives self-hosters SQLite over HTTP with replication and snapshots. One dialect, three backends (§10). |
| D1 provisioning | One D1 database per hosted installation (D8), created through the Cloudflare API in the existing lifecycle job, its id recorded in the control plane; deletion = drop the database; backup/export is per database. | Isolation matches today's per-installation Durable Object, a backup/export is per database, and deleting an installation is a single drop. |
| rqlite driver | Plain `fetch()` against rqlite's HTTP API (`/db/execute`, `/db/query`), parameterised statements as `[sql, ...values]`, associative row form, `error_v2` handling, `db_timeout`, basic auth or TLS. No Node shims, no `cloudflare:sockets`. | The HTTP API works in workerd with nothing but `fetch`, and it is the only interface rqlite promises to keep stable. |
| Files | R2 behind a `Blobs` adapter; keys unchanged (`installations/<do_name>/…`) | Same buckets, same keys, no migration. |
| Crypto | WebCrypto HMAC for CSRF/tokens; `bcryptjs` for password hashes; `libsodium-wrappers` for `enc:v1:` secretbox values | `bcryptjs` verifies existing PHP `password_hash(…, PASSWORD_DEFAULT)` hashes unchanged, so no user is locked out; libsodium reads existing encrypted settings byte-for-byte. |
| ZIP | `fflate` (streaming) | Backup/restore format must stay readable by 2.6.x and the PHP image; PHP writes and TS reads, and the reverse, are both CI tests. |
| QR/labels | `qrcode` (pure JS), output compared against the PHP `Labels\QrCode` fixtures | Same pixels on the label sheet. |
| Images | `ImageProcessor` adapter: Images binding (hosted) / WASM codecs (workerd, lower pixel cap) / browser-side pre-resize | See decision D2. The adapter keeps the choice out of the app code. |
| Unit tests | `@cloudflare/vitest-pool-workers` (runs the real bundle in workerd with real DO SQLite and R2) | Tests exercise the runtime that ships, not a Node mock of it. |
| Lint | Biome + `dependency-cruiser` with rules copied from the deptrac module graph | `deptrac` and the DI rule stay for PHP; the TS graph gets the same boundaries. |
| CI | New `.github/workflows/v4.yml` on PRs into `experimental-v4` and pushes to it. `ci.yml` on `main` untouched | The frozen branch keeps its own green/red signal. |

## 4. Frozen interfaces (what "no regression" means concretely)

All three backends are SQLite, so there is exactly one target schema. Nothing to normalise across dialects.
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
- S0.1 Branch and freeze: cut `experimental-v4` from `main` @ `4a187d0f`; protect it; add `main-freeze` workflow; commit this plan as `docs/v4-migration.md`; set `VERSION` to `4.0.0-dev`.
- S0.2 `v4/` workspace: package.json, tsconfig, `wrangler.jsonc` (worker `apotheke-v4`, no routes, no containers), vitest-pool-workers, biome, dependency-cruiser, npm scripts (`dev`, `test`, `typecheck`, `lint`).
- S0.3 Platform adapters + `Installation` DO: `Sql` (async, driver-pluggable), `Blobs`, `Clock`, `Secrets`, config loader, `blockConcurrencyWhile` migration-on-open, schema-newer-than-code refusal, `/healthz`.
- S0.4 Migration runner: the 35 migrations as typed steps, one SQLite dialect (shared by D1, rqlite and local SQLite), refuse-if-newer, unit of work supplied per driver (D1 `batch()`, one `?transaction` request on rqlite, `transactionSync` locally), plus the **transaction audit**: every `atomically()`/`inUnit()` call site (10 across 7 files) classified as expressible-in-one-batch or not, with the rewrite listed for each. Code is written to the batch contract, the weakest of the three.
- S0.5 Router + filters: route table generated from `Routes.php` (method, path, permission, filter list), the filter chain in the real order (`hostcheck`, `publicbase`, `session`, `sameorigin`, `permission`, `sessioncsrf`/`apicsrf`), problem+json errors, security headers/CSP.
- S0.6 CI `v4.yml`: typecheck → lint → unit → schema parity → workerd smoke (boot, `/healthz`, one table read), plus a **rqlite job** that starts a real `rqlited` and repeats migration, parity and handler suites, plus a **D1 job** using the Miniflare-emulated D1 binding under `vitest-pool-workers`. Real-D1 smoke (a database created through the API) runs on the branch only when a Cloudflare token is present, because the emulator is SQLite and would hide D1-specific limits.
- S0.7 D1 driver: the `Sql` implementation over `env.DB` (`prepare`/`bind`/`all`, `batch` as the unit of work, `meta.changes`/`last_row_id` for results), the binding-name contract, and the local emulation path so the same code runs in tests.
- S0.8 rqlite driver (D1): `Sql` implementation over the HTTP API, `DB_DRIVER=rqlite` boot path, plan-then-flush unit of work (`/db/execute?transaction`), read-consistency and retry/timeout policy, auth/TLS from the environment, and a documented `RQLITE_URL` contract for self-host.

Timeboxed spikes inside phase 0 (one day each, result written into the phase notes):
FTS5 available in DO SQLite under local `workerd`; DO alarms firing with `localDisk` storage; the bundled R2 disk
bucket surviving `wrangler dev` restarts; `libsodium-wrappers` decrypting a PHP-produced `enc:v1:` value;
`bcryptjs` verify cost for `PASSWORD_DEFAULT` hashes at cost 12; the Images binding's offline limits;
rqlite from `workerd` (boot against a real `rqlited`, parameterised round trip, one atomic batch, TLS and basic auth, retry across a leader restart), FTS5 availability in the rqlite build, and the no-cross-request-transaction audit on the real call sites
and the one that can send D1 back to Stathis if the batch model cannot carry the write paths.

on **all three** backends (identical SQLite schema, so one comparison, run three times); `/healthz` 200 from `wrangler dev` on D1 and SQLite and from a workerd-only boot against a real `rqlited`; `v4.yml` green including the rqlite and D1 jobs.

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
environment); self-host docs; fold `v4/src` into `edge/src` and delete the container code paths.

**Gate G6:** lifecycle tests green without a container; the workerd image passes a self-host smoke test twice — once
once against a rqlite node
still there); `docs/self-host-workerd.md` written and followed literally by someone other than its author, covering
both database options.

### Phase 7 — Parity gate and merge decision (1–2 pw)

Full matrix: every Playwright project against v4 with untouched baselines; parity recorder zero unexplained diffs;
contract tests; performance sanity (list/detail p95 within 1.5× of PHP on the same data); upgrade notes; a merge
proposal with the diff summary.

**Gate G7:** E2E 100% green on `experimental-v4` with unmodified baselines, and Stathis says go. Merging `main` ←
`experimental-v4` is the release decision, not a build decision.

### Phase 8 — Decommission (post-merge, only with explicit approval)

Delete `ApothekeContainer` with a `deleted_classes` migration (destroys that object's storage — owner approval),
The PHP Postgres/PDO code stays on `main` for v3 only; v4 never speaks Postgres.
it is the self-host database.

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
| Write replay | PHP and v4 from the same seed, on both engines | identical table dumps and blob lists after writes |
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
| The rqlite path rots because everyone develops on the DO |
| View porting drift (thousands of DOM details) | visual suite fails late and expensively | golden corpus per page in phase 2, visual per phase, no baseline updates without sign-off |
| ZIP format drift | backups unreadable by 2.6.x/PHP | two-direction round trip in gate G5 |
| Long-lived branch diverges from main | painful merge | `main` frozen by rule, so divergence is bounded; weekly rebase of the branch onto `main` is forbidden — only forward merges of approved security fixes |
| workerd features still experimental (localDisk, bucket, r2.js) | self-host fragility | pin workerd version; document the pin; smoke test on every PR |
| Scope creep into an SPA rewrite | doubles the work | D3: v4 keeps the server-rendered UI; the SPA stays a separate track |

## 8. Decisions (answered 2026-11-23, 12:52–12:59 EET)

| # | Decision | Answer | Effect on the plan |
|---|---|---|---|
| D1 | Database backends | **Revised 12:58 — Postgres dropped.** Hosted defaults to **D1**; self-host defaults to **rqlite**, plain SQLite supported; no migration path, v3 export → v4 import | Plan §10, S0.7 (D1) and S0.8 (rqlite), all three backends in G0/G3/G6 |
| D8 | Hosted D1 tenancy | **Answered 12:59 — one D1 database per installation**, no shared database. No data move from current hosted installs: they start fresh and re-import a backup if needed | Control plane stores the D1 database id; provisioning and deletion run through the existing lifecycle jobs |
| D2 | Media pipeline | **Yes as recommended**: server work capped at 12 MP, big phone photos pre-resized in the browser, HEIC decoded client-side, originals stored untouched | Phase 4 as planned; non-browser uploads over 12 MP get a clear 422 |
| D3 | UI through the port | **Server-rendered HTML stays for now**; the SPA remains a separate track | Phase 2 ports views, not a client app |
| D4 | Evidence before `main` ← `experimental-v4` | **G7 only** — every suite green on the branch with untouched baselines, then Stathis's go. No dress rehearsal, no shadow period | Phase 7 is G7 + the merge proposal: 1–2 pw, nothing extra |
| D5 | Sessions at cutover | **One re-login per installation** is acceptable | PHP session serialisation is not ported |
| D6 | Workers Paid and CPU budget | **Acceptable** | bcrypt cost 12 and image work stay; cost re-checked at phase 4 |
| D7 | Switch-over | **Ping Stathis when the branch work is complete and ready to switch**; he makes the go call | G7 ends with a written merge proposal, never a merge |

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
- 2026-11-23 12:58 EET — **D1 revised again, on Stathis's clarification: hosted defaults to Cloudflare D1.** Self-host
  defaults to rqlite with plain SQLite supported; Postgres stays dropped. Rationale: D1 is the native hosted SQLite —
  no server to run, `batch()` atomicity, FTS5 already proven on that path in production, and provisioning/deletion map
  cleanly onto the existing lifecycle jobs. Consequences in §10: three backends, one dialect; the batch contract becomes
  the single unit-of-work model (which rqlite forces anyway); the `d1.internal` bridge and the DO-as-database-proxy
  become redundant on hosted. Net effort +1 pw against the original plan.
- 2026-11-23 12:56 EET — **D1 reversed: PostgreSQL is dropped.** v4 supports SQLite (DO) and rqlite; rqlite is the self-host default. No migration path is built — a v3 backup ZIP exported and imported into v4 is sufficient, for v3 SQLite and v3 Postgres installs alike. Rationale: rqlite is SQLite over HTTP, so the SQL text, the schema, the migrations and the parity comparison stay single-dialect; it needs no Node shims under workerd; and it gives self-hosters replication and snapshots without a second engine to test. What it costs: no transaction spans a request (audit in S0.4), one extra process in the self-host stack, and every query becomes an HTTP round trip. Supersedes the 12:52 entry that kept Postgres.
- 2026-11-23 12:56 EET — Clock correction: the earlier D1–D7 entries were stamped 13:05/13:22, ahead of the actual time. Relabelled 12:52/12:54; content unchanged.

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
8. **Read consistency:** D1 is read-your-writes within a request; rqlite uses its default (Weak, leader-forwarded). No
   knob is exposed to the app, and nothing needs to see another client's uncommitted work because nothing is ever
   uncommitted across requests. Bounded retries (leader change, `503`, D1 transient errors) live in the drivers.
9. **Backups:** Apotheke's ZIP stays the format of record on every backend. rqlite's native snapshot and D1's
   export/snapshot are operational extras documented in the guides; neither is a second product format.

### 10.1 What the revisions removed

Between revision 1 and 4 the database work lost: PostgreSQL and its dialect layer, `postgres.js`/`pg` and their spike,
the `postgres:16` CI service, `DB_DRIVER=postgres`, dual-dialect migrations, and the `LIKE`/`ILIKE` divergence rules
(about 3 pw out). It gained: the rqlite driver, the D1 driver, D1-per-installation provisioning, and the transaction
audit (about 4 pw in). Net +1 pw against the original plan, one dialect instead of two.

Also gone earlier than planned: the `d1.internal` outbound bridge and the Installation-DO-as-database-proxy
(`edge/src/d1.ts`) exist only because the PHP container could not hold the D1 binding. With TypeScript in the Worker,
hosted talks to D1 directly. **Open design point for S0.3:** what is left of the `Installation` Durable Object once it
stops holding the database — probably alarms/job scheduling and a migration lock, possibly nothing. Decided in phase 0,
not assumed.

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
