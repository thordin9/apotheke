# QA: apotheke PR #76, CI4 request and auth hardening (SEC-M3, L2, N6, M1, M2)

- **PR:** [#76](https://github.com/thordin9/apotheke/pull/76), "feat(ci4): request and auth hardening (SEC-M3, L2, N6, M1, M2)", into `feature/ci4`, by Coder2. Open, not a draft.
- **Base:** `feature/ci4` at `3146170`. The branch is 13 linear commits on `3146170`, 13 ahead and 0 behind. No merge commit, no force-push.
- **Head tested:** `e24ce859069c154fa208ab7738284ecd8829f2ad` (`fd37f43`, `196df82`, `2251b45`, `afbdd91`, `82b9a4b`, `8b457ec`, `bc608b9`, `208bda3`, `ce10bed`, `1893e8c`, `28d0f5f`, `54dcd02`, `e24ce85`). The head was the same at the start (14:26) and the end (14:58) of the review (Athens time, UTC+3), checked through the API and the PR patch.
- **Changes vs `feature/ci4`:** 51 files, +2184 / −367.
  - Runtime: `ci4/app/Config/{App,Filters,Services}.php`; new `ci4/modules/Auth/{AdminGuard,CurrentUser,SessionEpoch,SignOutEverywhere,UserRepository}.php`; `Core/Config/ApothekeConfig.php`; new `Core/Filters/{HostFilter,SameOriginFilter}.php`; `Core/Filters/SessionCookie.php`; new `Core/Http/{ClientAddress,HostAllowList,HostCheck,ProxyList,SameOrigin,SessionGate,SessionToken}.php`; `Core/Http/RequestScheme.php`; new `Core/Security/{ActorGate,CommandLine,Nobody}.php`; `Settings/{SettingsSchema,SettingsService}.php`. `Core/Http/TrustProxyReader.php` is removed.
  - Tests: 7 new and 4 changed files under `ci4/tests/`, 1 new and 7 changed under `tests/Unit/` (`TrustProxyReaderTest` removed), plus the test-only `tests/ci4-router.php` hook and `tests/ci4-session-on-demand.php`.
  - Docs: ADR 001, AGENTS.md, `docs/plans/feature12-ci4/PLAN.md`, `deptrac.yaml`, `CHANGELOG.md`.
  - No shared runtime code changed: `src/`, `public/`, `bin/`, `migrations/`, `docker/` and both `composer.lock` files are identical to the base.
- **CI:** run 40088707145 at `e24ce85` (`pull_request`), **22/22 `success`** (14:08–14:19 Athens time, UTC+3).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - Sources: codeload tarballs of `e24ce85` and `3146170`, `main`'s legacy sources at `895b007` for parity, the GitHub API (PR, commits, files, check runs, compare), the 2026-10-24 security audit (SEC-M1, M2, M3, L2, N6) and the #74 and #68 QA docs.
  - Local runs only in scratch copies, against PostgreSQL 17 in private databases (`qa2_76_*`), on ports 9761–9769.
  - No comment, review, push or merge was made.
- **Verdict: PASS.** There are no High or Medium findings: three Low, three Nit and notes.
  - All five audit items are fixed at the head and were checked live against the base: M3 (Host allow-list and same-origin writes), L2 (no session for anonymous requests), N6 (idle end and sign out everywhere), M1 (admin-only sign-in settings import) and M2 (trusted proxies). The ports match `main`'s legacy code line for line where `main` has the same function.
  - #74's L-1, N-1 and N-2 and both #68 nits are done. #74's L-1 mutations (C1e, C2f) are now killed.
  - 28 of 30 mutations are caught. One survivor is an equivalent mutant (I-3). The other shows the CLI actor isn't pinned by a test (N-1).
  - The three Lows are all latent at this head, because no CI4 route renders HTML or keeps a signed-in session yet, and the repo has no worker entry. All three should be fixed before the first CI4 page with a session (the Auth pages) or the worker entry lands.

## 1. Test plan

### 1.1 Environment

- **Docker isn't available on this box.** `git` to GitHub doesn't work here, so trees came from codeload tarballs. The mutation tree was `git init`ed locally to track and revert mutations.
- **Suites:** the box's PHP **8.4.27** CLI (pdo_pgsql, pgsql, pdo_sqlite, gd, imagick), with Composer dev dependencies from the locked `composer.lock` and `ci4/composer.lock` (CodeIgniter **4.7.5**). Each leg ran in its own copy of the tree. The legacy 8.3 legs weren't run, because no shared runtime code changed (CI ran them green).
- PostgreSQL **17**, scram-sha-256 on TCP, test-only superuser `apotheke` with CI's public test password (written as `<CI test password>` here). Databases `qa2_76_c84`, `qa2_76_mut` and `qa2_76_live`.
- **Live repros:** `php8.4 -S` with `ci4/public/router.php` (the shipped boot), SQLite, `CI_ENVIRONMENT=production`.
  - `:9761` the head, `:9762` the base `3146170`.
  - `:9764` head with `ALLOWED_HOSTS`, `:9765` head with an https `PUBLIC_URL`, `:9766` head with `ALLOWED_HOSTS=*`.
  - `:9763`, `:9768` (`TRUSTED_PROXIES` = loopback) and `:9769` (`TRUSTED_PROXIES` = a 10/8 block): a head copy with **scratch routes** only (a POST target, a page that asks `SessionToken` for a token, `CurrentUser`, `ClientAddress`, `base_url()`/redirect, sign out everywhere, settings import). Nothing else changed. Signed-in sessions were written as session files for two test accounts (admin and editor), because CI4 has no sign-in page yet.
  - `:9767` a base copy with one scratch HTML route, for comparison.
- gitleaks **8.30.1**, checked against the sha256 pinned in `ci.yml`.
- Every suite run used `CI=true`.

### 1.2 Acceptance checks

1. **CI** on the head SHA: 22/22, plus the commit subjects.
2. **Audit repros** for M3, L2, N6, M1 and M2, against the base and the head.
3. **Suites:** ci4 @ 8.4 on SQLite and PostgreSQL 17, the CI4 module suite, then PHPStan (DI rule), deptrac, `lint.sh` (8.4 and 8.3), `composer audit` and the commit check.
4. **Mutations:** revert each fix, break each new rule, and prove #74 L-1 / N-1 / N-2.
5. **Worker-mode conditions** and the Cloudflare-phase note.
6. **Docs and CHANGELOG:** Conventional Commits, the Added and Fixed bullets linked to #76, the #68 nits, and hardening wording only.
7. **Security** and gitleaks.
8. **#69 interaction** (Info only).

## 2. Results

### 2.1 CI and commits (item 1)

- **Run 40088707145** (`e24ce85`, `pull_request`): **22/22 `success`**, 14:08–14:19 Athens time (UTC+3). Nothing failed and nothing is pending.
  - Lint, headless mobile checks, Docker build + smoke test, and Visual E2E shards 1–3: each on PHP 8.3 and 8.4.
  - Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan.
- The job logs need a signed-in API call, so the counts below come from local runs.
- **Commit subjects:** all 13 pass `scripts/check-commits.sh --message`, 54–67 characters: `feat(ci4)` ×6, `test(ci4)` ×3, `docs(changelog)` ×2, `fix(ci4)` ×1, `docs(ci4)` ×1.

**PASS**

### 2.2 SEC-M3: Host allow-list (item 2)

Base `3146170` on `:9762` against head `e24ce85` on `:9761`. Each Host was sent to `/healthz`, `/`, `/nonexistent` and `/theme.css`.

| Host header | Base | Head |
|---|---|---|
| `127.0.0.1:port`, `localhost`, `app.localhost`, the machine's own host name, `[::1]:port`, `1.2.3.4` | 200 / 404 as routed | same (built-in names) |
| `evil.example`, `evil.example:port`, `EVIL.example`, `evil.example.` | **200 / 404 as routed** | **421** on all four paths |
| malformed (`a..b`, `-x.example`, `x y`, port 99999) | 500 or 200 | **421** |
| no Host (HTTP/1.0) / empty Host | 200 | 200 (as legacy `request_host_ok()`) |
| two Host headers (one allowed, one not) | – | 421 |
| HEAD with an unknown name | – | 421 |

- **421 response:** `text/plain; charset=UTF-8`, `Cache-Control: no-store`, the four security headers, and legacy's fixed text. The name is never echoed.
- **Configured names:** `ALLOWED_HOSTS="inv.example, other.example:8080"` accepts both names on any port and refuses the rest. An https `PUBLIC_URL` accepts its host with or without `:443`. `ALLOWED_HOSTS=*` accepts any valid name and still refuses a malformed one. The `public_url` **setting** (set with `bin/settings.php`) is accepted too, and the Host check falls back to `PUBLIC_URL` when the settings can't be read.
- **Order:** `hostcheck` is a required before-filter, so it covers `/healthz`, branding and unknown paths. `originalsguard` runs first, so `/originals/*` gives its 404 before the Host check (harmless).
- **Parity:** `HostAllowList` and `SameOrigin` are line-for-line ports of `main`'s `src/hosts.php` (`host_split`, `host_is_builtin`, `host_configured`, `host_allowed`, `method_is_safe`, `request_same_origin`).
- **`Config\App`:** `allowedHostnames` is `PUBLIC_URL`'s host plus `ALLOWED_HOSTS`, with `*` dropped. `baseURL` takes only an allowed Host, else `localhost`, and `AppBaseUrlTest` pins that. It ignores the `public_url` setting and `trust_proxy` (L-2).

**PASS**

### 2.3 SEC-M3: same-origin writes (item 2)

`:9763`, sign-in off (no accounts), a valid session CSRF token sent with every write so that only the new filter decides.

| Request | Result |
|---|---|
| POST with no `Sec-Fetch-Site` and no `Origin` | 200 (allowed, as `main`) |
| `Sec-Fetch-Site: same-origin` / `none` | 200 |
| `Sec-Fetch-Site: cross-site` / `same-site` | **403** |
| `Sec-Fetch-Site: cross-site` with a matching `Origin` | **403** (`Sec-Fetch-Site` decides) |
| `Origin` = own origin, also with a trailing `/` | 200 |
| `Origin` = own host with the other scheme / another host / `null` | **403** |
| a refused write from a fetch client (`X-Requested-With: fetch` or `Accept: application/json`) | **403**, `application/json` `{"ok":false,"error":…}` |
| PUT, PATCH, DELETE, and POST with `_method=DELETE`, cross-site | **403** |
| GET and OPTIONS cross-site | 200 (safe methods) |
| cross-site POST to an unknown path and to `/healthz` | **403** (required filter, before routing) |
| cross-site POST without a token | **403** (same-origin runs before the CSRF filter, as legacy) |
| same-origin POST without a token | 419 (CSRF unchanged) |
| sign-in on, cross-site POST with a token | 200 (the rule applies only with sign-in off, as `main`) |

- The 403 has the four security headers and `Cache-Control: no-store`.

**PASS**

### 2.4 SEC-L2: sessions on demand (item 2)

| Check | Base | Head |
|---|---|---|
| 50 cookieless `GET /nope<i>`, plus `/theme.css`, `/healthz`, `/favicon.ico`, `/manifest.webmanifest` | **+50 session files, 50 `Set-Cookie`** | **0 files, 0 `Set-Cookie`** |
| a well-formed cookie with no session file / a cookie with path characters / a 300-character cookie | – | no session, no `Set-Cookie`, no file |
| a page that asks `SessionToken` for a token (scratch route) | – | one session with `csrf` and `last_seen`, `Set-Cookie` with `HttpOnly`, `SameSite=Lax`, `Max-Age` from `session_days` |
| the same cookie again | – | the same session, no new file |

- `SessionGate::isLive()` is legacy `session_cookie_live()` (the same id pattern plus a file check), so a cookie can't name a path outside the session directory.
- The session is released right after it's loaded (`session_write_close()`), as legacy `init_session()` does. This is where L-1 starts.

**PASS** (L-1 is latent)

### 2.5 SEC-N6: idle end and sign out everywhere (item 2)

| Check | Result |
|---|---|
| `last_seen` 31 days ago, `session_days` 30 | session file removed, `CurrentUser` = nobody |
| 29 days ago, 30 days minus 5 s, or no `last_seen` (a session from before the upgrade) | resumed and stamped with the current time |
| `session_days` 7: 8 days ago / 6 days ago | ended / kept. The cookie is `Max-Age=604800` |
| two sessions of one admin, plus one of another user; sign out everywhere with a wrong password | 403, all three still signed in |
| the same with the right password | the calling session gets a new id and stays signed in. The other session of that account is rejected (epoch mismatch). The old id of the calling session no longer exists. The other user's session is unaffected |

- The epoch is legacy `user_epoch()` (the first 16 hex characters of sha256 of the hash, `|` and the id), compared with `hash_equals()`. Rehashing changes the salt, so the epoch changes without a schema change.
- **Known gap:** the PR body says under Security: "Known gap, SEC-N6 idle half still open: the idle limit reuses `session_days` for parity with legacy…", with the PM's `session_idle_hours` plan. Confirmed and not graded, as asked.

**PASS**

### 2.6 SEC-M1: admin-only sign-in settings import (item 2)

The scratch route imports `auth_register_disabled`, `auth_guest_disabled`, `session_days`, `trust_proxy` (security section) and `app_tagline`, with sign-in on.

| Actor | Result |
|---|---|
| signed-in editor | `app_tagline` applied, the 4 security keys **ignored**, database unchanged for them |
| guest (guest browsing on) | same, 4 ignored |
| nobody (guest browsing off) | same, 4 ignored |
| signed-in admin | all 5 applied |
| sign-in off (anonymous admin, as legacy) | applied |
| `bin/settings.php import` with sign-in **and guest browsing on** | applied ("Imported 3 settings"). CI4 passes `CommandLine` explicitly, so the CLI bug that `main` fixes in #77 doesn't exist here |

- The rule is legacy `settings_import()` on `main` (`$def[0] === 'security' && !actor_is_admin()`), and the security section has the same 8 keys as `main`.
- `SettingsService` defaults to `Nobody`, so a missing actor fails closed (mutation A3).
- `AdminGuard` (grant admin, change an admin account, turn sign-in off) has `main`'s messages and 403. It isn't wired to a route yet, as the PR says. `AccountActionsTest` covers it.
- The CLI path isn't pinned by a test (N-1).

**PASS**

### 2.7 SEC-M2: trusted proxies and the client address (item 2)

`ClientAddress` isn't used by a route yet (CI4 has no throttle), so a scratch route printed `ClientAddress::from()` with the request's `trust_proxy` and `TRUSTED_PROXIES`, next to CI4's `getIPAddress()`.

| `trust_proxy` | `TRUSTED_PROXIES` | `X-Forwarded-For` | Client |
|---|---|---|---|
| off | any | any | the peer (`127.0.0.1`) |
| on | empty | `203.0.113.9` | the peer: ignored from an unlisted peer |
| on | 10/8 (peer not in it) | `203.0.113.9` | the peer |
| on | loopback | `203.0.113.9` | **`203.0.113.9`** |
| on | loopback | `198.51.100.1, 203.0.113.9` | `203.0.113.9` (rightmost non-proxy hop) |
| on | loopback | `203.0.113.9, 127.0.0.1` | `203.0.113.9` (proxy hops skipped) |
| on | loopback | an upper-case, zero-padded IPv6 address | the canonical form (`2001:db8::1`) |
| on | loopback | `127.0.0.1` / empty | the peer |

- CI4's own `getIPAddress()` stayed `127.0.0.1` in every case, because `proxyIPs` is empty.
- `ProxyList` is a port of `main`'s `config_proxy_list()`, `config_ip_normalize()`, `config_ip_matches()` and `config_proxy_allows()`, and `ClientAddress` of `client_address_from()`. `ClientAddressTest` has `main`'s cases plus CIDR edges.
- `X-Forwarded-Proto` is honoured from any peer when `trust_proxy` is on, as `main` does (I-1).

**PASS**

### 2.8 Mutations (item 4)

Scratch copy only. Each mutation was reverted (`git status` clean afterwards) before the next. Unit = `ci4/phpunit.xml.dist` on SQLite and PostgreSQL. Root = `Ci4RequestHardeningHttpTest`, `Ci4HttpTest`, `ApothekeConfigTest`, `Ci4SettingsCliTest`, `RequestSchemeTest` and `Ci4DiRuleTest` on ci4 @ 8.4, SQLite (run when Unit passed).

| # | Mutation | Caught by | Result |
|---|---|---|---|
| H1 | M3 revert: `HostFilter` never refuses | `Ci4RequestHardeningHttpTest` (421 and configured names) | **killed** |
| H2 | `*.localhost` not built in | `HostAllowListTest` | **killed** |
| H3 | `*` in `ALLOWED_HOSTS` ignored | `HostAllowListTest` | **killed** |
| H5 | the Host check ignores the `public_url` setting | `Ci4RequestHardeningHttpTest` | **killed** |
| H6 | `baseURL` takes any Host | `AppBaseUrlTest` | **killed** |
| H7 | `hostcheck` removed from the required filters | `Ci4RequestHardeningHttpTest` | **killed** |
| S1 | `Sec-Fetch-Site: same-site` accepted | `SameOriginTest` | **killed** |
| S2 | any `Origin` accepted | `SameOriginTest` | **killed** |
| S3 | same-origin applied only with sign-in on | `Ci4RequestHardeningHttpTest` | **killed** |
| S5 | `sameorigin` removed from the required filters | `Ci4RequestHardeningHttpTest` | **killed** |
| L1 | any well-formed cookie loads a session (no file check) | `SessionGateTest` | **killed** |
| L2 | L2 revert: every request starts a session | `SessionCookieWorkerTest` | **killed** |
| N1 | idle sessions never expire | `SessionGateTest` (2 tests) | **killed** |
| N2 | an idle session is neither emptied nor destroyed | `SessionGateTest` | **killed** |
| N3 | `last_seen` never stamped | `SessionGateTest` (2 tests) | **killed** |
| N5 | the epoch isn't checked | `AccountActionsTest` (2 tests) | **killed** |
| N6 | sign out everywhere doesn't store the new hash | `AccountActionsTest` | **killed** |
| N7 | sign out everywhere without the password | `AccountActionsTest` | **killed** |
| A1 | M1 revert: import applies sign-in settings for anyone | `AccountActionsTest`, `SettingsServiceTest` | **killed** |
| A2 | spark's `SettingsCommand` uses the request actor instead of `CommandLine` | none | **survives (N-1)** |
| A3 | a missing actor fails open (`CommandLine` default) | `SettingsServiceTest` | **killed** |
| A4 | anyone grants admin | `AccountActionsTest` | **killed** |
| A5 | anyone changes an admin account | `AccountActionsTest` | **killed** |
| P1 | `X-Forwarded-For` read without the peer-is-a-proxy guard | none | survives: **equivalent** (I-3) |
| P2 | leftmost hop instead of the rightmost non-proxy hop | `ClientAddressTest` | **killed** |
| P3 | CIDR partial-byte mask ignored | `ClientAddressTest` | **killed** |
| W1 | **#74 L-1 (C1e):** a function-level `static` holds `Services::settings()` in `SessionCookie::before()` | `FilterStateTest` (static variables of filter methods) | **killed** (survived on #74) |
| W2 | **#74 L-1 (C2f):** a function-level `static` holds a `SettingsService` in the `sessionLifetime` factory | `FilterStateTest::testServiceFactoriesKeepNoStaticVariables`, `SessionCookieWorkerTest`, `SessionLifetimeTest` | **killed** (survived on #74) |
| W3 | **#74 N-1 (C2d):** `SessionCookie` uses the shared `sessionLifetime()` | `SessionCookieWorkerTest::testTheFilterIgnoresASharedSessionLifetime` | **killed** (survived on #74) |
| W4 | **#74 N-2:** `SessionCookie` builds its own `SettingsService` on a new, non-shared connection | `TrustProxyOnceTest` | **killed** |

- The PHPStan DI rule doesn't flag W1 or W2, by design: it allows anything inside `before()`/`after()`. `FilterStateTest` now catches function-level statics, in filters and in `Config\Services` factories, which is what #74's L-1 asked for.
- PHPStan also failed on some non-W mutations, but only because a constant condition such as `if (false)` is dead code. Those weren't counted as catches.

**28 of 30 killed.** One survivor is equivalent (I-3), and one is a test gap (N-1).

### 2.9 Suites and static checks (item 3)

| Leg | Command | Result |
|---|---|---|
| ci4 @ 8.4.27, SQLite | `CI=true APOTHEKE_APP=ci4 APOTHEKE_TEST_DB=sqlite DB_DRIVER=sqlite php vendor/bin/phpunit` | **OK**, 483 tests, 12434 assertions, 4 skipped (PostgreSQL only ×2, php-cgi not installed, `HttpSuiteTest`) |
| ci4 @ 8.4.27, PostgreSQL 17 | same with `pgsql`, `TEST_PG_DB=qa2_76_c84`, `TEST_PG_PASSWORD=<CI test password>` | **OK**, 483 tests, 12442 assertions, 2 skipped (php-cgi, `HttpSuiteTest`). `php scripts/ci4-pgsql-proof.php`: `rows=126`, exit 0 |
| both legs | CLI migrations as in CI (`bin/migrate.php`, idempotent re-run, `bin/seed.php --demo` twice, `--status`) | OK |
| CI4 module suite | `php8.4 vendor/bin/phpunit -c ci4/phpunit.xml.dist` with PostgreSQL enabled | **OK**, 115 tests, 1013 assertions (82 on #74) |
| PHPStan DI rule | `php scripts/ci4-arch.php di` | `[OK] No errors` |
| deptrac | `php scripts/ci4-arch.php deptrac` | 0 violations, 0 errors |
| lint | `bash scripts/lint.sh` on 8.4, and on 8.3 via a `php` → `php8.3` shim | exit 0 / 0: PHP lint, JS syntax, 235 relative links in 65 files with 0 broken, shellcheck, actionlint, pipefail-safe greps |
| dependencies | `composer audit` (root and `--working-dir=ci4`) | "No security vulnerability advisories found" (both) |

- `Ci4ConnectFailureLeakTest` passed on the PostgreSQL leg with the new count: only the migration runner logs in before CI4's own connect, so `Config\App` opens no connection (#74 N-2).
- The legacy legs weren't run locally (see 1.1). CI ran all four green.

**PASS**

### 2.10 Worker-mode conditions and the #74 follow-ups (item 5)

| Condition | Code at `e24ce85` | Enforced by | OK |
|---|---|---|---|
| (1) Filters resolve services only inside `before()`/`after()`, no properties or statics | The two new filters (`HostFilter`, `SameOriginFilter`) and `SessionCookie` declare no properties. `Services::` appears only in their `before()`. No function-level statics anywhere in `modules/` or `Config/Services.php` | the DI rule, and `FilterStateTest` (properties, and now static variables of every filter method and every `Services` factory: W1, W2) | ✓ |
| (2) No cross-request caching | `HostCheck` reads its list through a closure per request (`Services::hostCheck(false)`). `CurrentUser` keeps only closures and a repository. `SessionGate` and `SessionToken` take everything as arguments. `Config\App` captures its list in a local closure. spark's `SettingsCommand` (persistent `commands` service) gets a closure that builds a new `SettingsService` with `CommandLine` per call | `SessionCookieWorkerTest` (a fresh `SettingsService` per request, saves through another instance), `SessionLifetimeTest`, `FilterStateTest` | ✓, but `$_SESSION` itself isn't reset per request (L-3) |
| (3) The rule allows `Services::` only in files directly in `ci4/modules/<Module>/Filters/` | `tools/PhpStan/NoServiceRule.php` is unchanged from #74 | `Ci4DiRuleTest` | ✓ |

- **#74 L-1:** done. `FilterStateTest` rejects function-level statics in filter methods and `Services` factories, and the worker test injects a fresh `SettingsService` per request. Proved by W1 and W2.
- **#74 N-1:** done. `testTheFilterIgnoresASharedSessionLifetime` injects a shared instance and checks the filter ignores it. Proved by W3.
- **#74 N-2:** done. `TrustProxyReader` is gone, `Config\App` reads nothing from the database, and `trust_proxy` and `public_url` come from the request's shared `SettingsService` on CI4's connection. `TrustProxyOnceTest` greps `app/` and `modules/` for `pg_connect`, `new PDO`, `new SQLite3` and `TrustProxyReader`. W4 is killed.
- `persistentServices` in `Config/WorkerMode.php` is still the framework default, so `settings`, `currentUser`, `hostCheck` and `sessionLifetime` are reset after each worker request.

**PASS** with L-3.

### 2.11 Cloudflare phase (item 5)

- **PR body:** it has a "Cloudflare phase" section covering per-request global state, storage behind interfaces and DSN-driven DB config. It matches the code, except that `$_SESSION` isn't reset per request (L-3).
- **Storage behind interfaces:** users go through `Auth/UserRepository`. Sessions still use the file save path under `DATA_DIR/sessions`, and `SessionGate` takes the save path as an argument. The PR body says a session-store interface comes later. `BrandStore` is unchanged.
- **Per-request global state:** no new statics. The only new statics in the diff are `static fn` closures and static factory methods.
- **DSN-driven DB config:** improved. Each request now uses CI4's single connection, and the separate `trust_proxy` connection is gone.

**PASS**

### 2.12 Docs and CHANGELOG (item 6)

- **Commits:** 13 Conventional Commits (2.1).
- **CHANGELOG:**
  - One new `### Added` bullet, `**ci4:** request and sign-in hardening ported from main (SEC-M3, SEC-L2, SEC-N6, SEC-M1, SEC-M2) … ([#76](https://github.com/thordin9/apotheke/pull/76))`.
  - One new `### Fixed` bullet, `**ci4:** Config\App no longer opens its own database connection to read trust_proxy … (#74 N-2). ([#76](https://github.com/thordin9/apotheke/pull/76))`.
  - The wording is hardening only (421, 403, sessions on demand, idle end, trusted proxies, admin-only import). It has no repro, exploit or audit detail.
  - `lint.sh`'s link check passes.
- **#68 nits:** both done. The `ci:` e3 bullet now ends with `([#66](…), [#68](…))`, and the test is renamed `testDifferingCredentialsLogOneWarningPerRequestWithoutValues`.
- **ADR 001, AGENTS.md, PLAN:** consistent with the code: filter order, sessions on demand, the idle limit, sign out everywhere, the actor gate, the client address and one connection per request. Two wording points are in N-2 and N-3.

**PASS**

### 2.13 Security (item 7)

| Check | Result |
|---|---|
| **Secrets** | gitleaks 8.30.1 with the repo `.gitleaks.toml`: the 49 changed files that still exist at `e24ce85` have **no leaks**. The whole tree has 6 hits: 5 inside the dev-only `vendor/phpstan/phpstan/phpstan.phar`, and the known `MigrateDsnTest` fake (`SecretMissingEq99`), which is unchanged. A git-history scan wasn't possible on this box. CI's history scan ("Secret scan") is green. The new test literals are placeholders (`'long-enough'`, `'wrong-pass'`, `'placeholder-pass'`, `'stale'`). The only URLs are `github.com/thordin9/apotheke/pull/…` |
| **Host and origin** | 421 and 403 bodies are fixed text and never echo the request. `baseURL` never uses a disallowed Host |
| **Sessions** | Cookie flags unchanged (`HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS or for an https `PUBLIC_URL` reached by its host, as legacy `session_cookie_secure()`). Cookie ids are checked against legacy's pattern before any file lookup. Idle sessions are emptied and their files removed |
| **Auth** | The epoch is compared with `hash_equals()`. Sign out everywhere needs the password (403 otherwise) and returns a fixed message. `CurrentUser` accepts only `active` accounts |
| **Admin guard** | Security-section import is admin-only on the web and fails closed without an actor. The CLI is explicitly unrestricted |
| **Client address** | `X-Forwarded-For` counts only with `trust_proxy` on and a listed peer. CI4's `proxyIPs` stays empty |
| **New runtime surface** | No new routes. Two required before-filters that only refuse. New config keys `ALLOWED_HOSTS` and `TRUSTED_PROXIES`, both empty by default, as on `main` |
| **Dependencies** | `composer audit`: no advisories |

**Nothing High or Medium.** L-1 and L-3 are latent session-lifecycle hardening items for the Auth pages and the worker phase.

**PASS**

### 2.14 #69 interaction (item 8, Info only)

- **#69 L-3 (Host and same-origin):** `hostcheck` and `sameorigin` are required filters, so once both PRs are on `feature/ci4`, #69's four POST routes get the Host check and, with sign-in off, the same-origin check, with no extra wiring. With sign-in on, they still have only the CSRF check and no permission check until the Auth module, as Coder2 says.
- **#69 M-1 (the 303 path):** on the base, an HTML-typed response on a request with a session gives "Server error". That's because CI4's `storePreviousURL()` starts its own Session service while the native session is open. I reproduced it on `:9767`. With #76, the session is released after loading, so that 500 goes away. Instead, CI4's Session service starts on its own file handler (L-1). After the merge, #69's form pages also have to get their token through `SessionToken`, because `SessionCookie` no longer creates one for a new visitor. Re-run the plain-form 303 path with a live session after the merge, as Coder2 plans.

### 2.15 Secrets in this doc

No real secret appears here. The CI test password is written as `<CI test password>`. No session id, CSRF token or password hash is reproduced, and the test accounts' passwords aren't recorded.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low | **CI4's own session service starts after the session is released.** `SessionGate::resume()` and `SessionToken` close the native session but leave `$_SESSION` set, as legacy does. On any `text/html` response, CI4's `storePreviousURL()` then calls `session()`, which starts CI4's `FileHandler` session for the same id. That writes a second file (`apothekesid<id>`) next to `sess_<id>`. After `Config\Session::$timeToUpdate` (300 s), it regenerates the id into one that `SessionGate` doesn't recognise. On scratch routes, a signed-in admin was signed out on the request after the regeneration, and the CSRF token changes with it. The same happens after an idle end (empty `$_SESSION`). It's latent: no CI4 route at this head renders HTML with a session. On the base, the same call path gave a 500 (#69 M-1), so #76 turns a loud failure into a silent one. | Before the first HTML page with a session: keep CI4's Session service from starting. For example, override `Services::session()` or `storePreviousURL()`, or set `$timeToUpdate = 0` and point CI4's handler at the native `sess_` files. Add an HTTP test with an HTML route, a live session and a regeneration time more than 300 s old. |
| L-2 | Low | **`Config\App::baseURL` ignores the `public_url` setting and `trust_proxy`.** Legacy `public_url()` reads the setting and `request_is_https()`. CI4's `Config\App` uses only the `PUBLIC_URL` config value and `RequestScheme(false)`. On `:9763` with `public_url` set in the database only, a request for that name passes the Host check but gets `base_url()` and `redirect()->to()` as `http://localhost/…`. Behind a trusted TLS proxy without `PUBLIC_URL`, the scheme is `http`. The base used the request Host and `trust_proxy`. It's fail-safe (never an attacker's name), and nothing at this head builds absolute URLs, so it's latent. | Resolve the base URL per request through the shared `SettingsService` (setting, then config) with `trust_proxy`, the way `HostCheck` already does. Or keep Apotheke's own redirects relative, as legacy's are. Extend `AppBaseUrlTest` for the setting. |
| L-3 | Low | **`$_SESSION` isn't reset per request.** `SessionCookie` leaves `$_SESSION` untouched when the request has no live cookie. CI4 4.7.5's worker template doesn't reset it either. In a long-lived worker, a request without a cookie could therefore still see the previous request's session data. A scratch two-request test in one process showed the second, cookieless request still had the first one's `uid`, `auth_epoch` and `csrf`. It's latent: the repo has no worker entry, and php-fpm and `php -S` start each request clean. The base was also affected (#74 I-2, an open session was kept). The PR body's "no per-request global state" doesn't cover it. | In `SessionCookie::before()`, start each request with `$_SESSION = []` (or `unset`) before deciding whether to resume. Add that case to `SessionCookieWorkerTest`, and add it to ADR 001's worker conditions. |
| N-1 | Nit | The CLI actor for SEC-M1 isn't pinned. If spark's `SettingsCommand` used the request's actor instead of `CommandLine`, every test would still pass (A2). `Ci4SettingsCliTest` imports sign-in keys with sign-in off, where the request actor is the anonymous admin anyway. That's the `main` #77 case, which fails closed. Live, the head is correct (2.6). | Add a CLI import test with sign-in on and guest browsing on. |
| N-2 | Nit | `ALLOWED_HOSTS` and `TRUSTED_PROXIES` are new CI4 config keys, but `feature/ci4`'s `docs/configuration.md` and `config.example.php` don't mention them yet. They come from `main`'s #70 and #71 docs at the next sync. Until then, only the CHANGELOG, the ADR and the PLAN name them. | Optional: note it in the PR body, or carry the two config rows with the next `main` sync. |
| N-3 | Nit | "`/healthz` reads no settings" (PR body, ADR, PLAN) holds only for built-in host names. For a configured name, the Host check reads `public_url` through the shared `SettingsService` (on CI4's one connection, falling back to config). | Optional: "reads no settings for addresses and localhost". |
| I-1 | Info | `X-Forwarded-Proto` is honoured from any peer when `trust_proxy` is on, whatever `TRUSTED_PROXIES` says. That's the same as `main`'s `request_is_https()`. It only affects that client's own cookie flag and `Origin` match. | None, or later limit it to listed proxies on both branches. |
| I-2 | Info | `public_url` is in the `general` section, so a non-admin with the settings permission can import it, and it adds a name to the Host allow-list. That's parity with `main` #71. | None for this PR. |
| I-3 | Info | P1 is equivalent. With an unlisted peer, the rightmost-non-proxy rule already returns the peer, so the explicit guard is defence in depth. | None. |
| I-4 | Info | A session from before the upgrade has no `last_seen`. It's resumed and stamped, not ended, which is the same as legacy on `main`. | None. |
| I-5 | Info | #69 interaction, see 2.14. | Re-run #69's 303 path with a live session after both merge. |
| I-6 | Info | The SEC-N6 idle half is stated in the PR body as a known gap with the PM's `session_idle_hours` plan. Not graded. | None. |
| I-7 | Info | #69 I-5 (token-form 303 `Location` built from the request Host, #69 head `614cb1b`, merging into `feature/ci4` first): after #76 merges `feature/ci4` in, `hostcheck` is a **required** before-filter, which CI4 runs before routing and before any controller. Live at this head, an unknown or malformed Host got 421 on `/healthz`, branding and unknown paths for GET and HEAD, so #69's controller never runs for such a name and can't put it in a `Location`. What still passes, by design and as on `main`: IP literals, `localhost` and `*.localhost`, the container name, `PUBLIC_URL`'s host, `ALLOWED_HOSTS` (or any valid name with `*`), and a request with no Host. A `Location` built from the Host can still carry one of those, and #69's controller wasn't run here. | Re-check at the merged head: an unknown Host on the token POST gives 421 and no `Location`. Optionally make #69's `Location` relative, as legacy's are (see also L-2). |

No High or Medium findings.

## 4. Verdict

**PASS at `e24ce859069c154fa208ab7738284ecd8829f2ad`.**

- **CI:** run 40088707145, 22/22 `success`. 13 Conventional Commit subjects.
- **Audit items:** M3 (421 for unknown names, including `/healthz`; 403 for cross-site writes with sign-in off), L2 (no session or cookie for anonymous requests), N6 (idle end; sign out everywhere rejects the other sessions), M1 (security-section import admin-only, CLI unrestricted) and M2 (`X-Forwarded-For` only from listed proxies, rightmost non-proxy hop). Each was repro'd live against the base, and each matches `main`'s legacy code.
- **#74 follow-ups:** L-1, N-1 and N-2 are done and proved by mutations W1–W4. Both #68 nits are done.
- **Suites:** ci4 @ 8.4 is green on SQLite and PostgreSQL 17 under `CI=true`, and so is the module suite (115 tests). The DI rule, deptrac, `lint.sh` (8.4 and 8.3), `composer audit` and the commit check pass.
- **Mutations:** 28 of 30 caught. One survivor is equivalent (P1), and one is a test gap (A2, N-1).
- **Open items:** L-1, L-2 and L-3, recommended before the Auth pages or the worker entry land, plus nits N-1 to N-3.
