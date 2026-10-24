# QA: apotheke PR #82, CI4 native session, public base URL and per-request reset (#76 L-1, L-2, L-3)

- **PR:** [#82](https://github.com/thordin9/apotheke/pull/82), "fix(ci4): native session, public base URL and per-request reset", into `feature/ci4`, by Coder2. Open, not a draft, `mergeable_state` clean.
- **Base:** `feature/ci4` at `f888dc08a0cce20900de1e640beaa8d97b99d46e` (the #76 merge, which includes #69). The compare API reports 12 ahead and 0 behind. The branch is linear, with no merge commit and no force-push.
- **Head tested:** `e7ba34f9ceb5df317bdbdec2eac9ae9620016268`. The commits are `e5f250a`, `d62ef5a`, `49e099f`, `8d7b74d`, `ed95c24`, `25a74b4`, `1576531`, `7909415`, `281196f`, `6bbf613`, `6ccd9e5` and `e7ba34f`. The head was the same at the start (16:34) and at the end (17:08) of the review, Athens time (UTC+3). I checked it through the API and the PR patch.
- **Changes vs `feature/ci4`:** 21 files, +703 / −26.
  - **Runtime:** `ci4/app/Config/{Filters,Services}.php`, `ci4/modules/Auth/CurrentUser.php`, `Autofill/Controllers/SettingsAutofillController.php` (`done()` only), `Core/Filters/SessionCookie.php`. New files: `Core/Filters/PublicBaseFilter.php`, `Core/Http/NativeSession.php` and `Core/Http/PublicBase.php`.
  - **Tests:** under `ci4/tests/` there are 2 new files (`Core/NativeSessionTest`, `Core/PublicBaseTest`) and 3 changed ones (`Core/SessionCookieWorkerTest`, `Auth/AccountActionsTest`, `Autofill/AutofillTest`). Under `tests/Unit/` there is 1 new file (`Ci4PageHttpTest`) and 1 changed (`Ci4SettingsCliTest`). The test-only `tests/ci4-router.php` hook gained `APOTHEKE_TEST_HTML_PAGE`.
  - **Docs:** ADR 001, AGENTS.md, `docs/configuration.md`, `docs/plans/feature12-ci4/PLAN.md` and `CHANGELOG.md`.
  - **No shared runtime code changed.** `src/`, `public/`, `bin/`, `migrations/`, `docker/` and both `composer.lock` files are identical to the base.
- **CI:** run 40097033128 at `e7ba34f` (`pull_request`) shows **22/22 `success`**, 16:11–16:22 Athens time (UTC+3). The commit's check-runs API also shows 22 completed, all `success`.
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - **Sources:** codeload tarballs of `e7ba34f` and `f888dc0`, the GitHub API (PR, commits, files, compare, run, jobs, check-runs), and my #76 QA doc and merge re-check notes, with their scratch tests.
  - **Local runs:** only in scratch copies, against PostgreSQL 17 in private databases (`qa2_82_*`), on ports 9821–9829.
  - No comment, review, push or merge was made.
- **Verdict: PASS.** There are no High or Medium findings. There are two Lows, three Nits and some notes.
  - All three #76 Lows are fixed at the head. I checked each one live or in a scratch test against the base:
    - **L-1:** the HTML page no longer writes a second session file and there's no forced sign-out.
    - **L-2:** the base URL follows the `public_url` setting and `trust_proxy`, and the token form sends a relative 303.
    - **L-3:** a cookieless request no longer sees the previous request's session.
  - #76 N-1 (the A2 mutation is now killed), N-2 and N-3 are done. Both deviations from `main` are recorded in the PR body and hold.
  - **Mutations:** 19 of 22 are caught. Two survivors are equivalent or improvements (I-2, I-3). One shows that the DB-unreadable fallback has no test (N-1).
  - **New Lows:** both are latent hardening items at this head. L-1 is that `current_url()` mixes the public host with the request's scheme and port. L-2 is that a session left active is neither closed nor reset between worker requests.

## 1. Test plan

### 1.1 Environment

- **Docker isn't available on this box,** and `git` to GitHub doesn't work here, so the trees came from codeload tarballs. I ran `git init` on the mutation tree locally to track and revert mutations, and on the lint tree for `actionlint`.
- **Suites:** the box's PHP **8.4.27** CLI (pdo_pgsql, pgsql, pdo_sqlite, gd, imagick) with Composer dev dependencies from the locked `composer.lock` and `ci4/composer.lock` (CodeIgniter **4.7.5**). Each leg ran in its own copy of the tree. `lint.sh` also ran on PHP **8.3.36**. I didn't run the legacy legs, because no shared runtime code changed and CI ran all four green.
- **PostgreSQL 17:** scram-sha-256 on TCP, with the test-only superuser `apotheke` and CI's public test password (written here as `<CI test password>`). The databases were `qa2_82_c84`, `qa2_82_mut` and `qa2_82_live`.
- **Live repros:** `php8.4 -S` with `ci4/public/router.php` (the shipped boot), SQLite, `CI_ENVIRONMENT=production`. Each server ran on a copy of the head (or base) with **scratch routes only**: an HTML page that asks `SessionToken` for a token, a plain HTML page that prints `base_url()`, `site_url()` and `current_url()`, a `redirect()->to()`, `CurrentUser`, a settings import, and `session()->regenerate()` / `destroy()` / `set()`. Nothing else changed. In both copies, `Config\Session::$timeToUpdate` was set to 3 s to stand in for 300 s.
  - `:9821` was the head with sign-in on (an admin account, guest browsing on), and `:9822` was the base `f888dc0` with the same setup.
  - `:9823` was the head with `PUBLIC_URL=https://pub.example`.
  - `:9824` and `:9825` were the head with `TRUSTED_PROXIES` set to `127.0.0.1` (the peer is listed) and to `10.0.0.0/8` (the peer isn't listed).
  - `:9826` was the head with only the `public_url` **setting** set, and `:9827` was the head with `ALLOWED_HOSTS=inv.example`.
  - `:9828` was the head with sign-in off and nothing set.
  - `:9829` was the head with an unreachable PostgreSQL and `PUBLIC_URL=https://fallback.example`.
  - Signed-in sessions were written as session files (uid, epoch, csrf, `last_seen`), because CI4 has no sign-in page yet.
- **gitleaks:** 8.30.1, the same version CI pins.
- Every suite run used `CI=true`.

### 1.2 Acceptance checks

1. **CI** on the head SHA (22/22) and the commit subjects.
2. **The #76 Lows:** L-1, L-2 and L-3 against the base and the head, plus the claims made for each.
3. **#76 N-1 / `main` #77** (the CLI actor, never admin for the web guest), N-2 and N-3.
4. **Suites:** ci4 @ 8.4 on SQLite and PostgreSQL 17 and the CI4 module suite. Then the PHPStan DI rule, deptrac, `lint.sh` (8.4 and 8.3), `composer audit` and the commit check.
5. **Mutations** on `NativeSession`, `publicbase`, the per-request reset and the CLI-actor rule.
6. **Worker-mode conditions,** the static-local rule and the Cloudflare-phase note.
7. **Docs, CHANGELOG** and the PR body's "Deviations from main".
8. **Security** and gitleaks.

## 2. Results

### 2.1 CI and commits (item 1)

- **Run 40097033128** (`e7ba34f`, `pull_request`): **22/22 `success`**, 16:11–16:22 Athens time (UTC+3). Nothing failed and nothing is pending.
  - Lint, headless mobile checks, Docker build + smoke test, and Visual E2E shards 1–3 each ran on PHP 8.3 and 8.4.
  - The test jobs were legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan also passed.
- **Commit subjects:** all 12 pass `scripts/check-commits.sh --message`, at 54–68 characters. A bad subject is refused, so the check is live. They are `fix(ci4)` ×4, `test(ci4)` ×4, `docs(ci4)` ×2, `refactor(ci4)` ×1 and `docs(changelog)` ×1.

**PASS**

### 2.2 #76 L-1: CI4's own session service (item 2)

On a signed-in admin session, `GET` of an HTML page was sent once, then again after `timeToUpdate`, then `CurrentUser` was asked with the cookie the server last sent.

| Check | Base `f888dc0` (`:9822`) | Head `e7ba34f` (`:9821`) |
|---|---|---|
| First HTML page | **`Set-Cookie`, plus a second file `apothekesid<id>`** next to `sess_<id>` | no `Set-Cookie`, only `sess_<id>` |
| HTML page after `timeToUpdate` | **a new id, a third file**, `Set-Cookie` with the new id | no `Set-Cookie`, the same single file |
| `CurrentUser` afterwards | **anonymous guest (signed out)** | still the admin, `admin=1` |
| A new visitor on a token page, then 40 more HTML requests | – | one `sess_` file, one `Set-Cookie`, no `apothekesid*` file |
| 20 cookieless HTML pages | – | no file, no `Set-Cookie` |
| An idle-ended session (31 days) on an HTML page | – | file removed, no CI4 file, no new cookie |
| `session()->regenerate()` / `destroy()` from a controller | – | **500**, `LogicException` logged. The session file and id are unchanged, and the user is still signed in |
| `session()->set()` / `setFlashdata()` | – | `NativeSession`, `session_status()` = none. Nothing is written to `sess_<id>` (N-3) |

- `Services::session()` returns `NativeSession` both shared and non-shared, through `getSharedInstance()`. The constructor doesn't call the parent's, so no handler, cookie or `ini_set()` is touched. `start()` returns `$this`, `close()` does nothing, and `regenerate()` and `destroy()` throw.
- CI4 4.7.5's `Session` in this version has no `stop()`. The other inherited methods (`set`, `get`, `remove`, flash and temp data) only touch `$_SESSION`. They don't throw (N-3).
- `storePreviousURL()` now runs on every HTML answer, because `$_SESSION` is always set (L-3). It writes `_ci_previous_url` only into memory. No `sess_` file contained it.

**PASS** (N-3 is wording)

### 2.3 #76 L-2: per-request base URL (item 2)

The #76 merge live table was re-run at the head, with the token-form save and clear (`POST /settings/autofill/token`) plus a scratch page that printed `base_url()`, `site_url()` and a `redirect()->to()`.

| Case | Token form | `base_url('labels')` / `redirect()->to('/healthz')` |
|---|---|---|
| Sign-in off, Host `127.0.0.1:9828` / `localhost:9828` | **303**, `Location: /settings/autofill?done=token-saved` / `…token-cleared` (relative) | `http://127.0.0.1:9828/…` / `http://localhost:9828/…` |
| Only the `public_url` setting (`http://set.example:9091`), Host `set.example:9091`, `127.0.0.1:9826` or `localhost:9826` | 303, relative | **`http://set.example:9091/…`** for every Host (on `aa02471` it was `http://localhost/…`) |
| `PUBLIC_URL=https://pub.example`, Host `pub.example` or `127.0.0.1:9823` | 303, relative | `https://pub.example/…` |
| `ALLOWED_HOSTS=inv.example`, Host `inv.example`, `inv.example:8080` or `INV.example` | – | `http://inv.example[:8080]/…` (lower-cased) |
| Empty Host, or HTTP/1.0 without Host | – | `http://localhost/…` |
| IPv6 literal `[::1]:9828` | – | `http://[::1]:9828/…` |
| Spoofed Host (`evil.example`, `evil.example:9091`, `other.example`), sign-in off or on | **421**, no `Location`, token unchanged | 421 |
| Cross-site (`Sec-Fetch-Site: cross-site` or a foreign `Origin`), sign-in off | **403** (JSON for a fetch client) | – |
| Fetch client | 200 `{"ok":true,"redirect":"/settings/autofill?done=token-cleared"}` | – |
| No CSRF token, sign-in off or on | **419** | – |
| Sign-in on: an anonymous session with a token, cross-site with a token, a signed-in admin | 303, relative (same-origin is off with sign-in on, as on `main`) | `http://127.0.0.1:9821/…` |

- No case wrote a second session file or gave a 500.
- **The setting decides first.** With an address configured, the request's Host is never used for `base_url()`, `site_url()` or `redirect()->to()`. Without one, only a Host that passed `HostCheck` is used, else `localhost`. That's legacy `public_url()` plus `main`'s allow-list. **`current_url()` is the exception:** it keeps the request's scheme and port (L-1).
- **`/healthz` and `/health` skip the filter.** Mutation B8 is killed by `testHealthIsLeftAlone`. `/healthz` answered 200 with the setting stored.
- **`Config\App` reads no database.** `Ci4ConnectFailureLeakTest` passed on the PostgreSQL leg. `Config\App` keeps the fallback (`PUBLIC_URL` or an allowed Host, `RequestScheme(false)`).

**X-Forwarded-Proto / X-Forwarded-Host** (`/qa/html` and `redirect()->to()` with Host `127.0.0.1:<port>`):

| `TRUSTED_PROXIES` | `trust_proxy` | No header | `X-Forwarded-Proto: https` | `XFP: http, https` | `X-Forwarded-Host: evil.example` + `XFP: https` |
|---|---|---|---|---|---|
| empty | off | http | http | http | http, own host |
| empty | on | http | **https** | http (first value) | https, **own host** |
| `127.0.0.1` (peer listed) | off / on | http | http / **https** | http | own host |
| `10.0.0.0/8` (peer not listed) | off / on | http | http / **https** | http | own host |

- With `trust_proxy` off, no header changes the scheme. `X-Forwarded-Host` is never read. With `trust_proxy` on, `X-Forwarded-Proto` is honoured from any peer, including one that isn't in a non-empty `TRUSTED_PROXIES`. That's the same as `main`'s `request_is_https()` (#76 I-1, carried over as I-1). It only sets the scheme of that client's own response, and it's irrelevant once an address is configured.

**DB-unreadable fallback** (`:9829`, PostgreSQL unreachable, `PUBLIC_URL=https://fallback.example`, with `X-Forwarded-Proto: https` sent):

| Host | Result |
|---|---|
| `fallback.example` / `127.0.0.1:9829` | 200, `base_url()` = `https://fallback.example/…` (`trust_proxy` taken as off) |
| `evil.example` | 421 |

- That's deviation 2, as written. `/healthz` gives legacy's empty 500 when the database is down, which is unchanged code. No test covers this fallback (B7, N-1).

**PASS** (L-1 is new and latent)

### 2.4 #76 L-3: per-request `$_SESSION` reset (item 2)

My #76 scratch test `QaWorkerBleedTest` (two `SessionCookie::before()` calls in one process, with `Services::reset()` and `$_COOKIE = []` in between, as CI4's worker loop does) was re-run unchanged:

| Test | Base `f888dc0` | Head `e7ba34f` |
|---|---|---|
| A cookieless request after a live one | **fails**: it sees the first user's `uid`, `auth_epoch` and `csrf` | **passes**: `[]` |
| New: the previous request left the native session *active* (no `session_write_close()`), then the worker loop's `Services::session()->close()` ran | fails | **fails**: still active, and it still sees `uid`, `auth_epoch` and `csrf` (L-2) |

- `SessionCookie` resets `$_SESSION = []` before the health and asset skip (R2 killed), but only after `if (session_status() === PHP_SESSION_ACTIVE) return null;`. In CI4's FrankenPHP template, `Services::session()->close()` used to write-close such a session, because `Session::close()` calls `session_write_close()`. `NativeSession::close()` is a no-op, so that safety net is gone.
- `SessionCookieWorkerTest::testASecondRequestDoesNotSeeThePreviousSession` pins the main case (R1 killed).

**PASS** (L-2 is latent)

### 2.5 #76 N-1 / `main` #77: the CLI actor (item 3)

The scratch route imports `auth_guest_disabled`, `session_days`, `trust_proxy` (security section) and `app_tagline`.

| Actor | Result |
|---|---|
| Sign-in off (anonymous admin, `anon` false) | all 4 applied, `admin=1` (legacy parity kept) |
| Sign-in on, guest browsing on, anonymous guest | `app_tagline` applied, the 3 security keys **ignored**, `admin=0` |
| Sign-in on, signed-in editor | 3 ignored, `admin=0` |
| Sign-in on, signed-in admin | all 4 applied, `admin=1` |
| `bin/settings.php import` (`APOTHEKE_APP=ci4`, so spark) with sign-in **and** guest browsing on | "Imported 3 settings.", and `session_days` and `trust_proxy` are stored |

- **A web request can't get `CommandLine`.** Only the `commands()` factory (spark's `WiredCommands` → `SettingsCommand`) constructs it. No module or controller resolves `commands` or calls `command()`, and `ci4/public/` holds only `index.php`, `router.php` and assets. The web `settings()` factory uses `currentUser()` when no actor is given, and `CurrentUser` is never admin for nobody or a guest with `anon` set, on any SAPI.
- `testTheCommandLineImportsSignInSettingsWithGuestBrowsingOn` (bin and spark) **now kills A2**, which survived on #76. `testTheAnonymousGuestIsNeverAdmin` runs on the CLI SAPI and kills A6 and A7.
- **Deviation 1 holds.** CI4 has no SAPI branch, and its CLI is admin only through the explicit actor. That's stricter than `main`'s `actor_is_admin(?string $sapi)`.

**PASS**

### 2.6 #76 N-2 and N-3 (item 3)

- **N-2:** `docs/configuration.md` gained `allowed_hosts` / `ALLOWED_HOSTS` and `trusted_proxies` / `TRUSTED_PROXIES` rows. Both are marked "CI4 build (the legacy app gets it with the next `main` sync)". The `public_url` row explains CI4's per-request base. `config.example.php` is unchanged on purpose, because `ConfigTest` pins its keys (PR body).
- **N-3:** ADR 001 now says "`/healthz` reads no settings for built-in host names … for any other name the Host check reads `public_url`". No other copy of the old wording is left in `docs/`, AGENTS.md or the code.

**PASS**

### 2.7 Mutations (item 5)

These ran in a scratch copy only. Each mutation was reverted (`git status` clean afterwards) before the next.

- **Unit** = `ci4/phpunit.xml.dist` with PostgreSQL enabled.
- **Root** = `Ci4PageHttpTest`, `Ci4RequestHardeningHttpTest`, `Ci4HttpTest`, `ApothekeConfigTest`, `Ci4SettingsCliTest`, `RequestSchemeTest` and `Ci4DiRuleTest` on ci4 @ 8.4, SQLite.
- The DI rule ran on every mutation.

| # | Mutation | Caught by | Result |
|---|---|---|---|
| N1 | **L-1 revert:** `Services::session()` returns CI4's own `Session` | `NativeSessionTest`, `Ci4PageHttpTest` (3) | **killed** |
| N2 | `regenerate()` changes the id instead of refusing | `NativeSessionTest::testRegenerateAndDestroyRefuse` | **killed** |
| N3 | `destroy()` ends the session instead of refusing | `NativeSessionTest::testRegenerateAndDestroyRefuse` | **killed** |
| N4 | `start()` starts a native session | `NativeSessionTest::testTheSessionServiceNeverStartsASession` | **killed** |
| N5 | `close()` write-closes an active session | none | survives: **an improvement**, which is the L-2 fix (I-3) |
| B1 | `publicbase` removed from the required filters | `Ci4PageHttpTest` (2) | **killed** |
| B2 | the `public_url` setting ignored (config only) | `PublicBaseTest` (3), `Ci4PageHttpTest` | **killed** |
| B3 | `trust_proxy` ignored | `PublicBaseTest`, `Ci4PageHttpTest` | **killed** |
| B4 | the base takes any Host (no allow-list) | `PublicBaseTest::testAHostThatFailsTheAllowListBecomesLocalhost` | **killed** |
| B5 | the request URI keeps the request's Host | `PublicBaseTest` (5), `Ci4PageHttpTest` | **killed** |
| B6 | `App::baseURL` not set per request | `PublicBaseTest` (5), `Ci4PageHttpTest` (2) | **killed** |
| B7 | the DB-unreadable fallback drops `PUBLIC_URL` | none | **survives (N-1)** |
| B8 | `/healthz` not skipped | `PublicBaseTest::testHealthIsLeftAlone` | **killed** |
| B9 | `RequestScheme::publicBase()` uses a disallowed Host instead of `localhost` | `AppBaseUrlTest`, `PublicBaseTest`, `Ci4RequestHardeningHttpTest` (2), `RequestSchemeTest` | **killed** |
| B10 | the token form's 303 absolute again (controller alone) | `AutofillTest::testPlainFormSavesAndClearsTheTokenWith303`, `Ci4PageHttpTest` | **killed** |
| B11 | `publicbase` before `hostcheck` | none | survives: **equivalent** (I-2) |
| R1 | **L-3 revert:** `$_SESSION` not reset | `SessionCookieWorkerTest::testASecondRequestDoesNotSeeThePreviousSession` | **killed** |
| R2 | reset only after the health and asset skip | `SessionCookieWorkerTest` | **killed** |
| A2 | **#76 A2:** spark's `SettingsCommand` uses the request actor | `Ci4SettingsCliTest::testTheCommandLineImportsSignInSettingsWithGuestBrowsingOn` | **killed** (survived on #76) |
| A6 | the anonymous-guest guard removed from `admits()` | `AccountActionsTest::testTheAnonymousGuestIsNeverAdmin` | **killed** |
| A7 | the web anonymous guest resolves as admin | `AccountActionsTest` (2) | **killed** |
| A8 | `CommandLine` is not admin | `AccountActionsTest` (2), `SettingsServiceTest` (2), `Ci4SettingsCliTest` (2) | **killed** |

**19 of 22 killed.** One survivor is equivalent (B11), one is an improvement the tests allow (N5), and one is a test gap (B7, N-1).

### 2.8 Suites and static checks (item 4)

| Leg | Command | Result |
|---|---|---|
| ci4 @ 8.4.27, SQLite | `CI=true APOTHEKE_APP=ci4 APOTHEKE_TEST_DB=sqlite DB_DRIVER=sqlite php vendor/bin/phpunit` (with `TEST_PG_*` set, as in CI) | **OK**, 487 tests (483 on #76), 12501 assertions, 4 skipped (PostgreSQL leg only ×2, php-cgi not installed, `HttpSuiteTest`) |
| ci4 @ 8.4.27, PostgreSQL 17 | same with `pgsql`, `TEST_PG_DB=qa2_82_c84`, `TEST_PG_PASSWORD=<CI test password>` | **OK**, 487 tests, 12509 assertions, 2 skipped (php-cgi, `HttpSuiteTest`). `php scripts/ci4-pgsql-proof.php`: `rows=141`, exit 0 |
| both legs | CLI migrations as in CI (`bin/migrate.php`, an idempotent re-run, `bin/seed.php --demo` twice, `--status`) | OK |
| CI4 module suite | `php8.4 vendor/bin/phpunit -c ci4/phpunit.xml.dist` | **OK**, 163 tests and 1500 assertions with PostgreSQL. 135 tests and 1094 assertions on SQLite only. This matches the PR body |
| PHPStan DI rule | `php scripts/ci4-arch.php di` | `[OK] No errors` |
| deptrac | `php scripts/ci4-arch.php deptrac` | 0 violations, 0 errors |
| lint | `bash scripts/lint.sh` on 8.4, and on 8.3 through a `php` → `php8.3` shim | exit 0 / 0: PHP lint, JS syntax, 236 relative links in 67 files with 0 broken, shellcheck, actionlint and the pipefail-safe greps |
| dependencies | `composer audit` (root and `--working-dir=ci4`) | "No security vulnerability advisories found" for both |

- I didn't run the legacy legs locally (see 1.1). CI ran all four green.

**PASS**

### 2.9 Worker-mode conditions and the static-local rule (item 6)

| Condition | Code at `e7ba34f` | Enforced by | OK |
|---|---|---|---|
| (1) Filters resolve services only inside `before()`/`after()`, with no properties or statics | `PublicBaseFilter` declares no properties. `Services::settings()` and `Services::hostCheck(false)` are only in `before()`. Its only `static` is a `static fn` closure. `SessionCookie` is unchanged in this respect. The new `Services::session()` factory has no static variables. `NativeSession` and `PublicBase` live under `Core/Http/`, take everything as arguments and call no `Services::` | the DI rule. `FilterStateTest` globs `modules/*/Filters/*.php`, so it covers `PublicBaseFilter` automatically, checking properties and method static variables, and it also checks every `Services` factory | ✓ |
| (2) No cross-request caching | The per-request base is written into `config(App::class)` and the request's `SiteURI`. CI4's worker loop runs `Factories::reset()` (config included) and `Services::resetForWorkerMode()` after each request, and `persistentServices` is the framework default (`session` isn't persistent). `$_SESSION` is reset per request | `PublicBaseTest::testEachRequestResolvesItsOwnBase`, `SessionCookieWorkerTest` | ✓, except the active-session edge (L-2) |
| (3) `NoServiceRule` allows `Services::` only directly in `ci4/modules/<Module>/Filters/` | `tools/PhpStan/NoServiceRule.php` is byte-identical to the base | `Ci4DiRuleTest` | ✓ |

**PASS** with L-2.

### 2.10 Cloudflare phase (item 6)

- **PR body:** it has a "Cloudflare phase" section covering per-request global state, storage behind interfaces and DSN-driven DB config. It matches the code, apart from L-2's edge.
- **No per-request global state:** there are no new statics or properties. `$_SESSION` is reset per request, and the base lives in per-request config and URI objects.
- **Storage behind interfaces:** unchanged. Sessions still use PHP's file save path under `DATA_DIR/sessions`, and `NativeSession` adds no storage. The session-store interface is still planned.
- **DSN-driven DB config:** unchanged. There's no new connection, and `Config\App` reads nothing.
- **`$_SESSION`** is noted as Info (I-5).

**PASS**

### 2.11 Docs, CHANGELOG and the PR body (item 7)

- **Commits:** 12 Conventional Commits (2.1).
- **CHANGELOG:** three new `### Fixed` bullets under `[Unreleased]`, each `**ci4:** …` and ending with `([#82](https://github.com/thordin9/apotheke/pull/82))`. They cover the native session (#76 L-1), the base URL and the relative form redirect (#76 L-2), and the per-request session reset (#76 L-3). The wording is hardening only, with no repro or exploit detail. `lint.sh`'s link check passes.
- **ADR 001, AGENTS.md, PLAN:** these match the code (filter order `hostcheck` → `publicbase` → `posttoolarge` → `session` → `sameorigin`, `NativeSession`, relative redirects, the `$_SESSION` reset among the worker conditions, and the `CurrentUser` rule without a SAPI check).
- **"Deviations from main":** present, with three bullets:
  1. CI4 has no SAPI check, and the CLI is admin only through `CommandLine` (verified, 2.5).
  2. When the database can't be read, the base URL falls back to the `PUBLIC_URL` config with `trust_proxy` off (verified live, 2.3).
  3. `configuration.md` marks the two keys as CI4-only, and `config.example.php` is unchanged.
- **PR body wording:** the last line says "The CHANGELOG bullets are drafted separately and aren't committed", but `e7ba34f` commits them (N-2).

**PASS**

### 2.12 Security (item 8)

| Check | Result |
|---|---|
| **Secrets** | gitleaks 8.30.1 with the repo's `.gitleaks.toml` found **no leaks** in the 21 changed files. The new test literals are placeholders (`placeholder-pass`, `form-token-placeholder`, `kept`, `tokA`). The `docs/configuration.md` example values (`change-me`, `secret`) are unchanged lines. The only URLs are `github.com/thordin9/apotheke/pull/…` and `*.example` names. CI's history scan ("Secret scan") is green |
| **Host** | No case builds a URL from a Host that failed the allow-list, and with an address configured the request's Host isn't used at all. `X-Forwarded-Host` is never read. A spoofed Host gets 421 before any controller |
| **Scheme** | `X-Forwarded-Proto` counts only with `trust_proxy` on, as on `main` (I-1) |
| **Sessions** | CI4 can no longer create, rename or end a session. Only `SessionCookie`, `SessionGate` and `SessionToken` touch the native session. The cookie flags are unchanged |
| **Redirects** | Apotheke's own token-form 303 is relative. The JSON answer was already relative |
| **Actor** | The web never gets `CommandLine`. The web guest is never admin, and sign-in off keeps legacy's anonymous admin |
| **New runtime surface** | No new public route. The HTML test route exists only in `tests/ci4-router.php` with `APOTHEKE_TEST_HTML_PAGE=1`. One new required before-filter only sets the base URL |
| **Dependencies** | `composer audit`: no advisories |

**Nothing High or Medium.** L-1 and L-2 are latent hardening items for the first CI4 pages with forms and for the worker phase.

**PASS**

### 2.13 Secrets in this doc

No real secret appears here. The CI test password is written as `<CI test password>`. No session id, CSRF token or password hash is reproduced, and the scratch accounts used random passwords that weren't recorded.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low | **`current_url()` mixes the public host with the request's scheme and port.** `PublicBase::apply()` sets `App::baseURL` and only the **host** of the request's `SiteURI`. `base_url()`, `site_url()` and `redirect()->to()` are correct, but `current_url()` keeps the request URI's scheme and port. With the address `https://shelf.example` and the app opened at `127.0.0.1:9826`, `current_url()` was `http://shelf.example:9826/…`. With `http://set.example:9091` opened at `localhost`, it was `http://set.example/…`. CI4 uses `current_url()` for `form_open()` without an action, for pager links and for `storePreviousURL()`, so a future form could post to a scheme and port the public address doesn't serve. It's fail-safe for SEC-M3, because the host is always the configured one and never the request's. It's latent: no CI4 page uses `current_url()` or `form_open()` yet, and `storePreviousURL()`'s value is discarded. | In `PublicBase::apply()`, also set the URI's scheme and port from the base (or `null` for the default port), or rebuild the request URI against the per-request `App`. Assert `current_url()` in `PublicBaseTest` / `Ci4PageHttpTest`. |
| L-2 | Low | **A session left active isn't closed or reset between worker requests.** `SessionCookie::before()` returns early on `PHP_SESSION_ACTIVE` before `$_SESSION = []`. `NativeSession::close()` is a no-op, so the `Services::session()->close()` in CI4's worker loop no longer write-closes a native session that a request left open, which CI4's own `Session::close()` did. A scratch test (2.4) left the session active, as an exception between `session_start()` and `session_write_close()` in `SessionToken` or `SessionGate` would. The next, cookieless request still saw the previous user's `uid`, `auth_epoch` and `csrf`. It's latent: there's no worker entry, and it needs an error path. | Have `NativeSession::close()` write-close an active session (N5 shows the tests allow it). In `SessionCookie`, close a leftover active session and still reset, instead of returning early. Add the case to `SessionCookieWorkerTest`. |
| N-1 | Nit | The DB-unreadable fallback in `PublicBaseFilter` (`PUBLIC_URL` with `trust_proxy` off, deviation 2) has no test. B7 (fallback to `''`) survives. It's correct live (2.3). | Add a `PublicBaseTest` case with a settings service that throws. |
| N-2 | Nit | The PR body's last line says the CHANGELOG bullets "are drafted separately and aren't committed", but `e7ba34f` commits them. | Optional: update the PR body. |
| N-3 | Nit | "Throws on any attempt to change or end it" holds for `regenerate()` and `destroy()`. `set()`, `remove()` and the flash and temp data methods don't refuse. They change this request's in-memory `$_SESSION`, which `CurrentUser` reads for the rest of the request. Nothing is persisted while the session is closed (2.2). | Optional: say "change the id or end it" in the docs, or keep `uid`, `auth_epoch` and `csrf` read-only in `NativeSession`. |
| I-1 | Info | `X-Forwarded-Proto` is honoured from any peer when `trust_proxy` is on, including one that isn't in a non-empty `TRUSTED_PROXIES` (live, 2.3). This is parity with `main`'s `request_is_https()` and #76 I-1. It now also picks the scheme of CI4's base URL for that client's own response, but only when no address is configured. The `trusted_proxies` row in `configuration.md` mentions only `X-Forwarded-For`. | None now. Later, on both branches, honour it only from listed proxies when the list isn't empty. |
| I-2 | Info | B11 (`publicbase` before `hostcheck`) is equivalent. The filter applies the allow-list itself, and `hostcheck` still answers 421 first in effect. | None. |
| I-3 | Info | N5 (`close()` write-closes an active session) survives because it's an improvement. It's the L-2 fix. | See L-2. |
| I-4 | Info | `publicbase` reads `trust_proxy` and `public_url` on every request except health, including branding assets. That's one more settings load per request through the shared `SettingsService`, with no new connection. | None. |
| I-5 | Info | Cloudflare phase: `$_SESSION` is a process global tied to PHP's session API and file save path, which won't exist on Workers. With the per-request reset it fits "no per-request global state" (apart from L-2). The session-store interface the PR body plans is what removes it. | Track with the session-store interface. |
| I-6 | Info | With an address configured, a user who opens the app by IP gets links to the configured host. That's legacy `public_url()` behaviour, by design. | None. |

No High or Medium findings.

## 4. Verdict

**PASS at `e7ba34f9ceb5df317bdbdec2eac9ae9620016268`.**

- **CI:** run 40097033128, 22/22 `success`. 12 Conventional Commit subjects.
- **#76 Lows:**
  - **L-1:** on HTML pages there's no second session file, no id change after `timeToUpdate` and no sign-out. Base and head were compared live.
  - **L-2:** the base URL follows the `public_url` setting, then `PUBLIC_URL`, then the scheme with `trust_proxy` and an allowed Host, else `localhost`. The token form's 303 is relative. Spoofed Host gives 421, cross-site gives 403 and no token gives 419. The DB-down fallback goes to `PUBLIC_URL`.
  - **L-3:** a cookieless request sees `[]`.
- **#76 nits:** N-1 is done (A2 killed, and the web never gets `CommandLine`). N-2 and N-3 are done.
- **Suites:** ci4 @ 8.4 is green on SQLite and PostgreSQL 17 (487 each), and so is the module suite (163 with PG, 135 on SQLite only). The DI rule, deptrac, `lint.sh` (8.4 and 8.3), `composer audit` and the commit check pass.
- **Mutations:** 19 of 22 caught. One is equivalent (B11), one is an improvement (N5) and one is a test gap (B7, N-1).
- **Open items:** L-1 (`current_url()`) before the first CI4 form page, and L-2 (a leftover active session) before the worker entry. Plus nits N-1 to N-3.
