# Apotheke v4 — TypeScript on Workers/workerd: execution plan

Owner: Apotheke PM · Build: Apotheke Builder · Branch of record: `experimental-v4`
Created 2026-11-23 12:50 EET · **Revision 34 at 10:02 EET on 2026-11-25** — phase 1's contents list one item that no slice owned: **the account page**. §5 has said "account pages" since revision 15; the slice list runs S1.1–S1.4 and covers the page shell, sessions, users and roles, and the session API — none of them `/account` — and **Gate G1's own sentence said "the proof that S1.1–S1.4 hang together"**, so the first reader after S1.4's acceptance would have closed phase 1 with a signed-in user's own page unbuilt. It is unbuilt: `Routes.php` puts `GET`/`HEAD`/`POST` `/account` and `POST /account/signout` on `AccountController::index`/`::signOutEverywhere` (`routes.generated.ts:45`, `:177-178`), `src/http/router.ts`'s `HANDLERS` map (`:77-96`) binds sixteen handler names and none is `AccountController`, so both paths fall to `notPorted` (`:184`) and answer **501** at `e2c49a21` — read in the map, not inferred. Nothing catches that today: `test/html/unported.spec.ts` lists only page routes whose permission is `none` or `public`, and `/account` needs `self`, so it is in no spec at all and would stay silent if it rendered wrong. **S1.5 is added** with a definition of done read out of `AccountController.php` and `AuthViews::account()`, and **Gate G1 now reads S1.1–S1.5**. What it costs: one increment (one page, two POSTs, one epoch path) before G1 can be signed, and one clause of G1's sentence. What it does not do: it does not reopen S1.4, change a gate, or touch `main`. **Revision 33 text follows.****Revision 33 at 09:27 EET on 2026-11-25** — S1.4 is accepted, and its acceptance changed three things the plan had said. `ae9d661c` (run `45566485521` green 09:13:45→09:15:32 EET: seven jobs, 72 steps, zero non-success, zero skipped; topic branch `v4/s1.4-session-api` at the same head) wires `SessionController`'s handlers onto the four rows that already existed and answer **501** through `notPorted` — no route is added, and `routes:check` still reads 281 routes / 3 chains, which I ran locally at that head rather than taking the builder's word for it. Two commits, 20 files, +3061/−184. `state()` is six keys in PHP's order (signed_in, sign_in_enabled, registration_open, user, permissions, csrf_token), `account()` is **four** (id, username, role, permissions) — my dispatch said "a different, smaller shape" for `/me` and was wrong by one key, which `docs/api/openapi.yaml`'s `Me` schema had said all along. Refusals are v3's own: 409 `auth.sign_in_off`, 400 `bad_request`, 422 `validation.failed`, 401 `auth.invalid_credentials`, 403 `auth.pending_approval`, 429 `rate_limited` with `RateLimiter::LIMITS['login'][1]` as `Retry-After` (`SessionController.php:70`), and `DELETE` answers 204 with no body. `apicsrf` is no longer a phase-0 refusal but v3's `ApiCsrf`: `Sec-Fetch-Site` same-origin/same-site when sent, an `Origin` that is the request's own, JSON or multipart else 415, the token in `X-CSRF-Token`, refusal 403 `csrf.invalid` with `ApiCsrf::DETAIL`. The contract is a **capture, not a table**: `scripts/gen-api-fixture.sh` boots v3 on a temp SQLite install and drives the four routes over curl for 28 answers (guest with and without the token, every 400/422, 401 wrong name and wrong password, 403 pending, guest browsing, a viewer signing in and out, the admin, registration open, sign-in off, the 409, the eleventh attempt's 429, both 204s), pins status plus nine named headers plus body bytes, records the SHA-256 of 28 PHP sources, and `--check` sits in `fixtures:check` — I ran it at the head and it prints "The api fixture is current: 28 sources unchanged". **The increment also found a defect that was already on the branch:** `resolveActor()` had v3's two synthetic visitors' flags *swapped* since 2a, so a sign-in-off installation allowed `self` (v4 would have served `/api/v1/me` as `id: null, role: ""`, where v3 answers 403) and a guest-browsing visitor got a 403 sentence where v3 answers "sign in". That is the argument for porting against source rather than against a summary, and it is why `SignInPace` was ported in the same commit: the deadline had been documented at S1.2 and called by nothing, so "a failed sign-in answers at the deadline" was false from S1.2 until `4faccedb`, at a stated cost of up to a second of wall time per failed attempt on `/login` as well as on the API. **Three narrowings are now named rather than implied:** `CORS_ALLOWED_ORIGINS` is not in §11's sixteen names, so v4's `ApiCsrf` accepts only the app's own origin and `PUBLIC_URL` where v3's also admits configured CORS origins (`ApiCsrf.php:36`) — a self-hoster who sets it is refused where v3 accepts; v4 sends none of PHP's `Expires`/`Pragma` on these answers, and the nine pinned headers do not include them, so the difference is written here instead of being asserted by the oracle; and `AuthPages::firstAdmin()` is not a per-request write in v4 — the first account comes from the seed phase, identical once booted, with the case that differs being an installation that turns sign-in on later without an account (`AuthPages.php:105-111`). D30's timing moves with the surface: the full suite is **89.63 s** at `c8cf8d62` measured here (300 tests passed / 6 skipped, 32 files) and **103 s** as the builder measured it on a busier box, against 74 s before S1.4 — the exact second is load-dependent and the only number worth keeping is that 74 is gone. `v4/docs/router.md`'s deferred table had listed `session`, `consent` and `apicsrf` as deferred, the first two since S1.2; it is corrected in the same commit. The header's build owner now names the bot that did the work (D32). **Revision 32 text follows.****Revision 32 at 20:16 EET on 2026-11-24** — the sqlite leg's fifth fact is pinned, and revision 31's own three sentences were corrected before they aged. `74868d8f` (run `45456706215` green 18:10:59→18:12:35Z: seven jobs, 72 steps, zero non-success, zero skipped; 3 files, +30/−4) adds the sixth spec in `v4/test/sqlite.spec.ts`, asserts `PRAGMA journal_mode` → `SQLITE_AUTH` and `SELECT sqlite_version()` → “not authorized to use function”, lists facts 1–5 in that file's header, and names all four deliberate throws in this leg's log — `too many SQL variables at offset 997`, `no such table: driver_missing`, `table broken has no column named nope`, `UNIQUE constraint failed: _write_log.id`. Only three of the four assert their text: `migrations.spec.ts:105` uses a bare `rejects.toThrow()`, which is the reason the header comment has to exist rather than a matcher being added there. The pair I called “measured by hand, pinned by no test” is now asserted, and `adapters.md` says “asserted, not inferred, in `test/sqlite.spec.ts`” instead. The increment took two pushes and the red one is worth keeping: `81731f6b` (run `45455617136`, 18:05:03→18:07:04Z) failed in the `sqlite` job at step 6, “Assert the sqlite run is complete”, because `v4.yml:379` pins that spec's assertion count at five and the increment made it six — and steps 7 and 8, the workerd boot and the database-bytes inspection, were skipped as a result, so the red run proved nothing about the driver while the pin made the omission visible inside one push. The fix was the number, not the rule. The finding about my own gates is the part I am keeping: **none of D30's six runs `v4.yml`'s jq rule**, so “six gates green” is not the claim “the sqlite leg is green”, and I had been reporting it as though it were — D30 is amended below rather than quietly stretched. S1.4 `/api/v1/session` remains 0 of 4 routes and no `v4/s1.4-*` ref exists on origin at 20:15. Revision 31 text follows.**Revision 31 at 18:55 EET on 2026-11-24** — the sqlite leg is its own job, and the premise I dispatched it on was backwards. `d8984a30` (18:40 EET, run `45439935862` green: seven jobs, 72 steps, seventy-two successes, zero non-success, zero skipped) adds `v4/test/sqlite.spec.ts` (five tests, six after revision 32), the `v4.yml` `sqlite` job, and the docs in the same commit — 5 files, +319/−3. The correction worth keeping: I sent the job saying “only D1 and rqlite are proven in CI, so the third driver is an assumption”, and that was wrong in the direction that matters. `wrangler.jsonc:49` sets `DB_DRIVER: "sqlite"`, so every existing test already ran `requestSql()` → `durableObjectSql` → the DO's RPC in every job: sqlite was the **most exercised** driver and the **least asserted**. No spec named its contract (nothing referenced `changes` except the rqlite ones), no job applied the count rule to that leg, and nothing read the database the DO wrote as bytes — while the `d1` job reads its own file and asserts the DO holds **zero** tables. The job's value is exactly those three things, not a fourth run of the same suite. What it pins: `changes`/`lastRowId` are `null` and a unit that changed a row reports exactly what one that changed nothing does (D26), so branching on `changes` on this driver is always a bug; a failing statement rolls the whole unit back, DDL included; the binding cap is **D1's 100**, not SQLite's 999 — 100 binds answer, the 101st refuses; a BLOB arrives as an `ArrayBuffer` (rqlite's live spec asserts `Uint8Array`, the emulated D1 binding hands back a byte array: one column, three JS shapes); and `PRAGMA`/`sqlite_version()` are refused on this driver and on emulated D1, which is why “the app cannot tune or even name its engine from SQL” is a fact rather than caution. One precision this revision owed a future reader, and revision 32 discharged: that last pair was measured by hand and documented (`v4/docs/adapters.md:172`) but pinned by no test, so nothing went red if it changed. The sixth spec now asserts it and the doc says “asserted, not inferred, in `test/sqlite.spec.ts`”. Counts, read from the logs rather than from the report: the sqlite leg's own jq rule checks per assertion that the only pending ones are the six `rqlite-live` specs, that all six are present and `skipped`, and that `test/sqlite.spec.ts`'s six ran (five at this commit; `v4.yml:379` was bumped 5→6 in `74868d8f` when the sixth landed); the leg printed `files=31 tests=272 failed=0 pending=6`, the unit and d1 jobs printed `30 passed | 1 skipped (31)` and `266 passed | 6 skipped (272)` — identical, which is the parity proof — and the rqlite job `31 passed (31)`, `272 passed`, because that is the leg that runs the six live specs. My DoD's “counts identical to the d1 and rqlite jobs” was therefore unsatisfiable as worded for rqlite; the builder's reading — identical to d1, and on this leg only the six known pending — is the right one. The DO's file, read with the `sqlite3` CLI: 36 `schema_migrations` rows, 64 `settings` rows, one active admin, 37 application tables in the DO's own storage (the exact inverse of d1's 0), no D1 database file, FTS5 diff clean. It also paid D30 back immediately: the first jq rule required the live specs' assertions to be `["pending"]` and failed locally, because vitest's JSON writes `"skipped"` per assertion while `.numPendingTests` counts them as pending — a check written against the aggregate passes against a spec that never ran. Two judgement calls recorded rather than thanked: the builder refused to put the §6 row inside the feature commit, on the rule that a docs file must be true at the commit it ships in and that this file's integrity depends on its header describing the diff — correct, so the row is here in revision 31 and the code commit says so; and the increment came in at +319/−3 against ~200, the workflow (138 lines) being the overrun, each step carrying the reason it exists — which is the part I would not trim either. S1.3 stays accepted at `bf1e60fb`; S1.4 `/api/v1/session` is untouched and next. Previous revision 30 at 18:35 EET on 2026-11-24** — S1.3 is **accepted**: the last slice of phase 1 that is about people, and the only one that writes permissions. `bf1e60fb` (18:20 EET, run `45436214312` green: six jobs, 60 steps, sixty successes, zero non-success, zero skipped, `routes:check` 281 routes / 3 chains, `fixtures:check` five oracles, `biome` 90 files, `depcruise` 56 modules / 133 dependencies, and the unit job printing **29 files passed / 1 skipped, 261 tests passed / 6 skipped**) ports `SecurityController::role()`: `POST /settings/roles`, `/{key}` and `/{key}/delete`, with the one JSON branch v3 has (`{ok, key}` on success, `{ok: false, error}` on a refusal) and v3's own statuses and words read out of `RoleAdmin.php` — 409 `Nobody could manage users any more – make someone an admin first.` (`:181`), 409 `Built-in roles cannot be deleted.` (`:175`), 422 `Choose another role for its accounts.` (`:178`). The permission matrix is now pinned twice and neither copy is hand-typed: `routes-parity.spec.ts:61-62`/`:91-95` assert the route column against `php-routes.json`, and `test/auth/permissions.spec.ts` asserts `Permissions::ALL`, `ROLE_DEFAULTS` and `RoleAdmin::NOT_FOR_GUESTS` against `php-permissions.json`, generated by `scripts/gen-permissions-fixture.sh` from `modules/Auth/Permissions.php` and `RoleAdmin.php` with their SHA-256s recorded and `--check` inside `fixtures:check`. Verified rather than trusted: regenerating that fixture on this box with a real PHP 8.4.4 CLI produced a byte-identical file (sha256 `84353c25…`), so the oracle is PHP's output and not a transcription of the TypeScript it checks. **D29's proof lands on the new surface too**: `POST /settings/roles` with no token answers 419 with v3's sentence, and the roles table is still four rows afterwards — the filter covers the handler that was added, which is the only thing that makes the decision worth having. Two contract changes ride with it and are named so they are not discovered later: `Ctx.post` is now a `FormPost` (a `Map` that also answers `all(name)`), because the editor posts one checkbox per permission (`perms[]=view&perms[]=users`) and PHP's `getPost('perms')` answers a list, while `get()` still answers the first value so no existing reader changed; and the delete branch is chosen from the matched route's own pattern ending in `/delete`, because that word is a literal in the table rather than a capture group. `docs/self-host-docker.md`'s summary sentence (`/login` is no longer the only page that answers) and `filters.ts`'s header line (`sessioncsrf` 419, `apicsrf` 403) are fixed in the same commit, and `guards.spec.ts` is untouched by both increments. What S1.3 does not cover, unchanged and still true: the section's settings form (`POST /settings/security`, `/settings/security/reset`, `POST /settings/password`) answers 501, and the page's header bar is P2. Previous revision 29 at 18:10 EET on 2026-11-24 — one process rule changes because the machine changed, and nothing in the plan's technical direction does. The development VM was rebuilt and dedicated to Apotheke: x86_64, six vCPU, ~9.7 GB RAM, load near zero. Measured at `5db8a9f8` on that box: `tsc --noEmit` 0.4 s, `biome check .` 0.2 s (89 files, three infos), `depcruise` 1 s (56 modules, 132 dependencies), `routes:check` 0.1 s (281 routes, 3 chains), `fixtures:check` 0.0 s, and **`vitest run` 74 s returning 28 files passed / 1 skipped and 248 tests passed / 6 skipped - the same counts as CI's run `45390457762`**. So the standing gate list grows one line (**D30**): typecheck, lint, dependency-cruise, `routes:check`, `fixtures:check` and now **`vitest run` before every push**. Section 6's rule that a phase is signed off only on a CI run is unchanged and is not what this relaxes: the local suite decides whether a push is worth a run, and CI still decides whether a slice is done. The environment note matters because the rebuild moved the architecture: this VM is x86_64, the node 22 and every native dependency under `v4/node_modules` were ARM, and `Exec format error` was being read as tooling flakiness. Node v22.14.0 for x64 is installed at `/home/rakazo/node22` and `npm ci` was re-run for the new architecture. Previous revision 28 at 14:12 EET on 2026-11-24 - records S1.3 increment 2a landing, and the one dependency it crossed, as a decision rather than a hidden scope change. `65d63014` (12:06 EET, run `45390457762` green: six jobs, 60 steps, sixty successes, zero non-success, zero skipped) is the Users & sign-in page — `src/html/security-view.ts` (489 lines), `src/auth/security-routes.ts` (222), `scripts/gen-security-fixture.php` (170) with its `--check` wired into `fixtures:check` beside the three older oracles — plus `GET settings/security` and the users POSTs; the page is byte-identical to PHP's own output on a pinned state, proved by a fixture rendered from the real `SecurityView.php` with a `Page` stub (`The security-page fixture is current: 5 sources unchanged` in the run's own log), and the unit job prints **28 test files passed / 1 skipped, 248 tests passed / 6 skipped**, with the new `http/security.spec.ts` (12) and `html/security.spec.ts` (6) among them. It is also the commit that made **`sessioncsrf` real**, which is **D29**: the filter answered every unsafe request with a phase-0 **403**, so no users POST could reach a handler at all. It now answers v3's **419** — `{"ok":false,"error":"Your session expired."}` for a fetch, the app's error page with `Your session expired. Reload the page and try again.` otherwise, both read from `main@4a187d0f` (`modules/Core/Http/CsrfCheck.php:18`, `:20`, `:23`, and `modules/Core/Filters/CsrfFilter.php`) — and the body is parsed once before the chain (`src/http/form.ts`, called from `router.ts`), because a filter cannot await. That is a decision about **every form write**, not about this page, so it is written down instead of left inside a feature commit's diff. Previous revision 27 at 14:00 EET — closes the one item revision 26 left open (this header first read 14:05 and the commit landed at 14:02:50, so the line was a prediction rather than the clock read the rule asks for; corrected in the next commit, which is the only way to correct it without rewriting history), and gets the count wrong in a way worth recording. `bf3af79c` (13:12 EET, run `45382091338` green) puts `--max-time 5 --connect-timeout 3` on the `/healthz` and `/readyz` probes (`scripts/selfhost-smoke.sh:113`, `:133`); `e2283817` (13:24 EET, run `45383878498` green: six jobs, 60 steps, zero non-success, zero skipped) bounds a **third** probe revision 26 never listed — a raw `fetch` to rqlite's `/db/query?level=strong` at `:163`, now carrying `AbortSignal.timeout(5000)`. Two of the three were `curl` and one was not, which is exactly why the script's own header at `:97-101` now states the rule instead of leaving it to reviewers. It also records **D28**: S1.3's increment 2 is split by **handler**, not by page, because `SecurityView::page()` is one 279-line document whose users half cannot render without the roles half's markup — so 2a is the page plus the users POSTs, 2b the roles POSTs and the refusal assertions, and the accepted residue (the roles forms posting into the 501 handler between the two) is named rather than discovered. Revision 26 at 12:53 EET — one decision answered, and the answer is a decision *not* to act elsewhere. Stathis ruled on the `RoleAdmin` last-manager race: the `EXISTS` assert inside v4 phase 1, and **no freeze-line fix on `main`** (**D27**). The assert is work S1.3's DoD already required, so choosing it adds no slice work; the consequence is written down instead of left to be discovered — **the gap stays open on hosted D1 until phase 1 ships and the installation is cut over**, and only two edits landing in the same write unit are open, because `RoleAdmin::managers()` already guards the sequential path. It also records S1.3's checkpoint: the role guards are on the branch and green at `e89fb84a` (run `45378250554`: six jobs, 60 steps, zero non-success, zero skipped) after two defects of the builder's own — a doubled `export` that stopped the module parsing, and a `DELETE_SAFE` binding that judged the role being deleted rather than the one receiving its accounts, which is the one that mattered: it would have deleted the last manager's role and moved their account somewhere harmless. Previous revision 25 **corrected a DoD that would have produced a wrong test**: §5's S1.3 said the last-manager refusal answers 422. §5's S1.3 said that removing the last holder of a permission answers **422**; v3 answers **409**, read from `main@4a187d0f` (`RoleAdmin.php:143` in `updateLocked()`, `:181` in `deleteLocked()`, both `new UserError(..., 409)`), and every v4 guard already sends 409. The 422 came from nowhere but this plan, and a builder scoring itself against it would have "fixed" the status away from parity. The revision also records S1.3's first increment on the branch and red for a reason that is not the slice: head `76dab1db`, run `45367702792`, a duplicated `export` keyword at `v4/src/auth/roles.ts:205` breaks that module's parse — so typecheck fails at step 7 with `lint`, `routes:check`, `assets:check`, `fixtures:check` and `npm test` all **skipped** behind it, and in the d1 and rqlite jobs 18 of 27 test files fail to transform at all. That run therefore proves nothing about S1.3 and no acceptance is made on it. One defect it does not hide is a parameter binding in `deleteStatements()`, sent back with the SQLite simulation that shows the guard letting the last manager go; the work is in the builder's hands, not the plan. Revision 24 at 10:13 EET on 2026-11-24 — records S1.2b landed and **accepts it on run `45353030657`** (head `762cb60f`: six jobs, 60 steps, zero non-success, zero skipped), across four runs that each taught something — red at `npm run lint` with the four parity steps behind it, red on three of the builder's own test premises, red on one assertion, then green. Two defects it found matter more than its code. The first: the `Installation` Durable Object boots **migrated and seeded** in tests, so six of its "install with no admin" cases were running against an install *with* an admin — fixed in the tests, the slice was right. The second: a backend that lies. `DurableObjectSql.batch()` returns `{changes: 0, lastRowId: null}` for every statement, so a first version that decided success by reading `changes` would have refused **every** claim with 409 on `DB_DRIVER=sqlite`, the single-process self-host backend — while D1 and rqlite agreed with each other and disagreed with it. The fix is verification from state; the interface is corrected in **D26** so the next caller cannot make the same reading. The slice also raises who mints a token at all (**D25**), and it closes with one CI gap named rather than assumed: `DB_DRIVER=sqlite` gets a boot smoke and no live-driver parity run, unlike rqlite and d1 — which is precisely where that lie lived. **Revision 23 at 07:41 EET on 2026-11-24** — one ruling, and it corrects a premise rather than a plan row. `/claim` was held to be blocked on two environment names that §11 does not carry; only one of them is missing. `APOTHEKE_HOSTED` is already in the contract — `src/env.ts:17`, `src/platform/config.ts:81` (`hosted: flag(env.APOTHEKE_HOSTED)`), `wrangler.jsonc:60`, `.env.example:66` — so the hosted gate needs no new name and no plan change. `HOSTED_CLAIM_HOURS` is genuinely v3's alone (`ApothekeConfig.php:61`, default **72**, clamped **1–720** at `:118`), and it is not ported: S1.2b uses that default and clamp as a constant, recorded as **D24**. The rule was never the count of names — it is that v4 carries the names v3 ships and invents none; adding a name is still a §11 change that touches `.env.example`, both compose files and the walkthrough in one commit, and it does not belong inside a slice. **Revision 22 at 07:33 EET on 2026-11-24** — records S1.2 landed and **accepts it on run `45333064072`** (head `d338d41c`: six jobs, 60 steps, zero non-success, zero skipped), and it took four runs to get there because the slice's own test surfaced a session-id defect in increment 1 that no non-booting test could see — an id minted by `btoa` carries `+` and `/`, which PHP's session alphabet does not, so about three ids in four could be written and never read back. It also splits the slice's last bullet out rather than closing S1.2 without it: `/claim` becomes **S1.2b** (**D22**), and the two consent knobs v3 has and v4 does not are recorded as named divergence instead of silence (**D23**). **Revision 21 at 05:52 EET on 2026-11-24** — two corrections, no scope change, and both are mine: the sign-in throttle DoD sentence in §5 described a session-keyed counter, which is the bypass the limit exists to close — v3 keys it by address + account (`RateLimiter`, read from `main@4a187d0f`), so a fresh cookie does not reset it. And `Retry-After` on the sign-in 429, which I required, is not in v3 at all; it stays, but it is now labelled an addition to parity rather than quiet parity. §5 also records that the Secure bit's inputs are the `trust_proxy` and `public_url` **settings rows**, not environment names, and v3's fallback when they cannot be read. Adds **D21**. **Revision 20 at 05:36 EET on 2026-11-24** — a clock correction, no scope change, and the rule it corrects could not be followed as written. Revision 19's header claimed 05:45 EET and its D20 row "Answered 05:40" for a commit that is `a6b681ad` at **05:31:14** — two timestamps written from memory 9 to 14 minutes into the future, the fourth instance of this class (13, 15, 16, 19). "Read the header clock back from the commit" is unexecutable inside the commit that writes it, which is why it kept recurring: from this revision on, the header time is a **clock reading taken immediately before the file is written**, verified afterwards to be *not later than* the commit time read back from the API. The direction of the guard is inverted, so a future stamp fails the check instead of defining it. It also writes down the one thing S1.1's probe assertion does not cover, which its own author flagged rather than let a reader assume the arm is total. **Revision 19 at 05:31 EET on 2026-11-24** (its header said 05:45 until this revision) — one correction, no scope change, and it is the S1.2 constraint paying for itself. §4 item 4 and §5 called the session cookie `__Host-apotheke`. The v3 source says otherwise: the cookie is **`apothekesid`**, and `__Host-apothekesid` is used *only when the request derives Secure* (`SessionGate::COOKIE`/`SECURE_COOKIE`/`cookieName(bool $secure)`; `RequestScheme::secureCookie()` = HTTPS, or `PUBLIC_URL` is `https://` and the request Host is that host). Both rows are corrected here, with the attributes read off `SessionCookie.php` rather than remembered. Verified from `main@4a187d0f`, not from the build report. The slice also surfaced the dependency this row hid: v3 starts a session **only with cookie consent present**, so v4 signing in without one would be a behaviour change nothing in the plan named — recorded as **D20**. **Revision 18 at 05:25 EET on 2026-11-24** — records S1.1 landing and **accepts it on run `45318694037`**: six jobs, 60 steps, zero non-success, zero skipped, at head `e9d10591`. It also names the first defect class this project has produced that unit tests structurally cannot catch — a constant exported from the Worker's *entry* module is read by workerd as a handler binding, so 156 unit tests passed while the app refused to boot — and rules when S0.5's 401 scaffold retires (**D19**). S1.2 picks up one constraint from it: the cookie has to be the cookie v3 actually sets, because `__Host-` needs HTTPS and a self-hoster on `http://192.168.x.x` is not a secure context. No scope change. **Revision 17 at 05:05 EET on 2026-11-24** — a record correction, no scope change: revision 16 was committed at 04:11 EET, not the 04:20 its own header claimed, so the header is fixed here rather than quoted onward. **Revision 16 at 04:11 EET on 2026-11-24** (its header said 04:20 until this revision) — three S0.5 rulings, none of them new scope: the two scaffold answers are named decisions rather than documented behaviour, and the two PHP host/CORS variables stay out of the sixteen-name contract with the narrowing written down (D18). It also corrects revision 15's clock, which was written at 04:10 and 04:35 for commits that landed at 04:02 and 04:05. **Revision 15 at 04:02 EET on 2026-11-24, corrected 04:05 before dispatch** — records S0.5 landing, which **closes phase 0**, and slices phase 1 (S1.1–S1.4 plus the gate) so the next dispatch is a definition of done rather than a phase title. Phase 0 ends with nine slices landed and gate G0 signed at 00:50, and with the surface it deliberately does not serve written down rather than implied: no page renders, no session exists, and every page route answers 401 or 501 problem+json. Slicing phase 1 is organisation, not new scope — the contents are §5's phase-1 paragraph, unchanged. One correction inside it before the slice went out: S1.1's definition of done ended "no page route still answers `501 route.not_implemented`", which phase 1 cannot deliver — P2 owns the pages — so it now reads that `/login` stops answering 501 and every other unported page route keeps answering it, asserted as a list. **Revision 14 at 03:40 EET on 2026-11-24** — a record correction, no scope change. §5 recorded S0.9's head as `e654d275`, which is where run `45305147064` was green, not where S0.9 ends: the sixth commit, `d3aaafb1`, adds the root-level-path line D16 asks the walkthrough to state, and it is green in run `45305655725` (six jobs, 47 steps, zero non-success, zero skipped). Revision 13's own timestamp is corrected too — it was committed at 03:33 EET, not 03:45. **Revision 13 at 03:33 EET on 2026-11-24** — records S0.9 landing and fixes the one table row the run proved wrong. Run `45305147064` (six jobs, 57 steps, zero non-success, zero skipped) brings both stacks up from `down -v`, answers `/readyz 200 {"schema":"0036","seed":"done"}` with `driver` `rqlite` and `sqlite` respectively, asserts both container healthchecks through `compose ps`, and proves a second boot over the same volume leaves the seeded rows identical — full row content, bcrypt hash included, with the row counts asserted before the comparison so a read that missed the WAL fails instead of passing quietly. The correction: **§11's rqlite volume row.** rqlite v10.7.4 keeps the raft log and the SQLite database inside **one** data directory and has no flag to split them; the image pre-creates `/rqlite/file` owned by uid 1000, so the stack mounts one volume there with `DATA_DIR=/rqlite/file/data` — not `rqlite-raft:/data/raft` + `rqlite-db:/data/db`, a layout this image does not have at all. Adds **D17**. Revision 12 at 03:30 EET — names where the compose skeleton actually lives in phase 0: under `v4/`, not at the repo root, because the root already ships v3's `docker-compose.yml` (blob `d631dc53`, 4,734 bytes: nginx/php-fpm and FrankenPHP images, `./data:/data`) and v3's `.env.example` (blob `7c3da402`), which are the files today's self-hosters run and the ones `docs/getting-started.md` and `docs/deployment-and-operations.md` describe. Overwriting them in phase 0 would hand a v3 operator a stack the v4 app cannot serve yet — S0.5 is not landed, and auth, pages and API are phases 1–2. The root swap becomes the cutover step (P7), with v3's compose kept for one release as `docker-compose.v3.yml`. Adds **D16**. Revision 11 at 02:05 EET — one correction, no scope change: §11's service contract named the published URL `PUBLIC_BASE_URL`, a name that appears nowhere else in the repo; the variable is `PUBLIC_URL` (`v4/src/env.ts`, `v4/src/platform/config.ts`, `v4/wrangler.jsonc`) and it is the same name v3 already ships, so adopting the table's wording would break every existing self-host `.env`. Revision 10 text follows. **Revision 10 at 00:50 EET on 2026-11-24** — records S0.6's D1 job landing (run `45282360833`: five jobs, the emulated D1 binding booted and its bytes inspected) and **signs off G0**, naming what that sign-off does not cover. Narrows what is next to S0.9, the compose skeleton, which now has an environment contract to pin. Revision 9 at 00:35 EET on 2026-11-24 — records S0.8 (the rqlite driver) and, with it, G0's last unproven leg: `rqlite` from inside workerd, proven in run `45279526055` with the two remaining gaps (TLS to a self-signed node, retry across a real election) named rather than glossed. Narrows what is next to S0.6's D1 job, and states that a CI D1 job proves the emulated binding, not hosted D1. Revision 8 at 00:20 EET on 2026-11-24 — records what S0.2–S0.4 actually built
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
4. **Cookies.** The session cookie is **`apothekesid`**; **`__Host-apothekesid`** replaces it only when the request derives Secure — `SessionGate::cookieName(bool $secure)`, where `secureCookie()` is true for HTTPS (direct, port 443, or `X-Forwarded-Proto: https` from a trusted proxy) **or** when `PUBLIC_URL` is `https://` and the request Host is that host. Attributes: `Path=/`, `HttpOnly`, `SameSite=Lax`, no `Domain`, and `lifetime` equal to the session lifetime in seconds (persistent, not browser-session). On HTTPS the plain name is still *read* as a fallback — v3 keeps that for one release. Also `apotheke_consent`, and the rule that **no session is loaded or started without it**; idle timeout and `session_days` semantics, session epoch invalidation on password/role change. Test: the expiry line matches `SessionGate::isExpiry()` — `(__Host-)?apothekesid=deleted; expires=…; Max-Age=0; path=/; HttpOnly; SameSite=Lax` — with `; secure` only under the `__Host-` name.
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
- S0.9 Self-host compose skeleton: **`v4/docker-compose.yml`** (`apotheke` + `rqlite`), **`v4/docker-compose.sqlite.yml`**
  (single process), **`v4/.env.example`**, and `scripts/selfhost-smoke.sh` — run against a stub `workerd` that serves
  `/healthz`, so ports, volumes, healthcheck ordering and env names are pinned before the app exists. The `v4/` prefix
  is D16, not taste: the root `docker-compose.yml` is v3's live self-host file and stays untouched until the cutover.
  The smoke script takes the compose file as its argument, so phase 0 passes `v4/docker-compose.yml` and the same
  script runs the root files unchanged after P7. Contract in §11.

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

Slices landed by 2026-11-24 03:56 EET: S0.1 (`0e46d908`), S0.2 (`9aedb9e0`), S0.4 with its audit (`f1473b17`;
audit `ac08ea6f` merged as `f27b79de`), S0.3 (`744bc946`), S0.8 (`94309317`, five commits `bc801feb` driver and
tests, `bc472f50` the rqlite job, `4d179942` and `864a0efb` its two defects fixed, `94309317` the gaps recorded),
S0.6's D1 job (`48d7dc00`, plus `d2c1c173` correcting `adapters.md`), and S0.9 (`d3aaafb1`, six commits:
`7a918ad8` the compose skeleton and `.env.example`, `ea1908a0` the smoke script and its CI job, `91d07ca4` the
failure-path fix, `9eefa7a5` the missing CA bundle, `e654d275` the healthcheck assertions, `d3aaafb1` the
root-level-path line D16 asks the walkthrough to state). S0.7's D1 driver landed
early inside S0.3 because S0.4's parity test needs a backend, and S0.8 delivered S0.6's rqlite job with it.
S0.5 (`c79f8389`, three commits: `90f9e81e` the router, chain and problem+json, `7f3898b4` the
walkthrough correction, `c79f8389` `adapters.md`).

**D1 from the emulated binding — pass, 2026-11-24 00:44 EET** (run `45282360833`, run 15, job `d1 (emulated
binding, parity, workerd boot)` 12/12; five jobs, zero non-success and zero skipped steps): the suite's own JSON
report reads `tests=75 failed=0 pending=6`, the six pending being the `rqlite-live` specs that the rqlite job runs;
the job asserts that the eleven G0 assertions are present and all `passed`, that `numFailedTests == 0`, and that
nothing outside `rqlite-live` is pending — so a spec that skips itself fails the job. `wrangler dev --var
DB_DRIVER:d1`, started from an empty `.wrangler`, answers `/healthz 200 {"ok":true,"driver":"d1"}` and
`/readyz 200 {"ok":true,"schema":"0036","seed":"done","driver":"d1"}`, so the binding runs the migration chain, the
seed phase and the readiness gate before it can answer. On the bytes: `schema_migrations` holds 36 rows, 0019's seven
`search_fts*` objects are diffed **by name against the PHP oracle** (a backend that loses FTS5 names what went
missing instead of passing quietly), and the DO's own storage holds **zero application tables**, which checks D12
rather than asserting it. Parity therefore runs on all three backends inside one run: D1 through the driver, local
DO SQLite, and `rqlited`.

**G0 — signed off at 00:50 EET on 2026-11-24.** Every clause of the gate has a green run behind it, and the three
legs run together in run `45282360833`. What the sign-off does **not** cover, named so nobody reads more into it than
exists: the D1 job proves the **emulated** binding, not hosted D1 — real D1 is first exercised when a hosted
installation is provisioned, in phase 1; TLS from workerd to a `rqlited` with a self-signed certificate is implemented
but unexercised, which is G6's compose stack; and retry across a real leader election is proven only against a stub
`503`, which is G3's restart-with-data. None of the three is a phase-0 input, and each is scheduled to the gate that
will actually exercise it.

**S0.9 — landed 03:31 EET**, six commits on top of revision 12, head `d3aaafb1`; green in run `45305147064` at
`e654d275` (six jobs, 57 steps, zero non-success, zero skipped) and again in run `45305655725` at the sixth,
docs-only commit `d3aaafb1` (six jobs, 47 steps, the same guarantees). Both files boot from `down -v`: `v4/docker-compose.yml` answers
`/healthz 200 {"ok":true,"driver":"rqlite"}` then `/readyz 200 {"ok":true,"schema":"0036","seed":"done","driver":"rqlite"}`,
`v4/docker-compose.sqlite.yml` the same with `"driver":"sqlite"`, both containers report `healthy` from their own
healthchecks (asserted through `compose ps`, not assumed), and a second boot over the same volume leaves the seeded
rows identical — the snapshot asserts 1 user and 64 settings before it compares, so an identical-but-empty read
fails. The rqlite stack runs with `-auth` and a generated `RQLITE_AUTH`, so the credential path is exercised rather
than assumed; `.env.example` carries the sixteen names the code reads, checked programmatically
(`extra: []`, `missing: []`, `PUBLIC_URL` and no `PUBLIC_BASE_URL`). Two of its five commits are the slice's own red
runs, kept in the record: run 19 answered no `/healthz` for 300 s and printed nothing, because `die`'s `exit` fired
an EXIT trap while the log dump lived in an ERR trap — one EXIT trap now dumps `compose ps -a` and 300 lines, and
the wait fails in seconds when the container is `exited`; run 20's single line was `No CA certificates were loaded
from the system`, because `node:22-slim` ships no CA bundle and workerd refuses to boot — the stub image now
installs `ca-certificates`. What S0.9 does **not** prove: G6's acceptance list (claim admin, create an item, upload
a photo, download and restore the backup ZIP) needs S0.5's router (landed 03:56) and P2's pages, so this slice pins packaging and the
readiness/seed contract, not the product; the image is a **stub**, not a release image; TLS to rqlite is G6 and
retry across a live election is G3.

**S0.5 — landed 03:56 EET**, head `c79f8389`, three commits, 19 files in the first of them. Green in run
`45308153624` at `90f9e81e` and again in `45308619604` at the docs head `c79f8389`: six jobs, 58 steps, zero
non-success and zero skipped. What it actually is, checked against the tree rather than the report: **281 route rows**
generated from the PHP app's own artifact (`app/Config/RoutesCompiled.php` + `app/Config/Filters.php`), the chain in
CI4's real order as data (14 required before, the globals with their `except` lists, `apicsrf` per path, then
`securityheaders` and the three required after filters), `problem.ts` with both header sets copied verbatim from
`SecurityPolicy`/`ApiSecurityPolicy`, and exactly two live handlers — the frozen `/api` 404 and 501 "not ported yet".
Fail-closed is **demonstrated, not asserted**: every one of the 281 rows is turned into a request and run through the
real chain, no row answers 2xx except the CORS preflight, the parity test compares each row's method, path, name,
handler, permission and filter list against the PHP fixture in both directions, and `npm run routes:check` fails when
`Routes.php` or `Filters.php` changes without regeneration (adding one newline to `Routes.php` was shown to turn CI
red). Two answers are scaffold and documented as scaffold: a page route that needs a session answers **401** where v3
redirects 303 to `/login` — there is no login page until P1/P2 — and page 404/405 are problem+json where v3 renders
the error page. `/healthz` and `/readyz` are untouched and still answered before the router (D14, §11 rule 7). Two PHP behaviours are not ported because their variables are not in the sixteen-name contract — `ALLOWED_HOSTS` and `CORS_ALLOWED_ORIGINS` — and that narrowing is **D18**, not an omission: `hostcheck` accepts IP literals, `localhost`, `*.localhost` and `PUBLIC_URL`'s host, and the CORS list is empty, which is v3's own self-host default and the safe direction. The non-vacuity proofs — a row deleted from the table, one permission changed from `edit` to `view`, `sameorigin` removed from the chain, one newline added to `Routes.php` — were run **locally and reverted**, not pushed as a red run; the permanent guard is the both-directions parity test plus `npm run routes:check`.

**S1.1 — landed and accepted at 05:21 EET**, head `e9d10591` (five commits: `a90feb2` page shell, `26698fb` walkthrough doc, `11f2d59` probe test, `96e15a79` the boot fix, `e9d10591` the test budget). Green in run `45318694037`: six jobs, 60 steps, zero non-success, zero skipped. What it is, checked in the tree rather than in the report: the views ported with v3's escaping and v3's own CSRF field name, `/login` rendering, v3's error pages in place of the 404/405 scaffold, CSS/JS served from the Worker, and the page header set still coming from `problem.ts` — one source, not a second copy. The DoD holds where it counts: `golden.spec.ts` normalises **exactly** the CSRF token and the session id and compares bytes otherwise; `assets.spec.ts` re-hashes what the Worker serves against `public/assets/` on disk; `unported.spec.ts` derives its list from the 281-row table and asserts `501 route.not_implemented` for every unported page route while asserting `/login` does not answer it. The slice's first run was **red** (run `45317275573`, four jobs down), and the red is the useful part: `export const HEALTHZ_PATH`/`READYZ_PATH` in `src/index.ts` are named exports of the **entry** module, workerd reads those as handler bindings and refused to start — `Incorrect type for map entry 'HEALTHZ_PATH' … The Workers runtime failed to start` — which took out workerd smoke, rqlite, compose and, through the parity sweep, D1, while the unit job stayed green with 156 passing tests. **A constant exported from the entry module is invisible to `vitest` and fatal to the runtime**: the constants now live in `src/http/probe-paths.ts`, and the file's own header says why, which is worth more than the fix. The second defect was a budget, not an assertion: the 281-request sweep took 4.8 s against vitest's 5 000 ms default and now has its own 60 s. Limits stated, not implied: the golden fixture was generated on the builder's box with PHP 8.4.28 and is **not** CI-rendered — the staleness guard is `fixtures:check`, which hashes the 24 PHP and asset sources into the fixture, with the regenerating script committed beside it — and the `/metrics` mutation proof for the probe-bypass arm is local, not a pushed red run. A third limit comes from the assertion's own author and belongs beside the other two: the `?raw` arm reads **`src/index.ts` alone**, so a third path hard-coded in a module the entry calls before the chain is invisible to both that arm and the 281-route sweep — the sweep only sees routes the table knows about. The rule that follows is written into the test's comment and here: whichever commit moves a `pathname` comparison out of the entry carries the `?raw` target with it in that same commit.

**S1.2 — landed and accepted at 07:31 EET**, in two increments on the branch of record: increment 1 `327cec2e` (the session foundation) and increment 2 `4edbcb0b`..`d338d41c` (sign-in, sign-out, consent, the session filter). Green in run `45333064072` at head `d338d41c`: six jobs, 60 steps, zero non-success, zero skipped — and the unit job's own steps are the part that matters, because on the red run they were *skipped* behind a lint failure: `routes:check`, `assets:check`, `fixtures:check` and `npm test` all ran and passed this time (steps 7–10 of 13). What it is, checked in the tree rather than in the report: `src/session/cookie.ts` derives `apothekesid` / `__Host-apothekesid` from the request's Secure bit with no `Domain`; `src/session/php-session.ts` writes `sessions.data` in PHP's own serialisation; `src/session/store.ts` stores the row id as the **SHA-256 of the cookie value**, never the value, and re-checks the epoch on every request; `src/session/throttle.ts` counts in v3's `auth_attempts` table, so both runtimes count the same attempts while both run; `src/session/consent.ts` holds the gate. `signin.spec.ts` asserts the row column by column — `id` 64 hex that is the cookie's digest, `data` carrying `uid|i:…`, `auth_epoch|s:16:`, `last_activity|i:…`, `signed_in_at|i:…`, `expires_at` = now + lifetime, `lock_token ""`, `locked_until 0` — and asserts the epoch case with the row **destroyed**, not merely refused. Three defects the slice's own tests found, in the order they were found, and all three are worth more than the code: (1) the first red head was lint — seven Biome errors — and lint runs before the parity sweep, so that push proved nothing about 281 routes or the fixture oracle while looking like an ordinary failure; (2) two assertions in `router.spec.ts` were still describing S0.5, one expecting `POST /login` to answer 501; (3) the session id. `newSessionId()` returned `btoa(...)` with padding stripped — standard base64, whose alphabet is `[A-Za-z0-9+/]` — while `DatabaseSessionHandler` accepts `[A-Za-z0-9,-]` and `SessionGate::isLive()` refuses anything else. About three ids in four therefore contained `+` or `/` and the row written under one could never be loaded again, which is why the same test passed in one run and failed in the next: it depended on whether the random id happened to be legal. Fixed by mapping `+`→`-` and `/`→`,` (`d0016695`). The lesson generalises past this bug and belongs beside the entry-module lesson: **a value's format is proven by reading it back through the validator, not by writing it** — and nothing before this slice ever loaded a session back, so nothing could fail. Limits stated, not implied: the session fixture is **builder-generated** (`scripts/gen-session-fixture.sh`, PHP 8.4.23 on the builder's box, recording the SHA-256 of the seven PHP files it read), not CI-rendered, with `--check` needing no PHP and running in `fixtures:check`; and increment 2 is the first slice built with **no local signal at all** — the build box sat at load ~18 with two wedged vitest pools, `tsc` and Biome timed out, the formatter was applied by hand from CI's diff, and the four runs were the only feedback. That is a process risk, not a defect: the cost is more red heads per slice, and it is worth paying while a wrong fix costs two minutes of CI instead of an unverified green claim.

**Phase 0 — complete at 03:56 EET on 2026-11-24.** Nine slices, S0.7 inside S0.3, every phase-0 spike closed or
assigned to the gate that exercises it, and G0 signed at 00:50 with its three limits named (emulated D1 is not hosted
D1 — P1; TLS to a self-signed `rqlited` — G6; retry across a real leader election — G3). What phase 0 produced is
machinery: one schema, one runner, three drivers, a boot gate, a router that fails closed, and CI that runs all of it
on all three backends. Nothing in it is a product yet — no page renders and no one can sign in — and that is the
phase-1 job.

**Phase 1 — sliced.** Contents unchanged from the paragraph above; the slices exist so each one can be refused or
accepted on evidence. Sequence is set by dependency, not by size: a login form needs a shell to render in, and users
and roles need a session to check.

- **S1.1 Page shell and the login page (HTML identity).** Template engine, layout and partials, flash and CSRF form
  fields, CSS/JS served from the Worker with the right content types and cache headers, v3's error pages in place of
  the 404/405 scaffold, and `/login` rendering the same HTML the PHP app renders. DoD: a golden-HTML test compares v4's
  `/login` against a PHP-rendered fixture, normalised only for the CSRF token and session id, and fails on a changed
  whitespace run or attribute order; assets are byte-identical to `public/`'s; `/login` stops answering
  `501 route.not_implemented`, and **every other page route that is still unported keeps answering it** — asserted as a
  list, so a route that silently starts answering 200 fails the test.
- **S1.2 Sessions, cookies, CSRF, throttle, sign-in/out, first-admin claim.** Session store and epoch, v3's session cookie name and flags
  (`apothekesid`, or `__Host-apothekesid` when the request derives Secure) with no `Domain`, rotation on password
  change and on privilege change, the sign-in throttle, `POST /login`,
  `GET /logout`. `/claim` is **not** in this slice — it was split out as S1.2b (D22) after the rest of the slice went green without it. DoD: a session v4 issues is the row v3 would have written (columns and
  serialisation compared against the PHP fixture); no cookie carries `Domain`; a pre-rotation session stops
  authenticating once the epoch moves; N failed sign-ins **from one address for one account** are throttled with
  a 429, and **a fresh session does not reset that count** — v3 keys the sign-in counter by address + account
  (`RateLimiter::bucket()` = first 32 hex of `sha256(address "\0" account key)`), not by session, because a new
  cookie is free to mint; a second, higher cap of 30 per 900 s counts every account from that address
  (`login_address`). The attempt is recorded before it is counted and its row taken back when refused, so two
  simultaneous requests cannot both slip under the limit. One constraint carried in from S1.1: **the cookie must be the cookie v3 actually sets.**
  `__Host-` requires `Secure`, and a self-hoster on `http://192.168.x.x` is not a secure context, so a blind
  prefix is silently dropped by the browser; the name and flags are derived from `PUBLIC_URL`'s scheme the way v3
  derives them. The inputs to that derivation are **settings rows, not environment names**: `trust_proxy`
  (Security, bool, default off, absent from the sixteen) and `public_url` (General). v3 reads both from
  `Services::settings()` on the request's own connection and, when that read throws, falls back to
  `trust_proxy = false` and the config's `public_url` — so a database blip degrades to the plain cookie name,
  never to `__Host-` on a host that cannot set it (`SessionCookie.php:60-71`).
  **One deliberate divergence from v3, written down so nobody "fixes" it back:** v4's sign-in 429 carries
  `Retry-After`. v3 sends none on that path — `PlainHandler` sets no such header and `ApiResponse::problem`
  only when handed one. That is an addition to parity, not parity, and a parity test that observes it is
  correct to expect it.
- **S1.2b The first-admin claim.** v3's `FirstAdminClaim`: the claim page, the token that authorises it, and the transition that makes an account the first admin. Split out of S1.2 because every part of it is a page and a store the rest of S1.2 does not have, and because folding an unbuilt page into a slice whose other eleven bullets are green is how a DoD bullet disappears. DoD: on a database with no admin, `/claim` renders v3's page; a valid token creates or promotes exactly one admin and answers 303; a second use of the same token is refused with v3's answer; an installation that already has an admin answers the same way v3 does. **Before any code, one line of design**: the report names where the claim token lives in v4 (v3 reads it from a store the port has not built), because that choice is a §4/§11 decision and not an implementation detail.
  **Accepted at 10:13 EET on run `45353030657`** (head `762cb60f`, six jobs, 60 steps, zero non-success, zero skipped). The design line came first, as D22 required: the token is the settings row `hosted_first_admin_claim` holding `{sha256, expires}` — `settings` reused, no new table, never exported or backed up. The three doc drifts the slice exposed (`self-host-docker.md` item 6 and its walkthrough still telling an operator `POST /login` answers 501, `router.md`'s "the sign-in POST included" 403, `adapters.md`'s legend missing the 303s) were folded into the slice's own commit, because a doc has to be true at the commit it ships in. Two knock-ons the change made false were caught in the same pass: `router.md`'s "the only 2xx are `/login` and the preflight" (it now names `/claim`, hosted) and `unported.spec.ts`'s route list, which would have failed `/claim` as "still 501". The page oracle is a second fixture, `php-claim.json`, generated from the real `AuthViews.php` with a `Page` stub and checked by `scripts/gen-claim-fixture.sh --check` inside `fixtures:check` — builder-generated, not CI-rendered, and it caught nothing, which is the point: the port was byte-identical first time.
- **S1.3 Users and roles admin, and the `RoleAdmin` last-manager check.** List/create/edit/delete users, roles and
  permissions through the ported controllers. The last-manager race (plan §10.1) gets its `EXISTS` assertion inside
  the write unit **here** — **answered 12:49 by Stathis (D27): phase 1 only, no freeze-line fix on `main`.** DoD: the role permission matrix matches PHP route-for-route; removing the last holder of a
  permission answers **409** with the same message v3 sends (corrected in revision 25: this line said 422, which is
  v3's answer nowhere — `RoleAdmin.php:143`/`:181` both throw 409); two concurrent edits in one unit cannot both remove it.
  **Checkpoint at 12:50 EET: the guards are green, the pages are not built.** Head `e89fb84a`, run `45378250554` — six jobs, 60 steps, zero non-success, zero skipped, with `routes:check`, `assets:check`, `fixtures:check` and `npm test` all having *run* rather than been skipped behind a lint failure. Two defects of the builder's own were fixed on the way, and the second is the one worth keeping: `DELETE_SAFE`'s fourth placeholder was bound to the role being **deleted** instead of `moveTo`, the role **receiving** its accounts, so deleting the role of the last manager and moving that account to a viewer role passed its own guard. v3 counts the accounts as if they held the receiving role's permissions (`RoleAdmin.php:180`), and that is what the port now does at `roles.ts:231-242`. The race shape is asserted, not just the single request: two guarded demotions in one batch (`guards.spec.ts:171`), two guarded deletes (`:180`), two role edits (`:216`), and delete-with-move (`:235`, `:243`). **Closed at `e2283817`, and it took two commits because the first list was short.** Revision 26 named two unbounded probes in `scripts/selfhost-smoke.sh`; there were three. `bf3af79c` (run `45382091338` green) bounds the `/healthz` and `/readyz` curls (`:113`, `:133`) with `--max-time 5 --connect-timeout 3`, so an unbootable app now costs 5 seconds per attempt and reaches the loop's own `die` message instead of blocking until the runner is cancelled — 37.5 minutes of wall clock on `45367702792` bought nothing. `e2283817` (run `45383878498` green, six jobs / 60 steps / zero non-success / zero skipped) bounds the third, the raw `fetch` at `:163`, with `AbortSignal.timeout(5000)`. Read back from the tree at `e2283817`: every probe is bounded, `:36`'s `command -v curl` is a precondition rather than a probe, and `compose up -d --build` stays unbounded **on purpose** — a build that hangs should be visible, not silently killed.
  **Increment 2 splits by handler, not by page (D28, 13:36 EET).** **2a** is `SecurityView::page()` — one document, 279 lines, users list `:137-159`, role summary `:82`, roles editor `:173-216` — plus `GET settings/security` and the three **users** POSTs; **2b** is the three **roles** POSTs, the JSON branch and the refusal assertions. A page split was the first instinct and it is wrong: one document cannot render half of itself, so splitting the page produces a red commit by construction. Routes: `v4/src/http/routes.generated.ts:67` (GET `settings/security`), `:221-223` (users POSTs), `:224-226` (roles POSTs). Constraints that are cheap to forget: the permission matrix is **already** asserted, `v4/test/http/routes-parity.spec.ts:61-62` (the column) and `:91-95` (the set), so hand-written expectations would duplicate it and a later edit would break both; the page fixture is deterministic only with a pinned CSRF token, UTC timestamps and v3's timezone handling (`SecurityView.php:265`), a five-role seed, and a user set that includes one pending account and one with a null `last_login_at`. Accepted residue, to be named in 2a's own commit: the roles forms POST into the 501 not-ported handler until 2b lands, and `guards.spec.ts` is untouched by both.
  **Checkpoint at 14:10 EET: 2a is green, 2b is not started.** `65d63014`, run `45390457762` — six jobs, 60 steps, zero non-success, zero skipped, with `routes:check` (281 routes, 3 chains), `assets:check`, `fixtures:check` (four oracles, the security one among them) and `npm test` all having *run*. What landed: `src/html/security-view.ts` (the page, `roleSummary`, `formatTime`), `src/auth/security-routes.ts` (`GET settings/security` with v3's `?done=` toasts, and create/role/password/rename/approve/delete on `settings/users`), the oracle `scripts/gen-security-fixture.php` + `.sh` with `--check` in `fixtures:check`, and the three doc files that make the surface true at the commit. Refusals keep v3's own status and text — **409** last admin, **422** a taken name, **403** the admin-only guards, **404** an unknown action — by re-rendering *that page* with `#users`, which is why the page and its POSTs had to land together. **D29** is the part to read: `sessioncsrf` was a phase-0 refusal (403 on every unsafe method), so no users POST could reach its handler; it is now v3's `CsrfCheck` at **419**, and the request body is read once before the chain. Measured consequence for the residue: a token-less POST to any of those paths now answers **419**, not 501 — a browser form carries the token the page rendered, so an operator sees the 501 the route table documents, and a script without a token sees 419. Both are v3's answers to the same request; the doc lines say 501 and remain true for the path they describe.
  **Accepted at 18:30 EET on run `45436214312`** (head `bf1e60fb`, 18:20 EET; six jobs, 60 steps, zero non-success, zero skipped). 2b is 14 files, **+637/-41**: `SecurityController::role()` in `src/auth/security-routes.ts` (+86), `test/http/security.spec.ts` (+222, the roles POST surface, eleven tests including the 419 one), `test/auth/permissions.spec.ts` (+29) with `php-permissions.json` (+148) and the two generator scripts, `FormPost` in `src/http/form.ts` (+53), the three handlers wired in `src/http/router.ts`, and the four doc lines that make the surface true at the commit. DoD, bullet by bullet: **the matrix matches PHP route-for-route** — asserted against `php-routes.json` (the route column) and against `php-permissions.json` (the built-in roles' sets), so nothing about permissions is a hand-written expectation; **removing the last holder answers 409 with v3's message** — `refuses deleting the role that is the install's only manager, at v3's 409` asserts the status, the exact `RoleAdmin.php:181` sentence, that the role row survives and that the account did not move; **two edits in one unit cannot both remove it** — `guards.spec.ts`'s four race tests, untouched by 2a or 2b, still green on sqlite, D1 and rqlite. One honest note about the slice's cost: 2b came in at +637 net against the 350-400 estimate, so both increments under-estimated — in the safe direction, which is the only direction an estimate may miss when the alternative is a red commit.
- **S1.4 `/api/v1/session` and the API auth path — accepted 2026-11-25 09:15 EET at `ae9d661c`** (run `45566485521`
  green: seven jobs, 72 steps, zero non-success, zero skipped). The four route rows existed since S0 and answered 501
  through `notPorted`; S1.4 wires `SessionController`'s handlers onto them, so `routes:check` stays 281 routes / 3
  chains and no other route starts answering 2xx (`router.spec` names the one conditional 2xx under /api, the CORS
  preflight). DoD, bullet by bullet: **shapes** — `state()` six keys in PHP's order, `account()` four, both read out of
  `SessionController.php` rather than described; **refusals** — v3's statuses and words, 409/400/422/401/403/429 with
  `Retry-After` from `RateLimiter::LIMITS['login']`, `DELETE` 204 and empty; **CSRF** — both directions asserted, every
  write without the token refused and the same session's sign-in and sign-out accepted with it; **the oracle** — 28
  answers captured over HTTP from v3 by `scripts/gen-api-fixture.sh`, 28 source hashes recorded, `--check` inside
  `fixtures:check`; **the replay** — status, the nine pinned headers and the body bytes, with bodies also validated
  against the `Session`/`Me`/`Problem` components of `docs/api/openapi.yaml`; **`SignInPace`** — ported, with its 1000 ms
  default and 400–10000 clamp, and the deadline now actually called. What it does **not** claim: `CORS_ALLOWED_ORIGINS`
  is honoured (it is not in the env contract, so only the app's own origin and `PUBLIC_URL` are accepted where v3 also
  admits configured CORS origins), PHP's `Expires`/`Pragma` are reproduced (they are not sent, and not pinned), or that
  `AuthPages::firstAdmin()` exists as a per-request write (it does not; the first account comes from the seed phase). The
  four routes are the surface; Gate **G1** is still what proves they hang together with the pages.

- **S1.5 `/account`: your own password, and sign out everywhere — added by revision 34.** The one phase-1 item no
  slice owned. DoD: **the page** — `GET /account` renders `AuthViews::account()` byte for byte against a PHP-rendered
  fixture, normalised only for the CSRF token; the document carries no timestamp, so the fixture needs a pinned
  token, a fixed account (`username`, `role`) and that role's permission names, and nothing else, with its
  generator's `--check` inside `fixtures:check`; the `#acct-*` ids are v3's and the compare covers them, and the
  back link is `$page->simpleHeader('Your account', '/')`, not a copy of the settings header. **`POST /account`
  answers v3's four refusals with v3's own sentences, re-rendering the page with `#acct-err` rather than a problem
  document**: 403 `The current password is not correct.` (`Accounts.php:210`), and 422 from `passwordValid()`
  (`:152-169`: `Use at least N characters for the password.`, `That password is too long.`, `Passwords cannot
  contain a null character.`, `The two passwords do not match.`), plus 404 `User not found.` for an account deleted
  mid-session (`:226`, `SignOutEverywhere.php:28`). **The current-password check is throttled exactly as sign-in
  is**: `login_address` (30 per 900 s) and the `login` bucket per username, both counted *before* the check and
  cleared and forgotten on success, a failure answered at `SignInPace`'s deadline (`Accounts.php:204-214`) — a port
  that skips those three lines turns `/account` into an unthrottled password oracle. **Success rotates the epoch and
  keeps this session signed in** with `keepSignIn: true` so `session_days` does not restart (#87), then answers 303
  to `/account?done=pw` with the toast `Password changed`; a second session of the same account must stop
  authenticating, asserted with the row's epoch the way S1.2 asserted rotation. **`POST /account/signout`** does the
  same rotation through `SignOutEverywhere`, answers 303 to `/account?done=out` with `Signed out on every other
  device`, and leaves this device signed in. **CSRF** is the page's own token on both POSTs; none is v3's 419 (D29),
  never a 403. **The guest branch** is the only one that redirects instead of answering: a visitor without an
  account goes to `/settings/security` when their role has `users`, else `/` (`AccountController.php:52-57`). And
  `/account` stops answering 501 while every other unported page keeps answering it, with `routes:check` still
  reading 281 routes / 3 chains.

**Gate G1** is not a slice: it is the proof that S1.1–S1.5 hang together — `visual-login.spec.ts` and the auth
functional specs green with untouched baselines, and the permission matrix equal to PHP route-for-route.

**Gate G0 (amended by D13):** TS runner vs PHP runner schema identical (normalised) on an empty database and on a
v3.72 dump, on **all three** backends (one SQLite schema, so one comparison run three times), where "identical" means
both directions held at once: the TS set contains every one of the PHP oracle's 64 objects (36 tables, 28 indexes),
and the TS set minus the literal pair `_write_log` + `idx_write_log_created` — the two objects 0036 adds, inside the
migration chain and not bolted on afterwards — equals the oracle exactly. No other object may appear on either side.
`/healthz` 200 from `wrangler dev` on D1 and SQLite and from a workerd-only boot against a real `rqlited`; `/readyz`
200 only once the seed is complete, 503 before it (D14); `v4.yml` green including the rqlite and D1 jobs.

### Phase 1 — Auth, sessions, page shell (3–4 pw)

Sign-in/out and first-admin claim; session store and epoch; rate limiter; the `apothekesid` cookie (`__Host-apothekesid` when Secure); CSRF for forms and `/api`;
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
| sqlite matrix (`DB_DRIVER=sqlite`, DO storage) | the same suite on the single-process driver, `test/sqlite.spec.ts`, and the database the DO wrote | the small self-host default does not rot |
| Golden pages | PHP-produced HTML corpus | DOM/route/text equality |
| API contract (`tests/ApiContract.php`) | base URL of v4 | `/api/v1` unchanged |
| Parity recorder (`tests/parity/recorder.php`, third target) | PHP app and v4 on the same steps | response equality with the existing allowlist quirks |
| Write replay | PHP and v4 from the same seed, on **all three** backends | identical table dumps and blob lists after writes |
| Playwright functional (`--grep-invert visual`) | v4 | every user flow |
| Playwright visual (`tests/visual/*.spec.ts`, 8 projects) | v4 | pixel equality with existing baselines |
| Backup round trip | v4 ↔ PHP image | format compatibility both directions |
| workerd self-host smoke | the built image, SQLite volume and a rqlite node | the self-host promise on both engines |

Rule: a phase gate is only signed off with a link to the CI run. "It works locally" is not a gate.

**Pre-push rule (D30, revision 29).** The two rules are different in kind and both apply. Before any push to the branch of record, the changed surface must pass, locally: `tsc --noEmit`, `biome check .`, `depcruise src`, `routes:check`, `fixtures:check` and `vitest run`. The full suite was 74 s on the dedicated box until S1.4 and is **89.63 s** at `c8cf8d62` (103 s as the builder measured it; the replay adds 28 captured answers against a real DO, five of them bcrypt and throttle), and time was spent learning what a local run would have said: `2e38fad2` died on lint, `76dab1db` on a doubled `export`, `8608a057` on one 121-character line of formatting. A red push is now a choice rather than a circumstance - and a run whose lint step fails skips `routes:check`, `assets:check`, `fixtures:check` and `npm test`, so the steps that would have caught a real defect never execute.

**The sqlite leg (D31, revision 31).** `v4.yml`'s `sqlite` job names the driver instead of inheriting it (`DB_DRIVER=sqlite` carried into the test bindings by `vitest.config.ts`), runs the whole suite, fails unless the only pending assertions are the six `rqlite-live` specs *and* all six are present as `skipped` *and* `test/sqlite.spec.ts`'s six have run (`v4.yml:379`; five until `74868d8f`), then boots workerd on that driver (`/healthz` and `/readyz` reporting `sqlite`, schema `0036`, seed `done`) and reads the DO's database with the `sqlite3` CLI. What it proves: the single-process self-host default does not rot, and its contract is asserted by name. What it does not: workerd's SQLite is not a self-hoster's `node:sqlite` or system `sqlite3`, so nothing here says two processes may open that file at once — the app never opens it and has exactly one writer. The E2E runner
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
| D15 | How `enc:v1:` secrets are sealed once `libsodium-wrappers` cannot run in workerd | **Answered 23:44 — write XSalsa20-Poly1305 by hand in `src/platform/secretbox.ts`.** workerd disables WebAssembly codegen, so the library does not load at all — this is not a packaging preference, it is a hard runtime limit. The format is libsodium's own secretbox, so existing `enc:v1:` values keep working and no user re-enters a secret | §3's crypto row; the interop question was closed by sealing and opening values in both directions against real `sodium_crypto_secretbox` (PHP 8.4.23), which is the only evidence that counts. Recorded as a numbered decision because it changes what every secret at rest depends on |
| D16 | Where v4's compose files and `.env.example` live before the cutover | **Answered 03:30 — under `v4/`.** The repo root already ships v3's `docker-compose.yml` (`d631dc53`, the file every current self-hoster runs) and v3's `.env.example` (`7c3da402`), and `docs/getting-started.md` / `docs/deployment-and-operations.md` describe them. Overwriting them in phase 0 would replace a working v3 stack with a v4 one that has no router, no auth and no pages yet | S0.9 writes `v4/docker-compose.yml`, `v4/docker-compose.sqlite.yml`, `v4/.env.example`, and `scripts/selfhost-smoke.sh` takes the compose path as an argument. P7 moves them to the root in one PR, keeps v3's file for one release as `docker-compose.v3.yml`, and rewrites the two v3 docs in the same PR |
| D17 | How rqlite's storage is mounted, once §11's two-volume row proved impossible | **Answered 03:40 — one volume at `/rqlite/file`.** rqlite v10.7.4 keeps the raft log and the SQLite database inside one data directory, and the released binary has no `-raft-dir`-style flag to split them; the image pre-creates `/rqlite/file` owned by uid 1000, so mounting an empty named volume elsewhere leaves it root-owned and unwritable. The stack therefore mounts `rqlite-data:/rqlite/file` with `DATA_DIR=/rqlite/file/data` | S0.9 shipped this and reported it rather than editing the plan; §10.3 and §11's service contract are corrected here. The advertised addresses stay pinned to the service name (`HTTP_ADV_ADDR`/`RAFT_ADV_ADDR`), because a single-node cluster that re-advertises a new hostname after `docker compose up --force-recreate` can refuse its own old address |
| D18 | The two PHP host/CORS behaviours whose variables are not in the sixteen-name contract | **Answered 04:15 — they stay out of the contract for phase 0, and the narrowing is documented rather than silent.** `ALLOWED_HOSTS` is not needed to serve correctly: `hostcheck` accepts IP literals, `localhost`, `*.localhost` and `PUBLIC_URL`'s own host. `CORS_ALLOWED_ORIGINS` is empty, which is v3's own self-host default and the safe direction — no cross-origin is allowed rather than all | S0.5; `v4/docs/router.md` names both as not ported, with the consequence. Revisit only if a self-hoster needs either: adding one is a change to §11's sixteen names, which then also changes `.env.example`, both compose files and the walkthrough in one commit, and is not a code-only edit |
| D19 | When S0.5's 401 scaffold for page routes retires | **Answered 05:20 — not in S1.2.** `/login` is the only ported page and it needs no session, so restoring v3's 303 redirect would change a behaviour nothing can observe yet; the 401 stays named as scaffold until the first ported page that needs a session, which is P2 | S1.2 dispatches without it. The slice that lands a session-gated page flips that route's class and asserts the list the way `unported.spec.ts` does, rather than flipping it in a slice that cannot show the difference |
| D20 | S1.2's session cookie name and flags, and whether a session may exist without consent | **Answered at 05:31 in revision 19 — v3 wins on both, and the plan was wrong.** Read from v3 at `main@4a187d0f`: `SessionGate.php` has `COOKIE='apothekesid'` and `SECURE_COOKIE='__Host-apothekesid'`, chosen by `cookieName(bool $secure)`; `RequestScheme::secureCookie()` derives that bit; `SessionCookie.php` sets `lifetime` = session lifetime in seconds, `path=/`, `httponly`, `samesite=Lax`, no domain — and returns before any session is loaded or started when consent is absent and no session cookie is carried | §4.4 and §5's S1.2 bullet are corrected in this revision, not left to the implementer. S1.2 implements v3's name and flags and states in its report where the consent gate lands — in the slice, or deferred with a named scaffold and the slice that retires it. A sign-in that issues a session cookie to a browser with no consent answer is a behaviour change and must not ship unnamed |
| D21 | S1.2's sign-in throttle: per-session or per-account, and does the 429 carry `Retry-After` | **Answered 05:52 — per address + account, exactly as v3 counts it, and the header is a named addition.** The DoD sentence in revision 20 ("the same N from a fresh session are not") described a session-keyed counter, which is the bypass the limit exists to close: `RateLimiter::LIMITS` is `login` 10/900 s keyed by `sha256(address "\0" account key)` plus `login_address` 30/900 s keyed by address alone, read from `main@4a187d0f`. | The builder implemented v3 and said the plan was wrong; the plan is corrected above rather than the code. The 429's `Retry-After` stays because I asked for it, and it is labelled a divergence from v3 in §5 so a later parity test does not read it as a regression. What the slice still owes on this point: where the counter lives in v4 (`auth_attempts`), and what a sign-in answers when that store is unavailable — v3 fails the request through `Writes::check`, it does not fail open |
| D22 | S1.2's last bullet, `/claim`, when the other eleven are green | **Answered 07:31 — split, not dropped, and not folded forward.** S1.2 closes on what it built and proved; the claim is **S1.2b**, the next slice, before S1.3 | The alternative — folding it into S1.3 — hides a slice's own DoD bullet inside another slice's, which is exactly the quiet omission a DoD exists to prevent. S1.2b starts only after its report names where the claim token lives, because v3 reads it from a store this port has not built and that is a §4/§11 choice |
| D23 | Two v3 consent knobs have no v4 equivalent: `COOKIE_CONSENT=off` and `COOKIE_DOMAIN` | **Answered 07:31 — not ported, and recorded rather than left as an unstated gap.** Neither is among the sixteen names (§11), and the sixteen stays sixteen (D18 is the precedent) | Consequences, stated plainly so nobody discovers them at a deploy: v4 always requires consent, so an operator who ran v3 with the notice off now gets the notice once per browser; and the consent cookie is always host-only, so an operator who shared consent across subdomains re-answers it per host. Both fail in the safe direction — more consent, not less. Revisit only if an installation asks: that is a §11 change and a plan revision, not a code change |
| D24 | The one environment name `/claim` needs that v4 does not carry (`HOSTED_CLAIM_HOURS`), and whether the claim is blocked on it | **Answered 07:41 — not ported; v4 uses v3's default of 72 hours with v3's 1–720 clamp as a constant.** The premise that two names were missing is wrong: `APOTHEKE_HOSTED` is already in §11's contract (`src/env.ts:17`, `src/platform/config.ts:81`, `wrangler.jsonc:60`, `.env.example:66`), so the hosted gate for `/claim` needs no plan change. Only the window knob is v3's alone (`ApothekeConfig.php:61`, default at `:266`, clamp at `:118`) | Consequence, stated rather than discovered at a deploy: a hosted install that ran v3 with `HOSTED_CLAIM_HOURS=24` gets 72 hours in v4 — the longer window for a first-admin token is the less safe direction, which is why it is written down. Revisit if our own hosting needs a different window: that is a §11 change (`.env.example`, both compose files, the walkthrough, one commit) and a plan revision, not a code-only edit |

| D25 | Who mints a claim token at all: a slice, or the operator console | **Answered 10:13 — C1 owns it, and the consequence is written down rather than discovered at G1.** `createClaim()` exists and is tested; nothing in the product calls it. v3 issues the link from a spark command (`ClaimCreateCommand`), and v4's operator console is C1. Inventing a CLI now would invent product surface for a one-off operator action, in the phase whose whole point is parity before addition. | The consequence, stated so nobody finds it at the gate: **`/claim` is unreachable by an operator until C1**, so **G1's "claim the first admin" step cannot be demonstrated end to end** before then. If G1 arrives first, the smallest honest fix is one documented one-shot invocation recorded as S1.2c — not a CLI framework. Nothing else in S1.2b is blocked by this decision |
| D26 | `StatementResult.changes` reports `0` on one of the three backends | **Answered 10:13 — do not change the driver; make the type say "unreported".** `DurableObjectSql.batch()` cannot report per-statement effects, because its unit of work is the DO's `transactionSync`: D1 reports `meta.changes`, rqlite `rows_affected`, and the single-process backend returns `{changes: 0, lastRowId: null}` for every statement on purpose. No product code reads `.changes` — the only reader is `test/rqlite-live.spec.ts:62`, which runs against rqlite, where the value is true. | Behaviour is decided **from state, never from a statement result**; that is what the slice's fix does, and it is the same rule as the `btoa` lesson — a value's contract is proven by reading it back, not by trusting what wrote it. `changes` becomes `number | null` with `null` meaning *this backend does not report it*, so TypeScript refuses a read that assumes a count instead of a comment asking politely. That change and the `_write_log` test-noise quietening go in one hygiene commit of their own, not inside a slice. Revisit only if a caller genuinely needs counts, which would mean the DO's batch RPC returning per-statement `meta` — a storage-layer change with the session flush in its blast radius |
| D27 | The `RoleAdmin` last-manager race: fix it on `main` now, or inside v4 phase 1 | **Answered 12:49 by Stathis — the `EXISTS` assert inside v4 phase 1, and no freeze-line fix on `main`.** The race is real: two concurrent `updateLocked()`/`deleteLocked()` calls can each read a manager count of two and each remove one, leaving none, and nothing in the schema forbids it. The fix chosen is the assert S1.3's DoD already required — read the count and refuse the write **in the same unit** — so it costs no extra slice work and needs no release of its own | What it costs, stated rather than implied: **the gap stays open on hosted D1 until v4 phase 1 ships and that installation is cut over.** Its reach is narrower than it sounds: the sequential path is already guarded (`RoleAdmin::managers()` covers a single request), so what is open is two edits landing in the same write unit — a double submit or two operators at once. What it does not do: it does not touch `main`, so v3.72.0 stays the frozen artefact, no tag moves, and the freeze keeps its meaning |
| D28 | S1.3's increment 2: split by **page** or by **handler** | **Answered 13:36 — by handler.** 2a is the page port, `GET settings/security` and the three users POSTs; 2b is the three roles POSTs, the JSON branch and the refusal assertions | A page split looks tidier on paper and fails in practice: `SecurityView::page()` is **one** 279-line document — the users table (`:137-159`), the role summary (`:82`) and the roles editor (`:173-216`) are sections of it — so a page split ships a commit that cannot render and therefore cannot be green. The handler split keeps each commit shippable and reviewable at roughly 700-900 and 350-400 lines. What it costs: between 2a and 2b the roles forms POST into the 501 handler, so 2a's docs must say so rather than let an operator find it |
| D29 | Who owns making `sessioncsrf` real: increment 2a, its own commit, or a later slice | **Answered 14:10 — it lands inside 2a, and it is recorded here as a decision rather than a silent scope change.** The filter refused every unsafe method with a phase-0 403, so every users POST in 2a would have been unreachable: the increment could not be tested, let alone shipped, without it. It is now v3's `CsrfCheck` — **419**, `Your session expired. Reload the page and try again.` as the app's error page and `{"ok":false,"error":"Your session expired."}` for a fetch, from `main@4a187d0f` (`modules/Core/Http/CsrfCheck.php:18`, `:23`) — with the body parsed once before the chain (`src/http/form.ts`), because a filter cannot await. | It is a decision about **every form write**, not about this page, which is why it needs a number: the phase-0 stance "refuse what cannot be checked" ends for all of them, the `sessioncsrf` entry leaves the deferred list, and `filters.spec.ts` now asserts 419 where it asserted 403. Re-splitting it into its own commit was considered and rejected: it would mean reverting `65d63014` to re-land the same 41 lines in two pieces, buying a red window for an audit trail this row already provides. What it costs: a wrong token is now a v3-shaped 419 everywhere instead of a plain refusal, so from 2a on any form POST is only as safe as the token the rendered page carried — which is exactly v3's own contract (`SEC-N10`), not a new one |
| D30 | Where the pre-push gates run, now that the dev VM is dedicated to Apotheke | **Answered 18:10 - locally, and the full suite is one of them.** The rebuilt box ran `vitest run` in 74 s with CI's own counts (28 files passed / 1 skipped, 248 tests passed / 6 skipped at `5db8a9f8`), and the suite is **89.63 s** at `c8cf8d62` measured here (300 tests passed / 6 skipped) and **103 s** as the builder measured it, because S1.4's replay is part of it, where the old box wedged at load 24 and made CI the only test runner. **The gate list is now six commands, `vitest run` included, before every push**; CI stays the sign-off authority (section 6) because a local run is still not a link to a run. The cost is one deliberate wait per push; the benefit is that a failure stops at the machine that can explain it instead of at a job whose lint step skipped the four steps behind it. **Reach: developer discipline only** - no CI job changes, no new dependency, and the six rqlite-live specs stay declared-skipped in the unit job, so `numPendingTests == 0` in the rqlite job remains the guard that they ran there. |

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
- 2026-11-24 00:50 EET — **Revision 10: S0.6's D1 job lands and G0 is signed.** Rationale: the gate's CI sentence
  named five jobs, all five now run green in one run (`45282360833`), parity runs on D1, local DO SQLite and `rqlited`
  inside it, and the D1 job inspects the emulated database file rather than trusting a log line. The sign-off states
  its own limits — emulated D1 is not hosted D1 (phase 1), TLS to a self-signed `rqlited` is G6, retry across a real
  election is G3. What it costs: nothing new. What it cuts: no further phase-0 work on either backend, which from here
  is a regression to keep green rather than a leg to build. What it unblocks: S0.9, then S0.5.
- 2026-11-24 02:05 EET — **Revision 11: `PUBLIC_URL`, and the healthcheck rule read as written.** Two corrections to my
  own instructions, both caught by the builder holding instead of reconciling them by hand. (1) The variable is
  `PUBLIC_URL`; `PUBLIC_BASE_URL` was a typo in §11's table and nothing else in the repo carries it. Not a "code beats
  plan" precedent — the plan was simply wrong about a name the shipped product already uses, so following it would have
  silently renamed a variable operators already have in their `.env`. (2) §11 rule 7 stands unamended: the `apotheke`
  container probes `/healthz`, and the `rqlite` container probes rqlite's own `/readyz` with `RQLITE_AUTH` from `.env`,
  falling back to a TCP connect on 4001 when no auth is configured. "Every container probes `/healthz`" was wrong —
  rqlite has no such route — while the principle it was reaching for is rule 7's own. `depends_on: service_healthy` on
  rqlite stays (rule 2, cold start); it is not a correctness mechanism, and the 1s-cooldown `/readyz` retry is.
  What it costs: nothing. What it changes: the builder is unblocked on S0.9 with the plan and the code agreeing on names.
- 2026-11-24 05:05 EET — **Revision 17: a clock correction, no scope change.** Rationale: revision 16 was committed at 04:11 EET and its header said 04:20 — the third self-misdating header in four revisions (revision 13 said 03:45 for 03:33; revision 15 said 04:10 for 04:02 and 04:35 for 04:05). A plan whose opening line misstates its own commit time is the same defect as a doc describing a superseded surface: the reader cannot tell whether the text they hold is the text that landed. Fixed at the source, and the rule for me: the header time is read back from the commit, never written from memory. What it costs: nothing. What it changes: the revision number and two timestamps. It does **not** touch D18, the phase-1 slices, the gates, or any table.
- 2026-11-24 04:20 EET — **Revision 16: S0.5's rulings, and revision 15's clock.** Rationale: the builder asked three questions it had no business answering alone, and two of them were already the plan's answers written as behaviour rather than as decisions — a page route that needs a session answers 401 where v3 redirects 303 to `/login`, and page 404/405 are problem+json where v3 renders its error page. Both are now named as scaffold with the slice that restores them (P1/P2), so the next reader cannot mistake a phase-0 stub for a contract. The third is a genuine gap: `ALLOWED_HOSTS` and `CORS_ALLOWED_ORIGINS` are not in the sixteen names, so their behaviour is a narrowing nobody had written down — D18 writes it down. It also corrects revision 15's own timestamps, which were written as 04:10/04:35 for commits that landed 04:02 and 04:05; a plan that misdates itself is the same defect as a doc that describes a superseded surface. What it costs: nothing. What it changes: D18, and `v4/docs/router.md` becomes load-bearing rather than a courtesy. What it does **not** do: it does not decide the `RoleAdmin` question, and it does not add a route.
- 2026-11-24 04:10 EET — **Revision 15: S0.5 lands, phase 0 closes, phase 1 is sliced.** Rationale: the last
  phase-0 slice shipped green in runs `45308153624` and `45308619604` (six jobs, 58 steps, zero non-success, zero
  skipped) and its evidence is a closed surface rather than a claim — 281 rows turned into requests, no 2xx outside
  the CORS preflight, parity both ways against the PHP fixture, and a routes step that goes red when the PHP sources
  move. That makes phase 0's machinery complete, so the plan's job changes from "what to build in phase 0" to "what
  to refuse in phase 1", which is why phase 1 got slices with definitions of done. What it costs: nothing. What it
  changes: §11's acceptance sentence no longer blames the router for the missing pages, and phase 1 has an order.
  What it does **not** do: it does not decide the `RoleAdmin` question, and it does not promise a page — nothing here
  renders.
- 2026-11-24 03:40 EET — **Revision 14: a record correction, no scope change.** Rationale: §5 named S0.9's head as
  `e654d275`, which is where run `45305147064` went green, but the slice's sixth commit `d3aaafb1` (the
  root-level-path line D16 asks for) is the actual head and has its own green run, `45305655725`. Revision 13's
  own timestamp said 03:45 EET when the commit landed at 03:33. What it costs: nothing. What it changes: two
  numbers in the record, so a reader auditing the branch is not told the tree ends one commit early.
- 2026-11-24 03:33 EET — **Revision 13: S0.9 lands, and the row it disproved.** Rationale: the compose skeleton shipped and was *executed* — run `45305147064` boots both stacks from nothing, gets `/readyz 200` with `seed:"done"` from each, and proves a second boot does not re-seed — which is the first time self-host packaging has been tested rather than read. It also settled §11's rqlite volume row the only way that counts: rqlite v10.7.4 has one data directory and the image pre-creates `/rqlite/file` owned by uid 1000, so the two-volume row could never have worked. What it costs: nothing shipped is affected — no release exists and the root v3 compose is untouched. What it changes: D17, and a documented stub image in place of the release image until P6. What it does not prove: the acceptance list (needs S0.5), TLS (G6), election retry (G3).
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
- 2026-11-24 12:53 EET — **D27: the last-manager race is fixed in phase 1, and `main` is not touched.** Stathis chose the `EXISTS` assert inside the write unit over a freeze-line fix on `main`, and the two options were not equal: the assert is already in S1.3's DoD, while a `main` fix would ship PHP into a runtime v4 deletes, need its own release and tag, and still leave the v4 port to write the same assert afterwards. What it costs: the hosted installation carries the gap until phase 1 lands, and that is now written in the plan rather than left as a silence. Reach: only two edits in one write unit; one request at a time is already safe.
- 2026-11-24 13:36 EET — **D28: increment 2 splits by handler, not by page.** Rationale: the page is one document, so a page split cannot be green; a handler split can, and it keeps the users half reviewable before the roles matrix lands. What it costs: the roles forms answer 501 between 2a and 2b, which the docs must name in 2a's own commit.
- 2026-11-24 14:05 EET — **Revision 27: the smoke is bounded at all three probes, and revision 26 could only name two.** Rationale: two of the three unbounded calls were `curl` and one was a raw `fetch` to rqlite, so a sweep that looked for `curl` found 2 of 3. The script's header now states the rule at `:97-101` so the next probe added is bounded by the text above it. What it costs: nothing — two one-flag commits, both green (`45382091338`, `45383878498`), and a red tree can no longer burn 37 minutes to learn nothing.
- 2026-11-24 14:10 EET — **D29: `sessioncsrf` becomes real inside 2a, at v3's 419.** Rationale: the filter's phase-0 403 made every users POST unreachable, so 2a could not be tested without it; and the status it refuses with is v3's own (`CsrfCheck::STATUS` 419), which the golden fixture and the E2E suite already expect, so the alternative was a green increment that diverged on every form write. What it costs: the stance changes for every surface at once — a token-less write is 419 rather than a plain refusal — and the change therefore has a number instead of living in a feature diff. Re-splitting was rejected: reverting a green commit to re-land 41 lines in two pieces costs a red window and buys only what this bullet records. Revision 28 also records 2a itself: `65d63014`, run `45390457762` green with 28 files and 248 tests, the page byte-identical to PHP on a pinned state.
- 2026-11-24 18:55 EET — **D31: the sqlite leg gets a job that names it, because the default driver was the most exercised and the least asserted.** Rationale: `wrangler.jsonc:49` makes `DB_DRIVER=sqlite` the default, so every existing test already ran the DO path in every job, and my dispatch premise — “only D1 and rqlite are proven, so sqlite is an assumption” — was backwards. What was actually missing: a spec naming the driver's contract, the count rule applied to that leg, and the DO's database read as bytes, while the `d1` job reads its own file and asserts the inverse. What it costs: one more job (~2 minutes) and `test/sqlite.spec.ts` now runs in every leg, which is the point. What it does not prove: a self-hoster's `node:sqlite` or system `sqlite3`, or a second process opening that file. Three facts measured here were new to both of us: the binding cap is D1's 100 (100 binds answer, the 101st refuses), a BLOB is an `ArrayBuffer` (rqlite `Uint8Array`, emulated D1 a byte array), and `PRAGMA`/`sqlite_version()` are refused on this driver and on D1 — that last pair measured by hand and documented in `adapters.md` at this commit, and **pinned by the sixth spec in `test/sqlite.spec.ts` from `74868d8f`** (revision 32).
- 2026-11-25 09:27 EET — **Revision 33: S1.4 accepted, and the three narrowings it implies are named rather than implied.** Rationale: the increment landed 4 of 4 routes at `ae9d661c` with v3's own answers captured over HTTP rather than tabulated by hand, and in doing so it fixed a flag swap that had been on the branch since 2a (`resolveActor()` gave the anonymous admin and the guest-browsing visitor each other's `guest`/`anon`), which would have served `/api/v1/me` to a sign-in-off installation where v3 answers 403. What it costs: nothing to run, one local gate that now takes ~90 s instead of 74 s, and a failed sign-in that holds a request for up to a second of wall time on `/login` as well as the API — v3's own behaviour, stated at the commit. What it does not do: honour `CORS_ALLOWED_ORIGINS` (not in §11's sixteen names, so v3 accepts configured CORS origins for `/api` writes and v4 does not), send PHP's `Expires`/`Pragma`, or create the first admin per request; all three are written down here so a later reader cannot mistake them for parity. The header's build owner is corrected to Apotheke Builder in the same revision (D32), because the plan should name the bot that does the work.
- 2026-11-24 20:16 EET — **D30, amended: the six gates do not include a workflow's own assertions.** Rationale: `v4.yml:379` pins the sqlite spec's assertion count, the sixth test broke that pin, and all six local gates were green on the push that then failed — because the jq rule lives in the workflow and no gate runs it. So D30's guarantee is “no red push is a choice” for the six gates it names; a yml assertion, a job's step count, and a downstream step that gets skipped are CI's to catch, and revision 31's phrasing that a green local run made a red push a matter of choice was too wide. What it costs: a pin that counts specs is bumped in the same commit as the spec, and the pin stays strict — relaxing it to an aggregate is exactly the failure revision 29 recorded. What it does not change: the six gates stay mandatory, and a phase is still signed off only on a CI run.

- 2026-11-25 10:02 EET — **Revision 34: phase 1's account page gets the slice it never had (S1.5), and G1 now
  covers S1.1–S1.5.** Rationale: the phase-1 paragraph has listed "account pages" since revision 15 while the slice
  list stopped at the session API, so phase 1 would have been signed by G1's own wording with `/account` still
  answering 501 through `notPorted` — and nothing would have gone red, because `unported.spec.ts` only lists page
  routes whose permission is `none` or `public` and `/account` needs `self`. The gap is cheap now and expensive later:
  the page is one document, its two POSTs are one account each, and the epoch path it needs already exists. What it
  costs: one increment before G1, and G1's first clause names five slices instead of four. What it does not do: it
  does not change S1.4's acceptance, any gate's evidence, or `main`.

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
   backend sees a query the others could not run. Measured on the sqlite leg (D31): 100 binds answer, the 101st refuses.
4. Migrations carry **one** dialect. All three are SQLite: the 35 migrations, the schema and the parity diff are
   written once. No `Dialect` branching in the runner.
5. Text rules come free: `LIKE` behaves identically on all three, so the PHP `lower()` comparisons stay as they are.
6. Ids and sequences are unchanged (`INTEGER PRIMARY KEY AUTOINCREMENT`). D1 returns `meta.last_row_id` and
   `meta.changes`; rqlite returns `last_insert_id` and `rows_affected`; only in-batch dependencies need the SQL form. Local Durable Object SQLite reports neither — `changes` and `lastRowId` are `null` for every statement (asserted in `test/sqlite.spec.ts`) — which is why a handler that needs to know whether it changed a row reads the state back (D26).
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

Default: `rqlited` (one process, one data directory at `/rqlite/file` — D17) beside `workerd`. Single-process option: `workerd` alone
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
`http://localhost:9091`, claim the first admin. That promise is read **from the repo root, and takes effect at the
cutover** (D16); until then the same files sit under `v4/` and the root files stay v3's. Nothing to install, nothing to compile, no database to prepare.

| File | Purpose |
|---|---|
| `docker-compose.yml` | default stack: `apotheke` + `rqlite` |
| `docker-compose.sqlite.yml` | single-process variant: `apotheke` only, `DB_DRIVER=sqlite` |
| `.env.example` | every knob with a safe default and a comment; no real secrets |
| `docs/self-host-docker.md` | the literal walkthrough, including upgrade, backup, restore, troubleshooting |
| `scripts/selfhost-smoke.sh` | boots a stack, runs the acceptance steps, tears it down; used by CI and by operators |

**Where these files live, and when (D16).** In phase 0 the first four are written as `v4/docker-compose.yml`, `v4/docker-compose.sqlite.yml` and `v4/.env.example`, plus `docs/self-host-docker.md`; `scripts/selfhost-smoke.sh` stays where it is, at the root of `scripts/`, and takes the compose file it should test as its first argument. The move to the repo root is the cutover step in P7, in one PR that also turns v3's `docker-compose.yml` into `docker-compose.v3.yml` (kept for exactly one release, so an operator can roll back by pointing at it) and rewrites `docs/getting-started.md` and `docs/deployment-and-operations.md`, which today describe the root compose. Nothing at the root is touched before then: pulling the branch must never break a v3 install.

Service contract (this is what S0.9 pins, and what the docs are written from):

| Service | Image | Published | Volumes | Key env |
|---|---|---|---|---|
| `apotheke` | `ghcr.io/thordin9/apotheke:<version>` | `9091:9091` | `apotheke-data:/data` (uploads, exports) | `DB_DRIVER=rqlite`, `RQLITE_URL=http://rqlite:4001`, `APP_KEY`, `PUBLIC_URL` |
| `rqlite` | `rqlite/rqlite:10` (pinned to the tested major: v10.7.4, SQLite 3.54.1) | **nothing** (4001 stays on the compose network) | `rqlite-data:/rqlite/file` — **one** volume (**D17**: the image pre-creates this path owned by uid 1000, so the volume inherits that ownership; the raft log, `raft.db` and `db.sqlite` all live under it) | `DATA_DIR=/rqlite/file/data`, `NODE_ID`, `HTTP_ADV_ADDR`/`RAFT_ADV_ADDR` set to the service name (the image's own entrypoint turns these into flags; the advertised address must not be the container hostname, which changes on every recreate), `-http-addr 0.0.0.0:4001`, `-raft-addr 0.0.0.0:4002`, and `RQLITE_AUTH` turned into an `-auth` file by a thin entrypoint wrapper (one secret in `.env`, never in the compose file) |

In phase 0 the `apotheke` image is a **stub** — `v4/docker/Dockerfile.stub`, tagged `apotheke:4-skeleton`, running
the real Worker under `wrangler dev` with real migrations, a real seed and real `/healthz`/`/readyz`. It is named so
it cannot be mistaken for the release image, which is P6's `ghcr.io/thordin9/apotheke:<version>`.

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
   artifacts: `scripts/selfhost-smoke.sh v4/docker-compose.yml` and `... v4/docker-compose.sqlite.yml` in phase 0, and the same two arguments without the prefix once P7 has moved them.
9. No Postgres service, no external object store, no volume the operator must pre-create.

Acceptance for G6 (and for S0.9 once the stub image exists): stack up on a clean machine with no Docker cache, claim
admin, create an item, upload a photo, download the backup ZIP, restore it, `docker compose restart`, and the catalogue
is still there — twice, once per compose file.
