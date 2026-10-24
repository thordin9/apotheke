# QA: apotheke #80, end sessions idle longer than `session_idle_hours`

- **PR:** [#80](https://github.com/thordin9/apotheke/pull/80), `cursor/session-idle-hours-e4a3` into `main`, by Coder
- **Head reviewed:** `735012a99f9f3aba0694c03de496bf6634db2b02` (still the PR head when I re-checked at the end of the review)
- **Base:** `main` at `c4e9eda` (`chore(release): v2.6.7`), the second parent of the merge `735012a`
- **Code reviewed in full:** `502048b` (fix) and `f5fa09e` (CHANGELOG). `785eee8` (visual baselines) and `735012a` (merge of `main` v2.6.7) were diff-read.
- **Scope:** a full review (auth fix, SEC-N6 idle part): `src/bootstrap.php`, `src/settings.php`, migration 0027, tests, five docs, CHANGELOG
- **Verdict: PASS.** 0 High, 0 Medium, 2 Low, 4 Nits, 8 Info. Nothing blocks.

## 1. Test plan

1. Read-only `git clone` into a temporary directory, `refs/pull/80/head`, `main`, and a worktree of `c4e9eda` for the upgrade test. Diff review, commit subjects.
2. Security core: where `init_session()` runs in the front controller, which requests stamp the session, what idle expiry does to the session file and id, and how the new key is guarded on the form, import, restore and the CLI.
3. Live probes under `php -S` with `APOTHEKE_CLOCK` (the suite's own clock hook): branding and health requests, 404 and media requests, expiry and the old cookie, the absolute limit, an unstamped (v2.6.7) session, guest browsing, JSON/API, session fixation, the password-change sign-out, form validation and CSRF, a Users-only account and the anonymous guest.
4. CLI: `bin/settings.php set` and `import` with valid and invalid values, the import with guest browsing on (#77 path), and `bin/restore.php` with invalid and valid values.
5. Upgrade: a v2.6.7 install (`c4e9eda`) with a signed-in session, then the head's `bin/migrate.php` (twice) and a request with the old cookie, on SQLite and PostgreSQL 17, on PHP 8.3 and 8.4.
6. PHPUnit unit suite and the HTTP suite (`HttpSuiteTest`, plus `tests/integration.php` directly for the counts) on PHP 8.3 and 8.4, on SQLite and PostgreSQL 17.
7. 20 mutations, each against the auth-related unit suites on both drivers and the full HTTP suite on SQLite.
8. CHANGELOG gate, `check-links`, `check-commits`, docs read against the code, secret and host-name scan of the diff.
9. CI run 40094713516, checked through the GitHub API.

**Environment.** The box's PHP 8.3.36 and 8.4.27 builds (pdo_sqlite, pdo_pgsql, gd, imagick). PostgreSQL 17 with a throwaway role and throwaway databases, all dropped afterwards. The role got `CREATEROLE` and `createrole_self_grant = 'set, inherit'` so that the `PgsqlSchemaCreateTest` cases and the HTTP suite's "schema without CREATE" section could run. The roles those tests create were dropped as well. The Docker build, the smoke test and Playwright were not repeated. CI covers them (2.1).

## 2. Results

### 2.1 CI

Run 40094713516 (`pull_request`, head `735012a`, attempt 1): **`completed` / `success`, 10/10 jobs `success`**. The jobs are Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E 1/3, 2/3 and 3/3, Docker build + smoke test, Conventional Commits, and Secret scan. The run started at 15:37:43 and ended at 15:47:46 (Athens, UTC+3).

### 2.2 Diff and commits

Against `main` `c4e9eda`: 21 files, +242/−25. That's `src/bootstrap.php` (+45), `src/settings.php` (+2/−1), `migrations/0027_session_idle_hours.php` (new, 10 lines), tests (`SessionCookieTest` +72, `SettingsTest`, `BackupTest`, `tests/integration.php` +104), five docs, CHANGELOG (+4) and 8 `settings-security.png` baselines.

| Commit | Subject |
|---|---|
| `502048b` | `fix(auth): end sessions idle longer than session_idle_hours` |
| `f5fa09e` | `docs(changelog): record the idle session limit` |
| `785eee8` | `test(e2e): update the security settings baseline` |
| `735012a` | Merge of `origin/main` (`c4e9eda`, v2.6.7) |

All subjects are Conventional Commits and 72 characters or shorter (48, 46, 59). `scripts/check-commits.sh d9eb204 785eee8` passes. There are no rewrites, and no `docs(qa):` commit yet: this doc becomes the last commit.

**`785eee8`** changes only the 8 `settings-security.png` baselines (no text files). The page gained one field, and CI's Visual E2E shards pass against them.

**`735012a` (diff-read).** The diff from `c4e9eda` to the head equals the PR's own diff (`d9eb204` to `785eee8`) file for file. Outside the CHANGELOG, the two patches differ only in blob ids and one README context line that v2.6.7 itself changed. So the merge brings in exactly `main` v2.6.7, and nothing else changes in the PR's files.

**The change.**
- `session_idle_seconds()`: the setting in hours, clamped to 1–8760, with 168 when it is 0 or unreadable.
- `session_idle_expired()`: true only for a signed-in session (`uid` > 0) that has a stamp and has had no request for **more than** the limit. A session with no stamp is not idle.
- `session_touch()`: stores `last_activity = app_time()` in the session, only for a signed-in session.
- `init_session()`: after `start_session()`, an idle session gets `logout()` (empties `$_SESSION`, `session_regenerate_id(true)`) and the user cache is reset. Otherwise the request is stamped, and then the CSRF token and `session_write_close()` follow as before.
- `login_session()`: stamps at sign-in.
- Settings schema: `session_idle_hours` in the `security` section, `int`, default 168, 1–8760. The `session_days` hint now says it is counted from sign-in and not extended by later visits.
- Migration 0027: inserts `168` if the row is missing and leaves an existing value alone.

### 2.3 Security core

| Check | Result |
|---|---|
| Which requests stamp | `init_session()` runs in `public/index.php` after `/healthz`, `/health`, `/theme.css`, `/labels.css`, `/manifest.webmanifest`, `/favicon.ico` and `/brand/*`, all of which `exit` first. So those never open the session or stamp it. Every other request with a live session cookie does, including 404s, `/media/*`, JSON and the non-JSON 413 page. The JSON 413 answer exits before `init_session()`. |
| Background requests | `app.js` has no polling. Its `fetch()` calls are all user-triggered (instant search, `/stats` once when opened, quantity taps, saved filters, autofill). There is no service worker. An open but untouched tab doesn't keep a session alive. |
| Where the stamp lives | Only in the server-side session file (`DATA_DIR/sessions`). No request input reaches it. `app_time()` is `time()` unless the `APOTHEKE_CLOCK` environment variable is set (test hook, I-7). |
| Idle expiry | `logout()`: `$_SESSION = []`, `session_regenerate_id(true)` (the old file is deleted) and a new cookie. The request carries on unsigned-in, so the route guard sends it to `/login`, a JSON caller gets 401 and media gets 401. |
| Session fixation | Unchanged and still fine: `use_strict_mode=1`, and `session_regenerate_id(true)` on sign-in, on your own password change, on sign-out and now on idle expiry. |
| Guard on the key | `settings_import()` (web import, restore through `backup.php`, and `bin/settings.php import`) skips every `security`-section key unless `actor_is_admin()`. The new key is in that section, so it is covered with no new code. The form (`/settings/security`) needs the `users` permission and CSRF, the same as the other sign-in settings (I-2). |

### 2.4 Live probes (`php -S`, SQLite, PHP 8.4, `APOTHEKE_CLOCK`)

Sign-in at T0, then a fresh server per point in time with the same data directory and cookie. 32/32 checks passed.

| # | Probe | Result |
|---|---|---|
| P1 | `/favicon.ico`, `/manifest.webmanifest`, `/theme.css`, `/labels.css`, `/healthz`, `/health` at T0+167 h | all 200. The stamp is still T0, and at T0+168 h+1 s `/` gives **303** (signed out). They don't refresh the idle clock. |
| P1b | A 404 page and an unknown `/media/thumb/…` at T0+167 h (both 404) | these count as activity: `/` at T0+168 h+1 s is 200 |
| P2 | Expiry at T0+8 d | 303, the **old session file is deleted**, a new id is issued (`Set-Cookie`, empty file), and replaying the old cookie stays signed out |
| P3 | The same cookie every 5 days up to T0+39 d (`session_days` = 30) | **200 every time**: the server never checks the absolute limit (L-1) |
| P4 | A session with its stamp removed (as written by v2.6.7), first request at T0+45 d | 200, and the first stamp is written then. At +168 h+1 s after that: 303. |
| P5 | Guest browsing on, expiry | `/` 200 as the anonymous guest (sign-in link shown), `/settings/security` 303 to `/login`, `POST /save` with the old token 303 to `/login`, nothing written. `POST /logout` with the old token: 419 (N-1). |
| P6 | JSON/API after expiry | `POST /save` (`X-Requested-With: fetch`): 401 `{"ok":false,"error":"Not signed in","redirect":"/login"}`. `/stats` (fetch): 401. |
| P7 | Fixation | sign-in issues a new id, and an attacker-chosen id is never adopted (no file is created) |
| P8 | Device A changes its password, device B is active | B is signed out at once (`auth_epoch`), whatever the idle clock says |
| P9 | Form values | `0`, `-1`, `8761`, `1.5`, `abc`, `1e3`, `0x10`, `99999999999`, `''`, `+5`: all **422**. `' 12 '`, `1`, `8760`: 303. A wrong CSRF token: **419**. The input is `type="number" min="1" max="8760" step="1" required`. |
| — | Users-only role (`view`, `users`), not admin | the form saves `8760` (303). `/settings/import` gives 403 (needs `settings`). See I-2. |
| — | Anonymous guest (guest browsing on) | `POST /settings/import` and `POST /settings/security`: 303 to `/login`, value unchanged. The guest's session has no stamp. |

### 2.5 CLI, import and restore

| Path | Rejected (value unchanged) | Accepted |
|---|---|---|
| `bin/settings.php set` | `0`, `-1`, `8761` ("between 1 and 8760"), `1.5`, `abc`, `1e3`, `0x10`, `99999999999` ("whole number"), `''`. Exit code 1, message on stderr. | `' 12 '`, `1`, `8760`, `168` |
| `bin/settings.php import` (JSON values) | `0`, `8761`, `-5`, `1.5`, `"abc"`, `false`, `null`, `99999999999`, `[1]` (each named as skipped) | `"24"`, `48`, `1e3` → 1000, `1.0e2` → 100, **`true` → 1** (N-4) |
| `bin/settings.php import` with sign-in **and** guest browsing on | — | applied (72). This is #77's CLI rule. |
| `bin/restore.php --yes` | `0` and `"abc"` in `backup.json`: the value stays | `36` applied |

The web side (a Settings account's import is skipped and named, an admin's import applies, and the same for restore) is covered by the PR's new HTTP checks and `SettingsTest` / `BackupTest`, all green, and M12 (2.8) shows they bite.

### 2.6 Migration 0027 and the upgrade from v2.6.7

On each of SQLite/PHP 8.4, PostgreSQL/PHP 8.4, SQLite/PHP 8.3 and PostgreSQL/PHP 8.3:
1. A v2.6.7 install (`c4e9eda`, `APP_PASSWORD` at install) runs 26 migrations. Sign-in gives 303 and `/` gives 200. The session file has no `last_activity`.
2. The head's `bin/migrate.php` applies `0027 session_idle_hours` (1 step). A second run prints "Already up to date". The row holds `168`, and `schema_migrations` has `0027` once.
3. A head server with the **v2.6.7 cookie**: `/` gives **200** (no mass sign-out), and the session gets its first `last_activity` stamp on that request.

On a fresh install, 0013's `settings_seed()` already writes the row from the schema, so 0027 is a no-op there (covered by `testMigration0027SeedsIdleHoursAndKeepsAnExplicitValue` and by M18).

### 2.7 Tests

| Leg | Unit | HTTP suite (`HttpSuiteTest`) | `tests/integration.php` directly |
|---|---|---|---|
| PHP 8.4, SQLite | 429 tests: 426 pass, 3 skipped | OK | **1571 passed, 0 failed** |
| PHP 8.4, PostgreSQL 17 | 429: 426 pass, 3 skipped | OK | **1280 passed, 0 failed** |
| PHP 8.3, SQLite | 429: 426 pass, 3 skipped | OK | **1571 passed, 0 failed** |
| PHP 8.3, PostgreSQL 17 | 429: 426 pass, 3 skipped | OK | **1280 passed, 0 failed** |

The 3 skips are environment-only (I-5). The PostgreSQL run of the HTTP suite leaves out its SQLite-only sections by design (the v1/v2 migrations, rollback, host allow-list, same-origin, sessions on demand and autofill), hence 1280. The PR body's "424" and "1568 each" don't match these counts exactly (I-4). That's not a defect.

### 2.8 Mutations

Each mutant ran against `SessionCookieTest`, `SettingsTest`, `BackupTest`, `MigrationRunnerTest`, `PermissionsTest` and `UsersTest` on SQLite (76 tests), the first three on PostgreSQL (43 tests), and the full HTTP suite on SQLite.

| ID | Mutation | Result |
|---|---|---|
| M01 | Idle boundary `>` → `>=` | **Caught** (HTTP: "exactly 168 hours is still signed in") |
| M02 | Boundary off by one second (`> limit + 1`) | **Caught** (HTTP ×5: "one second past the idle limit signs out", …) |
| M03 | `session_idle_expired()` always false | **Caught** (unit, both drivers; HTTP ×6) |
| M04 | `session_touch()` writes nothing (activity stamp) | **Caught** (unit, both drivers; HTTP "idle time counts from the last request") |
| M05 | No stamp at sign-in (`login_session()`) | **Caught** (HTTP ×6) |
| M06 | Per-request touch removed from `init_session()` | **Caught** (HTTP "idle time counts from the last request") |
| M07 | Schema default 168 → 24 | **Caught** (unit ×3 per driver; HTTP ×6) |
| M08 | Fallback in `session_idle_seconds()` `?: 168` → `?: 24` | Survives (N-3) |
| M09 | Max 8760 → 8761 | **Caught** (unit; HTTP ×4, including the CLI check) |
| M10 | Min 1 → 0 | **Caught** (unit; HTTP ×3) |
| M11 | Clamp `min(8760, …)` removed in `session_idle_seconds()` | Survives (N-3) |
| M12 | Import guard skips `session_idle_hours` (non-admin applies it) | **Caught** (unit ×2 per driver; HTTP ×3, import and restore) |
| M13 | Expiry only unsets `uid` (no `logout()`: the session file and id are kept) | **Survives** (L-2) |
| M14 | Touch before the expiry check (never expires) | **Caught** (HTTP ×6) |
| M15 | An unstamped session counts as idle (mass sign-out on upgrade) | **Caught** (unit, both drivers) |
| M16 | `session_touch()` also stamps anonymous sessions | **Caught** (unit, both drivers) |
| M17 | `init_session()` moved above the branding routes (favicon/manifest refresh the clock) | **Survives** (L-2) |
| M18 | Migration 0027 without its "already there" guard | **Caught** (duplicate key: unit errors, HTTP crash) |
| M19 | Migration 0027 writes 24 | **Caught** (unit, both drivers) |
| M20 | No `current_user(null, true)` after the idle `logout()` | Survives. Equivalent at this head: nothing calls `current_user()` before `init_session()` in `public/index.php`. |

**Score:** 15 of 20 caught. Of the 5 survivors, 1 is equivalent (M20), 2 are defensive clamps (M08, M11, N-3), and 2 are security properties without a test (M13, M17, L-2). The head behaves correctly for both (P1, P2).

### 2.9 CHANGELOG gate (at the head, against `main` `c4e9eda`)

| Check | Result |
|---|---|
| Head contains `main`'s latest release commit | Yes. `c4e9eda` (v2.6.7) is the second parent of `735012a`, and `git ls-remote` still shows `main` at `c4e9eda`. |
| `[2.6.7]` to EOF byte-identical to `main` | Yes, `cmp` matches. Both sides hash to `9bd87989…f063`. |
| The #80 bullet | One `### Fixed` bullet, only under `[Unreleased]`, `**auth:**` scope, linked to #80 |
| `check-links` | 203 relative links in 16 files, 0 broken |

### 2.10 Docs against the code

| Doc | Says | Matches? |
|---|---|---|
| README | at most `session_days` (default 30), ends after `session_idle_hours` with no request (default 168, at most 8760) | Yes for the idle limit and the values. "At most `session_days`" holds only through the cookie's expiry (L-1). |
| control-panel.md | two table rows: `session_days` 30, 1–365, counted from sign-in, from the next sign-in; `session_idle_hours` 168, 1–8760. The new key is in the import's sign-in key list. | Yes |
| user-guide.md | at most 30 days, ends sooner after 7 days with no request ("Sign out after idle", 1–8760 hours). The idle limit is among the keys a non-admin import leaves alone. | Yes (labels match the schema) |
| configuration.md | both limits are DB settings, with no `config.php` key for either | Yes (`cfg()` and `config.example.php` have neither) |
| architecture.md | the cookie lifetime isn't extended by later visits, and the last-request time is kept in the session file and updated when the session is opened | Yes. PHP doesn't resend the cookie on later requests (no `Set-Cookie` on a signed-in GET), and the stamp is written in `init_session()`. |

## 3. Findings

### L-1 (Low, not from this PR, but now documented as a guarantee): the absolute `session_days` limit is only the cookie's expiry

`session_days` sets the cookie's `Expires` at sign-in (and `gc_maxlifetime`), but the server never checks how old a sign-in is. Every request rewrites the session file, so its mtime never gets old enough for GC either. P3: the same cookie, used every 5 days, is still signed in 39 days after sign-in with `session_days` = 30. A browser drops the cookie on time, so normal use matches the docs. A copied cookie, or a client that ignores `Expires`, stays signed in for as long as it makes a request at least every `session_idle_hours`. Before #80 there was no limit at all for such a client, so this PR makes things strictly better. But the README, the user guide, control-panel and the setting's hint now state "the longest a sign-in lasts, counted from sign-in" as a fact.

Follow-up is fine. Store `$_SESSION['signed_in_at'] = app_time()` in `login_session()`, and in `init_session()` end the session the same way as idle expiry when `app_time() - signed_in_at > session_lifetime()`. Stamp it on the first request of an old session, as with `last_activity`. Add an HTTP check at `session_days` + 1 s.

### L-2 (Low): two of the PR's security properties have no test

- **Branding and health requests don't refresh the idle clock.** M17 moves `init_session()` above the branding routes, so `/favicon.ico` and `/manifest.webmanifest`, which browsers fetch on their own, would keep a session alive. All suites still pass.
- **Idle expiry destroys the server-side session and rotates the id.** M13 only unsets `uid` and keeps the old session file, id and CSRF token. All suites still pass.

The head is correct for both (P1, P2). Two short HTTP checks would cover them:
1. At limit − 1 h, `GET /favicon.ico` and `/manifest.webmanifest`. Then at limit + 1 s, `/` gives 303.
2. After expiry, the old `sess_<id>` file is gone and the response sets a new `apothekesid`.

### N-1 (Nit): "Sign out" on a page left open past the idle limit answers 419

`POST /logout` runs `check_csrf()` after `init_session()` has already ended the session, so the old token fails: "Your session expired. Reload the page and try again." (P5). The user is already signed out, so nothing is wrong, but sending a request that isn't signed in straight to `/login` would be friendlier.

### N-2 (Nit): every signed-in request now rewrites the session file

`last_activity` changes every second, so PHP's lazy write never skips the write. Each signed-in request, including every thumbnail on a list page, rewrites the session file under the session lock. It's harmless at this scale. Stamping only when the stored value is at least 60 s old would keep the idle precision to within a minute and cut almost all writes.

### N-3 (Nit): the clamp and fallback in `session_idle_seconds()` are untested

M08 (fallback 168 → 24) and M11 (no upper clamp) survive. Both only matter for a value that bypassed validation (a hand-edited row). A unit test that stores `0`, `-5` and `99999` directly with `settings_store()`, then checks 168 h, 1 h and 8760 h, would pin them, the way `testLifetime` already does for `session_days`.

### N-4 (Nit, not from this PR): a JSON `true` imports as 1 for integer settings

`settings_import()` passes booleans through unchanged, and the `int` case turns `true` into `"1"`, so `"session_idle_hours": true` sets a 1-hour idle limit (2.5). `false` and `null` are rejected. The same applies to every `int` setting. Rejecting `is_bool($raw)` in the `int` case would make the import strict.

### I-1 (Info): SEC-N6's "sign out everywhere" part isn't in this PR

The audit's SEC-N6 asked for an idle timeout **and** a "sign out everywhere" action. This PR does the first. The CHANGELOG bullet cites SEC-N6 without saying it's partial. A password change still ends every other session (P8). That isn't affected by the idle limit, and the two work independently.

### I-2 (Info): who can change the idle limit

- **Form:** an account with the `users` permission (the route rule for `/settings/security`, the same as for `session_days`, guest browsing and the other sign-in keys since #71) plus a valid CSRF token. That's not only admins: a custom role with `view`+`users` saved `8760`.
- **Import and restore:** admin account, or the command line.
- **Anonymous guest:** never. It's sent to `/login`, and the Guest role can't hold `users`, `settings` or `backup`.

This matches the existing design for all sign-in keys. Making the form admin-only too would be a separate change for every security key.

### I-3 (Info): a change to the idle limit applies at once

`session_idle_hours` is read on every request, so lowering it ends existing sessions that are already idle longer than the new value on their next request. `session_days` applies only from the next sign-in, which the docs say. The idle behaviour is reasonable but isn't spelled out. One clause in control-panel.md would do it.

### I-4 (Info): test counts against the PR body

Here: 429 unit tests (426 pass, 3 environment skips), and the HTTP suite has 1571 checks on SQLite and 1280 on PostgreSQL (its SQLite-only sections skip). The PR body says 424 and "1568 each". The differences come from the environment (Imagick/HEIC, `php-cgi`, the driver), and every suite is green on every leg.

### I-5 (Info): environment-only results

- `ImageDecodeTest::testHeicDecoderMemoryLimitIsTheDimensionError`: skipped (this libheif reports a truncated file, not a resource limit).
- `OriginalsTest` php-cgi: skipped (no `php-cgi`).
- `ReleaseTest::testRealChangelogUpgradeNoteLeadsTheNextReleaseNotes`: skipped, because there's no upgrade note under `[Unreleased]`. That's correct, since #80 needs none (P4 and 2.6: no mass sign-out).
- `PgsqlSchemaCreateTest` and the HTTP suite's "schema without CREATE" section need a role with `CREATEROLE` that can `SET ROLE` to the roles it creates (PostgreSQL 16+). They pass with that and fail without it.

### I-6 (Info): CI4 parity

Coder2 ports this to `feature/ci4` under the same key. The port should keep the same points:
- branding and health routes don't stamp;
- expiry regenerates the id and drops the data;
- an unstamped session isn't idle;
- `> limit`, not `>=`.

It should also read `session_days` through the settings service (SEC-N9), and ideally carry L-1's server-side absolute check and L-2's tests.

### I-7 (Info): `APOTHEKE_CLOCK`

`app_time()` uses the `APOTHEKE_CLOCK` environment variable when it's set. That freezes time, and the idle limit would then never fire. It's an existing test hook that only the environment can set (no request input). Production must not set it, which is already the case in the image and the docs.

### I-8 (Info): idle sign-out leaves an empty session file

Like a manual sign-out, `logout()` regenerates the id, so an empty `sess_<new id>` file and a new cookie are left (P2). That's the existing sign-out behaviour, and normal GC removes the file.

## 4. Security

- **Idle bypass through requests that don't stamp:** none that a user would rely on. Only the public branding and health routes skip the stamp, and they're also what browsers fetch on their own, so skipping them is the right choice. Every authenticated route stamps. An untouched tab sends nothing (no polling, no service worker). A cross-site page can't keep a session alive with sub-resources, because the cookie is `SameSite=Lax`.
- **Clock and timestamp tampering:** the stamp lives only in the server-side session file and is set from `app_time()`. No cookie, header or form field reaches it. A server clock that goes backwards gives a negative idle time (not expired), and one that jumps forward can end sessions early. Neither is reachable by a client.
- **Session fixation:** strict mode plus `session_regenerate_id(true)` on sign-in, password change, sign-out and idle expiry (P2, P7).
- **Guest browsing on:** an idle session falls back to the anonymous guest. It can look, but a write with the old token goes to `/login`, and the settings pages need sign-in again (P5). Anonymous guest sessions are never stamped.
- **"Sign out everywhere":** there's still no action for it (I-1). A password change ends all other sessions regardless of the idle clock (P8).
- **API and JSON routes:** an idle session gets 401 JSON with `redirect: /login`, and media gets 401 (P6 and the PR's HTTP checks).
- **Server-side session data:** destroyed on idle expiry. The old file is deleted, the id is rotated, and the old cookie is dead (P2). That's untested (L-2).
- **Absolute limit:** client-side only (L-1). This PR narrows the exposure from unlimited to "active at least every `session_idle_hours`".
- **Guards on the key:** form (`users` permission + CSRF), import and restore (admin or CLI), never the anonymous guest. Validation is strict on every path, apart from the existing JSON `true` → 1 (N-4).
- **Secrets and host names:** the diff has no tokens, keys or private host names. The only literals are test passwords (`s3cret`) and the `github.com` PR link. CI's secret scan passed.
- **No High or Medium findings.**

## 5. Gate summary

| Gate | Status |
|---|---|
| CI green at `735012a` | Yes, 10/10 (run 40094713516) |
| Docs and tests in the PR | Yes: 4 new unit tests, extended Settings/Backup tests, a new HTTP section and guard checks, five docs |
| QA with a Security section, no High or Medium | Yes (this doc) |
| CHANGELOG gate | PASS against `c4e9eda` |
| Merge `735012a` | diff-read: brings in exactly `main` v2.6.7 |
| PM secret scan | PM to run |
| Merge pinned to the `docs(qa):` head | after this doc is committed unchanged as the last commit |
