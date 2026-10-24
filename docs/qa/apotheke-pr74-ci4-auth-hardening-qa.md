# QA: apotheke PR #74, CI4 auth groundwork hardening (SEC-N8, N9, N10, N11)

- **PR:** [#74](https://github.com/thordin9/apotheke/pull/74), "fix(ci4): auth groundwork hardening (SEC-N8, N9, N10, N11)", into `feature/ci4`, by Coder2. Marked ready before this review started.
- **Base:** `feature/ci4` at `5b6584e`. The branch is 9 linear commits on `5b6584e`, 0 behind. No merge commit, no force-push.
- **Head tested:** `bbb0191751140d14d183f914489afe417f986235` (`fbea142`, `31d9b15`, `98604fc`, `1bb57f7`, `2dd7d99`, `8d34908`, `0090d7f`, `65852fc`, `bbb0191`). The head was the same at the start (12:41) and the end (12:59) of the review (Athens time, UTC+3).
- **Changes vs `feature/ci4`:** 28 files, +1029 / −43.
  - Runtime: `ci4/app/Config/{App,Filters,Routes,Services}.php`, `ci4/modules/Core/Exceptions/PlainHandler.php`, `ci4/modules/Core/Filters/{CsrfFilter,SessionCookie}.php`, `ci4/modules/Core/Http/{CsrfCheck,SessionLifetime}.php`.
  - Rule: `ci4/tools/PhpStan/NoServiceRule.php` and 5 fixtures.
  - Tests: `ci4/tests/Core/{CsrfFilter,FilterState,PlainHandler,SessionCookieWorker,SessionLifetime,TrustProxyOnce}Test.php` (new), `tests/Unit/{Ci4DiRuleTest,Ci4HttpTest}.php`.
  - Docs: ADR 001, AGENTS.md, `docs/plans/feature12-ci4/PLAN.md`, `docs/configuration.md`, `CHANGELOG.md`.
- **CI:** run 40081991443 at `bbb0191` (`pull_request`), **22/22 `success`** (12:27–12:36 Athens time, UTC+3).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - Sources: codeload tarballs of `bbb0191` and `5b6584e`, the GitHub API (PR, commits, files, check runs, job logs), the 2026-10-24 security audit (SEC-N8 to N11) and the #68 QA doc.
  - Local runs only in scratch copies, against PostgreSQL 17 in private databases (`qa2_74_*`), on ports 9501–9503.
  - No comment, review, push or merge was made.
- **Verdict: PASS.** There are no High or Medium findings: one Low, three nits and notes.
  - All four audit items are fixed at the head, checked live against the base: N8 headers on 404, N9 cookie lifetime, N10 GET-only health plus the CSRF filter, and N11 one `trust_proxy` read per request instead of two.
  - 28 of 32 mutations are caught. The four survivors are: one expected (`==` instead of `hash_equals()`), one harmless (N-1), and two that show the gap in L-1. L-1 means a function-level `static` that holds a `SettingsService` in a filter or factory passes the rule, `FilterStateTest` and `SessionCookieWorkerTest`.
  - Coder2's `SettingsService` claim holds for CI4 4.7.5: the cache is per instance, `save()` clears it, and worker mode drops the shared instance after each request. No shared service holds one across requests.

## 1. Test plan

### 1.1 Environment

- **Docker isn't available on this box.** `git` to GitHub doesn't work here, so trees came from codeload tarballs. Each scratch tree was `git init`ed locally to track and revert mutations.
- **Suites:** the box's PHP **8.3.36** and **8.4.27** CLIs (pdo_pgsql, pgsql, pdo_sqlite, gd, imagick), with Composer dev dependencies from the locked `composer.lock` and `ci4/composer.lock` (CodeIgniter **4.7.5**). Each leg ran in its own copy of the tree, with a `php` shim for the leg's version.
- PostgreSQL **17.11**, scram-sha-256 on TCP, test-only superuser `apotheke` with CI's public test password (written as `<CI test password>` here). Each leg used its own database (`qa2_74_l83`, `qa2_74_c84`, `qa2_74_l84`, `qa2_74_mut`).
- **Live repros:** `php8.4 -S` with `ci4/public/router.php` (the shipped boot), SQLite. `:9501` ran the head, `:9502` the base `5b6584e`, and `:9503` a head copy with one scratch route (2.3).
- gitleaks **8.30.1**, downloaded and checked against the sha256 pinned in `ci.yml`.
- Every suite run used `CI=true`.

### 1.2 Acceptance checks

1. **CI** on the head SHA: 22/22, plus the commit subjects.
2. **Audit repros** for N8, N9, N10 and N11, against the base and the head. **Mutations:** revert each fix, and break each of the PM's three conditions.
3. **N10 CSRF:** methods, tokens, exemptions, health verbs, method override, content types, and filter order.
4. **Suites:** legacy @ 8.3 and ci4 @ 8.4 (the four CI legs), each on SQLite and PostgreSQL 17. legacy @ 8.4 as well. Then PHPStan (DI rule), deptrac, `lint.sh` and the commit check.
5. **Cloudflare phase:** the PR body note, storage, per-request global state, DSN-driven DB config and the worker-mode conditions.
6. **Docs and CHANGELOG:** Conventional Commits, five linked `### Fixed` bullets, and hardening wording only.
7. **Security** and gitleaks.

## 2. Results

### 2.1 CI and commits (item 1)

- **Run 40081991443** (`bbb0191`, `pull_request`): **22/22 `success`**, 12:27–12:36 Athens time (UTC+3). Nothing failed and nothing is pending.
  - Lint, headless mobile checks, Docker build + smoke test, and Visual E2E shards 1–3: each on PHP 8.3 and 8.4.
  - Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan.
- **Job logs:**
  - ci4 @ 8.4 pgsql (job 120837324252): `Tests: 479, Assertions: 12337, Skipped: 1` and `apotheke_ci4_pgsql_probe rows=71`.
  - Conventional Commits (job 120837324192): `9 commit(s) checked`.
  - DI rule (job 120837324096): `[OK] No errors`.
- **Commit subjects:** all 9 pass `scripts/check-commits.sh --message`, 44–68 characters: `fix(ci4)` ×6, `refactor(ci4)` ×2, `docs(changelog)` ×1.

**PASS**

### 2.2 Audit repros (item 2)

Base `5b6584e` on `:9502` against head `bbb0191` on `:9501`, the shipped router, SQLite.

| Finding | Check | Base | Head |
|---|---|---|---|
| **SEC-N8** | `GET /nonexistent`, response headers | 404 with `Content-Type` only | 404 with `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin` and the same CSP as legacy. `Ci4HttpTest` asserts the four headers are byte-equal to legacy's 404 page. `PlainHandlerTest` covers 404, 500, `UserError` (422, 419, no code → 400) and a route's own CSP being kept. **Fixed** |
| **SEC-N9** | `session_days = 7` in `settings`, then `GET /` | `Max-Age=2592000` | `Max-Age=604800`. `session.gc_maxlifetime` follows it (`SessionCookieWorkerTest`). Out-of-range stored values fall back to 30 days, as legacy does (`Ci4HttpTest`, values 7 and 400). **Fixed** |
| **SEC-N10 (health)** | `/health` and `/healthz` with GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS and TRACE | **200 for all 8 methods** on both paths | GET 200, HEAD 200 (through `headasget`), and the other six get the plain `404 Not found` on both paths. **Fixed** |
| **SEC-N10 (CSRF)** | see 2.3 | no CSRF filter | global `sessioncsrf` before-filter, 419 on non-safe methods without the session token. **Fixed** |
| **SEC-N11** | `TrustProxyReader::trusted()` calls per request (counter added in scratch copies only) | **2** per `GET /`, 1 per `/healthz` | **1** per `GET /`, 1 per `/healthz`. `TrustProxyOnceTest` pins it: the filter reuses `Config\App::$trustProxy` with the database removed, and `App.php` is the only reader in `app/` and `modules/`. **Fixed** as the PR describes it: one read per request. The reader still opens its own connection (N-2) |

**PASS**

### 2.3 N10: CSRF filter (item 3)

The head has no state-changing CI4 route yet, so a **scratch copy** (`:9503`) added one `qa-post` route for GET, OPTIONS, POST, PUT, PATCH and DELETE to the existing health controller. The session token was read from the session file. Nothing else changed.

| Request | Result |
|---|---|
| POST with no token / empty field / empty header / wrong field / `csrf[]` array | **419** each, legacy text body |
| POST with a valid field (urlencoded or multipart) / a valid `X-CSRF-Token` header | 200 |
| POST with the token only in the query string | **419** (the field is read from the body only, like legacy `$_POST`) |
| POST with a wrong field and a valid header | **419** (the field wins, as legacy `$_POST['csrf'] ?? header`) |
| POST without a token, `X-Requested-With: fetch` | **419**, `application/json` body `{"ok":false,"error":…}` |
| POST without a token, `_method=GET` / `_method=OPTIONS` | **419**. CI4 4.7.5 `spoofRequestMethod()` accepts only PUT, PATCH and DELETE, so a POST can't become a safe method |
| POST without a token, `_method=DELETE` | **419** (it becomes DELETE, which still needs the token) |
| POST without a token, `X-HTTP-Method-Override: GET` / `X-HTTP-Method: GET` | **419** (CI4 ignores both headers) |
| POST with a JSON or `text/plain` body | **413** from the existing `posttoolarge` filter, which runs before the CSRF filter, as legacy does for a body that PHP doesn't parse (N-3) |
| PUT / PATCH / DELETE without a token | **419** |
| PUT with the token as a urlencoded field | **419** (PHP fills `$_POST` only for POST, so PUT needs the header, N-3). PUT and DELETE with the header: 200 |
| GET and OPTIONS without a token | 200 (safe methods). `CsrfFilterTest` also covers HEAD and lowercase `get` and asserts that no session is started |
| POST with a valid token but no session cookie | **419** (a new session gets a new token) |

- **Token:** `bin2hex(random_bytes(32))`, written to `$_SESSION['csrf']` by `SessionCookie` when the session has none, as legacy `init_session()` does. It's compared with `hash_equals()`, and an empty session token never matches (`$sessionToken !== ''`). `Ci4HttpTest` checks for the 64-hex token in the session file.
- **Methods:** everything except GET, HEAD, OPTIONS and TRACE needs the token, compared case-insensitively, so unknown verbs need it too. Legacy checks POST only, and it has no other non-safe routes.
- **Exempt routes:** **none**. The filter is in `$globals['before']` with no `except`. That matches legacy: the audit found every one of legacy's 47 POST routes calls `check_csrf()`. Health doesn't need an exemption, because it's GET-only now.
- **CI4's own CSRF:** stays off. `csrf` isn't in `globals` or `required` (`CsrfFilterTest`).
- **Order:** the `required` before filters run first (`headasget`, `harness`, `originalsguard`, `posttoolarge`, `session`), so `$_SESSION` is open before `sessioncsrf` runs. With no session (health, branding assets), the filter fails closed. No auth filter exists yet. The PLAN's `perm:<name>` route filters will run **after** the global CSRF filter, so a token-less, signed-out POST will get 419 where legacy checks permissions first (I-1).
- **419 response:** it has the four security headers (a before-filter's response skips the global after filters, so it sets them itself), `Page Expired` as the reason phrase, and no session started on safe methods.

**PASS**

### 2.4 Mutations (item 2)

Scratch copy only. Each mutation was reverted (`git status` clean afterwards) before the next. Unit = `ci4/phpunit.xml.dist` on SQLite and PostgreSQL. HTTP = `tests/Unit/Ci4HttpTest.php` on ci4 @ 8.4, SQLite. Rule = `Ci4DiRuleTest` or `scripts/ci4-arch.php di`.

| # | Mutation | Caught by | Result |
|---|---|---|---|
| R1 | **N8 revert:** `PlainHandler` sets no security headers | `PlainHandlerTest`, `Ci4HttpTest` (legacy header parity) | **killed** (unit and HTTP) |
| R2 | **N9 revert:** lifetime fixed at 2592000 | `SessionCookieWorkerTest` (sqlite + pgsql, "second request: 7 days"), `Ci4HttpTest::testSessionCookieFollowsSessionDays` | **killed** (unit and HTTP) |
| R3 | **N10 revert:** `sessioncsrf` removed from `globals` | `CsrfFilterTest::testRegisteredAsAGlobalBeforeFilterWithoutCi4Csrf` | **killed** |
| R4 | **N10 revert:** health back to `$routes->add()` | `Ci4HttpTest` health verbs (POST now gets 419 from the filter, not 404/405) | **killed** |
| R5 | **N11 revert:** `SessionCookie` reads `trust_proxy` again | `TrustProxyOnceTest` (both tests) | **killed** |
| X1 | POST treated as safe | `CsrfFilterTest` (5 failures) | **killed** |
| X2 | PUT, PATCH and DELETE treated as safe | `CsrfFilterTest` | **killed** |
| X3 | empty session token accepted | `testNoSessionTokenRefusesEvenAnEmptyMatch` | **killed** |
| X4 | header wins over the field | `testTheFieldWinsOverTheHeaderAsInLegacy` | **killed** |
| X5 | field or header may match | same | **killed** |
| X6 | no token put in the session | `Ci4HttpTest` (session file token) | **killed** |
| X7 | the filter starts a session on safe methods | `testSafeMethodsPassWithoutATokenAndStartNoSession` | **killed** |
| X8 | 419 without the security headers | `testPostWithoutATokenIsRefused` | **killed** |
| X9 | `==` instead of `hash_equals()` | none | survives, as expected: the comparison is functionally the same, so no test can see it. Checked in review (I-4) |
| C1a | instance property on `SessionCookie` | `FilterStateTest` | **killed** |
| C1b | static property on `CsrfFilter` | `FilterStateTest` | **killed** |
| C1c | `Services::settings()` in a filter constructor | rule: "Services:: is only allowed in controllers, filters and Config/Services.php" | **killed** |
| C1d | `Services::` in a filter helper method | rule | **killed** |
| C1e | function-level `static $held` holding `Services::settings()` inside `SessionCookie::before()` | none (rule, `FilterStateTest` and `SessionCookieWorkerTest` all pass) | **survives (L-1)** |
| C2a | `session_days` cached in a static in `SessionLifetime::seconds()` | `SessionCookieWorkerTest`, `SessionLifetimeTest` | **killed** |
| C2b | value memoised on the `SessionLifetime` instance | `testEverySecondsCallReadsTheSettingAgain` | **killed** |
| C2c | memo + `SessionCookie` uses the shared `Services::sessionLifetime()` | `SessionCookieWorkerTest` | **killed** |
| C2d | `SessionCookie` uses the shared instance, no memo | none | survives. Harmless: `SessionLifetime` keeps only a closure that resolves `settings()` on every call, and worker mode resets shared instances (N-1) |
| C2e | the factory reads `session_days` once when it builds the instance | `SessionCookieWorkerTest` | **killed** |
| C2f | the factory keeps a `SettingsService` in a function-level static | none | **survives (L-1)** |
| C3a | rule allows any `*/Filters/*.php` | `Ci4DiRuleTest` (`LooseFilter`) | **killed** |
| C3b | rule allows nested `Filters/**` | `Ci4DiRuleTest` (`DeepFilter`) | **killed** |
| C3c | `str_contains($file, '/Filters/')` like `/Controllers/` | `Ci4DiRuleTest` (0 of 3 in `BadFilterState`) | **killed** |
| C3d | any method of a module filter | `Ci4DiRuleTest` (1 of 3) | **killed** |
| C3e | closures inside `before()` allowed | `Ci4DiRuleTest` (2 of 3) | **killed** |
| C3f | `__construct` added to the allowed methods | `Ci4DiRuleTest` (2 of 3) | **killed** |
| C3g | the path check dropped (any class with `before()`) | `Ci4DiRuleTest` (`NotAFilter`) | **killed** |

- **Why C1e and C2f survive:**
  - `SessionCookieWorkerTest` reads and saves through the **same** injected `SettingsService`, and `save()` clears that instance's cache, so a held instance never goes stale in the test.
  - The rule allows anything inside `before()`, including a `static` variable.
  - `FilterStateTest` looks at properties only (`ReflectionClass::getProperties()`).
- **The fix is shown to work:** a scratch variant (deleted afterwards) caught both C1e and C2f on SQLite and PostgreSQL ("request 1: 2592000 vs 604800"), and passed on the head. In that variant, each request injects a fresh `SettingsService` after `Services::reset()`, and the save goes through another instance.

**PASS** with L-1.

### 2.5 Suites and static checks (item 4)

| Leg | Command | Result |
|---|---|---|
| legacy @ 8.3.36, SQLite | `CI=true APOTHEKE_APP=legacy APOTHEKE_TEST_DB=sqlite DB_DRIVER=sqlite php vendor/bin/phpunit` | **OK**, 479 tests, 9916 assertions, 35 skipped (CI4 vendor and PHP 8.4 required, PostgreSQL leg only, php-cgi) |
| legacy @ 8.3.36, PostgreSQL 17 | same with `pgsql`, `TEST_PG_DB=qa2_74_l83`, `TEST_PG_PASSWORD=<CI test password>` | **OK**, 479 tests, 9923 assertions, 34 skipped (same reasons) |
| ci4 @ 8.4.27, SQLite | `CI=true APOTHEKE_APP=ci4 APOTHEKE_TEST_DB=sqlite DB_DRIVER=sqlite php vendor/bin/phpunit` | **OK**, 479 tests, 12313 assertions, 5 skipped (PostgreSQL only ×3, php-cgi, `HttpSuiteTest`) |
| ci4 @ 8.4.27, PostgreSQL 17 | same with `pgsql`, `TEST_PG_DB=qa2_74_c84` | **OK**, 479 tests, 12327 assertions, 2 skipped (php-cgi not installed; `HttpSuiteTest`). `php scripts/ci4-pgsql-proof.php`: `rows=71`, exit 0 (CI: 71) |
| legacy @ 8.4.27, SQLite / PostgreSQL 17 | as above, `qa2_74_l84` | **OK**, 479 / 479 tests, 4 / 1 skipped |
| CI4 module suite | `php8.4 vendor/bin/phpunit -c ci4/phpunit.xml.dist` with PostgreSQL enabled | **OK**, 82 tests, 605 assertions (also run inside each ci4 leg by `Ci4ModuleSuiteTest`) |
| all six legs | CLI migrations as in CI (`bin/migrate.php`, idempotent re-run, `bin/seed.php --demo` twice, `--status`) | OK |
| PHPStan DI rule | `php scripts/ci4-arch.php di` (`phpstan-di.neon`) | `[OK] No errors` |
| deptrac | `php scripts/ci4-arch.php deptrac` | 0 violations, 0 errors |
| lint | `bash scripts/lint.sh` on 8.4, and on 8.3 via a `php` → `php8.3` shim | exit 0 / 0: PHP lint, JS syntax, 234 relative links in 64 files with 0 broken, shellcheck, actionlint, pipefail-safe greps |
| CI4 dependencies | `composer audit --working-dir=ci4` | "No security vulnerability advisories found" |

- `SessionCookieWorkerTest` and `SessionLifetimeTest` ran on both drivers in the ci4 legs (`TestDatabase::drivers()` adds pgsql when `TEST_PG_HOST` is set).
- `Ci4DiRuleTest`'s new fixture tests need PHP 8.4. They run in the 8.4 legs and in the DI rule job, and skip on 8.3, as the CI matrix intends.

**PASS**

### 2.6 Conditions and the `SettingsService` claim

| Condition | Code at `bbb0191` | Enforced by | OK |
|---|---|---|---|
| (1) Filters resolve `Services::` only inside `before()`/`after()`, never in static or class properties | All 7 module filters (`Core/Filters/*`) declare no properties. Only `SessionCookie::before()` calls `Services::`. | the rule (constructor, helper, closure, nested, loose and non-filter classes: C1c, C1d, C3a–g); `FilterStateTest` (properties: C1a, C1b) | ✓ for the code; enforcement misses function-level statics (L-1) |
| (2) `sessionLifetime()` caches nothing across requests; `SessionCookie` uses `(false)` | `SessionLifetime` holds only a closure. The factory's closure calls `static::settings()` on every `seconds()` call. `SessionCookie` calls `Services::sessionLifetime(false)` | `SessionLifetimeTest` (fresh read per call), `SessionCookieWorkerTest` (C2a–c, C2e) | ✓. `(false)` itself isn't pinned (N-1), and a held `SettingsService` isn't detected (L-1) |
| (3) Rule allows only files directly in `ci4/modules/<Module>/Filters/`, inside `before()`/`after()` | regex `#/ci4/modules/[A-Za-z0-9]+/Filters/[A-Za-z0-9]+\.php$#D`, plus `isInClass()`, not in an anonymous function, method name `before` or `after` | `Ci4DiRuleTest` (C3a–g, all killed) | ✓ |

- **`SettingsService` cache (Coder2's claim): verified.**
  - The values live in `private ?array $values` on the instance. `store()` (used by `save()` and reset) sets it to `null`. There's no static cache (the legacy `settings_cache()` isn't used).
  - `Config\Services::settings()` is a shared instance. CI4 4.7.5's worker loop (`frankenphp-worker.php.tpl`) calls `Factories::reset()` and `Services::resetForWorkerMode()` after every request. That keeps only `persistentServices` (`autoloader`, `locator`, `exceptions`, `commands`, `codeigniter`, `superglobals`, `routes`, `cache`). `ci4/app/Config/WorkerMode.php` is the framework default.
- **Shared services holding a `SettingsService`:**
  - `branding` holds one, but it isn't persistent, so it's dropped with `settings`.
  - `commands` **is** persistent, but its `SettingsCommand` gets a closure (`static fn () => static::settings()`), not an instance.
  - `sessionLifetime` (shared branch) holds only a closure.
  - `exceptions` (persistent) builds `PlainHandler`, which is stateless.
  - **No shared service holds a `SettingsService` across requests.** The repo has no worker entry yet, so this rests on the framework template and the default `WorkerMode` (I-6).
- **`Config\App::$trustProxy`:** it's per instance, and the `Factories` reset rebuilds it per worker request. Under php-fpm and `php -S`, measurement shows one read per request (2.2).

### 2.7 Cloudflare phase (item 5)

- **PR body:** it has a "Cloudflare phase" section covering storage, per-request global state and DSN-driven DB config. It matches the code.
- **Storage behind an interface:** unchanged. Sessions still use the file save path under `DATA_DIR/sessions`, and the PR body defers the session store interface to the L2 session-on-demand work. `BrandStore` is still the interface for brand files.
- **Per-request global state:** none added.
  - `SessionLifetime`, `CsrfCheck` and `CsrfFilter` have no statics. The new statics in the diff are only `static fn` closures and static factory methods.
  - `CsrfFilter` reads only the request and `$_SESSION`.
  - The existing statics (`BootMigration::$done`, `PdoMigrationRunner::$warnedCredentialsDiffer`) are untouched.
- **DSN-driven DB config:** unchanged. `trust_proxy` still comes from `DsnTranslator` → `TrustProxyReader` on its own connection, now once per request. Using the shared connection is planned with the M3 port (N-2).
- **Worker-mode conditions written down:** ADR 001 lists the three conditions with the tests that enforce them. AGENTS.md ("no properties or statics") and the PLAN point to it.
  - The ADR's "never in … a static" is wider than what's enforced (L-1).
  - One pre-existing worker-mode item isn't in the list (I-2).

**PASS**

### 2.8 Docs and CHANGELOG (item 6)

- **Commits:** 9 Conventional Commits (2.1).
- **CHANGELOG:** five new bullets under `## [Unreleased]` → `### Fixed`, each `**ci4:** … SEC-Nx. ([#74](https://github.com/thordin9/apotheke/pull/74))`: N8, N9, N10 (health), N10 (CSRF) and N11.
  - The wording is hardening only: headers, cookie lifetime, allowed methods, token required, one read fewer. It has no repro, exploit or audit detail.
  - `lint.sh`'s link check passes.
- **Accuracy:**
  - N9's "30 when unset or unreadable" matches `SessionLifetime`'s fallback and the HTTP test.
  - N10's "every POST, PUT, PATCH and DELETE needs the session CSRF token in the `csrf` field or the `X-CSRF-Token` header" is right. The field only works on POST, because PHP parses form bodies only for POST (N-3).
  - N11's "one settings connection fewer" is exact.
- **ADR 001, AGENTS.md, PLAN, `docs/configuration.md`:** consistent with the code. They name the tests that enforce each condition, and the PLAN moves the Auth module's Csrf filter to Core's `CsrfFilter`.

**PASS**

### 2.9 Security (item 7)

| Check | Result |
|---|---|
| **Secrets** | gitleaks 8.30.1 with the repo `.gitleaks.toml`: the 28 changed files at `bbb0191` have **no leaks**. The whole `bbb0191` tree has 1 hit, the known `MigrateDsnTest` fake (`SecretMissingEq99`), which is unchanged and also on the base. A git-history scan wasn't possible on this box (no git access to GitHub). CI's history scan ("Secret scan") is green. The new test literals are placeholders: a fixed 64-hex test token in `CsrfFilterTest`, `'nope'` and `'stale'`. The only URLs are `github.com/thordin9/apotheke/pull/74` |
| **Token handling** | 256-bit random token per session, compared with `hash_equals()`. The empty-token match is refused, and array input is refused. The token is never written to a response, a header or a log. The 419 body is a fixed message |
| **Error responses** | `PlainHandler` still returns generic bodies ("Not found", "Server error"). `error_log` keeps the existing class + message line for 500s only. Security headers are now on all error kinds |
| **Session** | Cookie flags unchanged (`HttpOnly`, `SameSite=Lax`, `Secure` from `RequestScheme`). Lifetime from `session_days`, clamped to 1–365, falling back to 30 when the read fails |
| **New runtime surface** | No new routes. Health lost every method except GET and HEAD. One global before-filter, which fails closed when there's no session |
| **Dependencies** | `composer audit` (ci4): no advisories |

**Nothing to report** beyond L-1, which is about enforcing the worker-mode conditions, not something reachable today.

**PASS**

### 2.10 Secrets in this doc

No real secret appears here. The CI test password is written as `<CI test password>`, and no session token is reproduced.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low | Condition (1) says "never in a static", and ADR 001 says "never in … a static". But a **function-level `static`** inside a filter's `before()`/`after()` (C1e), or inside a `Config/Services.php` factory (C2f), can hold a `SettingsService` across worker requests, and nothing catches it. The rule allows anything inside `before()`, `FilterStateTest` checks properties only, and `SessionCookieWorkerTest` saves through the same instance it reads. That instance's `save()` clears its own cache, so a held instance never looks stale. No current code does this. | (a) In `FilterStateTest`, also assert `getStaticVariables() === []` for every method of each module filter. (b) In `SessionCookieWorkerTest`, give each request a fresh `SettingsService` (after `Services::reset()`, as the worker does), and save through a different instance. The QA variant of this caught C1e and C2f and passes on the head (2.4). |
| N-1 | Nit | `SessionCookie`'s `Services::sessionLifetime(false)` (condition 2) isn't pinned: switching to the shared instance passes every test (C2d). It's harmless today, because `SessionLifetime` keeps only a closure. | Optional: drop the `$getShared` branch so the factory always builds a new instance, or assert in `SessionCookieWorkerTest` that two requests get different instances. |
| N-2 | Nit | SEC-N11 is fixed as described ("once per request"), but each request, `/healthz` included, still opens **one separate** settings connection: 1 read per request, against 2 on the base. The audit's fix also offered the shared connection or a per-process cache with a TTL. The PR body and CHANGELOG say so plainly, and the PR body defers the shared connection to the M3 port. | Track it with the M3 port, before Hyperdrive. |
| N-3 | Nit | The token "in the `csrf` field" only works on POST. PHP doesn't parse PUT, PATCH or DELETE bodies, so those need `X-CSRF-Token` (a PUT with the field gets 419). Separately, a POST with a JSON or `text/plain` body gets 413 from the existing `posttoolarge` filter before the CSRF filter runs (legacy does the same). | Optional: one line in the PLAN or the CHANGELOG bullet ("PUT, PATCH and DELETE use the header"), and keep fetch clients on `FormData` or the header when the Items module lands. |
| I-1 | Info | Order: `sessioncsrf` is a global before-filter. The PLAN's `perm:<name>` route filters will run after it. A token-less, signed-out POST will get 419, where legacy runs `route_perm()` first. That's fail-closed, not a weakness. | Cover the order in the Auth PR's parity tests, or record it as an intentional deviation. |
| I-2 | Info | Pre-existing, worker phase: `SessionCookie` uses the native `session_start()` and returns early when a session is already active. CI4's worker template closes only `Services::session()`. `SessionCookieWorkerTest` calls `session_write_close()` and clears `$_SESSION` itself to end each request. | When the worker entry lands, close the native session and reset `$_SESSION` per request, and add that to ADR 001's conditions. |
| I-3 | Info | `/health` and `/healthz` are now stricter than legacy, which answers any method. This follows the audit's fix and is in the CHANGELOG. Probes use GET or HEAD. | None. |
| I-4 | Info | X9 (`==` instead of `hash_equals()`) can't be caught by a functional test. The code uses `hash_equals()`, and an empty session token never matches. | None. |
| I-5 | Info | gitleaks' only tree hit is the known `MigrateDsnTest` fake, unchanged from the base. | None. |
| I-6 | Info | "Worker mode drops `SettingsService` per request" rests on CI4 4.7.5's worker template and the default `WorkerMode::$persistentServices`. Adding `settings`, `branding`, `apothekeConfig` or `sessionLifetime` there would break it. | Optional: a test that those names aren't in `persistentServices`, alongside the worker entry. |

No High or Medium findings. No security findings at any severity.

## 4. Verdict

**PASS at `bbb0191751140d14d183f914489afe417f986235`.**

- **CI:** run 40081991443, 22/22 `success`. 9 Conventional Commit subjects.
- **Audit items:** N8, N9, N10 (health and CSRF) and N11 are fixed at the head, each repro'd against the base. N11 halves the reads; the separate connection stays until M3 (N-2).
- **CSRF:** the token is required on every non-safe method. Missing, empty, wrong and array tokens, `_method` and override headers all get 419 or are ignored. No exempt routes. The filter runs after the session filter and fails closed.
- **Suites:** legacy @ 8.3 and 8.4 and ci4 @ 8.4 are green on SQLite and PostgreSQL 17 under `CI=true`. The DI rule, deptrac, `lint.sh` (8.3 and 8.4) and the commit check pass.
- **Mutations:** 28 of 32 caught. The survivors are X9 (expected), C2d (harmless, N-1), and C1e and C2f (L-1).
- **Conditions:** (1)–(3) hold in the code, and (3) is fully pinned. L-1 asks to also pin function-level statics and a `SettingsService` held across requests.
- **Open items:** L-1 (recommended before the Auth module adds filters), and nits N-1 to N-3.
